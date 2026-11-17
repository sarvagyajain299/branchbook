<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AppointmentChanged;
use App\Services\Availability\AvailabilityCache;

/** Laravel discovers this listener from the type-hint on handle(). */
final class ForgetAvailabilityCache
{
    public function __construct(private readonly AvailabilityCache $cache) {}

    public function handle(AppointmentChanged $event): void
    {
        foreach (array_unique($event->dates) as $date) {
            $this->cache->forget($event->branchId, $date);
        }
    }
}
