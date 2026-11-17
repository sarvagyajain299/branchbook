<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\QueueTicket;
use App\Models\User;

class QueueTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, QueueTicket $ticket): bool
    {
        return $user->canAccessBranch($ticket->branch_id);
    }

    /** Issue a walk-in ticket (front desk work). */
    public function issue(User $user, int $branchId): bool
    {
        return in_array($user->role, [Role::HqAdmin, Role::BranchManager, Role::FrontDesk], true)
            && $user->canAccessBranch($branchId);
    }

    /** Open the advisor desk and press "Call next": advisors, at their own branch only. */
    public function callNext(User $user, int $branchId): bool
    {
        return $user->role === Role::Advisor && $user->branch_id === $branchId;
    }

    /** Start / complete / no-show / recall a specific ticket: advisor desk work, at the advisor's own branch. */
    public function operate(User $user, QueueTicket $ticket): bool
    {
        return $user->role === Role::Advisor && $user->branch_id === $ticket->branch_id;
    }
}
