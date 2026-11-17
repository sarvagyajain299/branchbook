<?php

declare(strict_types=1);

namespace App\Http\Controllers\Branch;

use App\Actions\Queue\CheckInAppointment;
use App\Actions\Queue\IssueWalkInTicket;
use App\Enums\TicketStatus;
use App\Exceptions\QueueActionNotAllowed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\WalkInRequest;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\QueueTicket;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** The front desk screen: today's appointments with check-in buttons, and the walk-in form. */
class FrontDeskController extends Controller
{
    public function index(Request $request, Branch $branch): View
    {
        Gate::authorize('issue', [QueueTicket::class, $branch->id]);

        $tz = (string) config('app.display_timezone');
        $dayStart = CarbonImmutable::now($tz)->startOfDay();

        return view('staff.desk', [
            'branch' => $branch,
            // visibleTo() is the list-level guard; the Gate above is the record-level one.
            'appointments' => Appointment::query()->visibleTo($request->user())
                ->where('branch_id', $branch->id)
                ->where('starts_at', '>=', $dayStart->utc())->where('starts_at', '<', $dayStart->addDay()->utc())
                ->with(['customer', 'service', 'advisor.user', 'queueTicket'])
                ->orderBy('starts_at')->get(),
            'waiting' => QueueTicket::query()->visibleTo($request->user())
                ->where('branch_id', $branch->id)->where('service_date', $dayStart->toDateString())
                ->where('status', TicketStatus::Waiting->value)
                ->with('service')->orderBy('issued_at')->get(),
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(),
            'tz' => $tz,
        ]);
    }

    public function checkIn(Branch $branch, Appointment $appointment, CheckInAppointment $action): RedirectResponse
    {
        Gate::authorize('issue', [QueueTicket::class, $branch->id]);
        abort_unless($appointment->branch_id === $branch->id, 404);
        Gate::authorize('checkIn', $appointment);

        try {
            $ticket = $action->handle($appointment);
        } catch (QueueActionNotAllowed $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Checked in. Ticket {$ticket->display_code}.");
    }

    public function walkIn(WalkInRequest $request, Branch $branch, IssueWalkInTicket $action): RedirectResponse
    {
        Gate::authorize('issue', [QueueTicket::class, $branch->id]);

        try {
            $ticket = $action->handle($branch, Service::query()->findOrFail($request->validated('service_id')), $request->validated('name'));
        } catch (QueueActionNotAllowed $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Walk-in ticket {$ticket->display_code} issued.");
    }
}
