<?php

declare(strict_types=1);

namespace App\Actions\Queue;

use App\Enums\AppointmentStatus;
use App\Enums\TicketStatus;
use App\Exceptions\QueueActionNotAllowed;
use App\Models\Advisor;
use App\Models\QueueTicket;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The rest of an advisor's desk: start, complete, no-show and recall. Each stamps its own timestamp. */
final class ProgressTicket
{
    /** Called -> serving (the customer sat down). The appointment becomes in_service. */
    public function start(QueueTicket $ticket, User $user, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        $this->within($ticket, $user, 'ticket.started', function (QueueTicket $t) use ($now): void {
            $t->served_at = $now;
            $t->transitionTo(TicketStatus::Serving);
            $this->moveAppointment($t, AppointmentStatus::CheckedIn, AppointmentStatus::InService);
        });
    }

    /** Serving -> completed. The appointment becomes completed. */
    public function complete(QueueTicket $ticket, User $user, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        $this->within($ticket, $user, 'ticket.completed', function (QueueTicket $t) use ($now): void {
            $t->completed_at = $now;
            $t->transitionTo(TicketStatus::Completed);
            $this->moveAppointment($t, AppointmentStatus::InService, AppointmentStatus::Completed);
        });
    }

    /** Called -> no-show (the customer never came to the desk). The appointment becomes no_show. */
    public function noShow(QueueTicket $ticket, User $user): void
    {
        $this->within($ticket, $user, 'ticket.no_show', function (QueueTicket $t): void {
            $t->transitionTo(TicketStatus::NoShow);
            $this->moveAppointment($t, AppointmentStatus::CheckedIn, AppointmentStatus::NoShow);
        });
    }

    /** Announce a called ticket again. Status and called_at are unchanged; the display re-chimes. */
    public function recall(QueueTicket $ticket, User $user, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        $this->within($ticket, $user, 'ticket.recalled', function (QueueTicket $t) use ($now): void {
            if ($t->status !== TicketStatus::Called) {
                throw QueueActionNotAllowed::notYourTicket();
            }

            $t->recalled_at = $now;
            $t->save();
        });
    }

    private function within(QueueTicket $ticket, User $user, string $action, callable $work): void
    {
        DB::transaction(function () use ($ticket, $user, $action, $work): void {
            $locked = QueueTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            $mine = Advisor::query()->where('user_id', $user->id)->whereKey($locked->advisor_id)->exists();
            if (! $mine) {
                throw QueueActionNotAllowed::notYourTicket();
            }

            $work($locked);

            Audit::record($action, $locked, ['code' => $locked->display_code, 'status' => $locked->status->value], $user);
        });
    }

    private function moveAppointment(QueueTicket $ticket, AppointmentStatus $from, AppointmentStatus $to): void
    {
        $appointment = $ticket->appointment_id === null ? null : $ticket->appointment()->first();

        if ($appointment !== null && $appointment->status === $from) {
            $appointment->transitionTo($to);
        }
    }
}
