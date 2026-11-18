<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Advisor;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Closure;
use App\Models\Customer;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Services\Admin\DashboardStats;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;

// Mon 2026-12-07 10:00 Dubai.
beforeEach(function (): void {
    $this->travelTo(nowAt('2026-12-07 06:00:00'));

    $this->a = openBranch();
    $this->b = openBranch();
    $this->service = offering(30);
    $this->advA = advisorFor($this->a, $this->service);
    $this->advB = advisorFor($this->b, $this->service);

    $this->hq = User::factory()->hqAdmin()->create();
    $this->managerA = User::factory()->forBranch(Role::BranchManager, $this->a)->create();
    $this->managerB = User::factory()->forBranch(Role::BranchManager, $this->b)->create();
    $this->desk = User::factory()->forBranch(Role::FrontDesk, $this->a)->create();

    config(['backpack.basset.dev_mode' => true]);

    // Backpack keeps its CRUD panel (filters, clauses, "already applied" flags) in a scoped container
    // binding. A real server handles each request in a fresh process; a test makes several requests
    // in one process, so throw the panel away after every request.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetInstance('CrudManager');
        Facade::clearResolvedInstances();   // the CRUD and CrudManager facades cache their own copy
    });
});

/** DataTables-style search call that Backpack's list page makes. */
function search(object $test, string $crud, array $query = []): Illuminate\Testing\TestResponse
{
    return $test->postJson("/admin/{$crud}/search?".http_build_query($query), ['draw' => 1, 'start' => 0, 'length' => 0]);
}

// ---------------------------------------------------------------------------------------------
// Access
// ---------------------------------------------------------------------------------------------

it('sends guests to the normal login page and keeps front desk and advisors out', function (): void {
    auth()->logout();
    $this->get('/admin/dashboard')->assertRedirect(route('login'));
    $this->get('/admin')->assertRedirect();

    $this->actingAs($this->desk)->get('/admin/dashboard')->assertForbidden();
    $this->actingAs($this->advA->user)->get('/admin/branch')->assertForbidden();
    $this->actingAs($this->desk)->postJson('/admin/branch/search')->assertForbidden();
});

it('lets HQ and managers into the dashboard and every list page they may use', function (): void {
    $shared = ['branch', 'branch-hour', 'closure', 'service', 'advisor', 'advisor-shift', 'advisor-time-off', 'appointment', 'customer'];

    foreach ([$this->hq, $this->managerA] as $user) {
        $this->actingAs($user)->get('/admin/dashboard')->assertOk()->assertSee('Dashboard');

        foreach ($shared as $crud) {
            $this->actingAs($user)->get("/admin/{$crud}")->assertOk();
        }
    }

    foreach (['user', 'audit-log'] as $hqOnly) {
        $this->actingAs($this->hq)->get("/admin/{$hqOnly}")->assertOk();
        $this->actingAs($this->managerA)->get("/admin/{$hqOnly}")->assertForbidden();
    }
});

it('gives admin pages their own content-security policy and keeps the strict one elsewhere', function (): void {
    $admin = (string) $this->actingAs($this->hq)->get('/admin/dashboard')->headers->get('Content-Security-Policy');
    $public = (string) $this->get('/')->headers->get('Content-Security-Policy');

    expect($admin)->toContain("script-src 'self' 'unsafe-inline'")->toContain("frame-ancestors 'none'")
        ->and($public)->toContain("script-src 'self' 'unsafe-eval'")->not->toContain("script-src 'self' 'unsafe-inline'");
});

it('shows each role only the menu items it may use', function (): void {
    $this->actingAs($this->hq)->get('/admin/dashboard')->assertSee('Audit log')->assertSee('Staff users');
    $this->actingAs($this->managerA)->get('/admin/dashboard')->assertDontSee('Audit log')->assertDontSee('Staff users')->assertSee('Appointments');
});

// ---------------------------------------------------------------------------------------------
// Branches, hours and closures
// ---------------------------------------------------------------------------------------------

it('scopes the branch list to what each role may see', function (): void {
    $this->actingAs($this->hq);
    $hq = search($this, 'branch')->assertOk()->getContent();
    expect($hq)->toContain($this->a->code)->toContain($this->b->code);

    $this->actingAs($this->managerA);
    $body = search($this, 'branch')->assertOk()->getContent();
    expect($body)->toContain($this->a->code)->not->toContain($this->b->code);
});

