<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Advisor;
use App\Models\AdvisorShift;
use App\Models\Appointment;
use App\Models\Closure;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportService;
use App\Support\CsvStream;

// ISO week 50 of 2026 is Mon 2026-12-07 to Sun 2026-12-13 (YEARWEEK mode 3 = 202650).
const RANGE_FROM = '2026-12-07';
const RANGE_TO = '2026-12-13';

/** An advisor with exactly the given [weekday => [start, end]] shifts. */
function shiftedAdvisor(object $branch, Service $service, array $shifts, string $name): Advisor
{
    $advisor = Advisor::factory()->state([
        'branch_id' => $branch->id,
        'user_id' => User::factory()->forBranch(Role::Advisor, $branch)->state(['name' => $name]),
    ])->create();
    $advisor->services()->attach($service->id);

    foreach ($shifts as $weekday => [$start, $end]) {
        AdvisorShift::factory()->create(['advisor_id' => $advisor->id, 'weekday' => $weekday, 'starts_at' => $start, 'ends_at' => $end]);
    }

    return $advisor;
}

/** Writes one queue ticket with exact UTC times. $waitMinutes null = never called. */
function ticketRow(object $branch, Service $service, string $issuedUtc, ?int $waitMinutes, ?Appointment $appointment = null): QueueTicket
{
    static $sequence = 0;
    $issued = nowAt($issuedUtc);

    return QueueTicket::factory()->state([
        'branch_id' => $branch->id, 'service_id' => $service->id, 'queue_prefix' => 'Z',
        'service_date' => $issued->setTimezone('Asia/Dubai')->toDateString(),
        'sequence' => ++$sequence, 'display_code' => sprintf('Z-%03d', $sequence),
        'appointment_id' => $appointment?->id,
        'issued_at' => $issued,
        'called_at' => $waitMinutes === null ? null : $issued->addMinutes($waitMinutes),
    ])->create();
}

/**
 * The hand-calculated world used by every test below.
 *
 *  Branch A: advisor X (Mon 08-12 = 240 min, Tue 08-10 = 120 min), advisor Y (Mon 08-12 = 240 min)
 *  Branch B: advisor Z (Mon 08-12 = 240 min), no bookings
 *
 *  X: Mon 09:00 completed, Mon 10:00 completed (60 booked min), Tue 08:00 no-show, Tue 09:00 cancelled
 *  Y: Mon 08:00, 08:30, 09:00 all completed (90 booked min)
 */
beforeEach(function (): void {
    $this->branchA = openBranch();
    $this->branchB = openBranch();
    $this->service = offering(30);

    $this->x = shiftedAdvisor($this->branchA, $this->service, [1 => ['08:00', '12:00'], 2 => ['08:00', '10:00']], 'Xavier');
    $this->y = shiftedAdvisor($this->branchA, $this->service, [1 => ['08:00', '12:00']], 'Yasmin');
    $this->z = shiftedAdvisor($this->branchB, $this->service, [1 => ['08:00', '12:00']], 'Zara');

    bookedFor($this->x, $this->service, MON.' 09:00', AppointmentStatus::Completed);
    bookedFor($this->x, $this->service, MON.' 10:00', AppointmentStatus::Completed);
    bookedFor($this->x, $this->service, '2026-12-08 08:00', AppointmentStatus::NoShow);
    bookedFor($this->x, $this->service, '2026-12-08 09:00', AppointmentStatus::Cancelled);
    $this->y1 = bookedFor($this->y, $this->service, MON.' 08:00', AppointmentStatus::Completed);
    $this->y2 = bookedFor($this->y, $this->service, MON.' 08:30', AppointmentStatus::Completed);
    $this->y3 = bookedFor($this->y, $this->service, MON.' 09:00', AppointmentStatus::Completed);

    $this->all = new ReportFilters(RANGE_FROM, RANGE_TO);
    $this->onlyA = new ReportFilters(RANGE_FROM, RANGE_TO, $this->branchA->id);
    $this->reports = app(ReportService::class);
});

