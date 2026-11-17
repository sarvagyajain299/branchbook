<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case HqAdmin = 'hq_admin';
    case BranchManager = 'branch_manager';
    case Advisor = 'advisor';
    case FrontDesk = 'front_desk';

    public function label(): string
    {
        return match ($this) {
            self::HqAdmin => 'HQ administrator',
            self::BranchManager => 'Branch manager',
            self::Advisor => 'Advisor',
            self::FrontDesk => 'Front desk',
        };
    }

    /** Roles tied to one branch (everyone except HQ). */
    public function isBranchScoped(): bool
    {
        return $this !== self::HqAdmin;
    }

    /** Whether this role may see a customer's full email and phone. */
    public function canRevealContactDetails(): bool
    {
        return match ($this) {
            self::HqAdmin, self::BranchManager => true,
            self::Advisor, self::FrontDesk => false,
        };
    }
}
