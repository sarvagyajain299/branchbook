<?php

declare(strict_types=1);

use App\Actions\Booking\HoldAppointment;
use App\Enums\AppointmentStatus;
use App\Exceptions\RescheduleNotAllowed;
use App\Exceptions\SlotUnavailable;
use App\Mail\BookingConfirmedMail;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Services\Availability\AvailabilityService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

// "Now" is Tue 2026-12-01 10:00 Dubai. The booking moves around Mon 2026-12-07.
beforeEach(function (): void {
    $this->travelTo(nowAt());

    $this->branch = openBranch();
    $this->service = offering(30, notice: 2, maxDays: 30);
    $this->advisor = advisorFor($this->branch, $this->service);

    $this->token = 'old-secret-token';
    $this->old = bookedFor($this->advisor, $this->service, MON.' 09:00');
    $this->old->forceFill(['manage_token_hash' => hash('sha256', $this->token)])->save();
});

function moveTo(object $test, string $local, ?string $token = null): TestResponse
{
    return $test->post(route('booking.reschedule.perform', $test->old->reference), [
        'token' => $token ?? $test->token,
        'starts_at' => dubai($local)->toIso8601ZuluString(),
    ]);
}

// ---------------------------------------------------------------------------------------------
// The action
// ---------------------------------------------------------------------------------------------

it('books the new slot, releases the old one and links them', function (): void {
    [$new, $token] = app(HoldAppointment::class)->reschedule($this->old, dubai(MON.' 11:00'));

    expect($new->status)->toBe(AppointmentStatus::Confirmed)
        ->and($new->starts_at->equalTo(dubai(MON.' 11:00')))->toBeTrue()
        ->and($new->rescheduled_from_id)->toBe($this->old->id)
        ->and($new->customer_id)->toBe($this->old->customer_id)
        ->and($new->service_id)->toBe($this->old->service_id)
        ->and($new->branch_id)->toBe($this->old->branch_id)
        ->and($new->hold_expires_at)->toBeNull()
        ->and($new->reference)->not->toBe($this->old->reference)
        ->and($new->manage_token_hash)->toBe(hash('sha256', $token))->and($token)->toHaveLength(40);

    $old = $this->old->fresh();
    expect($old->status)->toBe(AppointmentStatus::Cancelled)
        ->and($old->cancelled_reason)->toBe("Rescheduled to {$new->reference}");
});

it('frees the old time slot and blocks the new one straight away', function (): void {
    $availability = app(AvailabilityService::class);
    expect(times($availability->slotsForDate($this->branch, $this->service, MON)))->not->toContain('09:00')->toContain('11:00');

    app(HoldAppointment::class)->reschedule($this->old, dubai(MON.' 11:00'));

    expect(times($availability->slotsForDate($this->branch, $this->service, MON)))->toContain('09:00')->not->toContain('11:00');
});

it('moves to another day', function (): void {
    [$new] = app(HoldAppointment::class)->reschedule($this->old, dubai('2026-12-08 10:00'));

    expect($new->localStart()->format('Y-m-d H:i'))->toBe('2026-12-08 10:00');
});

it('leaves the original booking untouched when the new slot is taken', function (): void {
    bookedFor($this->advisor, $this->service, MON.' 11:00');   // someone else has 11:00 (the only advisor)

    expect(fn () => app(HoldAppointment::class)->reschedule($this->old, dubai(MON.' 11:00')))->toThrow(SlotUnavailable::class);

    expect($this->old->fresh()->status)->toBe(AppointmentStatus::Confirmed)
        ->and(Appointment::query()->active()->count())->toBe(2)
        ->and(Appointment::query()->whereNotNull('rescheduled_from_id')->count())->toBe(0);   // no half-made booking
});

it('refuses closed days, past times and times outside opening hours without touching the old booking', function (string $local): void {
    expect(fn () => app(HoldAppointment::class)->reschedule($this->old, dubai($local)))->toThrow(SlotUnavailable::class);

    expect($this->old->fresh()->status)->toBe(AppointmentStatus::Confirmed);
})->with([
    'saturday (closed)' => [SAT.' 09:00'],
    'in the past' => ['2026-11-30 09:00'],
    'before opening' => [MON.' 06:00'],
    'after closing' => [MON.' 15:30'],
]);

