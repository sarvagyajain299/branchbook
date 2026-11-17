<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // every staff member can list; the visibleTo() scope narrows the rows
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->canAccessBranch($branch->id);
    }

    public function create(User $user): bool
    {
        return $user->isHq();
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->isHq() || ($user->role === Role::BranchManager && $user->branch_id === $branch->id);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->isHq();
    }
}
