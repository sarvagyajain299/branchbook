<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\Reminders\ReminderChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Idempotent: the job first CLAIMS the reminder with one atomic UPDATE ... WHERE
 * reminder_sent_at IS NULL. Only the run that changes a row sends; a duplicate or retried
 * job sees 0 rows changed and does nothing. If sending fails, the claim is released so the
 * queue's retry can try again.
 */
final class SendAppointmentReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $appointmentId) {}

    public function handle(ReminderChannel $channel): void
    {
        $claimed = Appointment::query()
            ->whereKey($this->appointmentId)
            ->where('status', AppointmentStatus::Confirmed->value)
            ->whereNull('reminder_sent_at')
            ->update(['reminder_sent_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $appointment = Appointment::query()->with(['customer', 'branch', 'service'])->findOrFail($this->appointmentId);

        try {
            $channel->send($appointment);
        } catch (Throwable $e) {
            Appointment::query()->whereKey($this->appointmentId)->update(['reminder_sent_at' => null]);

            throw $e;
        }
    }
}
