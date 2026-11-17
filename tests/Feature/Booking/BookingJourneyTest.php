<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Mail\BookingConfirmedMail;
use App\Mail\OtpMail;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\OtpCode;
use App\Models\Service;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

// "Now" is Tue 2026-12-01 10:00 Dubai. The slot used below is Mon 2026-12-07 09:00 Dubai.
beforeEach(function (): void {
    $this->travelTo(nowAt());

    $this->branch = openBranch();
    $this->service = offering(30, notice: 2, maxDays: 30);
    $this->advisor = advisorFor($this->branch, $this->service);
    $this->slot = dubai(MON.' 09:00')->toIso8601ZuluString();
});

/** @return array<string, mixed> */
function details(array $overrides = []): array
{
    return array_merge([
        'starts_at' => dubai(MON.' 09:00')->toIso8601ZuluString(),
        'first_name' => 'Sarah',
        'last_name' => 'Jones',
        'email' => 'sarah.jones@example.com',
        'phone' => '050 123 4567',
        'consent' => '1',
    ], $overrides);
}

/** Starts a booking over HTTP and returns [reference, plain OTP code]. */
function startBooking(object $test, array $overrides = []): array
{
    Mail::fake();

    $response = $test->post(route('book.store', [$test->service, $test->branch]), details($overrides));
    $response->assertRedirect();

    $reference = Appointment::query()->latest('id')->firstOrFail()->reference;
    $code = '';
    Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    return [$reference, $code];
}

// ---------------------------------------------------------------------------------------------
// The happy path, step by step
// ---------------------------------------------------------------------------------------------

it('lets a customer choose a service and a branch', function (): void {
    $this->get('/')->assertOk()->assertSee($this->service->name)->assertSee('What to bring');

    $this->get(route('book.branches', $this->service))->assertOk()->assertSee($this->branch->name);
    $this->get(route('book.branches', [$this->service, 'city' => 'Nowhere']))->assertOk()->assertSee('No branches');
});

it('hides branches without a qualified advisor and inactive services', function (): void {
    $empty = openBranch();   // open, but no advisor does this service

    $this->get(route('book.branches', $this->service))->assertSee($this->branch->name)->assertDontSee($empty->name);

    $this->service->update(['is_active' => false]);
    $this->get(route('book.branches', $this->service))->assertNotFound();
});

it('serves a week of slots as JSON for the picker', function (): void {
    $this->getJson(route('book.slots', [$this->service, $this->branch, 'from' => MON, 'days' => 2]))
        ->assertOk()
        ->assertJsonPath('from', MON)
        ->assertJsonPath('days.0.date', MON)
        ->assertJsonPath('days.0.slots.0.local_time', '08:00')
        ->assertJsonPath('days.0.slots.0.free_advisors', 1)
        ->assertJsonStructure(['days' => [['date', 'label', 'slots' => [['starts_at', 'ends_at', 'local_time', 'url']]]]]);
});

it('validates the slots request', function (): void {
    $this->getJson(route('book.slots', [$this->service, $this->branch, 'from' => 'tomorrow']))->assertStatus(422);
    $this->getJson(route('book.slots', [$this->service, $this->branch, 'days' => 99]))->assertStatus(422);
});

it('shows the details form with the no-banking-identifiers notice', function (): void {
    $this->get(route('book.details', [$this->service, $this->branch, 'starts_at' => $this->slot]))
        ->assertOk()
        ->assertSee('Please do not enter')
        ->assertSee('Emirates ID')
        ->assertSee('09:00');
});

