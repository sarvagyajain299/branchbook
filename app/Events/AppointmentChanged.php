<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Fired whenever an appointment is created, changed or deleted: its slot availability is stale. */
final class AppointmentChanged
{
    use Dispatchable;

    /** @param list<string> $dates branch-local dates ("Y-m-d") whose availability is now stale */
    public function __construct(
        public readonly int $branchId,
        public readonly array $dates,
    ) {}
}
