<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Advisor;
use App\Models\AdvisorShift;
use App\Models\AdvisorTimeOff;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchHour;
use App\Models\Closure;
use App\Models\Customer;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\LazyLoadingViolationException;

it('links a branch to its advisors, hours, closures, appointments and tickets', function (): void {
    $branch = Branch::factory()->create();
    $advisor = Advisor::factory()->create(['branch_id' => $branch->id]);
    BranchHour::factory()->create(['branch_id' => $branch->id, 'weekday' => 2]);
    Closure::factory()->create(['branch_id' => $branch->id]);
    $appointment = Appointment::factory()->create(['branch_id' => $branch->id, 'advisor_id' => $advisor->id]);
    QueueTicket::factory()->create(['branch_id' => $branch->id, 'appointment_id' => $appointment->id]);

    $loaded = Branch::query()->with(['advisors', 'hours', 'closures', 'appointments', 'queueTickets'])->findOrFail($branch->id);

    expect($loaded->advisors)->toHaveCount(1)
        ->and($loaded->hours)->toHaveCount(1)
        ->and($loaded->closures)->toHaveCount(1)
        ->and($loaded->appointments)->toHaveCount(1)
        ->and($loaded->queueTickets)->toHaveCount(1);
});

it('links an advisor to its user, branch, skills, shifts, time off and appointments', function (): void {
    $advisor = Advisor::factory()->create();
    $services = Service::factory()->count(2)->create();
    $advisor->services()->attach($services->pluck('id'));
    AdvisorShift::factory()->create(['advisor_id' => $advisor->id]);
    AdvisorTimeOff::factory()->create(['advisor_id' => $advisor->id]);
    Appointment::factory()->create(['advisor_id' => $advisor->id]);

    $loaded = Advisor::query()->with(['user', 'branch', 'services', 'shifts', 'timeOff', 'appointments'])->findOrFail($advisor->id);

    expect($loaded->user)->toBeInstanceOf(User::class)
        ->and($loaded->user->role)->toBe(Role::Advisor)
        ->and($loaded->branch)->toBeInstanceOf(Branch::class)
        ->and($loaded->services)->toHaveCount(2)
        ->and($loaded->shifts)->toHaveCount(1)
        ->and($loaded->timeOff)->toHaveCount(1)
        ->and($loaded->appointments)->toHaveCount(1);
});

it('links an appointment to customer, branch, service, advisor and its queue ticket', function (): void {
    $appointment = Appointment::factory()->create();
    QueueTicket::factory()->create(['appointment_id' => $appointment->id]);

    $loaded = Appointment::query()->with(['customer', 'branch', 'service', 'advisor', 'queueTicket'])->findOrFail($appointment->id);

    expect($loaded->customer)->toBeInstanceOf(Customer::class)
        ->and($loaded->branch)->toBeInstanceOf(Branch::class)
        ->and($loaded->service)->toBeInstanceOf(Service::class)
        ->and($loaded->advisor)->toBeInstanceOf(Advisor::class)
        ->and($loaded->queueTicket)->toBeInstanceOf(QueueTicket::class);
});

it('keeps the original appointment when one is rescheduled', function (): void {
    $original = Appointment::factory()->create();
    $replacement = Appointment::factory()->create(['rescheduled_from_id' => $original->id]);

    expect($replacement->load('rescheduledFrom')->rescheduledFrom?->is($original))->toBeTrue();
});

it('links a user to a branch and an advisor profile', function (): void {
    $advisor = Advisor::factory()->create();

    $user = User::query()->with(['branch', 'advisor'])->findOrFail($advisor->user_id);

    expect($user->advisor?->is($advisor))->toBeTrue();
});

it('makes N+1 queries fail loudly: lazy loading a relation throws outside production', function (): void {
    Appointment::factory()->count(2)->create();

    // Laravel flags lazy loading on models fetched as a collection of 2+, the N+1 situation:
    // a loop over a list that runs one extra query per row.
    $appointments = Appointment::query()->get();

    expect(fn () => $appointments->first()?->customer)->toThrow(LazyLoadingViolationException::class);

    // The fix: eager load with with(), which runs one query for all customers.
    $eager = Appointment::query()->with('customer')->get();
    expect($eager->first()?->customer)->toBeInstanceOf(Customer::class);
});

it('computes the minutes a slot needs from duration plus buffer', function (): void {
    $service = Service::factory()->create(['duration_minutes' => 45, 'buffer_minutes' => 15]);

    expect($service->slotMinutes())->toBe(60);
});

it('casts service documents to an array and appointment times to immutable dates', function (): void {
    $service = Service::factory()->create(['required_documents' => ['Passport', 'Visa']]);
    $appointment = Appointment::factory()->create();

    expect($service->fresh()->required_documents)->toBe(['Passport', 'Visa'])
        ->and($appointment->fresh()->starts_at)->toBeInstanceOf(Carbon\CarbonImmutable::class);
});