it('lets a manager edit their own branch but not another, create, or delete', function (): void {
    $this->actingAs($this->managerA);
    $payload = fn (Branch $b, array $over = []): array => array_merge([
        'id' => $b->id, 'code' => $b->code, 'name' => 'Renamed branch', 'city' => $b->city, 'address' => $b->address,
        'latitude' => 25.1, 'longitude' => 55.1, 'phone' => $b->phone, 'is_active' => 1,
    ], $over);

    $this->put("/admin/branch/{$this->a->id}", $payload($this->a))->assertRedirect();
    expect($this->a->fresh()->name)->toBe('Renamed branch');

    $this->put("/admin/branch/{$this->b->id}", $payload($this->b))->assertNotFound();
    expect($this->b->fresh()->name)->not->toBe('Renamed branch');

    $this->post('/admin/branch', $payload($this->a, ['id' => null, 'code' => 'NEW-ONE']))->assertForbidden();
    $this->delete("/admin/branch/{$this->a->id}")->assertForbidden();
    expect(Branch::query()->count())->toBe(2);
});

it('lets HQ create a branch and rejects a duplicate or malformed code', function (): void {
    $this->actingAs($this->hq);
    $new = ['code' => 'AUH-NEW', 'name' => 'Northwind Abu Dhabi New', 'city' => 'Abu Dhabi', 'address' => 'Somewhere 1', 'latitude' => 24.4, 'longitude' => 54.3, 'phone' => '+97126000000', 'is_active' => 1];

    $this->post('/admin/branch', $new)->assertRedirect();
    expect(Branch::query()->where('code', 'AUH-NEW')->exists())->toBeTrue();

    $this->post('/admin/branch', $new)->assertSessionHasErrors('code');                                   // duplicate
    $this->post('/admin/branch', array_merge($new, ['code' => 'lower case']))->assertSessionHasErrors('code');
    $this->post('/admin/branch', array_merge($new, ['code' => 'OK-1', 'latitude' => 500]))->assertSessionHasErrors('latitude');
});

it('manages opening hours with validation and a one-row-per-weekday rule', function (): void {
    $this->actingAs($this->managerA);
    $hours = ['branch_id' => $this->a->id, 'weekday' => 6, 'opens_at' => '09:00', 'closes_at' => '13:00'];

    $this->post('/admin/branch-hour', $hours)->assertRedirect();
    expect($this->a->hours()->where('weekday', 6)->exists())->toBeTrue();

    $this->post('/admin/branch-hour', $hours)->assertSessionHasErrors('weekday');                                        // already has Saturday
    $this->post('/admin/branch-hour', array_merge($hours, ['weekday' => 7, 'closes_at' => '08:00']))->assertSessionHasErrors('closes_at');
    $this->post('/admin/branch-hour', array_merge($hours, ['weekday' => 7, 'break_start' => '10:00']))->assertSessionHasErrors('break_end');
    $this->post('/admin/branch-hour', array_merge($hours, ['branch_id' => $this->b->id, 'weekday' => 7]))->assertSessionHasErrors('branch_id');   // not their branch
});

it('manages closures: managers own branch only, HQ may close every branch', function (): void {
    $this->actingAs($this->managerA);
    $this->post('/admin/closure', ['branch_id' => $this->a->id, 'date' => '2026-12-20', 'reason' => 'Water leak'])->assertRedirect();
    $this->post('/admin/closure', ['branch_id' => $this->b->id, 'date' => '2026-12-21', 'reason' => 'x'])->assertSessionHasErrors('branch_id');
    $this->post('/admin/closure', ['branch_id' => null, 'date' => '2026-12-22', 'reason' => 'All'])->assertSessionHasErrors('branch_id');

    $this->actingAs($this->hq)->post('/admin/closure', ['branch_id' => null, 'date' => '2026-12-22', 'reason' => 'Holiday'])->assertRedirect();
    expect(Closure::query()->whereNull('branch_id')->where('reason', 'Holiday')->exists())->toBeTrue();

    $list = $this->actingAs($this->managerA);
    $body = search($this, 'closure')->assertOk()->getContent();
    expect($body)->toContain('Water leak')->not->toContain('Holiday');   // the manager does not see all-branch holidays here
});

// ---------------------------------------------------------------------------------------------
// Services
// ---------------------------------------------------------------------------------------------

