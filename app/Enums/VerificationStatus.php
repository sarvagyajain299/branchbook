<?php

declare(strict_types=1);

namespace App\Enums;

enum VerificationStatus: string
{
    case Confirmed = 'confirmed';
    case WrongCode = 'wrong_code';
    case CodeExpired = 'code_expired';
    case LockedOut = 'locked_out';
    case HoldExpired = 'hold_expired';
    case NotPending = 'not_pending';
}
