<?php

declare(strict_types=1);

use App\Data\Slot;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Advisor;
use App\Models\AdvisorShift;
use App\Models\AdvisorTimeOff;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchHour;
use App\Models\Closure;
use App\Models\Service;
use App\Models\User;
use App\Services\Availability\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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

// ---------------------------------------------------------------------------------------------
// Opening hours
// ---------------------------------------------------------------------------------------------

it('lists every 15-minute start on a normal Monday', function (): void {
    $branch = openBranch();
    $service = offering(30);
    advisorFor($branch, $service);

    $starts = times(slots($branch, $service, MON));

    expect($starts[0])->toBe('08:00')
        ->and(end($starts))->toBe('14:30')   // 14:30 + 30 min = 15:00, the exact closing time
        ->and($starts)->toHaveCount(27)
        ->and($starts)->toContain('08:15', '12:00');
});

it('gives the Friday short day, closing at 12:00', function (): void {
    $branch = openBranch();
    $service = offering(30);
    advisorFor($branch, $service);

    $starts = times(slots($branch, $service, FRI));

    expect($starts[0])->toBe('08:00')
        ->and(end($starts))->toBe('11:30')
        ->and($starts)->toHaveCount(15);
});

it('has no slots on a closed weekend day (no opening hours)', function (): void {
    $branch = openBranch();
    $service = offering();
    advisorFor($branch, $service);

    expect(slots($branch, $service, SAT))->toBe([]);
});

it('has no slots on an all-branch public holiday', function (): void {
    $branch = openBranch();
    $service = offering();
    advisorFor($branch, $service);
    Closure::factory()->create(['branch_id' => null, 'date' => MON, 'reason' => 'National Day']);

    expect(slots($branch, $service, MON))->toBe([]);
});

it('closes only the branch named in a branch-specific closure', function (): void {
    $closed = openBranch();
    $open = openBranch();
    $service = offering();
    advisorFor($closed, $service);
    advisorFor($open, $service);
    Closure::factory()->create(['branch_id' => $closed->id, 'date' => MON, 'reason' => 'Maintenance']);

    expect(slots($closed, $service, MON))->toBe([])
        ->and(slots($open, $service, MON))->not->toBe([]);
});

it('splits the day around a lunch break', function (): void {
    $branch = openBranch(breakStart: '12:00', breakEnd: '13:00');
    $service = offering(30);
    advisorFor($branch, $service);

    $starts = times(slots($branch, $service, MON));

    expect($starts)->toContain('11:30')     // ends exactly when the break starts
        ->and($starts)->not->toContain('11:45', '12:00', '12:30', '12:45')
        ->and($starts)->toContain('13:00');  // first start after the break
});

it('fits the last slot exactly before closing and rejects the one that overruns', function (): void {
    $branch = openBranch();
    $service = offering(duration: 45, buffer: 15); // needs 60 minutes on the calendar
    advisorFor($branch, $service);

    $starts = times(slots($branch, $service, MON));

    expect(end($starts))->toBe('14:00')           // 14:00 + 60 = 15:00 exactly
        ->and($starts)->not->toContain('14:15');  // would end 15:15
});

it('shows the meeting length as the slot end, not the buffer', function (): void {
    $branch = openBranch();
    $service = offering(duration: 45, buffer: 15);
    advisorFor($branch, $service);

    $slot = slots($branch, $service, MON)[0];

    expect(abs($slot->endsAt->diffInMinutes($slot->startsAt)))->toBe(45.0);
});

// ---------------------------------------------------------------------------------------------
// Advisors
// ---------------------------------------------------------------------------------------------

it('has no slots when no advisor is qualified, or the qualified one is inactive', function (): void {
    $branch = openBranch();
    $service = offering();
    advisorFor($branch, $service, qualified: false);
    advisorFor($branch, $service, active: false);

    expect(slots($branch, $service, MON))->toBe([]);
});