// ---------------------------------------------------------------------------------------------
// Report 1: utilisation with RANK()
// ---------------------------------------------------------------------------------------------

it('computes utilisation and ranks advisors inside each branch', function (): void {
    $rows = collect($this->reports->advisorUtilisation($this->all))->keyBy('advisor');

    // X: 60 / (240 + 120) = 16.7%   Y: 90 / 240 = 37.5%   Z: 0 / 240 = 0%
    expect($rows)->toHaveCount(3)
        ->and((float) $rows['Xavier']['utilisation_pct'])->toBe(16.7)
        ->and((int) $rows['Xavier']['available_minutes'])->toBe(360)
        ->and((int) $rows['Xavier']['booked_minutes'])->toBe(60)
        ->and((float) $rows['Yasmin']['utilisation_pct'])->toBe(37.5)
        ->and((int) $rows['Yasmin']['booked_minutes'])->toBe(90)
        ->and((float) $rows['Zara']['utilisation_pct'])->toBe(0.0)
        ->and((int) $rows['Zara']['booked_minutes'])->toBe(0)
        ->and((int) $rows['Xavier']['iso_week'])->toBe(202650);

    // Ranks restart in each branch: Y beats X in branch A, Z is alone (rank 1) in branch B.
    expect((int) $rows['Yasmin']['branch_rank'])->toBe(1)
        ->and((int) $rows['Xavier']['branch_rank'])->toBe(2)
        ->and((int) $rows['Zara']['branch_rank'])->toBe(1);
});

it('gives tied advisors the same rank', function (): void {
    $twin = shiftedAdvisor($this->branchA, $this->service, [1 => ['08:00', '12:00']], 'Twin');
    foreach (['08:00', '08:30', '09:00'] as $time) {
        bookedFor($twin, $this->service, MON.' '.$time, AppointmentStatus::Completed);
    }   // 90 / 240 = 37.5%, the same as Yasmin

    $ranks = collect($this->reports->advisorUtilisation($this->onlyA))->pluck('branch_rank', 'advisor');

    expect((int) $ranks['Yasmin'])->toBe(1)->and((int) $ranks['Twin'])->toBe(1)->and((int) $ranks['Xavier'])->toBe(3);   // RANK skips 2
});

it('excludes closure days from capacity', function (): void {
    Closure::factory()->create(['branch_id' => null, 'date' => '2026-12-08', 'reason' => 'Holiday']);   // Tuesday

    $x = collect($this->reports->advisorUtilisation($this->onlyA))->firstWhere('advisor', 'Xavier');

    expect((int) $x['available_minutes'])->toBe(240)             // the 120 Tuesday minutes are gone
        ->and((float) $x['utilisation_pct'])->toBe(25.0);        // 60 / 240
});

it('ignores bookings that are not active or completed, and bookings outside the range', function (): void {
    bookedFor($this->y, $this->service, '2026-12-14 09:00', AppointmentStatus::Completed);   // next week
    bookedFor($this->y, $this->service, MON.' 10:00', AppointmentStatus::Cancelled);          // cancelled

    $y = collect($this->reports->advisorUtilisation($this->onlyA))->firstWhere('advisor', 'Yasmin');

    expect((int) $y['booked_minutes'])->toBe(90);
});

it('spans several weeks with one row per advisor and week', function (): void {
    $rows = $this->reports->advisorUtilisation(new ReportFilters('2026-12-07', '2026-12-20', $this->branchA->id));

    expect(collect($rows)->pluck('iso_week')->unique()->map(fn ($w) => (int) $w)->sort()->values()->all())->toBe([202650, 202651])
        ->and($rows)->toHaveCount(4);   // 2 advisors x 2 weeks
});

// ---------------------------------------------------------------------------------------------
// Report 2: no-show rate (raw SQL) and the query-builder twin
// ---------------------------------------------------------------------------------------------

