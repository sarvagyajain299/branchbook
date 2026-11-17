<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

final class SlotUnavailable extends DomainException
{
    public function __construct()
    {
        parent::__construct('That time was just taken by someone else. Please choose another time.');
    }
}
