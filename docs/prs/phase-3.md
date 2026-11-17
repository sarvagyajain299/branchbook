# PR: Phase 3 — availability engine

**Branch:** `feature/phase-3-availability-engine` → `develop`

## Summary
A pure, heavily tested `AvailabilityService` that turns opening hours, closures, advisor skills,
shifts, leave and existing bookings into bookable slots, with pluggable advisor assignment and a
60-second cache that clears the moment a booking changes.

## Changes
- `AvailabilityService`: `slotsForDate`, `slotsForRange`, `freeAdvisorsAt`.
- `Slot` value object; times are UTC instants, converted at the edges.
- `AdvisorAssignmentStrategy` + `LeastBookedTodayStrategy` (default) + `RandomStrategy`; bound in `AppServiceProvider`, chosen by `ASSIGNMENT_STRATEGY`.
- `AvailabilityCache` with versioned keys; `AppointmentChanged` event fired from `Appointment` save/delete, `ForgetAvailabilityCache` listener (also clears the old day on a reschedule).
- `config/branchbook.php`.

## Results (real run in Sail)
```
$ sail composer ci
pint --test      -> PASS
phpstan (lvl 6)  -> [OK] No errors
pest             -> Tests: 215 passed (1475 assertions)
```
New tests cover: normal day, Friday short day, closed weekend, all-branch and branch-only holidays,
lunch break, last slot exactly before closing and the one that overruns, buffer handling, advisor
leave (partial and full), no qualified or inactive advisors, other-branch advisors, overlapping
bookings of different lengths, appointment buffers, every appointment status, unexpired vs lapsed
holds, minimum notice and its 1-minute boundary, past dates, max days ahead, inactive service or
branch, weekly range, exact-time lookup, query count, cache hit and invalidation (booking, cancel,
delete, reschedule), and the assignment strategies and their container binding.

## How to test
1. `sail composer ci`
2. `sail artisan tinker`, then:
   `app(App\Services\Availability\AvailabilityService::class)->slotsForDate(App\Models\Branch::first(), App\Models\Service::first(), 'YYYY-MM-DD')`
   with a future weekday.

## Risks / notes
- Shifts, leave and opening-hour edits are not announced to the cache, so they take up to 60 seconds to show in browsing results. Booking always re-checks fresh.
- The grid rounding assumes whole quarter-hour timezone offsets (true for the UAE).
