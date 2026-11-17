# BranchBook

Branch appointment booking and queue management for **Northwind Bank**, a fictional UAE retail bank
with branches in Dubai, Abu Dhabi and Sharjah. Customers book in-branch appointments online and
confirm them with an emailed code. On the day, front desk staff check them in, advisors call the
next customer, and a waiting-room screen shows who goes to which desk.

> Portfolio project. Northwind Bank, its branches and all data are invented.

![CI](https://github.com/sarvagyajain299/branchbook/actions/workflows/ci.yml/badge.svg)

## What works today

| Area | Status |
|---|---|
| Domain model: 12 tables, enums with one status state machine, factories, demo seed | Done |
| Double-booking prevention: MySQL generated column + unique index **and** transactional row locks | Done |
| Availability engine: hours, breaks, closures, leave, buffers, notice, 15-minute grid, cached with instant invalidation | Done |
| Pluggable advisor assignment (load balancing or random) behind an interface | Done |
| Customer booking: service, branch, time (JSON picker), details, email OTP, confirmation, `.ics`, private manage/cancel link | Done |
| Scheduled jobs: expire holds, reminders (idempotent), no-show marking | Done |
| Staff roles (HQ, manager, advisor, front desk) with branch-scoped policies and query scopes | Done |
| Front desk check-in, walk-ins, daily queue tickets (`A-042`) | Done |
| Advisor desk: Call next (concurrency-safe), start, complete, no-show, recall | Done |
| Waiting-room display (public, no personal data, optional chime) | Done |
| Audit log (append-only), security headers, health command | Done |
| REST API `/api/v1` (Sanctum tokens with per-role abilities, OpenAPI spec, Postman collection) | Done, see [`docs/openapi.yaml`](docs/openapi.yaml) |
| Reports (raw SQL: window functions, recursive CTE, P90), Chart.js charts and streamed CSV exports at `/admin/reports` | Done |
| Backpack admin panel, reschedule, data-retention job, Arabic/RTL | **Not built yet** (see "What I'd do next") |

## Stack
Laravel 13 · PHP 8.3 · MySQL 8.0 · Laravel Sail (Docker) · Blade + Tailwind + small vanilla JS ·
Breeze (staff login) · Pest 4 · Pint · Larastan level 6 · GitHub Actions.

## Quality (real numbers from the last run)

```
$ sail composer ci
pint --test      -> PASS
phpstan (lvl 6)  -> [OK] No errors
pest             -> Tests: 370 passed (2570 assertions)

$ bash scripts/concurrency-check.sh        # real parallel processes against MySQL
Result: 7 passed, 0 failed
```
Code coverage is not measured yet (the Sail image has no Xdebug/PCOV).

## Architecture
Diagrams of the components, the customer booking flow and the branch-day flow are in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). Design decisions, concepts and interview Q&A for every
phase are in [`docs/INTERVIEW_NOTES.md`](docs/INTERVIEW_NOTES.md). Business logic lives in
`app/Actions` and `app/Services`; controllers stay thin.

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
| Reports (log in as HQ or a manager) | http://localhost/admin/reports |

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
```

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
Queue and booking actions are written to an append-only audit log with no personal data. Public endpoints
are rate limited, and pages send CSP, X-Frame-Options, Referrer-Policy and Permissions-Policy headers.

## What I'd do next
1. **Backpack admin panel** for branches, services, advisors, shifts and the audit log.
2. **Reschedule** in the customer journey and the API.
3. **Data retention job** that anonymises customers inactive for 24 months.
4. **Arabic / RTL** with the layout already using logical CSS properties.
5. Subtract advisor leave from utilisation capacity.
6. Replace Alpine's standard build with its CSP build to drop `'unsafe-eval'` from the CSP; add code coverage to CI.

## Repository workflow
`main` (releases) ← `develop` (integration) ← `feature/*` (one per phase), merged by pull request with CI
green. Conventional Commits. PR descriptions live in [`docs/prs/`](docs/prs/). Conventions for contributors
and AI assistants: [`CLAUDE.md`](CLAUDE.md).