it('lets only HQ edit services and stores the checklist one document per line', function (): void {
    $payload = ['code' => 'NEW-SVC', 'name' => 'New service', 'category' => 'Test', 'description' => 'A test service.', 'duration_minutes' => 40, 'buffer_minutes' => 10,
        'queue_prefix' => 'N', 'required_documents_text' => "Passport\r\n\r\n  Visa  \nUtility bill", 'min_notice_hours' => 2, 'max_days_ahead' => 30, 'is_active' => 1];

    $this->actingAs($this->managerA)->post('/admin/service', $payload)->assertForbidden();
    $this->actingAs($this->managerA)->get('/admin/service')->assertOk();   // managers may look

    $this->actingAs($this->hq)->post('/admin/service', $payload)->assertRedirect();

    $service = Service::query()->where('code', 'NEW-SVC')->firstOrFail();
    expect($service->required_documents)->toBe(['Passport', 'Visa', 'Utility bill'])
        ->and($service->required_documents_text)->toBe("Passport\nVisa\nUtility bill")
        ->and($service->slotMinutes())->toBe(50);

    $this->actingAs($this->managerA)->put("/admin/service/{$service->id}", $payload + ['id' => $service->id])->assertForbidden();
});

it('validates service settings', function (array $bad, string $field): void {
    $good = ['code' => 'SVC-OK', 'name' => 'N', 'category' => 'C', 'description' => 'D', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'queue_prefix' => 'Q', 'min_notice_hours' => 2, 'max_days_ahead' => 30];

    $this->actingAs($this->hq)->post('/admin/service', array_merge($good, $bad))->assertSessionHasErrors($field);
    expect(Service::query()->where('code', 'SVC-OK')->exists())->toBeFalse();
})->with([
    'tiny duration' => [['duration_minutes' => 1], 'duration_minutes'],
    'two-letter ticket prefix' => [['queue_prefix' => 'AB'], 'queue_prefix'],
    'lowercase ticket prefix' => [['queue_prefix' => 'a'], 'queue_prefix'],
    'negative buffer' => [['buffer_minutes' => -5], 'buffer_minutes'],
    'horizon too long' => [['max_days_ahead' => 1000], 'max_days_ahead'],
    'bad code' => [['code' => 'has space'], 'code'],
]);

// ---------------------------------------------------------------------------------------------
// Advisors, shifts and time off
// ---------------------------------------------------------------------------------------------

it('creates advisors with skills, inside the manager\'s branch only', function (): void {
    $newUser = User::factory()->forBranch(Role::Advisor, $this->a)->create();
    $payload = ['user_id' => $newUser->id, 'branch_id' => $this->a->id, 'desk_label' => 'Desk 9', 'is_active' => 1, 'services' => [$this->service->id]];

    $this->actingAs($this->managerA)->post('/admin/advisor', $payload)->assertRedirect();

    $advisor = Advisor::query()->where('user_id', $newUser->id)->firstOrFail();
    expect($advisor->services()->pluck('services.id')->all())->toBe([$this->service->id]);

    // Wrong branch, wrong role, duplicate login.
    $other = User::factory()->forBranch(Role::Advisor, $this->b)->create();
    $this->actingAs($this->managerA)->post('/admin/advisor', ['user_id' => $other->id, 'branch_id' => $this->b->id] + $payload)->assertSessionHasErrors('branch_id');
    $this->actingAs($this->hq)->post('/admin/advisor', ['user_id' => $this->desk->id, 'branch_id' => $this->a->id] + $payload)->assertSessionHasErrors('user_id');
    $this->actingAs($this->managerA)->post('/admin/advisor', $payload)->assertSessionHasErrors('user_id');
});

it('lists advisors with skills and filters by branch and skill', function (): void {
    $this->actingAs($this->hq);
    $body = search($this, 'advisor')->assertOk()->getContent();
    expect($body)->toContain($this->advA->user->name)->toContain($this->advB->user->name);

    $this->actingAs($this->managerA);
    $body = search($this, 'advisor')->assertOk()->getContent();
    expect($body)->toContain($this->advA->user->name)->not->toContain($this->advB->user->name);

    $this->actingAs($this->hq);
    $filtered = search($this, 'advisor', ['branch' => $this->b->id])->assertOk()->getContent();
    expect($filtered)->toContain($this->advB->user->name)->not->toContain($this->advA->user->name);
});