it('completes a booking: hold, emailed code, confirmation, calendar file', function (): void {
    [$reference, $code] = startBooking($this);

    // Held for 10 minutes, advisor assigned, code emailed.
    $held = Appointment::query()->where('reference', $reference)->firstOrFail();
    expect($held->status)->toBe(AppointmentStatus::Held)
        ->and($held->advisor_id)->toBe($this->advisor->id)
        ->and($held->hold_expires_at?->equalTo(nowAt()->addMinutes(10)))->toBeTrue()
        ->and($held->manage_token_hash)->toBeNull();

    // The verify page shows only the MASKED email.
    $this->withSession(['booking.pending' => $reference])
        ->get(route('booking.verify', $reference))
        ->assertOk()->assertSee('s***@example.com')->assertDontSee('sarah.jones@example.com');

    // Correct code: confirmed, redirected to the private manage page carrying the token.
    $response = $this->withSession(['booking.pending' => $reference])
        ->post(route('booking.verify.submit', $reference), ['code' => $code]);

    $location = $response->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
    $token = $query['token'] ?? '';

    $response->assertRedirect();
    expect($token)->toHaveLength(40);

    $confirmed = $held->fresh();
    expect($confirmed->status)->toBe(AppointmentStatus::Confirmed)
        ->and($confirmed->hold_expires_at)->toBeNull()
        ->and($confirmed->manage_token_hash)->toBe(hash('sha256', $token))   // only the hash is stored
        ->and($confirmed->manage_token_hash)->not->toBe($token)
        ->and($confirmed->customer->email_verified_at)->not->toBeNull();

    Mail::assertQueued(BookingConfirmedMail::class);

    // The confirmation page: reference, documents checklist, calendar link.
    $this->get((string) $location)
        ->assertOk()
        ->assertSee($reference)
        ->assertSee('Please bring')
        ->assertSee('Passport')
        ->assertSee('Add to calendar')
        ->assertSee('Cancel this appointment');

    // The .ics download.
    $this->get(route('booking.calendar', ['reference' => $reference, 'token' => $token]))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertSee('BEGIN:VCALENDAR', false)
        ->assertSee('DTSTART:20261207T050000Z', false);
});

it('normalises the phone number and stores it encrypted', function (): void {
    startBooking($this, ['phone' => '050 123 4567']);

    $customer = Customer::query()->firstOrFail();
    expect($customer->phone)->toBe('+971501234567')
        ->and((string) DB::table('customers')->value('phone'))->not->toContain('501234567');
});

it('reuses the customer record when the same email books again', function (): void {
    startBooking($this);
    $this->advisor->services()->detach();   // irrelevant here; keep the first hold untouched
    $this->advisor->services()->attach($this->service->id);

    $this->post(route('book.store', [$this->service, $this->branch]), details(['starts_at' => dubai(MON.' 10:00')->toIso8601ZuluString(), 'first_name' => 'Sara']))->assertRedirect();

    expect(Customer::query()->count())->toBe(1)
        ->and(Customer::query()->firstOrFail()->first_name)->toBe('Sara');
});

// ---------------------------------------------------------------------------------------------
// Validation and privacy
// ---------------------------------------------------------------------------------------------

it('rejects bad details and creates nothing', function (array $bad, array $errors): void {
    $this->post(route('book.store', [$this->service, $this->branch]), details($bad))->assertSessionHasErrors($errors);

    expect(Appointment::query()->count())->toBe(0);
})->with([
    'not a UAE mobile' => [['phone' => '12345'], ['phone']],
    'landline' => [['phone' => '04 123 4567'], ['phone']],
    'no consent' => [['consent' => null], ['consent']],
    'bad email' => [['email' => 'nope'], ['email']],
    'missing names' => [['first_name' => '', 'last_name' => ''], ['first_name', 'last_name']],
    'garbage time' => [['starts_at' => 'whenever'], ['starts_at']],
]);

it('accepts the common ways of writing a UAE mobile number', function (string $phone): void {
    startBooking($this, ['phone' => $phone]);

    expect(Customer::query()->firstOrFail()->phone)->toBe('+971501234567');
})->with(['0501234567', '+971 50 123 4567', '00971501234567', '050-123-4567', '501234567']);

it('protects the verify page: only the browser that started the booking may open it', function (): void {
    [$reference] = startBooking($this);
    $this->flushSession();   // a different browser: it never started this booking

    $this->get(route('booking.verify', $reference))->assertRedirect(route('book.services'));
    $this->withSession(['booking.pending' => 'NWB-AAAA-BBBB'])->get(route('booking.verify', $reference))->assertRedirect(route('book.services'));
});

