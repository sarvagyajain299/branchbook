<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Advisor;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Builds two branches (A and B) with staff of every role, and one appointment, ticket and
 * customer at each. advisorA owns appointmentA; advisorA2 is a different advisor at branch A.
 *
 * @return array<string, mixed>
 */
function policyWorld(): array
{
    $a = Branch::factory()->create();
    $b = Branch::factory()->create();
    $service = Service::factory()->create();

    $u = [
        'hq' => User::factory()->hqAdmin()->create(),
        'managerA' => User::factory()->forBranch(Role::BranchManager, $a)->create(),
        'managerB' => User::factory()->forBranch(Role::BranchManager, $b)->create(),
        'deskA' => User::factory()->forBranch(Role::FrontDesk, $a)->create(),
        'deskB' => User::factory()->forBranch(Role::FrontDesk, $b)->create(),
        'advisorA' => User::factory()->forBranch(Role::Advisor, $a)->create(),
        'advisorA2' => User::factory()->forBranch(Role::Advisor, $a)->create(),
        'advisorB' => User::factory()->forBranch(Role::Advisor, $b)->create(),
    ];

    $adv = [];
    foreach (['advisorA' => $a, 'advisorA2' => $a, 'advisorB' => $b] as $key => $branch) {
        $adv[$key] = Advisor::factory()->create(['user_id' => $u[$key]->id, 'branch_id' => $branch->id]);
    }

    $customerA = Customer::factory()->create();
    $customerB = Customer::factory()->create();

    $apptA = Appointment::factory()->create(['branch_id' => $a->id, 'advisor_id' => $adv['advisorA']->id, 'service_id' => $service->id, 'customer_id' => $customerA->id]);
    Appointment::factory()->create(['branch_id' => $a->id, 'advisor_id' => $adv['advisorA2']->id, 'service_id' => $service->id, 'customer_id' => $customerA->id]);
    Appointment::factory()->create(['branch_id' => $b->id, 'advisor_id' => $adv['advisorB']->id, 'service_id' => $service->id, 'customer_id' => $customerB->id]);

    return [
        'users' => $u, 'branchA' => $a, 'branchB' => $b, 'appointmentA' => $apptA, 'customerA' => $customerA,
        'ticketA' => QueueTicket::factory()->create(['branch_id' => $a->id, 'service_id' => $service->id]),
    ];
}

/** Every dataset row is [ability, who, expected]. "who" is a key from policyWorld()['users']. */
function expectedAccess(array $allowed): array
{
    $everyone = ['hq', 'managerA', 'managerB', 'deskA', 'deskB', 'advisorA', 'advisorA2', 'advisorB'];
    $rows = [];
    foreach ($everyone as $who) {
        $rows[$who] = [$who, in_array($who, $allowed, true)];
    }

    return $rows;
}

// ---- Appointment (subject: an appointment at branch A, owned by advisorA) ----------------
dataset('appointment view', expectedAccess(['hq', 'managerA', 'deskA', 'advisorA']));
dataset('appointment manage', expectedAccess(['hq', 'managerA', 'deskA']));

it('appointment: view', function (string $who, bool $expected): void {
    $w = policyWorld();
    expect(Gate::forUser($w['users'][$who])->allows('view', $w['appointmentA']))->toBe($expected);
})->with('appointment view');

it('appointment: update / cancel / mark no-show / check in', function (string $who, bool $expected): void {
    $w = policyWorld();
    foreach (['update', 'cancel', 'markNoShow', 'checkIn'] as $ability) {
        expect(Gate::forUser($w['users'][$who])->allows($ability, $w['appointmentA']))->toBe($expected, $ability);
    }
})->with('appointment manage');

it('appointment: book on behalf of a customer', function (string $who, bool $expected): void {
    $w = policyWorld();
    expect(Gate::forUser($w['users'][$who])->allows('create', Appointment::class))
        ->toBe(in_array($who, ['hq', 'managerA', 'managerB', 'deskA', 'deskB'], true));
})->with('appointment manage');

// ---- Queue tickets (subject: a ticket at branch A) ---------------------------------------
dataset('ticket view', expectedAccess(['hq', 'managerA', 'deskA', 'advisorA', 'advisorA2']));
dataset('ticket operate', expectedAccess(['advisorA', 'advisorA2']));
dataset('ticket issue', expectedAccess(['hq', 'managerA', 'deskA']));

