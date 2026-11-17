<?php

declare(strict_types=1);

namespace App\Actions\Queue;

use App\Enums\TicketStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\QueueTicket;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Issues the next ticket number: "A-001", "A-002" ... restarting every day, separately for
 * each branch and each service prefix.
 *
 * Concurrency choice: lock the BRANCH row (SELECT ... FOR UPDATE), then read MAX(sequence)+1.
 * Issuing tickets at one branch is therefore serialised, which is fine at front-desk volume
 * and needs no extra counter table. The unique index on
 * (branch_id, service_date, queue_prefix, sequence) is the safety net, and a collision is
 * retried. The alternative, "insert and retry on a unique violation" alone, works too but
 * wastes work under load; a separate counter row adds a table that can drift from reality.
 */
final class IssueQueueTicket
{
    public function handle(Branch $branch, Service $service, ?Appointment $appointment = null, ?string $walkInName = null, ?CarbonImmutable $now = null): QueueTicket
    {
        $now ??= CarbonImmutable::now();
        // Numbering resets at midnight BRANCH time, not UTC.
        $date = $now->setTimezone((string) config('app.display_timezone'))->toDateString();

        $attempts = 0;

        while (true) {
            try {
                return DB::transaction(fn (): QueueTicket => $this->issue($branch, $service, $appointment, $walkInName, $now, $date));
            } catch (UniqueConstraintViolationException $e) {
                if (++$attempts >= 3) {
                    throw $e;
                }
            }
        }
    }

    private function issue(Branch $branch, Service $service, ?Appointment $appointment, ?string $walkInName, CarbonImmutable $now, string $date): QueueTicket
    {
        // First statement in the transaction: the lock. The MAX() below is the first plain read,
        // so it sees every ticket committed by whoever held the lock before us.
        Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();

        $sequence = (int) QueueTicket::query()
            ->where('branch_id', $branch->id)
            ->where('service_date', $date)
            ->where('queue_prefix', $service->queue_prefix)
            ->max('sequence') + 1;

        $ticket = new QueueTicket([
            'branch_id' => $branch->id,
            'service_date' => $date,
            'service_id' => $service->id,
            'queue_prefix' => $service->queue_prefix,
            'sequence' => $sequence,
            'display_code' => sprintf('%s-%03d', $service->queue_prefix, $sequence),
            'appointment_id' => $appointment?->id,
            'issued_at' => $now,
            'walk_in_name' => $walkInName !== null && trim($walkInName) !== '' ? trim($walkInName) : null,
        ]);
        $ticket->status = TicketStatus::Waiting;
        $ticket->save();

        return $ticket;
    }
}
