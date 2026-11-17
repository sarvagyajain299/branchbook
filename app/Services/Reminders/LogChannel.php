<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Models\Appointment;
use Illuminate\Support\Facades\Log;

/** Development driver: "sends" by writing a log line. Deliberately logs no personal data. */
final class LogChannel implements ReminderChannel
{
    public function send(Appointment $appointment): void
    {
        Log::info('Appointment reminder sent', [
            'reference' => $appointment->reference,
            'branch' => $appointment->branch->code,
            'starts_at' => $appointment->starts_at->toIso8601String(),
        ]);
    }
}
