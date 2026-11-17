@php
    $weekdays = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
    $hours = range(8, 14);
    $cells = collect($heatmap)->keyBy(fn (array $r): string => $r['weekday'].'-'.$r['hour_of_day']);
    $weekLabel = fn (int $yw): string => intdiv($yw, 100).'-W'.str_pad((string) ($yw % 100), 2, '0', STR_PAD_LEFT);
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Reports</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-8 px-4 sm:px-6 lg:px-8">

            <form method="GET" action="{{ route('admin.reports') }}" class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm">
                <div>
                    <label for="from" class="block text-sm font-medium">From</label>
                    <input id="from" type="date" name="from" value="{{ $filters->from }}" class="mt-1 rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label for="to" class="block text-sm font-medium">To</label>
                    <input id="to" type="date" name="to" value="{{ $filters->to }}" class="mt-1 rounded-md border-gray-300 text-sm">
                </div>
                @if ($branches->isNotEmpty())
                    <div>
                        <label for="branch" class="block text-sm font-medium">Branch</label>
                        <select id="branch" name="branch" class="mt-1 rounded-md border-gray-300 text-sm">
                            <option value="">All branches</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->code }}" @selected($branch->code === $branchCode)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <button class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800">Apply</button>
                @if ($errors->any())
                    <p role="alert" class="text-sm text-red-700">{{ $errors->first() }}</p>
                @endif
            </form>

            <section aria-labelledby="r-util" class="rounded-lg bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 id="r-util" class="text-lg font-semibold">Advisor utilisation per week</h3>
                    <a class="text-sm text-teal-800 underline" href="{{ route('admin.reports.csv', ['report' => 'utilisation'] + $exportQuery) }}">Download CSV</a>
                </div>
                <p class="mt-1 text-sm text-gray-600">Booked minutes divided by shift minutes (holidays excluded, leave not subtracted), ranked within each branch.</p>
                <canvas id="chart-utilisation" class="mt-4 max-h-64" role="img" aria-label="Bar chart of utilisation per advisor; the table below has the same data"></canvas>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Advisor utilisation per week</caption>
                        <thead class="border-b text-gray-500"><tr>
                            <th scope="col" class="py-2 pe-4 text-start">Week</th><th scope="col" class="pe-4 text-start">Branch</th><th scope="col" class="pe-4 text-start">Advisor</th>
                            <th scope="col" class="pe-4 text-end">Available min</th><th scope="col" class="pe-4 text-end">Booked min</th><th scope="col" class="pe-4 text-end">Utilisation</th><th scope="col" class="text-end">Rank</th>
                        </tr></thead>
                        <tbody class="divide-y">
                            @forelse ($utilisation as $row)
                                <tr><td class="py-1.5 pe-4">{{ $weekLabel((int) $row['iso_week']) }}</td><td class="pe-4">{{ $row['branch'] }}</td><td class="pe-4">{{ $row['advisor'] }}</td>
                                    <td class="pe-4 text-end">{{ $row['available_minutes'] }}</td><td class="pe-4 text-end">{{ $row['booked_minutes'] }}</td><td class="pe-4 text-end">{{ $row['utilisation_pct'] }}%</td><td class="text-end">{{ $row['branch_rank'] }}</td></tr>
                            @empty
                                <tr><td colspan="7" class="py-3 text-gray-500">No data for this range.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section aria-labelledby="r-ns" class="rounded-lg bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 id="r-ns" class="text-lg font-semibold">No-show rate by service and weekday</h3>
                    <a class="text-sm text-teal-800 underline" href="{{ route('admin.reports.csv', ['report' => 'no-shows'] + $exportQuery) }}">Download CSV</a>
                </div>
                <p class="mt-1 text-sm text-gray-600">Of appointments that were completed or missed. Cancelled appointments are not counted.</p>
                <canvas id="chart-no-shows" class="mt-4 max-h-64" role="img" aria-label="Bar chart of no-show rate per weekday; the table below has the detail"></canvas>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">No-show rate</caption>
                        <thead class="border-b text-gray-500"><tr>
                            <th scope="col" class="py-2 pe-4 text-start">Branch</th><th scope="col" class="pe-4 text-start">Service</th><th scope="col" class="pe-4 text-start">Weekday</th>
                            <th scope="col" class="pe-4 text-end">Appointments</th><th scope="col" class="pe-4 text-end">No-shows</th><th scope="col" class="text-end">Rate</th>
                        </tr></thead>
                        <tbody class="divide-y">
                            @forelse ($noShows as $row)
                                <tr><td class="py-1.5 pe-4">{{ $row['branch'] }}</td><td class="pe-4">{{ $row['service'] }}</td><td class="pe-4">{{ $weekdays[$row['weekday']] ?? $row['weekday'] }}</td>
                                    <td class="pe-4 text-end">{{ $row['appointments'] }}</td><td class="pe-4 text-end">{{ $row['no_shows'] }}</td><td class="text-end">{{ $row['no_show_pct'] }}%</td></tr>
                            @empty
                                <tr><td colspan="6" class="py-3 text-gray-500">No data for this range.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section aria-labelledby="r-wait" class="rounded-lg bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 id="r-wait" class="text-lg font-semibold">Queue wait times by hour</h3>
                    <a class="text-sm text-teal-800 underline" href="{{ route('admin.reports.csv', ['report' => 'wait-times'] + $exportQuery) }}">Download CSV</a>
                </div>
                <p class="mt-1 text-sm text-gray-600">Time from ticket issued to first call: average and 90th percentile (nearest rank), appointments vs walk-ins.</p>
                <canvas id="chart-waits" class="mt-4 max-h-64" role="img" aria-label="Bar chart of average wait per hour; the table below has averages and percentiles"></canvas>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Wait times</caption>
                        <thead class="border-b text-gray-500"><tr>
                            <th scope="col" class="py-2 pe-4 text-start">Branch</th><th scope="col" class="pe-4 text-start">Hour</th><th scope="col" class="pe-4 text-start">Kind</th>
                            <th scope="col" class="pe-4 text-end">Tickets</th><th scope="col" class="pe-4 text-end">Average (min)</th><th scope="col" class="text-end">P90 (min)</th>
                        </tr></thead>
                        <tbody class="divide-y">
                            @forelse ($waits as $row)
                                <tr><td class="py-1.5 pe-4">{{ $row['branch'] }}</td><td class="pe-4">{{ sprintf('%02d:00', $row['hour_of_day']) }}</td><td class="pe-4">{{ str_replace('_', '-', $row['kind']) }}</td>
                                    <td class="pe-4 text-end">{{ $row['tickets'] }}</td><td class="pe-4 text-end">{{ $row['avg_wait_min'] }}</td><td class="text-end">{{ $row['p90_wait_min'] }}</td></tr>
                            @empty
                                <tr><td colspan="6" class="py-3 text-gray-500">No data for this range.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section aria-labelledby="r-heat" class="rounded-lg bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 id="r-heat" class="text-lg font-semibold">Peak hours (tickets issued)</h3>
                    <span class="space-x-4 text-sm">
                        <a class="text-teal-800 underline" href="{{ route('admin.reports.csv', ['report' => 'heatmap'] + $exportQuery) }}">Download CSV</a>
                        <a class="text-teal-800 underline" href="{{ route('admin.reports.csv', ['report' => 'appointments'] + $exportQuery) }}">Download appointments CSV</a>
                    </span>
                </div>
                <div class="mt-4 overflow-x-auto">
                    <table class="text-sm">
                        <caption class="sr-only">Tickets issued by weekday and hour</caption>
                        <thead><tr><th scope="col" class="p-2"></th>@foreach ($hours as $h)<th scope="col" class="p-2 text-center font-normal text-gray-500">{{ sprintf('%02d:00', $h) }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach ($weekdays as $d => $label)
                                <tr>
                                    <th scope="row" class="p-2 text-start font-normal text-gray-500">{{ $label }}</th>
                                    @foreach ($hours as $h)
                                        @php($n = (int) ($cells[$d.'-'.$h]['tickets'] ?? 0))
                                        @php($alpha = $n === 0 ? 0.04 : round(0.15 + 0.85 * $n / $maxTickets, 2))
                                        <td class="h-10 w-16 text-center" style="background-color: rgba(15, 118, 110, {{ $alpha }}); color: {{ $alpha > 0.55 ? '#fff' : '#111827' }}">{{ $n === 0 ? '' : $n }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <script type="application/json" id="report-data">{!! json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
</x-app-layout>
