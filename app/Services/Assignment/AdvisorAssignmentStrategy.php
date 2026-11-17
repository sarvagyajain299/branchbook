<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Models\Advisor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Strategy pattern: how to pick ONE advisor when several are free for a slot.
 * The booking action depends on this interface, never on a concrete class, so the policy
 * can be swapped in config (or replaced by a fake in a test) without touching booking code.
 */
interface AdvisorAssignmentStrategy
{
    /**
     * @param  Collection<int, Advisor>  $candidates  advisors who are all free for the slot
     * @param  CarbonImmutable  $startsAt  the slot start (used to find "that day")
     */
    public function choose(Collection $candidates, CarbonImmutable $startsAt): ?Advisor;
}
