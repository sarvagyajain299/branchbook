<?php

declare(strict_types=1);

use App\Actions\Queue\IssueQueueTicket;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Mail\OtpMail;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

// "Now" is Tue 2026-12-01 10:00 Dubai. Bookings below are for Mon 2026-12-07.
beforeEach(function (): void {
    $this->travelTo(nowAt());

    $this->branch = openBranch();
    $this->other = openBranch();
    $this->service = offering(30, notice: 2, maxDays: 30);
    $this->advisor = advisorFor($this->branch, $this->service);
    $this->desk = User::factory()->forBranch(Role::FrontDesk, $this->branch)->create();
});

const API = '/api/v1';

function token(User $user): string
{
    return $user->createToken('test', $user->role->apiAbilities())->plainTextToken;
}

/**
 * Sends the next request with this bearer token. A real server handles every request in a fresh
 * process; the test app is reused, so the auth guard must forget the previous user first.
 */
function bearer(object $test, string $token): object
{
    app('auth')->forgetGuards();

    return $test->withToken($token);
}

/** @return array<string, mixed> */
function bookingPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'service' => $test->service->code,
        'branch' => $test->branch->code,
        'starts_at' => dubai(MON.' 09:00')->toIso8601ZuluString(),
        'first_name' => 'Sarah',
        'last_name' => 'Jones',
        'email' => 'sarah.jones@example.com',
        'phone' => '050 123 4567',
        'consent' => true,
    ], $overrides);
}

/** Creates a booking over the API; returns [reference, code]. */
function apiBook(object $test, array $overrides = []): array
{
    Mail::fake();
    $reference = $test->postJson(API.'/bookings', bookingPayload($test, $overrides))->assertCreated()->json('data.reference');
    $code = '';
    Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code): bool {
        $code = $m->code;

        return true;
    });

    return [$reference, $code];
}

/** A confirmed booking with a known manage token. */
function apiConfirmed(object $test): array
{
    $appointment = bookedFor($test->advisor, $test->service, MON.' 09:00');
    $appointment->forceFill(['manage_token_hash' => hash('sha256', 'secret-token')])->save();

    return [$appointment, 'secret-token'];
}

// ---------------------------------------------------------------------------------------------
// Public: branches, services, availability
// ---------------------------------------------------------------------------------------------

it('lists active branches with pagination links and meta', function (): void {
    Branch::factory()->create(['is_active' => false, 'code' => 'OFF-ONE']);

    $this->getJson(API.'/branches')
        ->assertOk()
        ->assertJsonStructure(['data' => [['code', 'name', 'city', 'address', 'latitude', 'longitude', 'phone']], 'links' => ['first', 'next'], 'meta' => ['current_page', 'per_page', 'total']])
        ->assertJsonMissing(['code' => 'OFF-ONE'])
        ->assertJsonCount(2, 'data');
});

it('filters branches by city', function (): void {
    $this->branch->update(['city' => 'Dubai']);
    $this->other->update(['city' => 'Sharjah']);

    $this->getJson(API.'/branches?city=Sharjah')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', $this->other->code);
});

it('sorts branches by distance with ?lat=&lng= using the Haversine formula', function (): void {
    $this->branch->update(['latitude' => 25.0805, 'longitude' => 55.1403]);    // Dubai Marina
    $this->other->update(['latitude' => 24.4745, 'longitude' => 54.3320]);     // Abu Dhabi Corniche, ~120 km away

    $response = $this->getJson(API.'/branches?lat=25.0800&lng=55.1400')->assertOk();

    $response->assertJsonPath('data.0.code', $this->branch->code)->assertJsonPath('data.1.code', $this->other->code);
    expect($response->json('data.0.distance_km'))->toBeLessThan(1.0)
        ->and($response->json('data.1.distance_km'))->toBeBetween(100.0, 135.0);
});

it('omits distance when no coordinates are sent and rejects half a coordinate', function (): void {
    $this->getJson(API.'/branches')->assertJsonMissingPath('data.0.distance_km');
    $this->getJson(API.'/branches?lat=25.1')->assertStatus(422)->assertJsonValidationErrors('lng');
    $this->getJson(API.'/branches?lat=250&lng=55')->assertStatus(422)->assertJsonValidationErrors('lat');
});

