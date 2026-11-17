<?php

declare(strict_types=1);

namespace App\Exceptions;

use BackedEnum;
use DomainException;

final class InvalidStatusTransition extends DomainException
{
    public static function between(BackedEnum $from, BackedEnum $to): self
    {
        return new self("Cannot change status from '{$from->value}' to '{$to->value}'.");
    }
}
