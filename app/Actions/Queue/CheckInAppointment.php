<?php

declare(strict_types=1);

namespace App\Actions\Queue;

use App\Enums\AppointmentStatus;
use App\Exceptions\QueueActionNotAllowed;
use App\Models\Appointment;
use App\Models\QueueTicket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Front desk: the customer has arrived. Appointment -> checked_in and a queue ticket is issued. */
final class CheckInAppointment
{
    public function __construct(private readonly IssueQueueTicket $issue) {}

    /** @throws QueueActionNotAllowed */
    public function handle(Appointment $appointment, ?CarbonImmutable $now = null): QueueTicket
    {
        $now ??= CarbonImmutable::now();
        $tz = (string) config('app.display_timezone');

        return DB::transaction(function () use ($appointment, $now, $tz): QueueTicket {
            // Lock the appointment so two desks clicking "check in" together can't both succeed.
            $locked = Appointment::query()->with(['branch', 'service'])->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AppointmentStatus::Confirmed) {
                throw QueueActionNotAllowed::notConfirmed();
            }

            if ($locked->starts_at->setTimezone($tz)->toDateString() !== $now->setTimezone($tz)->toDateString()) {
                throw QueueActionNotAllowed::notToday();
            }

            $locked->transitionTo(AppointmentStatus::CheckedIn);

            return $this->issue->handle($locked->branch, $locked->service, $locked, null, $now);
        });
    }
}