it('lists services with their documents checklist', function (): void {
    $this->service->update(['required_documents' => ['Passport', 'Visa']]);

    $this->getJson(API.'/services')->assertOk()
        ->assertJsonStructure(['data' => [['code', 'name', 'category', 'description', 'duration_minutes', 'required_documents', 'min_notice_hours', 'max_days_ahead']], 'meta'])
        ->assertJsonPath('data.0.required_documents', ['Passport', 'Visa']);
});

it('returns availability per day', function (): void {
    $this->getJson(API."/branches/{$this->branch->code}/availability?service={$this->service->code}&date=".MON.'&days=2')
        ->assertOk()
        ->assertJsonStructure(['data' => [['date', 'slots' => [['starts_at', 'ends_at', 'local_time', 'free_advisors']]]]])
        ->assertJsonPath('data.0.date', MON)
        ->assertJsonPath('data.0.slots.0.local_time', '08:00')
        ->assertJsonPath('data.1.date', '2026-12-08');
});

it('validates the availability query', function (array $query, string $field): void {
    $this->getJson(API."/branches/{$this->branch->code}/availability?".http_build_query($query))->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no service' => [['date' => MON], 'service'],
    'unknown service' => [['service' => 'NOPE', 'date' => MON], 'service'],
    'bad date' => [['service' => 'X', 'date' => '07/12/2026'], 'date'],
    'too many days' => [['service' => 'X', 'date' => MON, 'days' => 30], 'days'],
]);

it('returns the standard error shape for an unknown branch', function (): void {
    $this->getJson(API.'/branches/NOPE/availability?service=X&date='.MON)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});

it('rate limits the public endpoints with 429', function (): void {
    foreach (range(1, 60) as $i) {
        $this->getJson(API.'/services')->assertOk();
    }

    $this->getJson(API.'/services')->assertStatus(429)->assertJsonStructure(['message']);
});

// ---------------------------------------------------------------------------------------------
// Bookings
// ---------------------------------------------------------------------------------------------

it('creates a held booking and emails a code', function (): void {
    Mail::fake();

    $this->postJson(API.'/bookings', bookingPayload($this))
        ->assertCreated()
        ->assertJsonStructure(['data' => ['reference', 'status', 'starts_at', 'ends_at', 'local_start', 'hold_expires_at', 'service' => ['code', 'name'], 'branch' => ['code', 'name', 'address'], 'required_documents'], 'message'])
        ->assertJsonPath('data.status', 'held')
        ->assertJsonPath('data.local_start', MON.' 09:00')
        ->assertJsonMissingPath('data.customer');

    Mail::assertSent(OtpMail::class);
    expect(Appointment::query()->firstOrFail()->status)->toBe(AppointmentStatus::Held);
});

it('verifies with the emailed code, confirms, and shows the manage token only once', function (): void {
    [$reference, $code] = apiBook($this);

    $token = $this->postJson(API."/bookings/{$reference}/verify", ['code' => $code])
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonStructure(['manage_token', 'message'])
        ->json('manage_token');

    expect($token)->toHaveLength(40);

    $this->getJson(API."/bookings/{$reference}", ['X-Manage-Token' => $token])
        ->assertOk()->assertJsonPath('data.reference', $reference)->assertJsonMissingPath('manage_token');
});

it('rejects wrong codes with attempts left, then locks out', function (): void {
    [$reference, $code] = apiBook($this);
    $wrong = $code === '000000' ? '111111' : '000000';

    $this->postJson(API."/bookings/{$reference}/verify", ['code' => $wrong])
        ->assertStatus(422)->assertJsonPath('errors.code.0', '4 attempt(s) left.')->assertJsonPath('message', 'The code is incorrect.');

    foreach (range(1, 4) as $i) {
        $this->postJson(API."/bookings/{$reference}/verify", ['code' => $wrong]);
    }

    $this->postJson(API."/bookings/{$reference}/verify", ['code' => $code])->assertStatus(422)->assertJsonPath('message', 'Too many wrong attempts.');
    expect(Appointment::query()->firstOrFail()->status)->toBe(AppointmentStatus::Held);
});

it('returns 410 when the hold has expired and 409 when already confirmed', function (): void {
    [$reference, $code] = apiBook($this);
    $this->postJson(API."/bookings/{$reference}/verify", ['code' => $code])->assertOk();
    $this->postJson(API."/bookings/{$reference}/verify", ['code' => $code])->assertStatus(409);

    advisorFor($this->branch, $this->service);
    [$second, $secondCode] = apiBook($this, ['starts_at' => dubai(MON.' 10:00')->toIso8601ZuluString(), 'email' => 'b@example.com']);
    $this->travel(11)->minutes();

    $this->postJson(API."/bookings/{$second}/verify", ['code' => $secondCode])->assertStatus(410)->assertJsonStructure(['message']);
});