it('limits shifts and time off to advisors of the manager\'s branch', function (): void {
    $this->actingAs($this->managerA);
    $shift = ['advisor_id' => $this->advA->id, 'weekday' => 6, 'starts_at' => '09:00', 'ends_at' => '12:00'];

    $this->post('/admin/advisor-shift', $shift)->assertRedirect();
    $this->post('/admin/advisor-shift', array_merge($shift, ['advisor_id' => $this->advB->id]))->assertSessionHasErrors('advisor_id');
    $this->post('/admin/advisor-shift', array_merge($shift, ['ends_at' => '08:00']))->assertSessionHasErrors('ends_at');

    $leave = ['advisor_id' => $this->advA->id, 'starts_at' => '2026-12-10 08:00', 'ends_at' => '2026-12-11 08:00', 'reason' => 'Training'];
    $this->post('/admin/advisor-time-off', $leave)->assertRedirect();
    $this->post('/admin/advisor-time-off', array_merge($leave, ['advisor_id' => $this->advB->id]))->assertSessionHasErrors('advisor_id');
    $this->post('/admin/advisor-time-off', array_merge($leave, ['ends_at' => '2026-12-09 08:00']))->assertSessionHasErrors('ends_at');

    bookedFor($this->advB, $this->service, '2026-12-08 09:00');   // unrelated
    $this->advB->shifts()->create(['weekday' => 6, 'starts_at' => '09:00', 'ends_at' => '10:00']);

    $body = search($this, 'advisor-shift')->assertOk()->getContent();
    expect($body)->toContain($this->advA->user->name)->not->toContain($this->advB->user->name);
});

// ---------------------------------------------------------------------------------------------
// Staff users
// ---------------------------------------------------------------------------------------------

it('lets HQ create staff with a role and branch even though neither is mass-assignable', function (): void {
    $payload = ['name' => 'New Manager', 'email' => 'New.Manager@Northwind.test', 'role' => 'branch_manager', 'branch_id' => $this->b->id, 'password' => 'Str0ng-Passphrase-1'];

    $this->actingAs($this->hq)->post('/admin/user', $payload)->assertRedirect();

    $user = User::query()->where('email', 'new.manager@northwind.test')->firstOrFail();
    expect($user->role)->toBe(Role::BranchManager)->and($user->branch_id)->toBe($this->b->id)
        ->and(Hash::check('Str0ng-Passphrase-1', $user->password))->toBeTrue()
        ->and($user->password)->not->toContain('Passphrase')
        ->and($user->email_verified_at)->not->toBeNull();

    $log = AuditLog::query()->where('action', 'user.created')->firstOrFail();
    expect($log->actor_id)->toBe($this->hq->id)->and(json_encode($log->toArray()))->not->toContain('Passphrase')->not->toContain('new.manager');
});

it('validates staff accounts: weak passwords, HQ with a branch, branch roles without one, duplicate emails', function (): void {
    $this->actingAs($this->hq);
    $good = ['name' => 'X Person', 'email' => 'x.person@northwind.test', 'role' => 'front_desk', 'branch_id' => $this->a->id, 'password' => 'Str0ng-Passphrase-1'];

    $this->post('/admin/user', array_merge($good, ['password' => 'short']))->assertSessionHasErrors('password');
    $this->post('/admin/user', array_merge($good, ['password' => 'alllowercase1234']))->assertSessionHasErrors('password');
    $this->post('/admin/user', array_merge($good, ['role' => 'hq_admin']))->assertSessionHasErrors('branch_id');
    $this->post('/admin/user', array_merge($good, ['branch_id' => null]))->assertSessionHasErrors('branch_id');
    $this->post('/admin/user', array_merge($good, ['role' => 'emperor']))->assertSessionHasErrors('role');
    $this->post('/admin/user', array_merge($good, ['email' => $this->hq->email]))->assertSessionHasErrors('email');
    expect(User::query()->where('email', 'x.person@northwind.test')->exists())->toBeFalse();
});

