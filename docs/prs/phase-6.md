# PR: Phase 6 — branch-day operations: front desk, queue and waiting-room display

**Branch:** `feature/phase-6-branch-queue` → `develop`

## Summary
Front desk check-in and walk-ins, daily-numbered queue tickets (`A-042`), an advisor desk with a
concurrency-safe "Call next", and a public waiting-room display. Every ticket records issued,
called, served and completed times for the later reports.

## Changes
- **Screens:** `/branch/{code}/desk`, `/branch/{code}/my-desk`, `/display/{code}` (+ `/state` JSON), role-aware dashboard.
- **Actions:** `IssueQueueTicket`, `CheckInAppointment`, `IssueWalkInTicket`, `CallNextTicket`, `ProgressTicket` (start / complete / no-show / recall).
- **Rules:** numbering resets daily per branch and prefix (branch-local midnight); call-next priority = due appointments by appointment time, then walk-ins FIFO; skill filtering; one active customer per advisor.
- **Security:** policy + branch match on every route (403 wrong role or branch, 404 cross-branch ids); masked contact details on the front desk; display endpoint exposes only codes and desk labels, throttled with its own limiter.
- **Migration:** `walk_in_name`, `recalled_at` on `queue_tickets`.
- **Tools:** `scripts/concurrency-check.sh` runs real parallel processes against MySQL.
- **Bug found and fixed by that script:** `LIMIT 1 FOR UPDATE SKIP LOCKED` locked every scanned row, so simultaneous advisors were told nobody was waiting. Now candidates are locked one by primary key.

## Results (real run in Sail)
```
$ sail composer ci
pint --test      -> PASS
phpstan (lvl 6)  -> [OK] No errors
pest             -> Tests: 301 passed (2045 assertions)

$ bash scripts/concurrency-check.sh
Result: 7 passed, 0 failed   (numbering x3, call-next x3, booking x1, all with parallel processes)
```

## How to test
1. `sail artisan migrate:fresh --seed && sail npm run build`
2. Log in as `desk.dxb@northwind.test` / `password`, open **Front desk**, check someone in, issue a walk-in.
3. In a second browser log in as `advisor.dxb1@northwind.test`, open **My desk**, press **Call next**.
4. Open `/display/DXB-MAR` full screen: the ticket appears as `A-001 → Desk 1`.
5. `sail composer ci` and `bash scripts/concurrency-check.sh`.

## Risks / notes
- Pest runs each test in one transaction, so true simultaneity is only covered by the parallel script.
- The display polls every 4 seconds; Reverb websockets are the documented upgrade path.
- Appointment tickets can be called by any qualified advisor, not only the pre-assigned one (per the brief).