it('refuses the same time, a cancelled booking, a held booking and a late change', function (): void {
    $hold = app(HoldAppointment::class);

    expect(fn () => $hold->reschedule($this->old, dubai(MON.' 09:00')))->toThrow(RescheduleNotAllowed::class, 'already your appointment time');

    $cancelled = bookedFor($this->advisor, $this->service, MON.' 12:00', AppointmentStatus::Cancelled);
    expect(fn () => $hold->reschedule($cancelled, dubai(MON.' 13:00')))->toThrow(RescheduleNotAllowed::class, 'no longer be rescheduled');

    $held = bookedFor($this->advisor, $this->service, MON.' 13:00', AppointmentStatus::Held, nowAt()->addMinutes(5));
    expect(fn () => $hold->reschedule($held, dubai(MON.' 14:00')))->toThrow(RescheduleNotAllowed::class);

    // 09:00 Dubai = 05:00 UTC: exactly 2 hours before is too late, 2h01m before is fine.
    expect(fn () => $hold->reschedule($this->old, dubai(MON.' 11:00'), nowAt('2026-12-07 03:00:00')))->toThrow(RescheduleNotAllowed::class, 'rescheduled online up to 2 hours');
    [$new] = $hold->reschedule($this->old, dubai(MON.' 11:00'), nowAt('2026-12-07 02:59:00'));
    expect($new->status)->toBe(AppointmentStatus::Confirmed);
});

it('cannot reschedule the same booking twice', function (): void {
    $hold = app(HoldAppointment::class);
    $hold->reschedule($this->old, dubai(MON.' 11:00'));

    expect(fn () => $hold->reschedule($this->old->fresh(), dubai(MON.' 12:00')))->toThrow(RescheduleNotAllowed::class, 'no longer be rescheduled');
});

it('clears the availability cache for both days', function (): void {
    $availability = app(AvailabilityService::class);
    expect(times($availability->slotsForDate($this->branch, $this->service, '2026-12-08')))->toContain('10:00');   // cached

    app(HoldAppointment::class)->reschedule($this->old, dubai('2026-12-08 10:00'));

    expect(times($availability->slotsForDate($this->branch, $this->service, '2026-12-08')))->not->toContain('10:00')
        ->and(times($availability->slotsForDate($this->branch, $this->service, MON)))->toContain('09:00');
});

it('writes an audit entry that holds references only', function (): void {
    [$new] = app(HoldAppointment::class)->reschedule($this->old, dubai(MON.' 11:00'));

    $log = AuditLog::query()->where('action', 'appointment.rescheduled')->firstOrFail();
    // MySQL stores JSON with its own key order, so compare without regard to order.
    expect($log->changes)->toEqual(['by' => 'customer', 'from' => $this->old->reference, 'to' => $new->reference])
        ->and(json_encode($log->toArray()))->not->toContain($this->old->customer->email);
});

// ---------------------------------------------------------------------------------------------
// The web pages
// ---------------------------------------------------------------------------------------------

it('offers a reschedule button on the manage page only while it is allowed', function (): void {
    $this->get(route('booking.show', [$this->old->reference, 'token' => $this->token]))->assertOk()->assertSee('Reschedule');

    $this->travelTo(nowAt('2026-12-07 04:00:00'));   // inside the 2-hour cut-off
    $this->get(route('booking.show', [$this->old->reference, 'token' => $this->token]))->assertOk()->assertDontSee('Reschedule');
});

it('walks a customer through picking and confirming a new time', function (): void {
    Mail::fake();

    $this->get(route('booking.reschedule', [$this->old->reference, 'token' => $this->token]))
        ->assertOk()->assertSee('Choose a new time')->assertSee('id="slot-picker"', false)
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $json = $this->getJson(route('booking.reschedule.slots', [$this->old->reference, 'token' => $this->token, 'from' => MON, 'days' => 1]))->assertOk();
    $url = $json->json('days.0.slots.6.url');   // some slot later in the day
    expect($url)->toContain('/reschedule/confirm')->toContain('token='.$this->token)->toContain('starts_at=');

    $this->get($url)->assertOk()->assertSee('Move your appointment?')->assertSee('Yes, move it');

    $response = moveTo($this, MON.' 11:00')->assertRedirect();
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($this->old->fresh()->status)->toBe(AppointmentStatus::Cancelled);
    $new = Appointment::query()->whereNotNull('rescheduled_from_id')->firstOrFail();

    // The customer lands on the NEW booking's private page, with the NEW token.
    expect($response->headers->get('Location'))->toContain($new->reference)
        ->and($new->manage_token_hash)->toBe(hash('sha256', $query['token']));
    $this->get((string) $response->headers->get('Location'))->assertOk()->assertSee($new->reference)->assertSee('11:00');

    Mail::assertQueued(BookingConfirmedMail::class, fn (BookingConfirmedMail $m): bool => $m->appointment->is($new));
});

