<?php

declare(strict_types=1);

namespace App\Actions\Maintenance;

use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use Carbon\CarbonImmutable;

/** Queues a reminder job for every confirmed appointment starting within the next 24 hours. */
final class DispatchDueReminders
{
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $ids = Appointment::query()
            ->where('status', AppointmentStatus::Confirmed->value)
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addHours(24))
            ->pluck('id');

        foreach ($ids as $id) {
            SendAppointmentReminder::dispatch((int) $id);
        }

        return $ids->count();
    }
}
