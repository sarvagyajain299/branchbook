<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Queue\CallNextTicket;
use App\Actions\Queue\IssueWalkInTicket;
use App\Actions\Queue\ProgressTicket;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\QueueTicketResource;
use App\Models\Branch;
use App\Models\QueueTicket;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Staff queue operations. Token abilities decide WHAT; policies decide WHERE (which branch). */
class QueueController extends Controller
{
    /** GET /branches/{code}/queue?status=waiting */
    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        Gate::authorize('view', $branch);

        $validated = $request->validate(['status' => ['nullable', 'in:'.implode(',', array_column(TicketStatus::cases(), 'value'))]]);

        $tickets = QueueTicket::query()->visibleTo($request->user())
            ->where('branch_id', $branch->id)
            ->where('service_date', CarbonImmutable::now((string) config('app.display_timezone'))->toDateString())
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->with('service')   // eager load: one extra query, not one per ticket
            ->orderBy('issued_at')->orderBy('id')
            ->paginate(50);

        return QueueTicketResource::collection($tickets);
    }

    /** POST /branches/{code}/queue/walk-ins {"service": "PAO", "name": "optional"} */
    public function walkIn(Request $request, Branch $branch, IssueWalkInTicket $issue): JsonResponse
    {
        Gate::authorize('issue', [QueueTicket::class, $branch->id]);

        $data = $request->validate([
            'service' => ['required', 'string', 'exists:services,code'],
            'name' => ['nullable', 'string', 'max:60'],
        ]);

        $ticket = $issue->handle($branch, Service::query()->where('code', $data['service'])->firstOrFail(), $data['name'] ?? null);

        return (new QueueTicketResource($ticket->load('service')))->response()->setStatusCode(201);
    }

    /** POST /queue/call-next: the authenticated advisor calls their next customer. */
    public function callNext(Request $request, CallNextTicket $callNext): JsonResponse
    {
        Gate::authorize('callNext', [QueueTicket::class, (int) $request->user()->branch_id]);

        $ticket = $callNext->handle($request->user());

        return response()->json([
            'data' => $ticket === null ? null : (new QueueTicketResource($ticket->load('service')))->resolve(),
            'message' => $ticket === null ? 'Nobody is waiting for your services.' : "Calling {$ticket->display_code}.",
        ]);
    }

    /** PATCH /queue-tickets/{id} {"action": "start|complete|no_show|recall"} */
    public function update(Request $request, QueueTicket $ticket, ProgressTicket $progress): QueueTicketResource
    {
        Gate::authorize('operate', $ticket);

        $action = $request->validate(['action' => ['required', 'in:start,complete,no_show,recall']])['action'];
        $user = $request->user();

        match ($action) {
            'start' => $progress->start($ticket, $user),
            'complete' => $progress->complete($ticket, $user),
            'no_show' => $progress->noShow($ticket, $user),
            'recall' => $progress->recall($ticket, $user),
            default => throw new InvalidArgumentException("Unknown action {$action}."),   // unreachable: validated above
        };

        return new QueueTicketResource($ticket->refresh()->load('service'));
    }
}
