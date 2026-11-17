# PR: Phase 4 (rest) — reschedule

**Branch:** `feature/phase-4-reschedule` → `develop`

## Summary
Customers can move a confirmed appointment to another time from the private manage page or through the
API. The new slot is booked and the old one released in one transaction, so a failure never loses the
original appointment.

## Changes
- `HoldAppointment::reschedule()` (shares the locked booking transaction; locks advisor and old appointment; keeps `rescheduled_from_id`; issues a new reference and manage token).
- Web: `/booking/{reference}/reschedule` (picker), `/reschedule/slots` (JSON), `/reschedule/confirm`, `POST /reschedule`; Reschedule button on the manage page (hidden inside the 2-hour cut-off).
- API: `PATCH /bookings/{reference}` (documented in `docs/openapi.yaml`, added to the Postman collection).
- `RescheduleNotAllowed` exception (409 in the API); `WeekPayload` shared by both pickers.
- Audit entry `appointment.rescheduled` (references only); confirmation email with the new `.ics`.

## Results (real run in Sail)
```
$ sail composer ci
pint --test      -> PASS
phpstan (lvl 6)  -> [OK] No errors
pest             -> Tests: 401 passed (2729 assertions)
```
21 new tests: links and releases both slots, other day, other advisor, slot taken leaves the original
untouched, closed day / past / outside hours, same time, cancelled or held booking, 2h cut-off boundary,
no double reschedule, cache cleared for both days, audit entry, every route needs the manage token, web
walk-through with emails, API success and error shapes.

## How to test
1. `sail artisan migrate:fresh --seed`, make a booking (see the booking PR), open its manage link.
2. Click **Reschedule**, choose a time, confirm; check Mailpit for the new email.
3. `sail composer ci`

## Risks / notes
- The parallel script (`scripts/concurrency-check.sh`) does not exercise reschedule; the transaction and lock order are covered by the sequential tests.
- Moving to an overlapping time with the same only advisor fails with "just taken" because the old booking still occupies that advisor until the move completes.