it('updates a user without a new password and records the role change', function (): void {
    $old = $this->desk->password;

    $this->actingAs($this->hq)->put("/admin/user/{$this->desk->id}", [
        'id' => $this->desk->id, 'name' => 'Desk Renamed', 'email' => $this->desk->email, 'role' => 'branch_manager', 'branch_id' => $this->a->id, 'password' => '',
    ])->assertRedirect();

    $fresh = $this->desk->fresh();
    expect($fresh->name)->toBe('Desk Renamed')->and($fresh->role)->toBe(Role::BranchManager)->and($fresh->password)->toBe($old);
    expect(AuditLog::query()->where('action', 'user.updated')->firstOrFail()->changes)->toEqual(['role' => ['front_desk', 'branch_manager'], 'branch_id' => [$this->a->id, $this->a->id]]);

    $this->actingAs($this->managerA)->post('/admin/user', ['name' => 'N', 'email' => 'n@n.test', 'role' => 'hq_admin', 'password' => 'Str0ng-Passphrase-1'])->assertForbidden();
});

// ---------------------------------------------------------------------------------------------
// Appointments
// ---------------------------------------------------------------------------------------------

it('lists appointments per branch with filters', function (): void {
    $mineConfirmed = bookedFor($this->advA, $this->service, '2026-12-08 09:00');
    $mineCompleted = bookedFor($this->advA, $this->service, '2026-12-08 10:00', AppointmentStatus::Completed);
    $theirs = bookedFor($this->advB, $this->service, '2026-12-08 09:00');

    $this->actingAs($this->managerA);
    $body = search($this, 'appointment')->assertOk()->getContent();
    expect($body)->toContain($mineConfirmed->reference)->toContain($mineCompleted->reference)->not->toContain($theirs->reference);

    $this->actingAs($this->hq);
    expect(search($this, 'appointment')->getContent())->toContain($theirs->reference)->toContain($mineConfirmed->reference);

    $byStatus = search($this, 'appointment', ['status' => 'completed'])->getContent();
    expect($byStatus)->toContain($mineCompleted->reference)->not->toContain($mineConfirmed->reference);

    $byBranch = search($this, 'appointment', ['branch' => $this->b->id])->getContent();
    expect($byBranch)->toContain($theirs->reference)->not->toContain($mineConfirmed->reference);

    $byDay = search($this, 'appointment', ['day' => json_encode(['from' => '2026-12-08', 'to' => '2026-12-08'])])->getContent();
    expect($byDay)->toContain($mineConfirmed->reference);
    $otherDay = search($this, 'appointment', ['day' => json_encode(['from' => '2026-12-20', 'to' => '2026-12-21'])])->getContent();
    expect($otherDay)->not->toContain($mineConfirmed->reference);

    $byAdvisor = search($this, 'appointment', ['advisor' => $this->advB->id])->getContent();
    expect($byAdvisor)->toContain($theirs->reference)->not->toContain($mineConfirmed->reference);
});

it('lets staff cancel and mark no-show with an audit trail, inside their own branch', function (): void {
    $appointment = bookedFor($this->advA, $this->service, '2026-12-08 09:00');
    $other = bookedFor($this->advB, $this->service, '2026-12-08 09:00');
    $this->actingAs($this->managerA);

    $this->post("/admin/appointment/{$appointment->id}/cancel")->assertRedirect();
    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled)->and($appointment->fresh()->cancelled_reason)->toBe('Cancelled by branch staff');

    $this->post("/admin/appointment/{$other->id}/cancel")->assertNotFound();
    $this->post("/admin/appointment/{$other->id}/no-show")->assertNotFound();
    expect($other->fresh()->status)->toBe(AppointmentStatus::Confirmed);

    $second = bookedFor($this->advA, $this->service, '2026-12-08 10:00');
    $this->post("/admin/appointment/{$second->id}/no-show")->assertRedirect();
    expect($second->fresh()->status)->toBe(AppointmentStatus::NoShow);

    $log = AuditLog::query()->where('action', 'appointment.cancelled')->firstOrFail();
    expect($log->actor_id)->toBe($this->managerA->id)->and($log->changes)->toEqual(['by' => 'staff', 'reason' => 'Cancelled by branch staff']);
});

it('refuses illegal status changes from the panel and keeps the appointment as it was', function (): void {
    $done = bookedFor($this->advA, $this->service, '2026-12-08 09:00', AppointmentStatus::Completed);
    $this->actingAs($this->managerA);

    $this->post("/admin/appointment/{$done->id}/cancel")->assertRedirect();
    $this->post("/admin/appointment/{$done->id}/no-show")->assertRedirect();

    expect($done->fresh()->status)->toBe(AppointmentStatus::Completed)->and(AuditLog::query()->count())->toBe(0);
});

