<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AppointmentStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Appointment;
use App\Models\User;
use App\Support\Audit;

/**
 * What staff may do to an appointment from the admin panel. Unlike a customer, staff can cancel
 * at any time before the visit, and must say it was them (audit log). The state machine on the
 * model still decides which moves are legal.
 */
final class StaffAppointmentActions
{
    /** @throws InvalidStatusTransition */
    public function cancel(Appointment $appointment, User $staff, ?string $reason = null): void
    {
        $appointment->cancelled_reason = $reason !== null && $reason !== '' ? $reason : 'Cancelled by branch staff';
        $appointment->transitionTo(AppointmentStatus::Cancelled);

        Audit::record('appointment.cancelled', $appointment, ['by' => 'staff', 'reason' => $appointment->cancelled_reason], $staff);
    }

    /** @throws InvalidStatusTransition */
    public function markNoShow(Appointment $appointment, User $staff): void
    {
        $from = $appointment->status;
        $appointment->transitionTo(AppointmentStatus::NoShow);

        Audit::record('appointment.no_show', $appointment, ['by' => 'staff', 'status' => [$from->value, 'no_show']], $staff);
    }
}