it('throttles code resends', function (): void {
    [$reference] = startBooking($this);

    foreach (range(1, 3) as $i) {
        $this->withSession(['booking.pending' => $reference])->post(route('booking.verify.resend', $reference))->assertRedirect();
    }

    $this->withSession(['booking.pending' => $reference])->post(route('booking.verify.resend', $reference))->assertStatus(429);
});

// ---------------------------------------------------------------------------------------------
// A slot taken by someone else
// ---------------------------------------------------------------------------------------------

it('shows a friendly message when the slot was taken between viewing and booking', function (): void {
    startBooking($this);   // customer one holds 09:00; there is only one advisor

    $this->post(route('book.store', [$this->service, $this->branch]), details(['email' => 'other@example.com']))
        ->assertRedirect(route('book.times', [$this->service, $this->branch]))
        ->assertSessionHas('error', 'That time was just taken by someone else. Please choose another time.');

    // The details page for the taken slot also bounces back instead of showing a doomed form.
    $this->get(route('book.details', [$this->service, $this->branch, 'starts_at' => $this->slot]))
        ->assertRedirect(route('book.times', [$this->service, $this->branch]));

    expect(Appointment::query()->active()->count())->toBe(1);
});

it('gives the second customer another free advisor instead of failing', function (): void {
    advisorFor($this->branch, $this->service);
    startBooking($this);

    $this->post(route('book.store', [$this->service, $this->branch]), details(['email' => 'other@example.com']))->assertRedirect(route('booking.verify', Appointment::query()->latest('id')->value('reference')));

    expect(Appointment::query()->active()->count())->toBe(2)
        ->and(Appointment::query()->active()->pluck('advisor_id')->unique())->toHaveCount(2);
});

// ---------------------------------------------------------------------------------------------
// Verification codes
// ---------------------------------------------------------------------------------------------

it('stores only a hash of the code', function (): void {
    [, $code] = startBooking($this);

    $stored = OtpCode::query()->firstOrFail();
    expect($stored->code_hash)->not->toContain($code)
        ->and(Hash::check($code, $stored->code_hash))->toBeTrue()
        ->and($code)->toMatch('/^\d{6}$/');
});

it('counts wrong codes, then locks out after 5 even for the right code', function (): void {
    [$reference, $code] = startBooking($this);
    $verify = fn (string $c) => $this->withSession(['booking.pending' => $reference])->post(route('booking.verify.submit', $reference), ['code' => $c]);
    $wrong = $code === '000000' ? '111111' : '000000';

    $verify($wrong)->assertSessionHasErrors(['code' => "That code isn't correct. 4 attempt(s) left."]);
    $verify($wrong)->assertSessionHasErrors(['code' => "That code isn't correct. 3 attempt(s) left."]);
    foreach (range(1, 3) as $i) {
        $verify($wrong);
    }

    // Locked out: the correct code no longer works.
    $verify($code)->assertSessionHasErrors(['code' => 'Too many wrong attempts. Request a new code below.']);
    expect(Appointment::query()->firstOrFail()->status)->toBe(AppointmentStatus::Held);

    // A fresh code (resend) works again.
    Mail::fake();
    $this->withSession(['booking.pending' => $reference])->post(route('booking.verify.resend', $reference));
    $newCode = '';
    Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$newCode): bool {
        $newCode = $m->code;

        return true;
    });
    $verify($newCode)->assertRedirect();
    expect(Appointment::query()->firstOrFail()->status)->toBe(AppointmentStatus::Confirmed);
});

it('invalidates the previous code when a new one is sent', function (): void {
    [$reference, $oldCode] = startBooking($this);
    Mail::fake();

    $this->withSession(['booking.pending' => $reference])->post(route('booking.verify.resend', $reference));
    $newCode = '';
    Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$newCode): bool {
        $newCode = $m->code;

        return true;
    });

    if ($oldCode !== $newCode) {
        $this->withSession(['booking.pending' => $reference])->post(route('booking.verify.submit', $reference), ['code' => $oldCode])
            ->assertSessionHasErrors('code');
        expect(Appointment::query()->firstOrFail()->status)->toBe(AppointmentStatus::Held);
    }
});

