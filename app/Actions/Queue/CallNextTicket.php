<?php

declare(strict_types=1);

namespace App\Actions\Queue;

use App\Enums\TicketStatus;
use App\Exceptions\QueueActionNotAllowed;
use App\Models\Advisor;
use App\Models\QueueTicket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Call next" at an advisor's desk.
 *
 * Which ticket? Among TODAY's waiting tickets at the advisor's branch for services the advisor
 * is qualified for:
 *   1. appointment tickets whose appointment time has arrived, earliest appointment first;
 *   2. otherwise walk-ins, first come first served.
 *
 * Concurrency: everything runs in one transaction whose first statement is a row lock. The
 * chosen ticket is locked individually with FOR UPDATE SKIP LOCKED (see lockFirst()). If two
 * advisors press the button at the same instant, the first locks the best ticket and the
 * second SKIPS that locked row and gets the next best, so the same customer is never called
 * twice and nobody is told "nobody is waiting" while customers wait.
 */
final class CallNextTicket
{
    /** @throws QueueActionNotAllowed */
    public function handle(User $user, ?CarbonImmutable $now = null): ?QueueTicket
    {
        $now ??= CarbonImmutable::now();
        $date = $now->setTimezone((string) config('app.display_timezone'))->toDateString();

        return DB::transaction(function () use ($user, $now, $date): ?QueueTicket {
            // Also serialises one advisor double-clicking "Call next".
            $advisor = Advisor::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            $busy = QueueTicket::query()
                ->where('advisor_id', $advisor->id)->where('service_date', $date)
                ->whereIn('status', [TicketStatus::Called->value, TicketStatus::Serving->value])
                ->exists();

            if ($busy) {
                throw QueueActionNotAllowed::advisorBusy();
            }

            $serviceIds = DB::table('advisor_service')->where('advisor_id', $advisor->id)->pluck('service_id')->all();

            $ticket = $this->nextAppointmentTicket($advisor, $date, $serviceIds, $now)
                ?? $this->nextWalkIn($advisor, $date, $serviceIds);

            if ($ticket === null) {
                return null;
            }

            $ticket->advisor_id = $advisor->id;
            $ticket->desk_label = $advisor->desk_label;
            $ticket->called_at = $now;
            $ticket->transitionTo(TicketStatus::Called);   // validates waiting -> called, then saves

            return $ticket;
        });
    }

    /** @param  list<int|string>  $serviceIds */
    private function nextAppointmentTicket(Advisor $advisor, string $date, array $serviceIds, CarbonImmutable $now): ?QueueTicket
    {
        return $this->lockFirst($this->waiting($advisor, $date, $serviceIds)
            ->join('appointments', 'appointments.id', '=', 'queue_tickets.appointment_id')
            ->where('appointments.starts_at', '<=', $now)      // their appointment time has arrived
            ->orderBy('appointments.starts_at')
            ->orderBy('queue_tickets.id'));
    }

    /** @param  list<int|string>  $serviceIds */
    private function nextWalkIn(Advisor $advisor, string $date, array $serviceIds): ?QueueTicket
    {
        return $this->lockFirst($this->waiting($advisor, $date, $serviceIds)
            ->whereNull('queue_tickets.appointment_id')
            ->orderBy('queue_tickets.issued_at')
            ->orderBy('queue_tickets.id'));
    }

    /**
     * Locks the best candidate that nobody else has locked.
     *
     * Why not simply "ORDER BY ... LIMIT 1 FOR UPDATE SKIP LOCKED"? MySQL locks EVERY row it
     * scans for such a query, not just the one returned (the sort and LIMIT happen after the
     * scan). The first advisor would lock the whole queue and the others would find nothing,
     * even with customers waiting. A real parallel test (scripts/concurrency-check.sh) caught
     * exactly that. So: pick candidate ids with a plain read, then lock ONE row at a time by
     * primary key, skipping rows another advisor already holds.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<QueueTicket>  $candidates  already ordered
     */
    private function lockFirst($candidates): ?QueueTicket
    {
        foreach ($candidates->limit(25)->pluck('queue_tickets.id') as $id) {
            $ticket = QueueTicket::query()
                ->whereKey($id)
                ->where('status', TicketStatus::Waiting->value)   // re-checked on the latest data
                ->lock('for update skip locked')
                ->first();

            if ($ticket !== null) {
                return $ticket;
            }
        }

        return null;
    }

    /**
     * @param  list<int|string>  $serviceIds
     * @return \Illuminate\Database\Eloquent\Builder<QueueTicket>
     */
    private function waiting(Advisor $advisor, string $date, array $serviceIds)
    {
        return QueueTicket::query()
            ->select('queue_tickets.*')
            ->where('queue_tickets.branch_id', $advisor->branch_id)
            ->where('queue_tickets.service_date', $date)
            ->where('queue_tickets.status', TicketStatus::Waiting->value)
            ->whereIn('queue_tickets.service_id', $serviceIds);
    }
}
