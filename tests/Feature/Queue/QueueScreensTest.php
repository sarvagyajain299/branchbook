<?php

declare(strict_types=1);

use App\Actions\Queue\CallNextTicket;
use App\Actions\Queue\CheckInAppointment;
use App\Actions\Queue\IssueQueueTicket;
use App\Actions\Queue\ProgressTicket;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Advisor;
use App\Models\Customer;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo(nowAt('2026-12-07 06:00:00'));   // Mon 10:00 Dubai

    $this->branch = openBranch();
    $this->other = openBranch();
    $this->service = Service::factory()->create(['queue_prefix' => 'A', 'name' => 'Accounts', 'duration_minutes' => 30]);
    $this->advisor = advisorFor($this->branch, $this->service);
    $this->desk = User::factory()->forBranch(Role::FrontDesk, $this->branch)->create();
    $this->manager = User::factory()->forBranch(Role::BranchManager, $this->branch)->create();
    $this->hq = User::factory()->hqAdmin()->create();
});

function walkIn(object $test, string $utc = '2026-12-07 05:00:00', ?string $name = null): QueueTicket
{
    return app(IssueQueueTicket::class)->handle($test->branch, $test->service, null, $name, nowAt($utc));
}

// ---------------------------------------------------------------------------------------------
// Front desk screen
// ---------------------------------------------------------------------------------------------

it('lets front desk, managers and HQ open the front desk, and nobody else', function (): void {
    $this->actingAs($this->desk)->get(route('desk', $this->branch))->assertOk();
    $this->actingAs($this->manager)->get(route('desk', $this->branch))->assertOk();
    $this->actingAs($this->hq)->get(route('desk', $this->branch))->assertOk();

    $this->actingAs($this->advisor->user)->get(route('desk', $this->branch))->assertForbidden();
    $this->actingAs(User::factory()->forBranch(Role::FrontDesk, $this->other)->create())->get(route('desk', $this->branch))->assertForbidden();
});

it('sends guests and unverified staff to log in', function (): void {
    auth()->logout();
    $this->get(route('desk', $this->branch))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->unverified()->forBranch(Role::FrontDesk, $this->branch)->create())
        ->get(route('desk', $this->branch))->assertRedirect(route('verification.notice'));
});

it('shows today\'s appointments with MASKED contact details only', function (): void {
    $customer = Customer::factory()->create(['first_name' => 'Sarah', 'last_name' => 'Jones', 'email' => 'sarah.jones@example.com', 'phone' => '+971501234567']);
    bookedFor($this->advisor, $this->service, MON.' 09:00')->forceFill(['customer_id' => $customer->id])->save();
    bookedFor($this->advisor, $this->service, '2026-12-08 09:00');   // tomorrow: not listed

    $this->actingAs($this->desk)->get(route('desk', $this->branch))
        ->assertOk()
        ->assertSee('Sarah J.')
        ->assertSee('s***@example.com')
        ->assertSee('+971 ••• ••• 567')
        ->assertDontSee('sarah.jones@example.com')
        ->assertDontSee('501234567')
        ->assertSee('09:00')
        ->assertSee('Check in');
});

it('checks a customer in from the desk and tells staff the ticket number', function (): void {
    $appointment = bookedFor($this->advisor, $this->service, MON.' 09:00');

    $this->actingAs($this->desk)->post(route('desk.check-in', [$this->branch, $appointment]))
        ->assertRedirect()->assertSessionHas('status', 'Checked in. Ticket A-001.');

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);

    // Second click: friendly error, no second ticket.
    $this->actingAs($this->desk)->post(route('desk.check-in', [$this->branch, $appointment]))->assertSessionHas('error');
    expect(QueueTicket::query()->count())->toBe(1);
});

it('refuses cross-branch check-in: wrong role 403, other branch 403, appointment from elsewhere 404', function (): void {
    $appointment = bookedFor($this->advisor, $this->service, MON.' 09:00');
    $otherDesk = User::factory()->forBranch(Role::FrontDesk, $this->other)->create();

    $this->actingAs($this->advisor->user)->post(route('desk.check-in', [$this->branch, $appointment]))->assertForbidden();
    $this->actingAs($otherDesk)->post(route('desk.check-in', [$this->branch, $appointment]))->assertForbidden();
    // A desk at the OTHER branch can't smuggle this appointment through its own branch URL.
    $this->actingAs($otherDesk)->post(route('desk.check-in', [$this->other, $appointment]))->assertNotFound();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Confirmed)->and(QueueTicket::query()->count())->toBe(0);
});

