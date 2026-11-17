<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Models\Appointment;

/**
 * How a reminder reaches the customer. The default LogChannel only writes to the log.
 *
 * To add a real SMS/WhatsApp provider: write a class implementing this interface that calls
 * the provider's HTTP API (the appointment arrives with customer, branch and service loaded),
 * then bind it in AppServiceProvider instead of LogChannel. No other code changes.
 */
interface ReminderChannel
{
    public function send(Appointment $appointment): void;
}
