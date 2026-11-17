<?php

declare(strict_types=1);

namespace App\Http\Controllers\Branch;

use App\Actions\Queue\CallNextTicket;
use App\Actions\Queue\ProgressTicket;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Exceptions\QueueActionNotAllowed;
use App\Http\Controllers\Controller;
use App\Models\Advisor;
use App\Models\Branch;
use App\Models\QueueTicket;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** An advisor's desk: Call next, Start, Complete, No-show, Recall. */
class AdvisorDeskController extends Controller
{
    public function index(Request $request, Branch $branch): View
    {
        Gate::authorize('callNext', [QueueTicket::class, $branch->id]);

        $advisor = Advisor::query()->where('user_id', $request->user()->id)->firstOrFail();
        $date = CarbonImmutable::now((string) config('app.display_timezone'))->toDateString();
        $serviceIds = DB::table('advisor_service')->where('advisor_id', $advisor->id)->pluck('service_id');

        return view('staff.my-desk', [
            'branch' => $branch,
            'advisor' => $advisor,
            'current' => QueueTicket::query()->where('advisor_id', $advisor->id)->where('service_date', $date)
                ->whereIn('status', [TicketStatus::Called->value, TicketStatus::Serving->value])
                ->with(['service', 'appointment.customer'])->first(),
            'waitingCount' => QueueTicket::query()->visibleTo($request->user())->where('service_date', $date)
                ->where('status', TicketStatus::Waiting->value)->whereIn('service_id', $serviceIds)->count(),
        ]);
    }

    public function callNext(Branch $branch, CallNextTicket $action, Request $request): RedirectResponse
    {
        Gate::authorize('callNext', [QueueTicket::class, $branch->id]);

        try {
            $ticket = $action->handle($request->user());
        } catch (QueueActionNotAllowed $e) {
            return back()->with('error', $e->getMessage());
        }

        return $ticket === null
            ? back()->with('status', 'Nobody is waiting for your services right now.')
            : back()->with('status', "Calling {$ticket->display_code}.");
    }

    public function start(Branch $branch, QueueTicket $ticket, ProgressTicket $action, Request $request): RedirectResponse
    {
        return $this->progress($branch, $ticket, $request, fn () => $action->start($ticket, $request->user()), 'Serving started.');
    }

    public function complete(Branch $branch, QueueTicket $ticket, ProgressTicket $action, Request $request): RedirectResponse
    {
        return $this->progress($branch, $ticket, $request, fn () => $action->complete($ticket, $request->user()), 'Completed.');
    }

    public function noShow(Branch $branch, QueueTicket $ticket, ProgressTicket $action, Request $request): RedirectResponse
    {
        return $this->progress($branch, $ticket, $request, fn () => $action->noShow($ticket, $request->user()), 'Marked as no-show.');
    }

    public function recall(Branch $branch, QueueTicket $ticket, ProgressTicket $action, Request $request): RedirectResponse
    {
        return $this->progress($branch, $ticket, $request, fn () => $action->recall($ticket, $request->user()), 'Announced again.');
    }

    private function progress(Branch $branch, QueueTicket $ticket, Request $request, callable $work, string $message): RedirectResponse
    {
        abort_unless($ticket->branch_id === $branch->id, 404);
        Gate::authorize('operate', $ticket);

        try {
            $work();
        } catch (QueueActionNotAllowed|InvalidStatusTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $message);
    }
}