it('ignores qualified advisors who work at another branch', function (): void {
    $branch = openBranch();
    $service = offering();
    advisorFor(openBranch(), $service);

    expect(slots($branch, $service, MON))->toBe([]);
});

it('removes slots while the only advisor is on leave', function (): void {
    $branch = openBranch();
    $service = offering(30);
    $advisor = advisorFor($branch, $service);
    AdvisorTimeOff::factory()->create(['advisor_id' => $advisor->id, 'starts_at' => dubai(MON.' 10:00'), 'ends_at' => dubai(MON.' 12:00'), 'reason' => 'Training']);

    $starts = times(slots($branch, $service, MON));

    expect($starts)->toContain('09:30')                 // ends at 10:00, just before leave
        ->and($starts)->not->toContain('09:45', '10:00', '11:45')
        ->and($starts)->toContain('12:00');
});

it('has no slots when the advisor is on leave all day', function (): void {
    $branch = openBranch();
    $service = offering();
    $advisor = advisorFor($branch, $service);
    AdvisorTimeOff::factory()->create(['advisor_id' => $advisor->id, 'starts_at' => dubai(MON.' 00:00'), 'ends_at' => dubai('2026-12-08 00:00')]);

    expect(slots($branch, $service, MON))->toBe([]);
});

it('only offers slots inside the advisor shift', function (): void {
    $branch = openBranch();
    $service = offering(30);
    $advisor = advisorFor($branch, $service);
    AdvisorShift::query()->where('advisor_id', $advisor->id)->where('weekday', 1)->update(['starts_at' => '10:00', 'ends_at' => '13:00']);

    $starts = times(slots($branch, $service, MON));

    expect($starts[0])->toBe('10:00')->and(end($starts))->toBe('12:30');
});

it('counts the free advisors per slot', function (): void {
    $branch = openBranch();
    $service = offering(30);
    $one = advisorFor($branch, $service);
    advisorFor($branch, $service);
    bookedFor($one, $service, MON.' 09:00');

    $byTime = collect(slots($branch, $service, MON))->keyBy(fn (Slot $s) => $s->startsAt->setTimezone('Asia/Dubai')->format('H:i'));

    expect($byTime['08:00']->freeAdvisors)->toBe(2)
        ->and($byTime['09:00']->freeAdvisors)->toBe(1)   // one advisor is with a customer
        ->and($byTime['09:00']->advisorIds)->not->toContain($one->id);
});

// ---------------------------------------------------------------------------------------------
// Existing bookings
// ---------------------------------------------------------------------------------------------

it('blocks overlapping slots when bookings have different lengths', function (): void {
    $branch = openBranch();
    $short = offering(30);
    $long = offering(60);
    $advisor = advisorFor($branch, $short);
    $advisor->services()->attach($long->id);
    bookedFor($advisor, $long, MON.' 09:00'); // 09:00-10:00

    $starts = times(slots($branch, $short, MON));

    expect($starts)->toContain('08:30')                       // ends 09:00, touching is fine
        ->and($starts)->not->toContain('08:45', '09:00', '09:45')
        ->and($starts)->toContain('10:00');
});

it('also blocks the buffer that follows an existing appointment', function (): void {
    $branch = openBranch();
    $short = offering(30);
    $withBuffer = offering(duration: 60, buffer: 15);
    $advisor = advisorFor($branch, $short);
    $advisor->services()->attach($withBuffer->id);
    bookedFor($advisor, $withBuffer, MON.' 09:00'); // meeting to 10:00, wrap-up to 10:15

    $starts = times(slots($branch, $short, MON));

    expect($starts)->not->toContain('10:00')->and($starts)->toContain('10:15');
});

it('keeps a slot free for a cancelled, expired, no-show or completed appointment', function (AppointmentStatus $status): void {
    $branch = openBranch();
    $service = offering(30);
    $advisor = advisorFor($branch, $service);
    bookedFor($advisor, $service, MON.' 09:00', $status);

    expect(times(slots($branch, $service, MON)))->toContain('09:00');
})->with([AppointmentStatus::Cancelled, AppointmentStatus::Expired, AppointmentStatus::NoShow, AppointmentStatus::Completed]);

