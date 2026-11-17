<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

final class RescheduleNotAllowed extends DomainException
{
    public static function tooLate(int $hours): self
    {
        return new self("Appointments can only be rescheduled online up to {$hours} hours before they start. Please call the branch.");
    }

    public static function notActive(): self
    {
        return new self('This appointment can no longer be rescheduled.');
    }

    public static function sameTime(): self
    {
        return new self('That is already your appointment time. Please choose a different time.');
    }
}
