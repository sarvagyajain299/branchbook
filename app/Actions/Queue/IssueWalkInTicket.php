<?php

declare(strict_types=1);

namespace App\Actions\Queue;

use App\Exceptions\QueueActionNotAllowed;
use App\Models\Branch;
use App\Models\QueueTicket;
use App\Models\Service;

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

        return $this->issue->handle($branch, $service, null, $name);
    }
}
