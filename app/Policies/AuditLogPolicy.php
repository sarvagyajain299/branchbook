<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    /** Audit logs are read-only, and only HQ may read them. */
    public function viewAny(User $user): bool
    {
        return $user->isHq();
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $user->isHq();
    }

    public function create(User $user): bool
    {
        return false; // written by the system, never by a user action
    }

    public function update(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $log): bool
    {
        return false;
    }
}