it('computes the no-show rate by branch, service and weekday', function (): void {
    $rows = collect($this->reports->noShowRate($this->onlyA))->keyBy('weekday');

    // Monday: 5 completed, 0 no-shows. Tuesday: 1 no-show, the cancelled booking is not counted.
    expect($rows)->toHaveCount(2)
        ->and((int) $rows[1]['appointments'])->toBe(5)->and((int) $rows[1]['no_shows'])->toBe(0)->and((float) $rows[1]['no_show_pct'])->toBe(0.0)
        ->and((int) $rows[2]['appointments'])->toBe(1)->and((int) $rows[2]['no_shows'])->toBe(1)->and((float) $rows[2]['no_show_pct'])->toBe(100.0)
        ->and($rows[1]['branch'])->toBe($this->branchA->code)->and($rows[1]['service'])->toBe($this->service->code);
});

it('mixes completed and missed appointments into a percentage', function (): void {
    bookedFor($this->y, $this->service, MON.' 11:00', AppointmentStatus::NoShow);   // Monday now 5 completed + 1 no-show

    $monday = collect($this->reports->noShowRate($this->onlyA))->firstWhere('weekday', 1);

    expect((int) $monday['appointments'])->toBe(6)->and((float) $monday['no_show_pct'])->toBe(16.7);   // 1 / 6
});

it('gives identical results from the raw SQL and the query builder', function (): void {
    expect($this->reports->noShowRateWithBuilder($this->all))->toEqual($this->reports->noShowRate($this->all))
        ->and($this->reports->noShowRateWithBuilder($this->onlyA))->toEqual($this->reports->noShowRate($this->onlyA))
        ->and($this->reports->noShowRate($this->all))->not->toBeEmpty();
});

// ---------------------------------------------------------------------------------------------
// Report 3: wait times with the nearest-rank 90th percentile
// ---------------------------------------------------------------------------------------------

it('computes average and 90th percentile wait per hour, split by appointment and walk-in', function (): void {
    // Hour 09 (Dubai) = 05:xx UTC. Appointment tickets wait 5, 10, 20 minutes.
    ticketRow($this->branchA, $this->service, '2026-12-07 05:00:00', 5, $this->y1);
    ticketRow($this->branchA, $this->service, '2026-12-07 05:10:00', 10, $this->y2);
    ticketRow($this->branchA, $this->service, '2026-12-07 05:20:00', 20, $this->y3);
    // Walk-ins wait 2, 4, 6 and 100 minutes (one slow outlier).
    foreach ([2, 4, 6, 100] as $i => $wait) {
        ticketRow($this->branchA, $this->service, '2026-12-07 05:'.(30 + $i * 5).':00', $wait);
    }
    ticketRow($this->branchA, $this->service, '2026-12-07 05:55:00', null);   // never called: excluded
    ticketRow($this->branchA, $this->service, '2026-12-07 07:15:00', 30);     // hour 11, single walk-in

    $rows = collect($this->reports->waitTimes($this->onlyA))->keyBy(fn (array $r): string => $r['hour_of_day'].'-'.$r['kind']);

    // appointments: avg (5+10+20)/3 = 11.7; p90 = value at rank ceil(0.9 * 3) = 3 -> 20
    expect((int) $rows['9-appointment']['tickets'])->toBe(3)
        ->and((float) $rows['9-appointment']['avg_wait_min'])->toBe(11.7)
        ->and((float) $rows['9-appointment']['p90_wait_min'])->toBe(20.0);

    // walk-ins: avg (2+4+6+100)/4 = 28.0; p90 = value at rank ceil(0.9 * 4) = 4 -> 100 (the outlier)
    expect((int) $rows['9-walk_in']['tickets'])->toBe(4)
        ->and((float) $rows['9-walk_in']['avg_wait_min'])->toBe(28.0)
        ->and((float) $rows['9-walk_in']['p90_wait_min'])->toBe(100.0);

    // one ticket: rank ceil(0.9 * 1) = 1, so p90 equals that single wait
    expect((int) $rows['11-walk_in']['tickets'])->toBe(1)->and((float) $rows['11-walk_in']['p90_wait_min'])->toBe(30.0)
        ->and($rows)->toHaveCount(3);
});