it('issues walk-in tickets with an optional name', function (): void {
    $this->actingAs($this->desk)->post(route('desk.walk-in', $this->branch), ['service_id' => $this->service->id, 'name' => 'Walk Inn'])
        ->assertSessionHas('status', 'Walk-in ticket A-001 issued.');
    $this->actingAs($this->desk)->post(route('desk.walk-in', $this->branch), ['service_id' => $this->service->id])
        ->assertSessionHas('status', 'Walk-in ticket A-002 issued.');

    $tickets = QueueTicket::query()->orderBy('sequence')->get();
    expect($tickets->pluck('walk_in_name')->all())->toBe(['Walk Inn', null])
        ->and($tickets->pluck('appointment_id')->filter()->all())->toBe([]);
});

it('validates and authorises walk-ins', function (): void {
    $this->actingAs($this->desk)->post(route('desk.walk-in', $this->branch), ['service_id' => 9999])->assertSessionHasErrors('service_id');
    $this->actingAs($this->desk)->post(route('desk.walk-in', $this->branch), [])->assertSessionHasErrors('service_id');
    $this->actingAs($this->desk)->post(route('desk.walk-in', $this->branch), ['service_id' => $this->service->id, 'name' => str_repeat('x', 61)])->assertSessionHasErrors('name');

    $this->service->update(['is_active' => false]);
    $this->actingAs($this->desk)->post(route('desk.walk-in', $this->branch), ['service_id' => $this->service->id])->assertSessionHas('error', 'That service is not available.');

    $this->actingAs($this->advisor->user)->post(route('desk.walk-in', $this->branch), ['service_id' => $this->service->id])->assertForbidden();
    expect(QueueTicket::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Advisor desk
// ---------------------------------------------------------------------------------------------

it('lets only advisors of that branch open the advisor desk', function (): void {
    $this->actingAs($this->advisor->user)->get(route('my-desk', $this->branch))->assertOk()->assertSee('Call next');

    $this->actingAs($this->desk)->get(route('my-desk', $this->branch))->assertForbidden();
    $this->actingAs($this->hq)->get(route('my-desk', $this->branch))->assertForbidden();
    $this->actingAs(advisorFor($this->other, $this->service)->user)->get(route('my-desk', $this->branch))->assertForbidden();
});

it('runs a customer through the advisor desk screens', function (): void {
    walkIn($this, name: 'Walk Inn');
    $desk = $this->actingAs($this->advisor->user);

    $desk->get(route('my-desk', $this->branch))->assertSee('1')->assertSee('waiting');

    $desk->post(route('my-desk.call-next', $this->branch))->assertSessionHas('status', 'Calling A-001.');
    $ticket = QueueTicket::query()->firstOrFail();

    $desk->get(route('my-desk', $this->branch))->assertSee('A-001')->assertSee('Walk Inn')->assertSee('Start')->assertSee('Recall')->assertSee('No-show');

    $desk->post(route('my-desk.recall', [$this->branch, $ticket]))->assertSessionHas('status', 'Announced again.');
    $desk->post(route('my-desk.start', [$this->branch, $ticket]))->assertSessionHas('status', 'Serving started.');
    $desk->get(route('my-desk', $this->branch))->assertSee('Complete')->assertDontSee('Recall');
    $desk->post(route('my-desk.complete', [$this->branch, $ticket]))->assertSessionHas('status', 'Completed.');

    expect($ticket->fresh()->status)->toBe(TicketStatus::Completed);
    $desk->post(route('my-desk.call-next', $this->branch))->assertSessionHas('status', 'Nobody is waiting for your services right now.');
});

it('shows a friendly error when an advisor calls again without finishing', function (): void {
    walkIn($this);
    walkIn($this, '2026-12-07 05:01:00');
    $desk = $this->actingAs($this->advisor->user);

    $desk->post(route('my-desk.call-next', $this->branch));
    $desk->post(route('my-desk.call-next', $this->branch))->assertSessionHas('error', 'Finish or mark the current customer before calling the next one.');
});

it('blocks wrong roles and other advisors from operating tickets', function (): void {
    walkIn($this);
    app(CallNextTicket::class)->handle($this->advisor->user);
    $ticket = QueueTicket::query()->firstOrFail();
    $colleague = advisorFor($this->branch, $this->service);

    $this->actingAs($this->desk)->post(route('my-desk.call-next', $this->branch))->assertForbidden();
    $this->actingAs($this->desk)->post(route('my-desk.start', [$this->branch, $ticket]))->assertForbidden();
    $this->actingAs(advisorFor($this->other, $this->service)->user)->post(route('my-desk.start', [$this->branch, $ticket]))->assertForbidden();

    // A colleague at the same branch passes the policy but not the "called by someone else" rule.
    $this->actingAs($colleague->user)->post(route('my-desk.start', [$this->branch, $ticket]))
        ->assertSessionHas('error', 'That ticket was called by another advisor.');

    // A ticket id from another branch in this branch's URL is a 404, not a data leak.
    $this->actingAs($this->advisor->user)->post(route('my-desk.start', [$this->other, $ticket]))->assertNotFound();
    expect($ticket->fresh()->status)->toBe(TicketStatus::Called);
});

// ---------------------------------------------------------------------------------------------
// Waiting-room display
// ---------------------------------------------------------------------------------------------

it('serves the display page without a login', function (): void {
    auth()->logout();

    $this->get(route('display', $this->branch))->assertOk()->assertSee($this->branch->name)->assertSee('Now serving');
    $this->branch->update(['is_active' => false]);
    $this->get(route('display', $this->branch))->assertNotFound();
});

it('reports now-serving and next tickets as JSON with no personal data', function (): void {
    $appointment = bookedFor($this->advisor, $this->service, MON.' 09:00');
    $appointment->customer->forceFill(['first_name' => 'Zainab', 'email' => 'zainab@example.com'])->save();
    app(CheckInAppointment::class)->handle($appointment, nowAt('2026-12-07 05:00:00'));
    walkIn($this, '2026-12-07 05:30:00', 'Private Name');
    walkIn($this, '2026-12-07 05:31:00');
    app(CallNextTicket::class)->handle($this->advisor->user);

    $response = $this->getJson(route('display.state', $this->branch))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $response->assertJsonPath('serving.0.code', 'A-001')
        ->assertJsonPath('serving.0.desk', $this->advisor->desk_label)
        ->assertJsonPath('next', ['A-002', 'A-003'])
        ->assertJsonStructure(['serving' => [['code', 'desk', 'key']], 'next']);

    $body = $response->getContent();
    expect($body)->not->toContain('Zainab')->not->toContain('zainab')->not->toContain('Private Name')->not->toContain('example.com');
});

it('lists the most recently called or recalled ticket first and changes the key on recall', function (): void {
    $second = advisorFor($this->branch, $this->service);
    walkIn($this);
    walkIn($this, '2026-12-07 05:01:00');
    $first = app(CallNextTicket::class)->handle($this->advisor->user);
    $this->travelTo(nowAt('2026-12-07 06:01:00'));
    app(CallNextTicket::class)->handle($second->user);

    $before = $this->getJson(route('display.state', $this->branch))->json('serving');
    expect(array_column($before, 'code'))->toBe(['A-002', 'A-001']);

    $this->travelTo(nowAt('2026-12-07 06:02:00'));
    app(ProgressTicket::class)->recall($first, $this->advisor->user);

    $after = $this->getJson(route('display.state', $this->branch))->json('serving');
    expect(array_column($after, 'code'))->toBe(['A-001', 'A-002'])         // the recalled ticket jumps to the top
        ->and($after[0]['key'])->not->toBe($before[1]['key']);             // new key: the screen chimes again
});

it('only shows today\'s tickets and never finished ones as "next"', function (): void {
    walkIn($this, '2026-12-06 05:00:00');   // yesterday
    $done = walkIn($this);
    $done->forceFill([])->save();
    $done->transitionTo(TicketStatus::Called);

    $json = $this->getJson(route('display.state', $this->branch))->json();

    expect($json['next'])->toBe([])->and(array_column($json['serving'], 'code'))->toBe(['A-001']);
});

it('throttles the display endpoint with its own limiter', function (): void {
    foreach (range(1, 120) as $i) {
        $this->getJson(route('display.state', $this->branch))->assertOk();
    }

    $this->getJson(route('display.state', $this->branch))->assertStatus(429);
});

it('shows each role the right shortcuts on the dashboard', function (): void {
    $this->actingAs($this->desk)->get('/dashboard')->assertOk()->assertSee('Front desk')->assertDontSee('My desk')->assertSee('Waiting-room display');
    $this->actingAs($this->advisor->user)->get('/dashboard')->assertOk()->assertSee('My desk')->assertDontSee('Front desk');
    $this->actingAs($this->hq)->get('/dashboard')->assertOk()->assertSee($this->branch->name)->assertSee($this->other->name);
});