it('returns 409 with a friendly message when the slot was just taken', function (): void {
    apiBook($this);

    $this->postJson(API.'/bookings', bookingPayload($this, ['email' => 'other@example.com']))
        ->assertStatus(409)
        ->assertExactJson(['message' => 'That time was just taken by someone else. Please choose another time.']);
});

it('validates a booking with the standard {message, errors} shape', function (): void {
    $this->postJson(API.'/bookings', bookingPayload($this, ['phone' => '123', 'consent' => false, 'email' => 'nope']))
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['phone', 'consent', 'email']]);

    $this->postJson(API.'/bookings', bookingPayload($this, ['service' => 'NOPE']))->assertStatus(422)->assertJsonValidationErrors('service');
    expect(Appointment::query()->count())->toBe(0);
});

it('protects a booking with the manage token header and never reveals which part was wrong', function (): void {
    [$appointment, $token] = apiConfirmed($this);

    $this->getJson(API."/bookings/{$appointment->reference}")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $this->getJson(API."/bookings/{$appointment->reference}", ['X-Manage-Token' => 'wrong'])->assertNotFound();
    $this->getJson(API.'/bookings/NWB-NOPE-NOPE', ['X-Manage-Token' => $token])->assertNotFound();
    $this->deleteJson(API."/bookings/{$appointment->reference}")->assertNotFound();

    $this->getJson(API."/bookings/{$appointment->reference}", ['X-Manage-Token' => $token])->assertOk()->assertJsonPath('data.status', 'confirmed');
});

it('cancels before the cut-off and refuses after it', function (): void {
    [$appointment, $token] = apiConfirmed($this);

    $this->deleteJson(API."/bookings/{$appointment->reference}", ['reason' => 'Plans changed'], ['X-Manage-Token' => $token])
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect($appointment->fresh()->cancelled_reason)->toBe('Plans changed');

    $this->deleteJson(API."/bookings/{$appointment->reference}", [], ['X-Manage-Token' => $token])->assertStatus(409);   // already cancelled

    [$late, $lateToken] = [bookedFor($this->advisor, $this->service, MON.' 10:00'), 'late-token'];
    $late->forceFill(['manage_token_hash' => hash('sha256', $lateToken)])->save();
    $this->travelTo(nowAt('2026-12-07 04:00:00'));   // exactly 2 h before 06:00 UTC

    $this->deleteJson(API."/bookings/{$late->reference}", [], ['X-Manage-Token' => $lateToken])
        ->assertStatus(409)->assertJsonPath('message', 'Appointments can only be cancelled online up to 2 hours before they start. Please call the branch.');
});

// ---------------------------------------------------------------------------------------------
// Staff tokens and abilities
// ---------------------------------------------------------------------------------------------

it('issues tokens limited to the role\'s abilities', function (Role $role, array $abilities): void {
    $user = User::factory()->forBranch($role, $this->branch)->create(['email' => 'staff@northwind.test']);

    $this->postJson(API.'/auth/tokens', ['email' => 'staff@northwind.test', 'password' => 'password', 'device_name' => 'desk tablet'])
        ->assertCreated()
        ->assertJsonPath('abilities', $abilities)
        ->assertJsonPath('user.role', $role->value)
        ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'role', 'branch_id']]);

    expect($user->tokens()->firstOrFail()->abilities)->toBe($abilities);
})->with([
    'front desk' => [Role::FrontDesk, ['queue:read', 'queue:issue']],
    'manager' => [Role::BranchManager, ['queue:read', 'queue:issue']],
    'advisor' => [Role::Advisor, ['queue:read', 'queue:operate']],
]);