it('shows an appointment with its status history from the audit log', function (): void {
    $appointment = bookedFor($this->advA, $this->service, '2026-12-08 09:00');
    $this->actingAs($this->managerA)->post("/admin/appointment/{$appointment->id}/no-show");

    $this->get("/admin/appointment/{$appointment->id}/show")
        ->assertOk()->assertSee($appointment->reference)->assertSee('appointment.no_show')->assertSee('Status history');
    $this->get('/admin/appointment/'.bookedFor($this->advB, $this->service, '2026-12-09 09:00')->id.'/show')->assertNotFound();
});

// ---------------------------------------------------------------------------------------------
// Customers: masked, scoped, audited reveal
// ---------------------------------------------------------------------------------------------

it('lists customers with masked contact details, only those with a booking at the manager\'s branch', function (): void {
    $mine = Customer::factory()->create(['first_name' => 'Sarah', 'last_name' => 'Jones', 'email' => 'sarah.jones@example.com', 'phone' => '+971501234567']);
    $theirs = Customer::factory()->create(['first_name' => 'Zed', 'last_name' => 'Other', 'email' => 'zed.other@example.com']);
    bookedFor($this->advA, $this->service, '2026-12-08 09:00')->forceFill(['customer_id' => $mine->id])->save();
    bookedFor($this->advB, $this->service, '2026-12-08 09:00')->forceFill(['customer_id' => $theirs->id])->save();

    $this->actingAs($this->managerA);
    $body = search($this, 'customer')->assertOk()->getContent();
    expect($body)->toContain('Sarah Jones')->toContain('s***@example.com')->toContain('+971 \u2022\u2022\u2022 \u2022\u2022\u2022 567')   // JSON escapes the bullet characters->not->toContain('Zed Other')
        ->not->toContain('sarah.jones@example.com')->not->toContain('501234567');

    $this->actingAs($this->hq);
    expect(search($this, 'customer')->getContent())->toContain('Sarah Jones')->toContain('Zed Other')->not->toContain('zed.other@example.com');
});

it('reveals contact details on request and writes an audit entry without the details', function (): void {
    $customer = Customer::factory()->create(['email' => 'reveal.me@example.com', 'phone' => '+971509998877']);
    bookedFor($this->advA, $this->service, '2026-12-08 09:00')->forceFill(['customer_id' => $customer->id])->save();

    $this->actingAs($this->managerA)->post("/admin/customer/{$customer->id}/reveal")
        ->assertRedirect()->assertSessionHas('alert_messages');

    $flash = json_encode(session('alert_messages'));
    expect($flash)->toContain('reveal.me@example.com')->toContain('+971509998877');

    $log = AuditLog::query()->where('action', 'customer.contact_revealed')->firstOrFail();
    expect($log->actor_id)->toBe($this->managerA->id)->and($log->subject_id)->toBe($customer->id)
        ->and(json_encode($log->toArray()))->not->toContain('reveal.me')->not->toContain('509998877');
});

it('does not reveal a customer from another branch', function (): void {
    $stranger = Customer::factory()->create(['email' => 'stranger@example.com']);
    bookedFor($this->advB, $this->service, '2026-12-08 09:00')->forceFill(['customer_id' => $stranger->id])->save();

    $this->actingAs($this->managerA)->post("/admin/customer/{$stranger->id}/reveal")->assertNotFound();
    expect(AuditLog::query()->where('action', 'customer.contact_revealed')->count())->toBe(0);

    $this->actingAs($this->managerA)->get("/admin/customer/{$stranger->id}/show")->assertNotFound();
});

it('shows a customer with booking history limited to the manager\'s branch', function (): void {
    $customer = Customer::factory()->create();
    $mine = bookedFor($this->advA, $this->service, '2026-12-08 09:00');
    $mine->forceFill(['customer_id' => $customer->id])->save();
    $theirs = bookedFor($this->advB, $this->service, '2026-12-08 10:00');
    $theirs->forceFill(['customer_id' => $customer->id])->save();

    $this->actingAs($this->managerA)->get("/admin/customer/{$customer->id}/show")
        ->assertOk()->assertSee($mine->reference)->assertDontSee($theirs->reference);
});

// ---------------------------------------------------------------------------------------------
// Audit log
// ---------------------------------------------------------------------------------------------