it('keeps the old booking when someone takes the chosen time first', function (): void {
    bookedFor($this->advisor, $this->service, MON.' 11:00');

    moveTo($this, MON.' 11:00')
        ->assertRedirect(route('booking.reschedule', [$this->old->reference, 'token' => $this->token]))
        ->assertSessionHas('error', 'That time was just taken by someone else. Please choose another time.');

    expect($this->old->fresh()->status)->toBe(AppointmentStatus::Confirmed);

    // The confirm page also bounces back for a slot that is no longer free.
    $this->get(route('booking.reschedule.confirm', [$this->old->reference, 'token' => $this->token, 'starts_at' => dubai(MON.' 11:00')->toIso8601ZuluString()]))
        ->assertRedirect(route('booking.reschedule', [$this->old->reference, 'token' => $this->token]));
});

it('sends the customer back to the manage page when rescheduling is no longer possible', function (): void {
    $this->travelTo(nowAt('2026-12-07 04:00:00'));

    $this->get(route('booking.reschedule', [$this->old->reference, 'token' => $this->token]))
        ->assertRedirect(route('booking.show', [$this->old->reference, 'token' => $this->token]))
        ->assertSessionHas('error');

    moveTo($this, MON.' 11:00')->assertSessionHas('error');
    expect($this->old->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});

it('is protected by the manage token on every reschedule route', function (): void {
    $ref = $this->old->reference;
    $slot = dubai(MON.' 11:00')->toIso8601ZuluString();

    $this->get(route('booking.reschedule', [$ref]))->assertNotFound();
    $this->get(route('booking.reschedule', [$ref, 'token' => 'wrong']))->assertNotFound();
    $this->getJson(route('booking.reschedule.slots', [$ref, 'token' => 'wrong']))->assertNotFound();
    $this->get(route('booking.reschedule.confirm', [$ref, 'token' => 'wrong', 'starts_at' => $slot]))->assertNotFound();
    moveTo($this, MON.' 11:00', 'wrong')->assertNotFound();

    expect($this->old->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});

it('validates the reschedule request', function (): void {
    $this->post(route('booking.reschedule.perform', $this->old->reference), ['token' => $this->token, 'starts_at' => 'whenever'])->assertSessionHasErrors('starts_at');
    $this->post(route('booking.reschedule.perform', $this->old->reference), ['starts_at' => dubai(MON.' 11:00')->toIso8601ZuluString()])->assertSessionHasErrors('token');
});

it('moves a booking to the same time at another advisor when the first advisor is busy at the new time', function (): void {
    $second = advisorFor($this->branch, $this->service);
    bookedFor($this->advisor, $this->service, MON.' 11:00');   // first advisor busy at 11:00

    [$new] = app(HoldAppointment::class)->reschedule($this->old, dubai(MON.' 11:00'));

    expect($new->advisor_id)->toBe($second->id);
});

// ---------------------------------------------------------------------------------------------
// The API
// ---------------------------------------------------------------------------------------------

it('reschedules through PATCH /bookings/{reference} and returns a new reference and token', function (): void {
    Mail::fake();

    $response = $this->patchJson('/api/v1/bookings/'.$this->old->reference, ['starts_at' => dubai(MON.' 11:00')->toIso8601ZuluString()], ['X-Manage-Token' => $this->token])
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.local_start', MON.' 11:00')
        ->assertJsonStructure(['data' => ['reference'], 'manage_token', 'message']);

    $newRef = $response->json('data.reference');
    expect($newRef)->not->toBe($this->old->reference)->and($this->old->fresh()->status)->toBe(AppointmentStatus::Cancelled);

    $this->getJson("/api/v1/bookings/{$newRef}", ['X-Manage-Token' => $response->json('manage_token')])->assertOk();
    Mail::assertQueued(BookingConfirmedMail::class);
});

it('answers API reschedule problems with the standard error shape', function (): void {
    $slot = dubai(MON.' 11:00')->toIso8601ZuluString();

    $this->patchJson('/api/v1/bookings/'.$this->old->reference, ['starts_at' => $slot])->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $this->patchJson('/api/v1/bookings/'.$this->old->reference, ['starts_at' => 'nope'], ['X-Manage-Token' => $this->token])->assertStatus(422)->assertJsonValidationErrors('starts_at');
    $this->patchJson('/api/v1/bookings/'.$this->old->reference, ['starts_at' => dubai(MON.' 09:00')->toIso8601ZuluString()], ['X-Manage-Token' => $this->token])
        ->assertStatus(409)->assertJsonPath('message', 'That is already your appointment time. Please choose a different time.');

    bookedFor($this->advisor, $this->service, MON.' 11:00');
    $this->patchJson('/api/v1/bookings/'.$this->old->reference, ['starts_at' => $slot], ['X-Manage-Token' => $this->token])
        ->assertStatus(409)->assertExactJson(['message' => 'That time was just taken by someone else. Please choose another time.']);

    expect($this->old->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});
