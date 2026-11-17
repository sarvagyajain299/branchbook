<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** A customer is visible to branch staff only if they have an appointment at that branch. */
    public function view(User $user, Customer $customer): bool
    {
        if ($user->isHq()) {
            return true;
        }

        return $customer->appointments()->where('branch_id', $user->branch_id)->exists();
    }

    /** Seeing full email/phone. Phase 5 writes every reveal to the audit log. */
    public function revealContactDetails(User $user, Customer $customer): bool
    {
        return $user->role->canRevealContactDetails() && $this->view($user, $customer);
    }
}
