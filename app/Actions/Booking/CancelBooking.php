<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\AppointmentStatus;
use App\Exceptions\CancellationNotAllowed;
use App\Models\Appointment;
use App\Support\Audit;
use Carbon\CarbonImmutable;

final class CancelBooking
{
    /** Customers can cancel online until this many hours before the start. */
    public const CUTOFF_HOURS = 2;

    /** @throws CancellationNotAllowed */
    public function handle(Appointment $appointment, ?string $reason = null, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        if ($appointment->status !== AppointmentStatus::Confirmed) {
            throw CancellationNotAllowed::notActive();
        }

        if ($appointment->starts_at->lessThanOrEqualTo($now->addHours(self::CUTOFF_HOURS))) {
            throw CancellationNotAllowed::tooLate(self::CUTOFF_HOURS);
        }

        $appointment->cancelled_reason = $reason !== null && $reason !== '' ? $reason : 'Cancelled by customer';
        $appointment->transitionTo(AppointmentStatus::Cancelled);

        Audit::record('appointment.cancelled', $appointment, ['by' => 'customer', 'reason' => $appointment->cancelled_reason]);
    }
}
