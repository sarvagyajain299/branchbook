<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        if ($user->role === Role::Advisor) {
            // Advisors only see their own appointments. A query (not $appointment->advisor)
            // so this check never lazy-loads a relation.
            return $user->advisor()->whereKey($appointment->advisor_id)->exists();
        }

        return $user->canAccessBranch($appointment->branch_id);
    }

    /** Book on behalf of a customer. */
    public function create(User $user): bool
    {
        return in_array($user->role, [Role::HqAdmin, Role::BranchManager, Role::FrontDesk], true);
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $this->manage($user, $appointment);
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $this->manage($user, $appointment);
    }

    public function markNoShow(User $user, Appointment $appointment): bool
    {
        return $this->manage($user, $appointment);
    }

    public function checkIn(User $user, Appointment $appointment): bool
    {
        return $this->manage($user, $appointment);
    }

    private function manage(User $user, Appointment $appointment): bool
    {
        return $this->create($user) && $user->canAccessBranch($appointment->branch_id);
    }
}
