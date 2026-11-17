<?php

declare(strict_types=1);

namespace App\Actions\Maintenance;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Carbon\CarbonImmutable;

/** Confirmed appointments still not checked in 30 minutes after their start become no-shows. */
final class MarkNoShows
{
    public const GRACE_MINUTES = 30;

    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $late = Appointment::query()
            ->where('status', AppointmentStatus::Confirmed->value)
            ->where('starts_at', '<=', $now->subMinutes(self::GRACE_MINUTES))
            ->get();

        foreach ($late as $appointment) {
            $appointment->transitionTo(AppointmentStatus::NoShow);
        }

        return $late->count();
    }
}