it('blocks the slot for held, confirmed, checked-in and in-service appointments', function (AppointmentStatus $status): void {
    $branch = openBranch();
    $service = offering(30);
    $advisor = advisorFor($branch, $service);
    bookedFor($advisor, $service, MON.' 09:00', $status, holdExpires: nowAt()->addMinutes(5));

    expect(times(slots($branch, $service, MON)))->not->toContain('09:00');
})->with([AppointmentStatus::Held, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InService]);

it('treats an unexpired hold as busy and a lapsed hold as free', function (): void {
    $branch = openBranch();
    $service = offering(30);
    $advisor = advisorFor($branch, $service);
    bookedFor($advisor, $service, MON.' 09:00', AppointmentStatus::Held, holdExpires: nowAt()->addMinutes(5));  // still valid
    bookedFor($advisor, $service, MON.' 11:00', AppointmentStatus::Held, holdExpires: nowAt()->subMinute());    // lapsed, job hasn't run yet

    $starts = times(slots($branch, $service, MON));

    expect($starts)->not->toContain('09:00')->and($starts)->toContain('11:00');
});

// ---------------------------------------------------------------------------------------------
// Notice and horizon
// ---------------------------------------------------------------------------------------------

it('applies the minimum notice, including the one-minute-past boundary', function (): void {
    $branch = openBranch();
    $service = offering(30, notice: 2);
    advisorFor($branch, $service);

    // Now = Mon 08:00 Dubai (04:00 UTC); earliest bookable = 10:00 exactly.
    $onTheDot = times(slots($branch, $service, MON, nowAt('2026-12-07 04:00:00')));
    expect($onTheDot[0])->toBe('10:00');

    // One minute later the 10:00 slot is only 1h59 away, so the first slot is 10:15.
    $oneMinuteLate = times(slots($branch, $service, MON, nowAt('2026-12-07 04:01:00')));
    expect($oneMinuteLate[0])->toBe('10:15');
});

it('offers nothing for a day in the past', function (): void {
    $branch = openBranch();
    $service = offering();
    advisorFor($branch, $service);

    expect(slots($branch, $service, '2026-11-30'))->toBe([]);
});

it('respects max_days_ahead: the last allowed day works, the next one does not', function (): void {
    $branch = openBranch();
    $service = offering(30, maxDays: 6);   // from Tue Dec 1, +6 days = Mon Dec 7
    advisorFor($branch, $service);

    expect(slots($branch, $service, MON))->not->toBe([])
        ->and(slots($branch, $service, '2026-12-08'))->toBe([]);
});

