# PR: Phase 8 — reports and exports (hand-written SQL)

**Branch:** `feature/phase-8-reports` → `develop`

## Summary
Four raw-SQL reports (window functions, a recursive CTE, a nearest-rank percentile), one of them
repeated with the query builder, an admin Reports page with Chart.js charts and filters, and
streamed CSV exports. Branch managers only see their own branch.

## Changes
- `ReportService`: advisor utilisation per week (`RANK() OVER`), no-show rate, wait times (average and P90), peak-hours heatmap; `noShowRateWithBuilder()` for comparison.
- `/admin/reports` page: date range and branch filters, 3 charts (loaded as a separate chunk only on this page), accessible tables, heatmap grid.
- `/admin/reports/export/{report}` CSV downloads (`utilisation`, `no-shows`, `wait-times`, `heatmap`, `appointments`), streamed with `lazy(500)`, UTF-8 BOM, formula-injection protection, no contact details.
- Access: `view-reports` gate (HQ and branch managers); managers confined to their branch (403 if they ask for another).
- Migration: index `(issued_at, branch_id)` on `queue_tickets`.
- Chart.js added as an npm dependency (MIT licence).

## Results (real run in Sail)
```
$ sail composer ci
pint --test      -> PASS
phpstan (lvl 6)  -> [OK] No errors
pest             -> Tests: 370 passed (2570 assertions)
```
23 new report tests use a small dataset calculated by hand: utilisation 16.7% / 37.5% / 0%, ranks 2 / 1 / 1,
no-show 0% / 100% / 16.7%, averages 11.7 and 28.0 with P90 20 and 100 minutes, heatmap counts including the
midnight timezone boundary, branch filtering, CSV headers and row counts.

`EXPLAIN` on 60,132 tickets (30-day range, 5,246 matching): `type ALL`, 59,996 rows examined before the index;
`type range`, 5,246 rows after it. Details in `docs/INTERVIEW_NOTES.md`.

Live check against the seeded database over HTTP: page returns 200 with 4 tables and 3 canvases, the chart chunk
is served, and all 5 CSV exports return 200 as `text/csv`.

## How to test
1. `sail artisan migrate:fresh --seed && sail npm run build`
2. Log in as the demo HQ admin (see README) and open **Reports and exports** on the dashboard.
3. Change the range or branch, then try each **Download CSV** link.
4. Log in as `manager.dxb@northwind.test`: only Dubai data, and `?branch=AUH-COR` gives 403.
5. `sail composer ci`

## Risks / notes
- Utilisation does not subtract advisor leave (documented in the code and notes).
- The three charts were verified through HTTP (data tag, canvases, chunk served) and not by eye: an attempt to look at the page in a browser failed because the browser tab ended up on an extension page.
- `npm audit` reports advisories in the Breeze/Tailwind toolchain; they are development dependencies and are not part of this change.
