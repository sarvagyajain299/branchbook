<?php

declare(strict_types=1);

use App\Data\Slot;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Advisor;
use App\Models\AdvisorShift;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchHour;
use App\Models\Service;
use App\Models\User;
use App\Services\Availability\AvailabilityService;
use Carbon\CarbonImmutable;

// Shared scenario builders for the availability and booking tests. Loaded from tests/Pest.php.

// Fixed calendar used by every test (all Dubai local dates):
//   Mon 2026-12-07, Fri 2026-12-11, Sat 2026-12-12.
// "Now" is Tue 2026-12-01 10:00 Dubai (06:00 UTC), so Dec 7 is 6 days ahead.
const MON = '2026-12-07';
const FRI = '2026-12-11';
const SAT = '2026-12-12';

function nowAt(string $utc = '2026-12-01 06:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse($utc, 'UTC');
}

/** A Dubai wall-clock time as a UTC instant. */
function dubai(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Dubai')->utc();
}

/** @param  list<Slot>  $slots  @return list<string> start times as Dubai "H:i" */
function times(array $slots): array
{
    return array_map(fn (Slot $s): string => $s->startsAt->setTimezone('Asia/Dubai')->format('H:i'), $slots);
}

/** Branch open Mon-Thu 08:00-15:00 and Fri 08:00-12:00, like the real UAE banking week. */
function openBranch(?string $breakStart = null, ?string $breakEnd = null): Branch
{
    $branch = Branch::factory()->create();

    foreach ([1, 2, 3, 4] as $weekday) {
        BranchHour::factory()->create(['branch_id' => $branch->id, 'weekday' => $weekday, 'opens_at' => '08:00', 'closes_at' => '15:00', 'break_start' => $breakStart, 'break_end' => $breakEnd]);
    }
    BranchHour::factory()->create(['branch_id' => $branch->id, 'weekday' => 5, 'opens_at' => '08:00', 'closes_at' => '12:00']);

    return $branch;
}

function offering(int $duration = 30, int $buffer = 0, int $notice = 0, int $maxDays = 60): Service
{
    return Service::factory()->create([
        'duration_minutes' => $duration, 'buffer_minutes' => $buffer,
        'min_notice_hours' => $notice, 'max_days_ahead' => $maxDays,
    ]);
}

/** An advisor qualified for $service, on shift 08:00-15:00 Mon-Thu and 08:00-12:00 Fri. */
function advisorFor(Branch $branch, Service $service, bool $qualified = true, bool $active = true): Advisor
{
    $advisor = Advisor::factory()->state([
        'branch_id' => $branch->id,
        'user_id' => User::factory()->forBranch(Role::Advisor, $branch),
        'is_active' => $active,
    ])->create();

    if ($qualified) {
        $advisor->services()->attach($service->id);
    }

    foreach ([1, 2, 3, 4] as $weekday) {
        AdvisorShift::factory()->create(['advisor_id' => $advisor->id, 'weekday' => $weekday, 'starts_at' => '08:00', 'ends_at' => '15:00']);
    }
    AdvisorShift::factory()->create(['advisor_id' => $advisor->id, 'weekday' => 5, 'starts_at' => '08:00', 'ends_at' => '12:00']);

    return $advisor;
}

/** A booking for $advisor starting at a Dubai local time, lasting the service's duration. */
function bookedFor(Advisor $advisor, Service $service, string $localStart, AppointmentStatus $status = AppointmentStatus::Confirmed, ?CarbonImmutable $holdExpires = null): Appointment
{
    $start = dubai($localStart);

    return Appointment::factory()->state([
        'branch_id' => $advisor->branch_id, 'advisor_id' => $advisor->id, 'service_id' => $service->id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes($service->duration_minutes),
        'status' => $status, 'hold_expires_at' => $holdExpires,
    ])->create();
}

function slots(Branch $branch, Service $service, string $date, ?CarbonImmutable $now = null): array
{
    return app(AvailabilityService::class)->slotsForDate($branch, $service, $date, $now ?? nowAt());
}
