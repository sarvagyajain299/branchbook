<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Data\Slot;
use App\Enums\AppointmentStatus;
use App\Models\Advisor;
use App\Models\Branch;
use App\Models\BranchHour;
use App\Models\Closure;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Works out which time slots a customer can book. Pure domain logic: no HTTP, no session.
 *
 * Everything below is computed with UNIX timestamps (absolute instants), so daylight saving
 * and timezone conversions can't creep into the comparisons. Local wall-clock times (opening
 * hours, shifts) are converted to instants once, using the branch's display timezone.
 *
 * Algorithm for one branch, service and local date:
 *   1. Closed? (branch-specific or all-branch closure) -> no slots.
 *   2. Opening window(s) from branch_hours for that weekday, split around the break.
 *   3. Candidate advisors: active, at this branch, qualified for the service.
 *   4. Walk a 15-minute grid; a slot needs duration + buffer minutes and must fit inside one window.
 *   5. An advisor is free if the slot is inside one of their shifts and overlaps neither leave
 *      nor an active appointment (an appointment also occupies its own buffer after it).
 *   6. Then min_notice_hours and max_days_ahead are applied.
 */
final class AvailabilityService
{
    private const GRID_SECONDS = 15 * 60;

    public function __construct(private readonly AvailabilityCache $cache) {}

    /**
     * Bookable slots for one local calendar date.
     *
     * @param  CarbonImmutable|string  $date  "Y-m-d" (a branch-local date) or an instant
     * @param  CarbonImmutable|null  $now  injectable clock, for tests
     * @return list<Slot>
     */
    public function slotsForDate(Branch $branch, Service $service, CarbonImmutable|string $date, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $day = $this->localDay($date);

        if (! $branch->is_active || ! $service->is_active || ! $this->isBookableDay($service, $day, $now)) {
            return [];
        }

        // The cached part doesn't depend on "now" (except for stale holds), so min-notice is
        // applied afterwards and a cached result never lets a too-soon slot through.
        $raw = $this->cache->remember(
            $branch->id,
            $service->id,
            $day->toDateString(),
            fn (): array => $this->computeRaw($branch, $service, $day, $now),
        );

        $earliest = $now->addHours($service->min_notice_hours)->getTimestamp();

        return array_values(array_map(
            Slot::fromRaw(...),
            array_filter($raw, static fn (array $slot): bool => $slot['start'] >= $earliest),
        ));
    }

    /**
     * Slots for several consecutive days, keyed by local date. Empty days are included.
     *
     * @return array<string, list<Slot>>
     */
    public function slotsForRange(Branch $branch, Service $service, CarbonImmutable|string $from, int $days = 7, ?CarbonImmutable $now = null): array
    {
        $start = $this->localDay($from);
        $result = [];

        for ($i = 0; $i < min($days, 31); $i++) {
            $date = $start->addDays($i)->toDateString();
            $result[$date] = $this->slotsForDate($branch, $service, $date, $now);
        }

        return $result;
    }

    /**
     * Advisors who can take this service at exactly this start time, computed fresh (never
     * cached). The booking action calls this inside its transaction, after locking.
     *
     * @return Collection<int, Advisor>
     */
    public function freeAdvisorsAt(Branch $branch, Service $service, CarbonImmutable $startsAt, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $day = $this->localDay($startsAt);
        $start = $startsAt->getTimestamp();
        $end = $start + $service->slotMinutes() * 60;

        if (! $branch->is_active || ! $service->is_active
            || ! $this->isBookableDay($service, $day, $now)
            || $start < $now->addHours($service->min_notice_hours)->getTimestamp()
            || ! $this->fitsAWindow($this->windows($branch, $day), $start, $end)) {
            return new Collection;
        }

        return collect($this->prepareAdvisors($branch, $service, $day, $now))
            ->filter(fn (array $a): bool => $this->isFree($a, $start, $end))
            ->map(static fn (array $a): Advisor => $a['advisor'])
            ->values();
    }

    /** @return list<array{start: int, end: int, advisors: list<int>}> */
    private function computeRaw(Branch $branch, Service $service, CarbonImmutable $day, CarbonImmutable $now): array
    {
        $windows = $this->windows($branch, $day);

        if ($windows === []) {
            return [];
        }

        $advisors = $this->prepareAdvisors($branch, $service, $day, $now);
        $length = $service->slotMinutes() * 60;
        $slots = [];

        foreach ($windows as [$windowStart, $windowEnd]) {
            // Round up to the 15-minute grid. All UAE offsets are whole quarter-hours, so
            // this lines up with the wall clock.
            $first = (int) (ceil($windowStart / self::GRID_SECONDS) * self::GRID_SECONDS);

            for ($start = $first; $start + $length <= $windowEnd; $start += self::GRID_SECONDS) {
                $free = [];
                foreach ($advisors as $a) {
                    if ($this->isFree($a, $start, $start + $length)) {
                        $free[] = $a['advisor']->id;
                    }
                }

                if ($free !== []) {
                    // A slot is shown with the meeting length; the buffer is just reserved time after it.
                    $slots[] = ['start' => $start, 'end' => $start + $service->duration_minutes * 60, 'advisors' => $free];
                }
            }
        }

        return $slots;
    }

