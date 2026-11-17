<?php

declare(strict_types=1);

namespace App\Enums;

enum OtpResult: string
{
    case Verified = 'verified';
    case Wrong = 'wrong';
    case Expired = 'expired';
    case LockedOut = 'locked_out';
    case Missing = 'missing';
}