it('lets HQ browse and filter the audit log read-only, and blocks managers', function (): void {
    AuditLog::query()->create(['actor_type' => 'user', 'actor_id' => $this->hq->id, 'action' => 'ticket.called', 'subject_type' => 'QueueTicket', 'subject_id' => 5, 'changes' => ['code' => 'A-001']]);
    AuditLog::query()->create(['action' => 'appointment.cancelled', 'subject_type' => 'Appointment', 'subject_id' => 9]);

    $this->actingAs($this->hq);
    $all = search($this, 'audit-log')->assertOk()->getContent();
    expect($all)->toContain('ticket.called')->toContain('appointment.cancelled');

    expect(search($this, 'audit-log', ['action' => 'ticket.called'])->getContent())->toContain('ticket.called')->not->toContain('appointment.cancelled');
    expect(search($this, 'audit-log', ['subject' => 'Appointment'])->getContent())->not->toContain('ticket.called');
    expect(search($this, 'audit-log', ['actor' => (string) $this->hq->id])->getContent())->toContain('ticket.called')->not->toContain('appointment.cancelled');

    $entry = AuditLog::query()->where('action', 'ticket.called')->firstOrFail();
    $this->get("/admin/audit-log/{$entry->id}/show")->assertOk()->assertSee('A-001');

    // Read-only: there are no create, update or delete routes.
    expect($this->post('/admin/audit-log', [])->status())->toBeIn([404, 405]);
    expect($this->delete("/admin/audit-log/{$entry->id}")->status())->toBeIn([404, 405]);
    expect(AuditLog::query()->whereKey($entry->id)->exists())->toBeTrue();

    $this->actingAs($this->managerA);
    search($this, 'audit-log')->assertForbidden();
});

// ---------------------------------------------------------------------------------------------
// Dashboard
// ---------------------------------------------------------------------------------------------

it('computes the dashboard figures per branch from hand-checked data', function (): void {
    // Branch A, today (Mon): 2 live bookings + 1 cancelled (not counted).
    bookedFor($this->advA, $this->service, MON.' 09:00');
    bookedFor($this->advA, $this->service, MON.' 10:00');
    bookedFor($this->advA, $this->service, MON.' 11:00', AppointmentStatus::Cancelled);
    // No-shows this week: Monday and Wednesday count, last week's does not.
    bookedFor($this->advA, $this->service, MON.' 12:00', AppointmentStatus::NoShow);
    bookedFor($this->advA, $this->service, '2026-12-09 09:00', AppointmentStatus::NoShow);
    bookedFor($this->advA, $this->service, '2026-12-02 09:00', AppointmentStatus::NoShow);
    // Queue today: 3 waiting; two called tickets waited 10 and 20 minutes (average 15).
    foreach ([[null, 1], [null, 2], [null, 3], [10, 4], [20, 5]] as [$wait, $n]) {
        $issued = nowAt('2026-12-07 05:00:00');
        QueueTicket::factory()->state([
            'branch_id' => $this->a->id, 'service_id' => $this->service->id, 'queue_prefix' => 'Q', 'sequence' => $n, 'display_code' => "Q-00{$n}",
            'service_date' => MON, 'issued_at' => $issued, 'called_at' => $wait === null ? null : $issued->addMinutes($wait),
        ])->create()->forceFill(['status' => $wait === null ? 'waiting' : 'completed'])->save();
    }

    $rows = collect(app(DashboardStats::class)->forUser($this->hq, nowAt('2026-12-07 06:00:00')))->keyBy('branch');

    expect($rows[$this->a->code])->toBe(['branch' => $this->a->code, 'name' => $this->a->name, 'bookings_today' => 3, 'queue_length' => 3, 'avg_wait_min' => 15.0, 'no_shows_week' => 2])
        ->and($rows[$this->b->code]['bookings_today'])->toBe(0)->and($rows[$this->b->code]['avg_wait_min'])->toBeNull();

    // A manager only gets their own branch.
    $mine = app(DashboardStats::class)->forUser($this->managerA, nowAt('2026-12-07 06:00:00'));
    expect($mine)->toHaveCount(1)->and($mine[0]['branch'])->toBe($this->a->code);
});

it('renders the dashboard with the manager\'s branch only', function (): void {
    bookedFor($this->advA, $this->service, MON.' 09:00');

    $this->actingAs($this->managerA)->get('/admin/dashboard')->assertOk()->assertSee($this->a->name)->assertDontSee($this->b->name)->assertSee('Bookings today');
    $this->actingAs($this->hq)->get('/admin/dashboard')->assertSee($this->a->name)->assertSee($this->b->name);
});
