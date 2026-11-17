<?php

declare(strict_types=1);

namespace App\Actions\Maintenance;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Carbon\CarbonImmutable;

/** Turns unverified holds whose 10 minutes have passed into "expired", freeing their slot. */
final class ExpireStaleHolds
{
    public function handle(?CarbonImmutable $now = null): int
    {
        return $this->expire(Appointment::query(), $now ?? CarbonImmutable::now());
    }

    /** Used by the booking action so a lapsed hold can't block its own advisor's unique slot index. */
    public function forAdvisor(int $advisorId, CarbonImmutable $now): int
    {
        return $this->expire(Appointment::query()->where('advisor_id', $advisorId), $now);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<Appointment>  $query */
    private function expire($query, CarbonImmutable $now): int
    {
        $stale = $query->where('status', AppointmentStatus::Held->value)
            ->where('hold_expires_at', '<=', $now)
            ->get();

        foreach ($stale as $appointment) {
            $appointment->transitionTo(AppointmentStatus::Expired);
        }

        return $stale->count();
    }
}
