<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\TicketStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\QueueTicket;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Numbers for the admin dashboard, restricted to what the signed-in staff member may see. */
final class DashboardStats
{
    /**
     * One row per visible branch: today's bookings, current queue length, average wait today
     * (minutes, for tickets already called) and no-shows this week.
     *
     * @return list<array{branch: string, name: string, bookings_today: int, queue_length: int, avg_wait_min: float|null, no_shows_week: int}>
     */
    public function forUser(User $user, ?CarbonImmutable $now = null): array
    {
        $tz = (string) config('app.display_timezone');
        $now ??= CarbonImmutable::now();
        $dayStart = $now->setTimezone($tz)->startOfDay();
        $weekStart = $dayStart->startOfWeek();
        $today = $dayStart->toDateString();

        $bookings = Appointment::query()->visibleTo($user)
            ->where('starts_at', '>=', $dayStart->utc())->where('starts_at', '<', $dayStart->addDay()->utc())
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::Expired->value])
            ->selectRaw('branch_id, COUNT(*) AS n')->groupBy('branch_id')->pluck('n', 'branch_id');

        $queue = QueueTicket::query()->visibleTo($user)
            ->where('service_date', $today)->where('status', TicketStatus::Waiting->value)
            ->selectRaw('branch_id, COUNT(*) AS n')->groupBy('branch_id')->pluck('n', 'branch_id');

        $waits = QueueTicket::query()->visibleTo($user)
            ->where('service_date', $today)->whereNotNull('called_at')
            ->selectRaw('branch_id, AVG(TIMESTAMPDIFF(SECOND, issued_at, called_at)) / 60 AS minutes')->groupBy('branch_id')->pluck('minutes', 'branch_id');

        $noShows = Appointment::query()->visibleTo($user)
            ->where('status', AppointmentStatus::NoShow->value)
            ->where('starts_at', '>=', $weekStart->utc())->where('starts_at', '<', $weekStart->addWeek()->utc())
            ->selectRaw('branch_id, COUNT(*) AS n')->groupBy('branch_id')->pluck('n', 'branch_id');

        return Branch::query()->visibleTo($user)->orderBy('name')->get()->map(fn (Branch $b): array => [
            'branch' => $b->code,
            'name' => $b->name,
            'bookings_today' => (int) ($bookings[$b->id] ?? 0),
            'queue_length' => (int) ($queue[$b->id] ?? 0),
            'avg_wait_min' => isset($waits[$b->id]) ? round((float) $waits[$b->id], 1) : null,
            'no_shows_week' => (int) ($noShows[$b->id] ?? 0),
        ])->all();
    }
}