it('picks the right nearest-rank value in a larger group', function (): void {
    // 10 waits of 1..10 minutes: rank ceil(0.9 * 10) = 9, so the p90 is 9 (not 10).
    foreach (range(1, 10) as $wait) {
        ticketRow($this->branchA, $this->service, '2026-12-07 06:00:00', $wait);
    }

    $row = collect($this->reports->waitTimes($this->onlyA))->firstWhere('hour_of_day', 10);

    expect((int) $row['tickets'])->toBe(10)->and((float) $row['p90_wait_min'])->toBe(9.0)->and((float) $row['avg_wait_min'])->toBe(5.5);
});

// ---------------------------------------------------------------------------------------------
// Report 4: heatmap, branch filter and the timezone boundary
// ---------------------------------------------------------------------------------------------

it('counts tickets by Dubai weekday and hour, including the midnight boundary', function (): void {
    foreach (range(1, 7) as $i) {
        ticketRow($this->branchA, $this->service, '2026-12-07 05:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00', 3);   // Mon 09:xx
    }
    ticketRow($this->branchA, $this->service, '2026-12-07 05:59:00', null);         // not called, still issued: counts (8 on Mon 09)
    ticketRow($this->branchA, $this->service, '2026-12-07 07:00:00', 1);            // Mon 11
    ticketRow($this->branchA, $this->service, '2026-12-08 06:00:00', 1);            // Tue 10
    ticketRow($this->branchA, $this->service, '2026-12-08 06:30:00', 1);            // Tue 10
    ticketRow($this->branchA, $this->service, '2026-12-06 20:30:00', 1);            // 00:30 Dubai MONDAY: inside the range
    ticketRow($this->branchA, $this->service, '2026-12-06 19:30:00', 1);            // 23:30 Dubai SUNDAY Dec 6: outside the range

    $cells = collect($this->reports->peakHours($this->all))->mapWithKeys(fn (array $r): array => [$r['weekday'].'-'.$r['hour_of_day'] => (int) $r['tickets']]);

    expect($cells['1-9'])->toBe(8)->and($cells['1-11'])->toBe(1)->and($cells['2-10'])->toBe(2)->and($cells['1-0'])->toBe(1)
        ->and($cells->sum())->toBe(12)                      // the Sunday-night ticket is not included
        ->and($cells->has('7-23'))->toBeFalse();
});

it('restricts every report to one branch when asked', function (): void {
    ticketRow($this->branchA, $this->service, '2026-12-07 05:00:00', 5);
    ticketRow($this->branchB, $this->service, '2026-12-07 05:00:00', 50);

    expect(collect($this->reports->advisorUtilisation($this->onlyA))->pluck('branch')->unique()->all())->toBe([$this->branchA->code])
        ->and(collect($this->reports->waitTimes($this->onlyA))->pluck('branch')->unique()->all())->toBe([$this->branchA->code])
        ->and(collect($this->reports->waitTimes($this->all))->pluck('branch')->unique()->all())->toHaveCount(2)
        ->and(collect($this->reports->peakHours($this->onlyA))->sum('tickets'))->toBe(1)
        ->and(collect($this->reports->peakHours($this->all))->sum('tickets'))->toBe(2);
});

