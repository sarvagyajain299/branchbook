<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Models\Advisor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Picks any free advisor at random. Kept for comparison with the load-balancing default. */
final class RandomStrategy implements AdvisorAssignmentStrategy
{
    public function choose(Collection $candidates, CarbonImmutable $startsAt): ?Advisor
    {
        return $candidates->isEmpty() ? null : $candidates->random();
    }
}
