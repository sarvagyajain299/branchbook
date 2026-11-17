<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Reporting queries, written as raw SQL with bound parameters on purpose: they use window
 * functions and CTEs that the query builder expresses badly, and reading the SQL shows exactly
 * what the database does. noShowRateWithBuilder() repeats one report with the query builder
 * so the two styles can be compared (see docs/INTERVIEW_NOTES.md).
 *
 * Conventions used by every query below:
 *  - timestamps are stored in UTC; CONVERT_TZ(x, '+00:00', '+04:00') turns them into Dubai
 *    wall-clock time. A fixed offset is used because the UAE has no daylight saving and it
 *    avoids needing MySQL's timezone tables;
 *  - named parameters are never reused inside one statement (PDO's native prepared statements
 *    reject a repeated name), so each use has its own name;
 *  - the optional branch filter is written "(:branch_x IS NULL OR column = :branch_y)".
 */
final class ReportService
{
    /**
     * Advisor utilisation per ISO week = booked minutes / shift minutes, ranked inside each branch.
     *
     * - `days` is a recursive CTE producing one row per calendar day in the range;
     * - `capacity` joins each day to the advisors' weekly shifts (weekday match) and drops
     *   closure days, then sums minutes per advisor and week;
     * - `booked` sums the length of active or completed appointments per advisor and week;
     * - the final SELECT joins them and ranks advisors with RANK() OVER (PARTITION BY branch, week).
     * Limitation: advisor leave (time off) is not subtracted from capacity.
     *
     * @return list<array<string, mixed>>
     */
    public function advisorUtilisation(ReportFilters $f): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE days AS (
                SELECT CAST(:range_start AS DATE) AS d
                UNION ALL
                SELECT d + INTERVAL 1 DAY FROM days WHERE d < :range_end
            ),
            capacity AS (
                SELECT a.id AS advisor_id,
                       a.branch_id,
                       YEARWEEK(days.d, 3) AS iso_week,
                       SUM(TIME_TO_SEC(TIMEDIFF(s.ends_at, s.starts_at)) / 60) AS available_minutes
                FROM days
                JOIN advisor_shifts s ON s.weekday = WEEKDAY(days.d) + 1          -- MySQL: Monday = 0
                JOIN advisors a ON a.id = s.advisor_id AND a.is_active = 1
                LEFT JOIN closures c ON c.date = days.d AND (c.branch_id IS NULL OR c.branch_id = a.branch_id)
                WHERE c.id IS NULL                                                  -- skip holidays
                  AND (:branch_a IS NULL OR a.branch_id = :branch_b)
                GROUP BY a.id, a.branch_id, YEARWEEK(days.d, 3)
            ),
            booked AS (
                SELECT ap.advisor_id,
                       YEARWEEK(CONVERT_TZ(ap.starts_at, '+00:00', '+04:00'), 3) AS iso_week,
                       SUM(TIMESTAMPDIFF(MINUTE, ap.starts_at, ap.ends_at)) AS booked_minutes
                FROM appointments ap
                WHERE ap.status IN ('confirmed', 'checked_in', 'in_service', 'completed')
                  AND ap.starts_at >= :utc_start AND ap.starts_at < :utc_end
                GROUP BY ap.advisor_id, YEARWEEK(CONVERT_TZ(ap.starts_at, '+00:00', '+04:00'), 3)
            )
            SELECT c.branch_id,
                   b.code AS branch,
                   c.advisor_id,
                   u.name AS advisor,
                   c.iso_week,
                   ROUND(c.available_minutes) AS available_minutes,
                   COALESCE(bk.booked_minutes, 0) AS booked_minutes,
                   ROUND(100 * COALESCE(bk.booked_minutes, 0) / c.available_minutes, 1) AS utilisation_pct,
                   RANK() OVER (
                       PARTITION BY c.branch_id, c.iso_week
                       ORDER BY COALESCE(bk.booked_minutes, 0) / c.available_minutes DESC
                   ) AS branch_rank
            FROM capacity c
            JOIN advisors a ON a.id = c.advisor_id
            JOIN users u ON u.id = a.user_id
            JOIN branches b ON b.id = c.branch_id
            LEFT JOIN booked bk ON bk.advisor_id = c.advisor_id AND bk.iso_week = c.iso_week
            ORDER BY c.iso_week, b.code, branch_rank, u.name
        SQL, [
            'range_start' => $f->from, 'range_end' => $f->to,
            'branch_a' => $f->branchId, 'branch_b' => $f->branchId,
            'utc_start' => $f->fromUtc, 'utc_end' => $f->toUtcExclusive,
        ]);

        return $this->toArrays($rows);
    }

    /**
     * No-show rate by branch x service x weekday, over appointments that reached an outcome
     * (completed or no_show). Cancelled and future appointments are excluded: they are not a
     * "customer didn't turn up" signal.
     *
     * @return list<array<string, mixed>>
     */
    public function noShowRate(ReportFilters $f): array
    {
        return $this->toArrays(DB::select(<<<'SQL'
            SELECT b.code AS branch,
                   s.code AS service,
                   WEEKDAY(CONVERT_TZ(ap.starts_at, '+00:00', '+04:00')) + 1 AS weekday,
                   COUNT(*) AS appointments,
                   SUM(ap.status = 'no_show') AS no_shows,
                   ROUND(100 * SUM(ap.status = 'no_show') / COUNT(*), 1) AS no_show_pct
            FROM appointments ap
            JOIN branches b ON b.id = ap.branch_id
            JOIN services s ON s.id = ap.service_id
            WHERE ap.status IN ('completed', 'no_show')
              AND ap.starts_at >= :utc_start AND ap.starts_at < :utc_end
              AND (:branch_a IS NULL OR ap.branch_id = :branch_b)
            GROUP BY b.code, s.code, weekday          -- group by the alias: ONLY_FULL_GROUP_BY can't match "WEEKDAY(x) + 1" to "WEEKDAY(x)"
            ORDER BY b.code, s.code, weekday
        SQL, [
            'utc_start' => $f->fromUtc, 'utc_end' => $f->toUtcExclusive,
            'branch_a' => $f->branchId, 'branch_b' => $f->branchId,
        ]));
    }

    /**
     * The same report with the query builder, for comparison. Same rows, same order; the raw
     * version keeps the SQL readable as one block, the builder version needs selectRaw and
     * groupByRaw for every expression anyway.
     *
     * @return list<array<string, mixed>>
     */
    public function noShowRateWithBuilder(ReportFilters $f): array
    {
        $local = "CONVERT_TZ(appointments.starts_at, '+00:00', '+04:00')";

        return $this->toArrays(
            DB::table('appointments')
                ->join('branches', 'branches.id', '=', 'appointments.branch_id')
                ->join('services', 'services.id', '=', 'appointments.service_id')
                ->whereIn('appointments.status', ['completed', 'no_show'])
                ->where('appointments.starts_at', '>=', $f->fromUtc)
                ->where('appointments.starts_at', '<', $f->toUtcExclusive)
                ->when($f->branchId !== null, fn ($q) => $q->where('appointments.branch_id', $f->branchId))
                ->selectRaw("branches.code AS branch, services.code AS service, WEEKDAY({$local}) + 1 AS weekday")
                ->selectRaw('COUNT(*) AS appointments')
                ->selectRaw("SUM(appointments.status = 'no_show') AS no_shows")
                ->selectRaw("ROUND(100 * SUM(appointments.status = 'no_show') / COUNT(*), 1) AS no_show_pct")
                ->groupBy('branches.code', 'services.code', 'weekday')
                ->orderBy('branches.code')->orderBy('services.code')->orderBy('weekday')
                ->get()->all(),
        );
    }

    /**
     * Average and 90th-percentile wait (called_at - issued_at) per branch, hour of day and
     * ticket kind (appointment or walk-in).
     *
     * MySQL has no PERCENTILE_CONT, so the percentile uses the "nearest rank" method:
     * number the waits 1..n in ascending order inside each group (ROW_NUMBER), then the p90 is
     * the wait at position CEIL(0.9 * n), i.e. the smallest wait that at least 90% of tickets
     * did not exceed. MIN(CASE WHEN rn >= ceil THEN wait END) picks exactly that value.
     *
     * @return list<array<string, mixed>>
     */
    public function waitTimes(ReportFilters $f): array
    {
        return $this->toArrays(DB::select(<<<'SQL'
            WITH waits AS (
                SELECT t.branch_id,
                       HOUR(CONVERT_TZ(t.issued_at, '+00:00', '+04:00')) AS hour_of_day,
                       IF(t.appointment_id IS NULL, 'walk_in', 'appointment') AS kind,
                       TIMESTAMPDIFF(SECOND, t.issued_at, t.called_at) AS wait_seconds
                FROM queue_tickets t
                WHERE t.called_at IS NOT NULL
                  AND t.issued_at >= :utc_start AND t.issued_at < :utc_end
                  AND (:branch_a IS NULL OR t.branch_id = :branch_b)
            ),
            ranked AS (
                SELECT w.*,
                       ROW_NUMBER() OVER (PARTITION BY branch_id, hour_of_day, kind ORDER BY wait_seconds) AS rn,
                       COUNT(*)     OVER (PARTITION BY branch_id, hour_of_day, kind)                       AS n
                FROM waits w
            )
            SELECT b.code AS branch,
                   r.hour_of_day,
                   r.kind,
                   MAX(r.n) AS tickets,
                   ROUND(AVG(r.wait_seconds) / 60, 1) AS avg_wait_min,
                   ROUND(MIN(CASE WHEN r.rn >= CEIL(0.9 * r.n) THEN r.wait_seconds END) / 60, 1) AS p90_wait_min
            FROM ranked r
            JOIN branches b ON b.id = r.branch_id
            GROUP BY b.code, r.hour_of_day, r.kind
            ORDER BY b.code, r.hour_of_day, r.kind
        SQL, [
            'utc_start' => $f->fromUtc, 'utc_end' => $f->toUtcExclusive,
            'branch_a' => $f->branchId, 'branch_b' => $f->branchId,
        ]));
    }

    /**
     * Peak hours: tickets issued by weekday x hour (Dubai time).
     *
     * @return list<array<string, mixed>>
     */
    public function peakHours(ReportFilters $f): array
    {
        return $this->toArrays(DB::select(<<<'SQL'
            SELECT WEEKDAY(CONVERT_TZ(t.issued_at, '+00:00', '+04:00')) + 1 AS weekday,
                   HOUR(CONVERT_TZ(t.issued_at, '+00:00', '+04:00')) AS hour_of_day,
                   COUNT(*) AS tickets
            FROM queue_tickets t
            WHERE t.issued_at >= :utc_start AND t.issued_at < :utc_end
              AND (:branch_a IS NULL OR t.branch_id = :branch_b)
            GROUP BY weekday, hour_of_day
            ORDER BY weekday, hour_of_day
        SQL, [
            'utc_start' => $f->fromUtc, 'utc_end' => $f->toUtcExclusive,
            'branch_a' => $f->branchId, 'branch_b' => $f->branchId,
        ]));
    }

    /**
     * @param  array<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function toArrays(array $rows): array
    {
        return array_map(static fn (object $row): array => (array) $row, array_values($rows));
    }
}