it('queue ticket: view', function (string $who, bool $expected): void {
    $w = policyWorld();
    expect(Gate::forUser($w['users'][$who])->allows('view', $w['ticketA']))->toBe($expected);
})->with('ticket view');

it('queue ticket: call next / start / complete (advisors at that branch only)', function (string $who, bool $expected): void {
    $w = policyWorld();
    expect(Gate::forUser($w['users'][$who])->allows('operate', $w['ticketA']))->toBe($expected);
})->with('ticket operate');

it('queue ticket: issue a walk-in ticket at branch A', function (string $who, bool $expected): void {
    $w = policyWorld();
    expect(Gate::forUser($w['users'][$who])->allows('issue', [QueueTicket::class, $w['branchA']->id]))->toBe($expected);
})->with('ticket issue');

// ---- Customer (subject: a customer who has an appointment at branch A) -------------------
dataset('customer view', expectedAccess(['hq', 'managerA', 'deskA', 'advisorA', 'advisorA2']));
dataset('customer reveal', expectedAccess(['hq', 'managerA']));

it('customer: view', function (string $who, bool $expected): void {
    $w = policyWorld();
    expect(Gate::forUser($w['users'][$who])->allows('view', $w['customerA']))->toBe($expected);
})->with('customer view');

it('customer: reveal full contact details', function (string $who, bool $expected): void {
    $w = policyWorld();
    expect(Gate::forUser($w['users'][$who])->allows('revealContactDetails', $w['customerA']))->toBe($expected);
})->with('customer reveal');

// ---- Branch (subject: branch A) ----------------------------------------------------------
dataset('branch view', expectedAccess(['hq', 'managerA', 'deskA', 'advisorA', 'advisorA2']));
dataset('branch update', expectedAccess(['hq', 'managerA']));
dataset('hq only', expectedAccess(['hq']));

it('branch: view', function (string $who, bool $expected): void {
    $w = policyWorld();
    expect(Gate::forUser($w['users'][$who])->allows('view', $w['branchA']))->toBe($expected);
})->with('branch view');

it('branch: update', function (string $who, bool $expected): void {
    $w = policyWorld();
    expect(Gate::forUser($w['users'][$who])->allows('update', $w['branchA']))->toBe($expected);
})->with('branch update');

it('branch: create and delete are HQ only', function (string $who, bool $expected): void {
    $w = policyWorld();
    expect(Gate::forUser($w['users'][$who])->allows('create', Branch::class))->toBe($expected)
        ->and(Gate::forUser($w['users'][$who])->allows('delete', $w['branchA']))->toBe($expected);
})->with('hq only');

// ---- Audit log ---------------------------------------------------------------------------
it('audit log: only HQ may read it, and nobody may write through a policy', function (string $who, bool $expected): void {
    $w = policyWorld();
    $log = AuditLog::query()->create(['action' => 'test']);
    $user = Gate::forUser($w['users'][$who]);

    expect($user->allows('viewAny', AuditLog::class))->toBe($expected)
        ->and($user->allows('view', $log))->toBe($expected)
        ->and($user->allows('update', $log))->toBeFalse()
        ->and($user->allows('delete', $log))->toBeFalse();
})->with('hq only');

// ---- Query scopes: the same rules applied to lists ---------------------------------------
it('scopes appointment lists to what each role may see', function (): void {
    $w = policyWorld();
    $count = fn (string $who): int => Appointment::query()->visibleTo($w['users'][$who])->count();

    expect($count('hq'))->toBe(3)
        ->and($count('managerA'))->toBe(2)
        ->and($count('deskA'))->toBe(2)
        ->and($count('advisorA'))->toBe(1)
        ->and($count('advisorA2'))->toBe(1)
        ->and($count('managerB'))->toBe(1)
        ->and($count('advisorB'))->toBe(1);
});

it('scopes branch and ticket lists to the staff member\'s branch', function (): void {
    $w = policyWorld();

    expect(Branch::query()->visibleTo($w['users']['hq'])->count())->toBe(2)
        ->and(Branch::query()->visibleTo($w['users']['deskB'])->pluck('id')->all())->toBe([$w['branchB']->id])
        ->and(QueueTicket::query()->visibleTo($w['users']['deskA'])->count())->toBe(1)
        ->and(QueueTicket::query()->visibleTo($w['users']['deskB'])->count())->toBe(0);
});
