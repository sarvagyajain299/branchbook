<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportRequest;
use App\Models\Appointment;
use App\Models\Branch;
use App\Services\Reports\ReportService;
use App\Support\CsvStream;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(ReportRequest $request, ReportService $reports): View
    {
        $filters = $request->filters();
        $utilisation = $reports->advisorUtilisation($filters);
        $noShows = $reports->noShowRate($filters);
        $waits = $reports->waitTimes($filters);
        $heatmap = $reports->peakHours($filters);

        return view('admin.reports', [
            'filters' => $filters,
            'branches' => $request->user()->isHq() ? Branch::query()->orderBy('name')->get() : collect(),
            'branchCode' => $request->validated('branch'),
            'utilisation' => $utilisation,
            'noShows' => $noShows,
            'waits' => $waits,
            'heatmap' => $heatmap,
            'maxTickets' => max(1, (int) collect($heatmap)->max('tickets')),
            'chartData' => $this->chartData($utilisation, $noShows, $waits),
            'exportQuery' => array_filter(['from' => $filters->from, 'to' => $filters->to, 'branch' => $request->validated('branch')]),
        ]);
    }

    /** Streamed CSV for one report, using the same filters (and the same branch confinement) as the page. */
    public function csv(ReportRequest $request, ReportService $reports, string $report): StreamedResponse
    {
        $f = $request->filters();
        $name = "branchbook-{$report}-{$f->from}-to-{$f->to}.csv";

        return match ($report) {
            'utilisation' => CsvStream::download($name, ['Branch', 'Advisor', 'ISO week', 'Available minutes', 'Booked minutes', 'Utilisation %', 'Rank in branch'],
                array_map(fn (array $r): array => [$r['branch'], $r['advisor'], $this->weekLabel((int) $r['iso_week']), $r['available_minutes'], $r['booked_minutes'], $r['utilisation_pct'], $r['branch_rank']], $reports->advisorUtilisation($f))),
            'no-shows' => CsvStream::download($name, ['Branch', 'Service', 'Weekday', 'Appointments', 'No-shows', 'No-show %'],
                array_map(fn (array $r): array => [$r['branch'], $r['service'], $this->dayName((int) $r['weekday']), $r['appointments'], $r['no_shows'], $r['no_show_pct']], $reports->noShowRate($f))),
            'wait-times' => CsvStream::download($name, ['Branch', 'Hour', 'Kind', 'Tickets', 'Average wait (min)', 'P90 wait (min)'],
                array_map(fn (array $r): array => [$r['branch'], sprintf('%02d:00', $r['hour_of_day']), $r['kind'], $r['tickets'], $r['avg_wait_min'], $r['p90_wait_min']], $reports->waitTimes($f))),
            'heatmap' => CsvStream::download($name, ['Weekday', 'Hour', 'Tickets issued'],
                array_map(fn (array $r): array => [$this->dayName((int) $r['weekday']), sprintf('%02d:00', $r['hour_of_day']), $r['tickets']], $reports->peakHours($f))),
            'appointments' => CsvStream::download($name, ['Reference', 'Branch', 'Service', 'Advisor', 'Date (Dubai)', 'Time (Dubai)', 'Status', 'Channel'], $this->appointmentRows($request, $f)),
            default => abort(404),
        };
    }

    /**
     * Appointments are exported with lazy(): rows are fetched in chunks of 500 and handed to
     * the CSV writer one by one, so memory stays flat however many appointments exist.
     * Contact details are never exported.
     *
     * @return iterable<array<int, string>>
     */
    private function appointmentRows(ReportRequest $request, \App\Services\Reports\ReportFilters $f): iterable
    {
        $tz = (string) config('app.display_timezone');

        return Appointment::query()->visibleTo($request->user())
            ->where('starts_at', '>=', $f->fromUtc)->where('starts_at', '<', $f->toUtcExclusive)
            ->when($f->branchId !== null, fn ($q) => $q->where('branch_id', $f->branchId))
            ->with(['branch', 'service', 'advisor.user'])
            ->orderBy('starts_at')->orderBy('id')
            ->lazy(500)
            ->map(fn (Appointment $a): array => [
                $a->reference, $a->branch->code, $a->service->code, $a->advisor->user->name,
                $a->starts_at->setTimezone($tz)->format('Y-m-d'), $a->starts_at->setTimezone($tz)->format('H:i'),
                $a->status->value, $a->channel->value,
            ]);
    }

    /**
     * Series for the Chart.js charts. Aggregation here is a plain weighted average, which is
     * valid for averages (unlike percentiles, which are shown only in the table).
     *
     * @param  list<array<string, mixed>>  $utilisation
     * @param  list<array<string, mixed>>  $noShows
     * @param  list<array<string, mixed>>  $waits
     * @return array<string, mixed>
     */
    private function chartData(array $utilisation, array $noShows, array $waits): array
    {
        $byAdvisor = collect($utilisation)->groupBy('advisor')->map(fn ($rows) => round(100 * $rows->sum('booked_minutes') / max(1, $rows->sum('available_minutes')), 1));
        $byWeekday = collect($noShows)->groupBy('weekday')->sortKeys()->map(fn ($rows) => round(100 * $rows->sum('no_shows') / max(1, $rows->sum('appointments')), 1));
        $byHour = collect($waits)->groupBy('hour_of_day')->sortKeys()->map(fn ($rows) => round($rows->sum(fn (array $r): float => (float) $r['avg_wait_min'] * (int) $r['tickets']) / max(1, $rows->sum('tickets')), 1));

        return [
            'utilisation' => ['labels' => $byAdvisor->keys()->all(), 'values' => $byAdvisor->values()->all()],
            'noShows' => ['labels' => $byWeekday->keys()->map(fn ($d): string => $this->dayName((int) $d))->all(), 'values' => $byWeekday->values()->all()],
            'waits' => ['labels' => $byHour->keys()->map(fn ($h): string => sprintf('%02d:00', $h))->all(), 'values' => $byHour->values()->all()],
        ];
    }

    private function dayName(int $isoWeekday): string
    {
        return ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$isoWeekday] ?? '?';
    }

    private function weekLabel(int $yearWeek): string
    {
        return intdiv($yearWeek, 100).'-W'.str_pad((string) ($yearWeek % 100), 2, '0', STR_PAD_LEFT);
    }
}
