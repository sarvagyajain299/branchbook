<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

final class CancellationNotAllowed extends DomainException
{
    public static function tooLate(int $hours): self
    {
        return new self("Appointments can only be cancelled online up to {$hours} hours before they start. Please call the branch.");
    }

    public static function notActive(): self
    {
        return new self('This appointment can no longer be cancelled.');
    }
}
