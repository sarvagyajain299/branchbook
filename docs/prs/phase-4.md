# PR: Phase 4 — customer booking journey

**Branch:** `feature/phase-4-booking-journey` → `develop`

## Summary
The public booking flow: service, branch, time (JSON-loaded picker), details, emailed one-time
code, confirmation with `.ics` and a private manage/cancel page, plus scheduled hold expiry,
reminders and no-show marking.

## Changes
- **Pages** (Blade + Tailwind, mobile-first, skip link, progress list, labelled fields, `aria-live` slot region, logical CSS properties): services, branches, times, details, verify, manage.
- **Actions:** `StartBooking`, `HoldAppointment` (row lock + re-check + unique-index fallback), `VerifyBooking`, `CancelBooking`; maintenance actions `ExpireStaleHolds`, `MarkNoShows`, `DispatchDueReminders`.
- **Services:** `OtpService` (hashed code, 5 attempts, 10 minutes), `ManageToken`, `IcsBuilder`, `ReminderChannel` + `LogChannel`.
- **Mail:** `OtpMail` (sent immediately, never queued), `BookingConfirmedMail` (queued, encrypted payload, `.ics` attached).
- **Scheduler + commands:** `branchbook:expire-holds`, `branchbook:send-reminders`, `branchbook:mark-no-shows`.
- **Security:** per-route named throttles, session-bound verify page, `no-store` + `no-referrer` on manage pages, UAE phone validation, notice that card/account/Emirates ID numbers are never requested.
- **Migration:** `reminder_sent_at` + `(status, starts_at)` index.
- **Tests:** shared helpers moved to `tests/Support/helpers.php`.

## Results (real run in Sail)
```
$ sail composer ci
pint --test      -> PASS
phpstan (lvl 6)  -> [OK] No errors
pest             -> Tests: 261 passed (1757 assertions)
```
Also checked in a real browser: the time picker loads and renders with no console errors.

## How to test
1. `sail artisan migrate:fresh --seed && sail npm run build`
2. Open http://localhost, pick Personal Account Opening, Dubai Marina, a time, fill the form.
3. Open Mailpit at http://localhost:8026 for the code, enter it, then use the private link to add to calendar or cancel.
4. `sail artisan branchbook:send-reminders` and `sail artisan schedule:list`.

## Risks / notes
- Reschedule and "nearest branch" are not built yet (listed as cut scope).
- The concurrency guarantee is tested sequentially and at the database level, not with truly parallel requests.
- Reminders are at-most-once if the process dies between claiming and sending.
- Reminders need `schedule:run` every minute in production (cron), and `queue:work` for queued mail.