it('offers nothing for an inactive service or branch', function (): void {
    $branch = openBranch();
    $service = offering();
    advisorFor($branch, $service);

    $service->update(['is_active' => false]);
    expect(slots($branch, $service, MON))->toBe([]);

    $service->update(['is_active' => true]);
    $branch->update(['is_active' => false]);
    expect(slots($branch, $service, MON))->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// Range, exact-time check, query count
// ---------------------------------------------------------------------------------------------

it('returns a keyed week with empty days included', function (): void {
    $branch = openBranch();
    $service = offering();
    advisorFor($branch, $service);

    $week = app(AvailabilityService::class)->slotsForRange($branch, $service, MON, 7, nowAt());

    expect(array_keys($week))->toBe(['2026-12-07', '2026-12-08', '2026-12-09', '2026-12-10', '2026-12-11', '2026-12-12', '2026-12-13'])
        ->and($week['2026-12-07'])->not->toBe([])
        ->and($week['2026-12-12'])->toBe([])   // Saturday
        ->and($week['2026-12-13'])->toBe([]);  // Sunday
});

it('answers "who is free at exactly this time" without using the cache', function (): void {
    $branch = openBranch();
    $service = offering(30);
    $busy = advisorFor($branch, $service);
    $free = advisorFor($branch, $service);
    bookedFor($busy, $service, MON.' 09:00');

    $ids = app(AvailabilityService::class)->freeAdvisorsAt($branch, $service, dubai(MON.' 09:00'), nowAt())->pluck('id')->all();

    expect($ids)->toBe([$free->id]);
    expect(app(AvailabilityService::class)->freeAdvisorsAt($branch, $service, dubai(MON.' 06:00'), nowAt()))->toBeEmpty()   // before opening
        ->and(app(AvailabilityService::class)->freeAdvisorsAt($branch, $service, dubai(MON.' 14:45'), nowAt()))->toBeEmpty(); // overruns closing
});

it('uses a fixed number of queries however many advisors there are (no N+1)', function (): void {
    $branch = openBranch();
    $service = offering(30);
    foreach (range(1, 6) as $i) {
        $advisor = advisorFor($branch, $service);
        bookedFor($advisor, $service, MON.' 09:00');
    }

    DB::enableQueryLog();
    slots($branch, $service, MON);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(8);
});

// ---------------------------------------------------------------------------------------------
// Caching
// ---------------------------------------------------------------------------------------------

it('caches availability, and a booking change clears it immediately', function (): void {
    $branch = openBranch();
    $service = offering(30);
    $advisor = advisorFor($branch, $service);

    expect(times(slots($branch, $service, MON)))->toContain('09:00');   // computed and cached

    // A change the cache is not told about stays invisible: proof that it IS cached.
    AdvisorTimeOff::factory()->create(['advisor_id' => $advisor->id, 'starts_at' => dubai(MON.' 00:00'), 'ends_at' => dubai('2026-12-08 00:00')]);
    expect(times(slots($branch, $service, MON)))->toContain('09:00');

    // Saving an appointment fires AppointmentChanged, whose listener bumps the cache version.
    $other = advisorFor($branch, $service);
    bookedFor($other, $service, MON.' 09:00');

    // Fresh result: the first advisor's leave now applies too, so only $other is left, and
    // $other is busy at 09:00.
    $byTime = collect(slots($branch, $service, MON))->keyBy(fn (Slot $s) => $s->startsAt->setTimezone('Asia/Dubai')->format('H:i'));
    expect($byTime->has('09:00'))->toBeFalse()
        ->and($byTime['08:00']->advisorIds)->toBe([$other->id]);
});

it('frees the old slot and blocks the new one straight after a reschedule', function (): void {
    $branch = openBranch();
    $service = offering(30);
    $advisor = advisorFor($branch, $service);
    $appointment = bookedFor($advisor, $service, MON.' 09:00');

    expect(times(slots($branch, $service, MON)))->not->toContain('09:00');
    expect(times(slots($branch, $service, '2026-12-08')))->toContain('09:00');

    $appointment->update(['starts_at' => dubai('2026-12-08 09:00'), 'ends_at' => dubai('2026-12-08 09:30')]);

    expect(times(slots($branch, $service, MON)))->toContain('09:00')                  // old day cleared
        ->and(times(slots($branch, $service, '2026-12-08')))->not->toContain('09:00'); // new day cleared
});

it('clears the cache when an appointment is cancelled or deleted', function (): void {
    $branch = openBranch();
    $service = offering(30);
    $advisor = advisorFor($branch, $service);
    $appointment = bookedFor($advisor, $service, MON.' 09:00');

    expect(times(slots($branch, $service, MON)))->not->toContain('09:00');

    $appointment->transitionTo(AppointmentStatus::Cancelled);
    expect(times(slots($branch, $service, MON)))->toContain('09:00');

    $replacement = bookedFor($advisor, $service, MON.' 09:00');
    expect(times(slots($branch, $service, MON)))->not->toContain('09:00');

    $replacement->delete();
    expect(times(slots($branch, $service, MON)))->toContain('09:00');
});