it('rejects a code that is not 6 digits without counting an attempt', function (): void {
    [$reference] = startBooking($this);

    $this->withSession(['booking.pending' => $reference])->post(route('booking.verify.submit', $reference), ['code' => 'abc'])
        ->assertSessionHasErrors('code');

    expect(OtpCode::query()->firstOrFail()->attempts)->toBe(0);
});

it('does not let a code for one booking confirm another', function (): void {
    [$firstRef, $firstCode] = startBooking($this);
    advisorFor($this->branch, $this->service);
    [$secondRef] = startBooking($this, ['starts_at' => dubai(MON.' 10:00')->toIso8601ZuluString()]);

    $this->withSession(['booking.pending' => $secondRef])->post(route('booking.verify.submit', $secondRef), ['code' => $firstCode])
        ->assertSessionHasErrors('code');

    expect(Appointment::query()->where('reference', $secondRef)->firstOrFail()->status)->toBe(AppointmentStatus::Held)
        ->and($firstRef)->not->toBe($secondRef);
});

// ---------------------------------------------------------------------------------------------
// Hold expiry
// ---------------------------------------------------------------------------------------------

it('sends the customer back to the start when the 10-minute hold has run out', function (): void {
    [$reference, $code] = startBooking($this);

    $this->travel(11)->minutes();

    $this->withSession(['booking.pending' => $reference])->post(route('booking.verify.submit', $reference), ['code' => $code])
        ->assertRedirect(route('book.services'))
        ->assertSessionHas('error');

    expect(Appointment::query()->firstOrFail()->status)->toBe(AppointmentStatus::Expired);
});

it('frees the slot when a hold expires: visible at once, and rebookable', function (): void {
    startBooking($this);
    $slot = dubai(MON.' 09:00');
    $service = app(App\Services\Availability\AvailabilityService::class);

    expect(times($service->slotsForDate($this->branch, $this->service, MON)))->not->toContain('09:00');

    $this->travel(11)->minutes();
    // Cached results are cleared by the clock only after 60 s; bust explicitly like the scheduler's change would.
    Cache::flush();

    // Lapsed hold no longer blocks, even before the scheduler has run...
    expect(times($service->slotsForDate($this->branch, $this->service, MON)))->toContain('09:00');

    // ...and another customer can book it straight away (the booking action clears the stale hold).
    $this->post(route('book.store', [$this->service, $this->branch]), details(['email' => 'other@example.com']))
        ->assertRedirect();

    expect(Appointment::query()->where('status', AppointmentStatus::Expired->value)->count())->toBe(1)
        ->and(Appointment::query()->active()->where('starts_at', $slot)->count())->toBe(1);
});

it('expires stale holds from the scheduler command', function (): void {
    startBooking($this);
    $this->travel(11)->minutes();

    $this->artisan('branchbook:expire-holds')->expectsOutputToContain('Expired 1 hold')->assertSuccessful();

    expect(Appointment::query()->firstOrFail()->status)->toBe(AppointmentStatus::Expired);
});

// ---------------------------------------------------------------------------------------------
// The private manage page
// ---------------------------------------------------------------------------------------------

/** A confirmed booking with a known token. @return array{0: Appointment, 1: string} */
function confirmedBooking(object $test, string $local = MON.' 09:00'): array
{
    $token = 'T'.str_repeat('k', 39);
    $appointment = bookedFor($test->advisor, $test->service, $local);
    $appointment->forceFill(['manage_token_hash' => hash('sha256', $token)])->save();

    return [$appointment, $token];
}