it('returns empty results for an empty range without errors', function (): void {
    $empty = new ReportFilters('2030-01-01', '2030-01-07');

    expect($this->reports->noShowRate($empty))->toBe([])->and($this->reports->waitTimes($empty))->toBe([])->and($this->reports->peakHours($empty))->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// The admin page: access control and filters
// ---------------------------------------------------------------------------------------------

it('shows the reports to HQ with all branches and charts data', function (): void {
    ticketRow($this->branchA, $this->service, '2026-12-07 05:00:00', 5);
    $hq = User::factory()->hqAdmin()->create();

    $this->actingAs($hq)->get(route('admin.reports', ['from' => RANGE_FROM, 'to' => RANGE_TO]))
        ->assertOk()
        ->assertSee('Advisor utilisation per week')->assertSee('Xavier')->assertSee('Yasmin')->assertSee('Zara')
        ->assertSee('2026-W50')->assertSee('37.5%')->assertSee('id="report-data"', false)
        ->assertSee('Download CSV');
});

it('confines a branch manager to their own branch and blocks other roles', function (): void {
    $manager = User::factory()->forBranch(Role::BranchManager, $this->branchA)->create();

    $this->actingAs($manager)->get(route('admin.reports', ['from' => RANGE_FROM, 'to' => RANGE_TO]))
        ->assertOk()->assertSee('Xavier')->assertSee('Yasmin')->assertDontSee('Zara')->assertDontSee($this->branchB->code);

    // Asking for another branch explicitly is refused, not silently ignored.
    $this->actingAs($manager)->get(route('admin.reports', ['branch' => $this->branchB->code]))->assertForbidden();
    $this->actingAs($manager)->get(route('admin.reports.csv', ['report' => 'utilisation', 'branch' => $this->branchB->code]))->assertForbidden();

    foreach ([Role::FrontDesk, Role::Advisor] as $role) {
        $staff = User::factory()->forBranch($role, $this->branchA)->create();
        $this->actingAs($staff)->get(route('admin.reports'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.reports.csv', ['report' => 'heatmap']))->assertForbidden();
    }

    auth()->logout();
    $this->get(route('admin.reports'))->assertRedirect(route('login'));
});

it('validates the date filters', function (): void {
    $hq = User::factory()->hqAdmin()->create();

    $this->actingAs($hq)->get(route('admin.reports', ['from' => '07/12/2026']))->assertSessionHasErrors('from');
    $this->actingAs($hq)->get(route('admin.reports', ['from' => '2026-12-10', 'to' => '2026-12-01']))->assertSessionHasErrors('to');
    $this->actingAs($hq)->get(route('admin.reports', ['branch' => 'NOPE']))->assertSessionHasErrors('branch');
    $this->actingAs($hq)->get(route('admin.reports', ['from' => '2024-01-01', 'to' => '2026-12-01']))->assertStatus(422);   // more than a year
});

it('does not let a malicious name break out of the chart data script tag', function (): void {
    $this->x->user->forceFill(['name' => '</script><script>alert(1)</script>'])->save();

    $page = $this->actingAs(User::factory()->hqAdmin()->create())->get(route('admin.reports', ['from' => RANGE_FROM, 'to' => RANGE_TO]))->assertOk()->getContent();

    expect($page)->not->toContain('<script>alert(1)')->not->toContain('</script><script>alert');
});

// ---------------------------------------------------------------------------------------------
// CSV exports
// ---------------------------------------------------------------------------------------------

/** @return list<list<string|null>> the CSV rows, header first */
function csvRows(string $content): array
{
    $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;

    return array_map(fn (string $line): array => str_getcsv($line, ',', '"', '\\'), array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $content)), fn ($l) => $l !== '')));
}

