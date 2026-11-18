# BranchBook

![CI](https://github.com/sarvagyajain299/branchbook/actions/workflows/ci.yml/badge.svg)

Appointment booking and queue management for bank branches.

> Portfolio project. **Northwind Bank**, a fictional UAE bank with branches in Dubai, Abu Dhabi and
> Sharjah, and all its data are invented.

## The problem

Walking into a bank branch today usually means:

- **Long, unpredictable waits.** Customers turn up, take a paper ticket and hope for the best.
- **Wasted trips.** People arrive without the right documents and get sent home.
- **Double bookings and no-shows.** Where appointments exist, two customers can end up with the same
  advisor at the same time, and empty slots go unused when people don't come.
- **No visibility for the branch.** Managers cannot see how long people really wait, which advisors are
  overloaded, or how many customers never show up.
- **Privacy risk.** Customer details are easy to over-collect and easy to leak through screens, emails
  and logs.

## The solution

**BranchBook** is a web system that covers a customer's whole visit, from booking to being served.

1. **Before the visit:** the customer picks a service, a branch and a time online, confirms with a code
   sent by email, and gets a confirmation with a calendar file and a list of documents to bring.
2. **On the day:** front desk staff check the customer in and a numbered queue ticket (for example `A-042`)
   is issued. Walk-ins get tickets too. Advisors press "Call next" and a waiting-room screen shows which
   ticket goes to which desk.
3. **Afterwards:** managers see reports on waits, no-shows and advisor workload. HQ and managers run
   everything (branches, hours, services, staff) from an admin panel.

## What makes it different

- **Double booking is impossible, not just unlikely.** The database itself rejects a second booking for
  the same advisor and time, and the booking code also locks the advisor while it checks. Parallel
  requests were tested against a real MySQL database.
- **A real availability engine.** It understands opening hours, breaks, holidays, advisor leave, buffers
  between appointments, minimum notice and how far ahead people can book, and it updates instantly when a
  booking changes.
- **Privacy by design.** No Emirates ID, account or card numbers are ever collected. Phone numbers are
  encrypted, codes and links are stored only as hashes, staff see masked contact details, the audit log
  holds no personal data, and customers inactive for 24 months are anonymised automatically.
- **Fair, safe queue.** Two advisors pressing "Call next" at the same moment never get the same customer.
- **Built like production software.** Role- and branch-based permissions, a REST API with documentation,
  rate limiting, security headers and 434 automated tests that run on every change.

## How it helps

| Who | What they get |
|---|---|
| **Customers** | Pick a time instead of queueing, know what to bring, cancel or reschedule from a private link |
| **Front desk** | One screen to check people in, add walk-ins and see today's bookings |
| **Advisors** | A single "Call next" button and a clear view of their own day |
| **Branch managers** | Reports on waiting times, no-shows and utilisation, plus CSV exports |
| **HQ** | One admin panel for every branch, with an audit trail of who did what |
| **The bank** | Fewer wasted trips and no-shows, and customer data handled safely |

## How it works

```
Customer                        System                              Branch staff
   |  choose service/branch/time  |                                       |
   |----------------------------->|  availability engine finds free slots |
   |  enter details               |  slot held for a few minutes          |
   |----------------------------->|  email code sent                      |
   |  enter code                  |  booking confirmed, email + .ics      |
   |<-----------------------------|  reminder sent before the visit       |
   |                                                                      |
   |  arrives at the branch -------------------------------------------->|  check-in
   |                              |  queue ticket A-042 issued            |
   |                              |<--------------------------------------|  advisor: "Call next"
   |  sees A-042 -> Desk 3 on the waiting-room screen                     |
   |                              |  served, completed or marked no-show  |
```

Under the hood: a booking is held briefly, confirmed by an emailed one-time code, then locked in with a
database transaction. Scheduled jobs expire unconfirmed holds, send reminders and mark no-shows. Business
logic lives in `app/Actions` and `app/Services`, and controllers stay thin. Diagrams are in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Tech stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13, PHP 8.3 |
| Database | MySQL 8.0 |
| Frontend | Blade, Tailwind CSS, small vanilla JavaScript, Chart.js |
| Staff login / admin | Laravel Breeze, Laravel Backpack (free core) |
| API | REST `/api/v1` with Laravel Sanctum tokens, OpenAPI spec and Postman collection |
| Dev environment | Laravel Sail (Docker), Mailpit for test emails |
| Quality | Pest 4 (434 tests), Pint, Larastan level 6, GitHub Actions |

## Setup (Windows: run everything in a WSL2 Ubuntu terminal)

Requirements: Docker Desktop with WSL2 integration, git inside WSL. Clone into the Linux filesystem
(`~/code`), **not** `/mnt/c/...`, or Docker will be very slow.

```bash
git clone https://github.com/sarvagyajain299/branchbook.git ~/code/branchbook && cd ~/code/branchbook
cp .env.example .env
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd)":/var/www/html -w /var/www/html \
  laravelsail/php83-composer:latest composer install --ignore-platform-reqs
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail artisan storage:link
./vendor/bin/sail artisan basset:cache   # downloads the admin panel's CSS/JS once (needs internet)
./vendor/bin/sail npm ci
./vendor/bin/sail npm run build
```

| What | URL |
|---|---|
| Customer booking | http://localhost |
| Staff login | http://localhost/login |
| Waiting-room display | http://localhost/display/DXB-MAR |
| Mailpit (all outgoing mail, including codes) | http://localhost:8026 |
| Health check | http://localhost/up |
| API (see `docs/openapi.yaml`) | http://localhost/api/v1/branches |
| Admin panel (log in as HQ or a manager) | http://localhost/admin |
| Reports (HQ or a manager) | http://localhost/admin/reports |

Tip: `alias sail='./vendor/bin/sail'`. If port 80, 3306 or 8026 is taken, change `APP_PORT`,
`FORWARD_DB_PORT` or `FORWARD_MAILPIT_DASHBOARD_PORT` in `.env`.

### Demo logins (password for all: `password`, development only)

| Role | Email |
|---|---|
| HQ administrator | `hq@northwind.test` |
| Branch manager (Dubai) | `manager.dxb@northwind.test` |
| Front desk (Dubai) | `desk.dxb@northwind.test` |
| Advisor (Dubai) | `advisor.dxb1@northwind.test` |

Same pattern for Abu Dhabi (`auh`) and Sharjah (`shj`).

### Background work
Reminders, hold expiry and no-show marking need the scheduler and a queue worker:

```bash
sail artisan schedule:work     # or a cron line running schedule:run every minute in production
sail artisan queue:work        # sends the queued confirmation emails and reminders
sail artisan branchbook:health # checks DB, queue, failed jobs, scheduler heartbeat and mail config
sail artisan branchbook:anonymise-customers --dry-run   # preview the 24-month retention job
```

## Project status

| Area | Status |
|---|---|
| Domain model: 12 tables, enums with one status state machine, factories, demo seed | Done |
| Double-booking prevention: MySQL generated column + unique index **and** transactional row locks | Done |
| Availability engine: hours, breaks, closures, leave, buffers, notice, 15-minute grid, cached with instant invalidation | Done |
| Pluggable advisor assignment (load balancing or random) behind an interface | Done |
| Customer booking: service, branch, time (JSON picker), details, email OTP, confirmation, `.ics`, private manage link with cancel and **reschedule** | Done |
| Scheduled jobs: expire holds, reminders (idempotent), no-show marking | Done |
| Staff roles (HQ, manager, advisor, front desk) with branch-scoped policies and query scopes | Done |
| Front desk check-in, walk-ins, daily queue tickets (`A-042`) | Done |
| Advisor desk: Call next (concurrency-safe), start, complete, no-show, recall | Done |
| Waiting-room display (public, no personal data, optional chime) | Done |
| Audit log (append-only), security log channel, security headers, health command, data-retention job, `composer audit` in CI | Done |
| REST API `/api/v1` (Sanctum tokens with per-role abilities, OpenAPI spec, Postman collection) | Done, see [`docs/openapi.yaml`](docs/openapi.yaml) |
| Reports (raw SQL: window functions, recursive CTE, P90), Chart.js charts and streamed CSV exports at `/admin/reports` | Done |
| Admin panel at `/admin` (Backpack): branches, hours, closures, services, advisors, shifts, leave, staff users, appointments, masked customers, audit log, dashboard | Done |
| Arabic/RTL | **Not built yet** (see "What I'd do next") |

## Quality (real numbers from the last run)

```
$ sail composer ci
pint --test      -> PASS
phpstan (lvl 6)  -> [OK] No errors
pest             -> Tests: 434 passed (2975 assertions)

$ bash scripts/concurrency-check.sh        # real parallel processes against MySQL
Result: 7 passed, 0 failed
```
Code coverage is not measured yet (the Sail image has no Xdebug/PCOV).

## Architecture
Diagrams of the components, the customer booking flow and the branch-day flow are in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). Design decisions, concepts and interview Q&A for every
phase are in [`docs/INTERVIEW_NOTES.md`](docs/INTERVIEW_NOTES.md). Business logic lives in
`app/Actions` and `app/Services`; controllers stay thin.