it('rejects bad credentials with 401, validates input with 422, and throttles login attempts', function (): void {
    User::factory()->forBranch(Role::FrontDesk, $this->branch)->create(['email' => 'staff@northwind.test']);
    User::factory()->unverified()->forBranch(Role::FrontDesk, $this->branch)->create(['email' => 'new@northwind.test']);
    $login = fn (string $email, string $password) => $this->postJson(API.'/auth/tokens', ['email' => $email, 'password' => $password, 'device_name' => 'x']);

    $login('staff@northwind.test', 'wrong')->assertStatus(401)->assertExactJson(['message' => 'Invalid credentials.']);
    $login('nobody@northwind.test', 'password')->assertStatus(401)->assertExactJson(['message' => 'Invalid credentials.']);   // same answer: no email enumeration
    $login('new@northwind.test', 'password')->assertStatus(401);   // unverified email

    $this->postJson(API.'/auth/tokens', [])->assertStatus(422)->assertJsonValidationErrors(['email', 'password', 'device_name']);

    $login('staff@northwind.test', 'wrong');   // 5th attempt in this test
    $login('staff@northwind.test', 'wrong')->assertStatus(429);
});

it('requires a token for staff endpoints', function (): void {
    $this->getJson(API."/branches/{$this->branch->code}/queue")->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
    $this->postJson(API.'/queue/call-next')->assertStatus(401);
    bearer($this, 'garbage')->getJson(API."/branches/{$this->branch->code}/queue")->assertStatus(401);
});

it('enforces token abilities: front desk issues but cannot call, advisor calls but cannot issue', function (): void {
    $ticket = app(IssueQueueTicket::class)->handle($this->branch, $this->service);
    $desk = token($this->desk);
    $advisor = token($this->advisor->user);
    $forbidden = ['message' => 'This action is not allowed for your account or token.'];

    bearer($this, $desk)->getJson(API."/branches/{$this->branch->code}/queue")->assertOk();
    bearer($this, $desk)->postJson(API."/branches/{$this->branch->code}/queue/walk-ins", ['service' => $this->service->code])->assertCreated();
    bearer($this, $desk)->postJson(API.'/queue/call-next')->assertForbidden()->assertExactJson($forbidden);
    bearer($this, $desk)->patchJson(API."/queue-tickets/{$ticket->id}", ['action' => 'start'])->assertForbidden();

    bearer($this, $advisor)->getJson(API."/branches/{$this->branch->code}/queue")->assertOk();
    bearer($this, $advisor)->postJson(API."/branches/{$this->branch->code}/queue/walk-ins", ['service' => $this->service->code])->assertForbidden()->assertExactJson($forbidden);
    bearer($this, $advisor)->postJson(API.'/queue/call-next')->assertOk();
});

it('keeps staff inside their own branch: cross-branch access is 403', function (): void {
    $otherDesk = token(User::factory()->forBranch(Role::FrontDesk, $this->other)->create());

    bearer($this, $otherDesk)->getJson(API."/branches/{$this->branch->code}/queue")->assertForbidden();
    bearer($this, $otherDesk)->postJson(API."/branches/{$this->branch->code}/queue/walk-ins", ['service' => $this->service->code])->assertForbidden();
    expect(QueueTicket::query()->count())->toBe(0);

    // The same token works at its own branch.
    bearer($this, $otherDesk)->getJson(API."/branches/{$this->other->code}/queue")->assertOk();
});

it('lists today\'s queue with a status filter, pagination and eager-loaded services', function (): void {
    $issue = app(IssueQueueTicket::class);
    foreach (range(1, 3) as $i) {
        $issue->handle($this->branch, $this->service);   // 3 tickets: strict mode would throw on an N+1 service lookup
    }
    QueueTicket::query()->firstOrFail()->forceFill(['status' => 'no_show'])->save();
    $issue->handle($this->other, $this->service);          // another branch: must not appear

    bearer($this, token($this->desk))->getJson(API."/branches/{$this->branch->code}/queue")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['id', 'code', 'status', 'service' => ['code', 'name'], 'is_walk_in', 'desk', 'issued_at', 'called_at', 'served_at', 'completed_at']], 'meta' => ['total']]);

    bearer($this, token($this->desk))->getJson(API."/branches/{$this->branch->code}/queue?status=waiting")->assertJsonCount(2, 'data');
    bearer($this, token($this->desk))->getJson(API."/branches/{$this->branch->code}/queue?status=bogus")->assertStatus(422);
});

it('issues walk-in tickets and validates the request', function (): void {
    $desk = token($this->desk);

    bearer($this, $desk)->postJson(API."/branches/{$this->branch->code}/queue/walk-ins", ['service' => $this->service->code, 'name' => 'Walk Inn'])
        ->assertCreated()->assertJsonPath('data.is_walk_in', true)->assertJsonPath('data.status', 'waiting')
        ->assertJsonPath('data.code', $this->service->queue_prefix.'-001');

    bearer($this, $desk)->postJson(API."/branches/{$this->branch->code}/queue/walk-ins", ['service' => 'NOPE'])->assertStatus(422)->assertJsonValidationErrors('service');
    bearer($this, $desk)->postJson(API."/branches/{$this->branch->code}/queue/walk-ins", [])->assertStatus(422);
});