it('streams each report as CSV with the right headers and row counts', function (): void {
    ticketRow($this->branchA, $this->service, '2026-12-07 05:00:00', 5, $this->y1);
    $hq = User::factory()->hqAdmin()->create();
    $query = ['from' => RANGE_FROM, 'to' => RANGE_TO];

    $cases = [
        'utilisation' => [['Branch', 'Advisor', 'ISO week', 'Available minutes', 'Booked minutes', 'Utilisation %', 'Rank in branch'], 3],
        'no-shows' => [['Branch', 'Service', 'Weekday', 'Appointments', 'No-shows', 'No-show %'], 2],
        'wait-times' => [['Branch', 'Hour', 'Kind', 'Tickets', 'Average wait (min)', 'P90 wait (min)'], 1],
        'heatmap' => [['Weekday', 'Hour', 'Tickets issued'], 1],
        'appointments' => [['Reference', 'Branch', 'Service', 'Advisor', 'Date (Dubai)', 'Time (Dubai)', 'Status', 'Channel'], 7],
    ];

    foreach ($cases as $report => [$header, $count]) {
        $response = $this->actingAs($hq)->get(route('admin.reports.csv', ['report' => $report] + $query))->assertOk();
        $rows = csvRows($response->streamedContent());

        expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
            ->and($response->headers->get('Content-Disposition'))->toContain("branchbook-{$report}-".RANGE_FROM.'-to-'.RANGE_TO.'.csv')
            ->and($rows[0])->toBe($header, $report)
            ->and(count($rows) - 1)->toBe($count, "{$report} rows");
    }
});

it('exports correct values in the utilisation CSV', function (): void {
    $response = $this->actingAs(User::factory()->hqAdmin()->create())->get(route('admin.reports.csv', ['report' => 'utilisation', 'from' => RANGE_FROM, 'to' => RANGE_TO, 'branch' => $this->branchA->code]));
    $rows = collect(csvRows($response->streamedContent()))->skip(1)->keyBy(1);

    expect($rows)->toHaveCount(2)
        ->and($rows['Yasmin'])->toBe([$this->branchA->code, 'Yasmin', '2026-W50', '240', '90', '37.5', '1'])
        ->and($rows['Xavier'][5])->toBe('16.7');
});

it('exports only the manager\'s branch and never any contact details', function (): void {
    $manager = User::factory()->forBranch(Role::BranchManager, $this->branchA)->create();
    $this->y1->customer->forceFill(['email' => 'private.person@example.com', 'phone' => '+971509999999'])->save();

    $csv = $this->actingAs($manager)->get(route('admin.reports.csv', ['report' => 'appointments', 'from' => RANGE_FROM, 'to' => RANGE_TO]))->streamedContent();
    $rows = csvRows($csv);

    expect(count($rows) - 1)->toBe(7)   // branch A only; branch B has no appointments anyway, so check the content below
        ->and($csv)->not->toContain('private.person')->not->toContain('971509999999')->not->toContain($this->branchB->code);
});

it('exports appointments in the requested range only', function (): void {
    bookedFor($this->y, $this->service, '2026-12-14 09:00', AppointmentStatus::Confirmed);   // outside the week

    $rows = csvRows($this->actingAs(User::factory()->hqAdmin()->create())->get(route('admin.reports.csv', ['report' => 'appointments', 'from' => RANGE_FROM, 'to' => RANGE_TO]))->streamedContent());

    expect(count($rows) - 1)->toBe(7)->and(array_column($rows, 4))->not->toContain('2026-12-14');
});

it('neutralises spreadsheet formulas in exported text (CSV injection)', function (): void {
    expect(CsvStream::safe('=HYPERLINK("http://evil")'))->toBe("'=HYPERLINK(\"http://evil\")")
        ->and(CsvStream::safe('+1+1'))->toBe("'+1+1")->and(CsvStream::safe('-2'))->toBe("'-2")->and(CsvStream::safe('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(CsvStream::safe('Normal Name'))->toBe('Normal Name')->and(CsvStream::safe(null))->toBe('')->and(CsvStream::safe(37.5))->toBe('37.5');

    $this->x->user->forceFill(['name' => '=cmd|calc'])->save();
    $csv = $this->actingAs(User::factory()->hqAdmin()->create())->get(route('admin.reports.csv', ['report' => 'appointments', 'from' => RANGE_FROM, 'to' => RANGE_TO]))->streamedContent();

    expect($csv)->toContain("'=cmd|calc")->not->toContain(',=cmd|calc');
});

it('rejects an unknown report name', function (): void {
    $this->actingAs(User::factory()->hqAdmin()->create())->get('/admin/reports/export/everything')->assertNotFound();
});