    /**
     * Opening windows for the day as [startTs, endTs] pairs, split around the break.
     * Empty when the branch is closed (closure, or no hours for that weekday).
     *
     * @return list<array{int, int}>
     */
    private function windows(Branch $branch, CarbonImmutable $day): array
    {
        $closed = Closure::query()
            ->where('date', $day->toDateString())
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->exists();

        if ($closed) {
            return [];
        }

        $hours = BranchHour::query()
            ->where('branch_id', $branch->id)
            ->where('weekday', $day->dayOfWeekIso)
            ->first();

        if ($hours === null) {
            return [];
        }

        $opens = $this->at($day, $hours->opens_at);
        $closes = $this->at($day, $hours->closes_at);

        if ($hours->break_start !== null && $hours->break_end !== null) {
            return [
                [$opens, $this->at($day, $hours->break_start)],
                [$this->at($day, $hours->break_end), $closes],
            ];
        }

        return [[$opens, $closes]];
    }

    /**
     * Loads every candidate advisor with the day's shifts, leave and appointments in a fixed
     * number of queries (eager loading, so no N+1).
     *
     * @return list<array{advisor: Advisor, shifts: list<array{int, int}>, busy: list<array{int, int}>}>
     */
    private function prepareAdvisors(Branch $branch, Service $service, CarbonImmutable $day, CarbonImmutable $now): array
    {
        $dayStart = $day->utc();
        $dayEnd = $day->addDay()->utc();

        $advisors = Advisor::query()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->whereHas('services', fn ($q) => $q->whereKey($service->id))
            ->with([
                'shifts' => fn ($q) => $q->where('weekday', $day->dayOfWeekIso),
                'timeOff' => fn ($q) => $q->where('starts_at', '<', $dayEnd)->where('ends_at', '>', $dayStart),
                'appointments' => fn ($q) => $q->active()
                    ->where('starts_at', '<', $dayEnd)->where('ends_at', '>', $dayStart)
                    ->with('service'),
            ])
            ->orderBy('id')
            ->get();

        $prepared = [];

        foreach ($advisors as $advisor) {
            $shifts = [];
            foreach ($advisor->shifts as $shift) {
                $shifts[] = [$this->at($day, $shift->starts_at), $this->at($day, $shift->ends_at)];
            }

            $busy = [];
            foreach ($advisor->timeOff as $leave) {
                $busy[] = [$leave->starts_at->getTimestamp(), $leave->ends_at->getTimestamp()];
            }
            foreach ($advisor->appointments as $appointment) {
                // A hold nobody verified in time no longer blocks the slot, even if the
                // scheduler hasn't marked it expired yet.
                if ($appointment->status === AppointmentStatus::Held
                    && $appointment->hold_expires_at !== null
                    && $appointment->hold_expires_at->lessThanOrEqualTo($now)) {
                    continue;
                }

                // The appointment occupies its meeting time plus its own wrap-up buffer.
                $busy[] = [
                    $appointment->starts_at->getTimestamp(),
                    $appointment->ends_at->getTimestamp() + $appointment->service->buffer_minutes * 60,
                ];
            }

            $prepared[] = ['advisor' => $advisor, 'shifts' => $shifts, 'busy' => $busy];
        }

        return $prepared;
    }

    /** @param array{advisor: Advisor, shifts: list<array{int, int}>, busy: list<array{int, int}>} $advisor */
    private function isFree(array $advisor, int $start, int $end): bool
    {
        $onShift = false;
        foreach ($advisor['shifts'] as [$shiftStart, $shiftEnd]) {
            if ($shiftStart <= $start && $end <= $shiftEnd) {
                $onShift = true;
                break;
            }
        }

        if (! $onShift) {
            return false;
        }

        foreach ($advisor['busy'] as [$busyStart, $busyEnd]) {
            // Two intervals overlap when each starts before the other ends.
            if ($busyStart < $end && $busyEnd > $start) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{int, int}> $windows */
    private function fitsAWindow(array $windows, int $start, int $end): bool
    {
        foreach ($windows as [$windowStart, $windowEnd]) {
            if ($windowStart <= $start && $end <= $windowEnd) {
                return true;
            }
        }

        return false;
    }

    /** Today (branch-local) up to today + max_days_ahead. */
    private function isBookableDay(Service $service, CarbonImmutable $day, CarbonImmutable $now): bool
    {
        $today = $now->setTimezone($this->timezone())->startOfDay();

        return $day->greaterThanOrEqualTo($today) && $day->lessThanOrEqualTo($today->addDays($service->max_days_ahead));
    }

    /** Midnight at the start of the branch-local calendar day. */
    private function localDay(CarbonImmutable|string $date): CarbonImmutable
    {
        if ($date instanceof CarbonImmutable) {
            return $date->setTimezone($this->timezone())->startOfDay();
        }

        return CarbonImmutable::parse($date, $this->timezone())->startOfDay();
    }

    /** Converts a "HH:MM[:SS]" wall-clock time on a local day into an absolute timestamp. */
    private function at(CarbonImmutable $day, string $time): int
    {
        return $day->setTimeFromTimeString($time)->getTimestamp();
    }

    private function timezone(): string
    {
        return (string) config('app.display_timezone');
    }
}
