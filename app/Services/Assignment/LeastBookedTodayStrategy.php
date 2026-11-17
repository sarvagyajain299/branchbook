<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Models\Advisor;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Load balancing: the candidate with the fewest active appointments that day. Ties: lowest id. */
final class LeastBookedTodayStrategy implements AdvisorAssignmentStrategy
{
    public function choose(Collection $candidates, CarbonImmutable $startsAt): ?Advisor
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $dayStart = $startsAt->setTimezone((string) config('app.display_timezone'))->startOfDay();

        $counts = Appointment::query()->active()
            ->whereIn('advisor_id', $candidates->pluck('id'))
            ->where('starts_at', '>=', $dayStart->utc())
            ->where('starts_at', '<', $dayStart->addDay()->utc())
            ->selectRaw('advisor_id, COUNT(*) AS booked')
            ->groupBy('advisor_id')
            ->pluck('booked', 'advisor_id');

        return $candidates
            ->sort(fn (Advisor $a, Advisor $b): int => [(int) ($counts[$a->id] ?? 0), $a->id] <=> [(int) ($counts[$b->id] ?? 0), $b->id])
            ->first();
    }
}
