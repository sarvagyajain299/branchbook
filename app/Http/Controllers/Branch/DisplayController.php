<?php

declare(strict_types=1);

namespace App\Http\Controllers\Branch;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\QueueTicket;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * The waiting-room screen. Public on purpose (a TV has no login) and therefore shows ONLY
 * ticket codes and desk labels: no names, no appointment details.
 */
class DisplayController extends Controller
{
    public function show(Branch $branch): View
    {
        abort_unless($branch->is_active, 404);

        return view('display.show', ['branch' => $branch]);
    }

    public function state(Branch $branch): JsonResponse
    {
        abort_unless($branch->is_active, 404);

        $date = CarbonImmutable::now((string) config('app.display_timezone'))->toDateString();

        $serving = QueueTicket::query()
            ->where('branch_id', $branch->id)->where('service_date', $date)
            ->whereIn('status', [TicketStatus::Called->value, TicketStatus::Serving->value])
            ->orderByRaw('COALESCE(recalled_at, called_at) DESC')
            ->limit(6)->get()
            ->map(fn (QueueTicket $t): array => [
                'code' => $t->display_code,
                'desk' => $t->desk_label,
                // Changes on every call AND every recall, so the screen knows when to chime.
                'key' => $t->id.'-'.($t->recalled_at ?? $t->called_at)?->getTimestamp(),
            ])->values();

        $next = QueueTicket::query()
            ->where('branch_id', $branch->id)->where('service_date', $date)
            ->where('status', TicketStatus::Waiting->value)
            ->orderBy('issued_at')->orderBy('id')->limit(6)->pluck('display_code');

        return response()->json(['serving' => $serving, 'next' => $next])
            ->header('Cache-Control', 'no-store');
    }
}