## Commands

| Command | What it does |
|---|---|
| `sail composer lint` / `lint:check` | Fix / check code style (Pint) |
| `sail composer stan` | Static analysis (Larastan level 6) |
| `sail composer test` | Pest suite on the MySQL `testing` database |
| `sail composer ci` | All three, in CI order |
| `bash scripts/concurrency-check.sh` | Parallel check of ticket numbering, Call next and booking races |

## Security and privacy
No Emirates ID, account or card numbers are ever collected (and the booking form says so). Phone numbers
are encrypted at rest; email is the lookup key. OTP codes and manage-booking tokens are stored only as
hashes; codes expire in 10 minutes and allow 5 attempts. Staff screens mask contact details by role.
Queue and booking actions are written to an append-only audit log with no personal data; security events go to a separate log channel. Customers inactive for 24 months are anonymised by a scheduled job. Public endpoints
are rate limited, and pages send CSP, X-Frame-Options, Referrer-Policy and Permissions-Policy headers.

## What I'd do next
1. **Arabic / RTL** with the layout already using logical CSS properties.
2. Booking on behalf of a customer and rescheduling from the admin panel.
3. Subtract advisor leave from utilisation capacity.
4. Replace Alpine's standard build with its CSP build to drop `'unsafe-eval'` from the CSP; add code coverage to CI.

## Repository workflow
`main` (releases) ← `develop` (integration) ← `feature/*` (one per phase), merged by pull request with CI
green. Conventional Commits. PR descriptions live in [`docs/prs/`](docs/prs/). Conventions for contributors
and AI assistants: [`CLAUDE.md`](CLAUDE.md).