it('opens the manage page only with the right token, and never reveals which part was wrong', function (): void {
    [$appointment, $token] = confirmedBooking($this);

    $this->get(route('booking.show', $appointment->reference))->assertNotFound();
    $this->get(route('booking.show', [$appointment->reference, 'token' => 'wrong']))->assertNotFound();
    $this->get(route('booking.show', ['NWB-NOPE-NOPE', 'token' => $token]))->assertNotFound();
    $this->get(route('booking.calendar', [$appointment->reference, 'token' => 'wrong']))->assertNotFound();

    $this->get(route('booking.show', [$appointment->reference, 'token' => $token]))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('cancels with a reason before the cut-off and frees the slot', function (): void {
    [$appointment, $token] = confirmedBooking($this);

    $this->post(route('booking.cancel', $appointment->reference), ['token' => $token, 'reason' => 'Plans changed'])
        ->assertRedirect(route('booking.show', [$appointment->reference, 'token' => $token]))
        ->assertSessionHas('status', 'Your appointment has been cancelled.');

    $fresh = $appointment->fresh();
    expect($fresh->status)->toBe(AppointmentStatus::Cancelled)
        ->and($fresh->cancelled_reason)->toBe('Plans changed');

    $this->bookAgain = app(App\Services\Availability\AvailabilityService::class)->freeAdvisorsAt($this->branch, $this->service, dubai(MON.' 09:00'));
    expect($this->bookAgain)->toHaveCount(1);
});

it('refuses to cancel within 2 hours of the start', function (): void {
    [$appointment, $token] = confirmedBooking($this);

    // 09:00 Dubai = 05:00 UTC. At 03:30 UTC (07:30 Dubai) only 90 minutes remain.
    $this->travelTo(nowAt('2026-12-07 03:30:00'));

    $this->post(route('booking.cancel', $appointment->reference), ['token' => $token])
        ->assertSessionHas('error', 'Appointments can only be cancelled online up to 2 hours before they start. Please call the branch.');

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Confirmed);

    $this->get(route('booking.show', [$appointment->reference, 'token' => $token]))->assertDontSee('Cancel appointment');
});

it('allows cancelling just outside the cut-off and refuses just inside it', function (): void {
    [$appointment, $token] = confirmedBooking($this);

    $this->travelTo(nowAt('2026-12-07 02:59:00'));   // 2h01m before 05:00 UTC
    $this->post(route('booking.cancel', $appointment->reference), ['token' => $token])->assertSessionHas('status');
    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);

    [$second, $token2] = confirmedBooking($this, MON.' 10:00');   // starts 06:00 UTC
    $this->travelTo(nowAt('2026-12-07 04:00:00'));                  // exactly 2h before: too late
    $this->post(route('booking.cancel', $second->reference), ['token' => $token2])->assertSessionHas('error');
    expect($second->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});

it('cannot cancel with a wrong token or an appointment that is not confirmed', function (): void {
    [$appointment, $token] = confirmedBooking($this);

    $this->post(route('booking.cancel', $appointment->reference), ['token' => 'wrong'])->assertNotFound();
    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Confirmed);

    $appointment->transitionTo(AppointmentStatus::CheckedIn);
    $this->post(route('booking.cancel', $appointment->reference), ['token' => $token])->assertSessionHas('error', 'This appointment can no longer be cancelled.');
});

// ---------------------------------------------------------------------------------------------
// Mail and calendar file
// ---------------------------------------------------------------------------------------------

it('renders the emails with the code, the checklist and the private link', function (): void {
    [$appointment, $token] = confirmedBooking($this);
    $appointment->load(['customer', 'branch', 'service']);

    $otp = (new OtpMail('123456', 'NWB-TEST-1234', 10))->render();
    $confirmed = (new BookingConfirmedMail($appointment, $token))->render();

    expect($otp)->toContain('123456')->toContain('never ask')
        ->and($confirmed)->toContain($appointment->reference)->toContain('Passport')
        ->toContain('token='.$token)->toContain('09:00');
});

it('builds a valid .ics file: CRLF lines, UTC times, escaped text, folded long lines', function (): void {
    [$appointment] = confirmedBooking($this);
    $appointment->branch->forceFill(['name' => 'Marina; Tower, Level 2', 'address' => str_repeat('A very long street name, ', 6)])->save();
    $appointment->load(['branch', 'service']);

    $ics = App\Support\IcsBuilder::for($appointment);
    $lines = explode("\r\n", rtrim($ics, "\r\n"));

    expect($ics)->toEndWith("\r\n")
        ->and($lines[0])->toBe('BEGIN:VCALENDAR')
        ->and(end($lines))->toBe('END:VCALENDAR')
        ->and($ics)->toContain('DTSTART:20261207T050000Z')->toContain('DTEND:20261207T053000Z')
        ->toContain('UID:'.$appointment->reference.'@')
        ->toContain('Marina\; Tower\, Level 2');

    foreach ($lines as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }
});