it('lets an advisor call, start and complete a customer through the API', function (): void {
    app(IssueQueueTicket::class)->handle($this->branch, $this->service);
    $advisor = token($this->advisor->user);

    $called = bearer($this, $advisor)->postJson(API.'/queue/call-next')->assertOk()->assertJsonPath('data.status', 'called')->json('data');
    bearer($this, $advisor)->postJson(API.'/queue/call-next')->assertStatus(409);   // still busy with this customer

    bearer($this, $advisor)->patchJson(API."/queue-tickets/{$called['id']}", ['action' => 'recall'])->assertOk()->assertJsonPath('data.status', 'called');
    bearer($this, $advisor)->patchJson(API."/queue-tickets/{$called['id']}", ['action' => 'start'])->assertOk()->assertJsonPath('data.status', 'serving');
    bearer($this, $advisor)->patchJson(API."/queue-tickets/{$called['id']}", ['action' => 'complete'])->assertOk()->assertJsonPath('data.status', 'completed');

    bearer($this, $advisor)->postJson(API.'/queue/call-next')->assertOk()->assertJsonPath('data', null)->assertJsonPath('message', 'Nobody is waiting for your services.');
});

it('answers invalid ticket updates with 409, 404 or 422 in the standard shape', function (): void {
    app(IssueQueueTicket::class)->handle($this->branch, $this->service);
    $mine = $this->advisor->user;
    $colleague = advisorFor($this->branch, $this->service)->user;

    $ticketId = bearer($this, token($mine))->postJson(API.'/queue/call-next')->json('data.id');

    bearer($this, token($mine))->patchJson(API."/queue-tickets/{$ticketId}", ['action' => 'complete'])->assertStatus(409)->assertJsonStructure(['message']);   // not started
    bearer($this, token($colleague))->patchJson(API."/queue-tickets/{$ticketId}", ['action' => 'start'])->assertStatus(409)->assertJsonPath('message', 'That ticket was called by another advisor.');
    bearer($this, token($mine))->patchJson(API."/queue-tickets/{$ticketId}", ['action' => 'explode'])->assertStatus(422)->assertJsonValidationErrors('action');
    bearer($this, token($mine))->patchJson(API.'/queue-tickets/99999', ['action' => 'start'])->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});

it('revokes a token on logout', function (): void {
    User::factory()->forBranch(Role::FrontDesk, $this->branch)->create(['email' => 'staff@northwind.test']);
    $token = $this->postJson(API.'/auth/tokens', ['email' => 'staff@northwind.test', 'password' => 'password', 'device_name' => 'tablet'])->json('token');

    bearer($this, $token)->getJson(API."/branches/{$this->branch->code}/queue")->assertOk();
    bearer($this, $token)->deleteJson(API.'/auth/tokens/current')->assertOk();

    bearer($this, $token)->getJson(API."/branches/{$this->branch->code}/queue")->assertStatus(401);
});

it('writes API token creation to the audit log without the token or password', function (): void {
    User::factory()->forBranch(Role::FrontDesk, $this->branch)->create(['email' => 'staff@northwind.test']);

    $plain = $this->postJson(API.'/auth/tokens', ['email' => 'staff@northwind.test', 'password' => 'password', 'device_name' => 'tablet'])->json('token');

    $entry = App\Models\AuditLog::query()->where('action', 'api.token_created')->firstOrFail();
    expect(json_encode($entry->toArray()))->not->toContain($plain)->not->toContain('password')
        ->and($entry->changes)->toBe(['device' => 'tablet', 'abilities' => ['queue:read', 'queue:issue']]);
});

it('uses the same {message} error shape everywhere', function (): void {
    $responses = [
        $this->getJson(API.'/nothing-here'),
        $this->getJson(API.'/branches/NOPE/availability'),
        $this->getJson(API.'/queue-tickets/1'),
        $this->postJson(API.'/queue/call-next'),
        bearer($this, token($this->desk))->postJson(API.'/queue/call-next'),
        $this->postJson(API.'/bookings', []),
    ];

    foreach ($responses as $response) {
        expect($response->json('message'))->toBeString()->not->toBeEmpty();
    }
});
