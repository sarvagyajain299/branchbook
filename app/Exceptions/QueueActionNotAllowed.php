<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** A queue action that is valid in general but not right now (the message is safe to show to staff). */
final class QueueActionNotAllowed extends DomainException
{
    public static function notToday(): self
    {
        return new self('Only appointments booked for today can be checked in.');
    }

    public static function notConfirmed(): self
    {
        return new self('This appointment is not waiting to be checked in (it may already be checked in or cancelled).');
    }

    public static function advisorBusy(): self
    {
        return new self('Finish or mark the current customer before calling the next one.');
    }

    public static function notYourTicket(): self
    {
        return new self('That ticket was called by another advisor.');
    }

    public static function serviceUnavailable(): self
    {
        return new self('That service is not available.');
    }
}
