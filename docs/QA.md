# BranchBook end-to-end QA checklist

Run after `sail artisan migrate:fresh --seed` and `sail npm run build`. Keep `sail artisan queue:work`
and `sail artisan schedule:work` running in two terminals, and Mailpit open at http://localhost:8026.

**Status column:** "auto" = covered by the automated suite (`sail composer ci`, 313 tests);
"parallel" = covered by `scripts/concurrency-check.sh`; "browser" = checked by hand in a browser;
"TO RUN" = not yet run by hand. Be honest when you run it: change the status only after you did it.

## A. Customer journey

| # | Step | Expected | Status |
|---|---|---|---|
| 1 | Open http://localhost | 6 service cards with duration and "What to bring" | auto |
| 2 | Choose **Personal Account Opening** → **Dubai Marina** | Time picker loads a week of slots with no page reload | auto + browser (picker rendered, no console errors) |
| 3 | Weekend days and holidays | "No times" | auto |
| 4 | Choose a time | Details form with the "never enter card/account/Emirates ID" notice | auto |
| 5 | Submit bad phone / no consent | Field errors, nothing booked | auto |
| 6 | Submit valid details | Verify page; email in Mailpit with a 6-digit code | auto (mail fake); **TO RUN** in Mailpit |
| 7 | Enter a wrong code 5 times | Locked out; "request a new code" | auto |
| 8 | Request a new code, enter it | Confirmed; manage page shows reference, "Please bring" list | auto |
| 9 | Click **Add to calendar** | `.ics` downloads and imports into a calendar app | auto (file contents); **TO RUN** (real calendar import) |
| 10 | Check Mailpit | Confirmation email with `.ics` attached (needs `queue:work`) | auto (queued); **TO RUN** |
| 11 | Open the manage link without its token | 404 | auto |
| 12 | Cancel > 2 hours before start | Cancelled; slot bookable again | auto |
| 13 | Cancel < 2 hours before start | Friendly refusal | auto |
| 13a | Click **Reschedule**, pick another time, confirm | New reference and email; old appointment cancelled; if the time is taken the old one is kept | auto; **TO RUN** (real clicks) |
| 14 | Two browsers book the last slot together | One wins, one sees "just taken" | auto (sequential) + parallel |
| 15 | Wait 10 minutes after step 6 without verifying | Hold expires, slot free | auto |

## B. Reminders and no-shows

| # | Step | Expected | Status |
|---|---|---|---|
| 16 | `sail artisan branchbook:send-reminders` with a booking < 24 h away | "Queued 1 reminder"; one log line in `storage/logs/laravel.log`; second run queues 0 | auto |
| 17 | `sail artisan branchbook:mark-no-shows` 30+ min after an un-checked-in booking | Marked no-show | auto |
| 18 | `sail artisan schedule:list` | Four entries incl. heartbeat | auto |

## C. Branch day

| # | Step | Expected | Status |
|---|---|---|---|
| 19 | Log in as `desk.dxb@northwind.test`, open **Front desk** | Today's appointments with **masked** email/phone | auto |
| 20 | **Check in** a confirmed appointment | Ticket e.g. `A-001`; second click refused | auto |
| 21 | Issue a **walk-in** ticket (optional name) | Next number for that prefix | auto |
| 22 | Open `/display/DXB-MAR` full screen | "Please wait to be called"; waiting codes under **Next** | auto + **TO RUN** (visual) |
| 23 | Log in as `advisor.dxb1@northwind.test`, **Call next** | Due appointment before walk-ins; display shows `A-001 → Desk 1` within ~4 s | auto + **TO RUN** (visual, chime) |
| 24 | **Recall** | Display re-announces (chime if sound is on) | auto |
| 25 | **Start**, then **Complete** | Appointment moves in_service → completed; timestamps stored | auto |
| 26 | **No-show** on a called ticket | Ticket and appointment no-show | auto |
| 27 | Two advisors press **Call next** at once | Two different customers | parallel |
| 28 | Advisor of another branch opens this branch's desk | 403 | auto |

## D. Security and operations

| # | Step | Expected | Status |
|---|---|---|---|
| 29 | `curl -I http://localhost` | X-Frame-Options, CSP, Referrer-Policy, Permissions-Policy present | auto |
| 30 | Visit `/register` | 404 (staff accounts are created by admins) | auto |
| 31 | Staff stays idle 30 minutes | Session expires | config (SESSION_LIFETIME=30) |
| 32 | `sail artisan branchbook:health` | OK for DB/queue/mail; scheduler OK only while `schedule:work` runs | auto + browser/terminal |
| 33 | Audit log after the steps above | Rows for check-in, call, start, complete, login, cancellation; no names or emails | auto |

## E. Reports and API

| # | Step | Expected | Status |
|---|---|---|---|
| 34 | Log in as HQ, open **Reports and exports** | Four tables, three charts, heatmap, filters | auto + HTTP (charts not looked at by eye: **TO RUN**) |
| 35 | Log in as `manager.dxb@northwind.test`, open Reports | Only Dubai data; `?branch=AUH-COR` gives 403 | auto |
| 36 | Click each **Download CSV** | `text/csv` files that open in Excel/Sheets; text starting with `=` is neutralised | auto + HTTP; **TO RUN** (open in a spreadsheet) |
| 37 | Run the Postman collection in `docs/api` | Booking and staff flows succeed in order | **TO RUN** (API behaviour is covered by 34 automated tests) |

| 38 | `sail artisan branchbook:anonymise-customers --dry-run` | Reports how many customers would be anonymised; changes nothing | auto |
| 39 | Run it without `--dry-run` | Those customers become "Anonymised Customer"; their appointments remain; second run reports 0 | auto |
| 40 | Fail a staff login, then read `storage/logs/security-*.log` | One line with a hashed email, no readable address | auto |

## F. Not built yet (cannot be QA'd)
Backpack admin, Arabic/RTL.
