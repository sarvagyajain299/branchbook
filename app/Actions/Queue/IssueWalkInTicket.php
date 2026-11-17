<?php

declare(strict_types=1);

namespace App\Actions\Queue;

use App\Exceptions\QueueActionNotAllowed;
use App\Models\Branch;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Support\Audit;

/** Front desk: someone without an appointment walks in. */
final class IssueWalkInTicket
{
    public function __construct(private readonly IssueQueueTicket $issue) {}

    /** @throws QueueActionNotAllowed */
    public function handle(Branch $branch, Service $service, ?string $name = null): QueueTicket
    {
        if (! $service->is_active) {
            throw QueueActionNotAllowed::serviceUnavailable();
        }

        $ticket = $this->issue->handle($branch, $service, null, $name);

        // The optional name is personal data: it is NOT written to the audit log.
        Audit::record('ticket.walk_in_issued', $ticket, ['code' => $ticket->display_code]);

        return $ticket;
    }
}
