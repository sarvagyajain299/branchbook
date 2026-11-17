# BranchBook — interview notes

One section per phase: what was built, why, the concepts behind it, and questions an interviewer
is likely to ask. Read these out loud before an interview; if you can't explain a line, ask.

---

## Phase 0 — Project setup, conventions and CI

### What was built
- A Laravel **13** app (latest stable on 2026-11-17, `laravel/framework` v13.43) on **PHP 8.3**, running in
  **Laravel Sail** (Docker) inside WSL2, with **MySQL 8.0** and **Mailpit**.
- **Breeze (Blade)** for staff login, **Pest 4** for tests, **Pint** for code style, **Larastan level 6**
  for static analysis, composer scripts that chain them, and **GitHub Actions** running all of it
  against a MySQL 8.0 service container.
- App-wide safety defaults: strict Eloquent outside production, `CarbonImmutable` as the default date
  class, UTC storage with an `Asia/Dubai` display timezone.

### Why each decision

| Decision | Why |
|---|---|
| Sail in WSL2, project in `~/code` (not `C:\` / OneDrive) | Docker bind mounts from the Linux filesystem are many times faster than from `/mnt/c`, and OneDrive would try to sync 100k+ files in `vendor/` and `node_modules/`. |
| PHP 8.3, so **Pest 4** not Pest 5 | The brief fixes PHP 8.3. Pest 5 requires PHP 8.4. Pest 4 + `pest-plugin-laravel` 4.1 support Laravel 13 on 8.3. Moving to PHP 8.4 later is a one-line Sail change plus a Pest upgrade. |
| MySQL 8.0 pinned (Sail now defaults to 8.4) | The brief targets 8.0, and CI uses the same image, so local and CI behave identically. |
| Tests run on MySQL `testing`, **not SQLite in memory** | Phase 1 uses a MySQL generated column + unique index for double-booking prevention, and Phase 8 uses window functions. SQLite would test a different database than production. Slower, but correct. |
| `app.timezone = UTC`, `app.display_timezone = Asia/Dubai` | See "Time" below. |
| `Model::shouldBeStrict()` outside production | Turns three silent bugs into exceptions in dev and tests: lazy loading (N+1), assigning non-fillable attributes, reading attributes that weren't selected. In production we prefer a slow page over a crashed booking. |
| `Date::use(CarbonImmutable::class)` | `$end = $start->addMinutes(30)` on a *mutable* Carbon also changes `$start`. In slot maths that is a real double-booking bug. Immutable dates return a new object instead. |
| Pint rule `declare_strict_types` | Enforced automatically instead of relying on memory. With strict types, `function f(int $x)` called with `"5"` throws a `TypeError` instead of silently converting. |
| Larastan level 6 | Level 6 requires typehints on properties, parameters and returns, and checks generics on Eloquent relations. It catches wrong types before tests run. |
| Pipeline order: Pint → Larastan → migrate → Pest | Cheapest checks first, so a style error fails in seconds, not after a 2-minute test run. |
| Fix Larastan errors instead of a baseline | A baseline file hides existing errors. Breeze had only 2, both real type issues (a nullable user passed where a non-null one is required; a `Stringable` where a `string` is required). |
| `User implements MustVerifyEmail` | Staff get access to customer data. Verifying the email proves the account owner controls the address before any authenticated page loads (`verified` middleware). |

**Real results at the end of Phase 0** (`sail composer ci`): Pint clean, Larastan level 6 `[OK] No errors`,
Pest `30 passed (70 assertions)`.

| Decision | Why |
|---|---|
| `DB::prohibitDestructiveCommands()` in production | `migrate:fresh` / `db:wipe` drop every table. One mistyped command on the live server would delete all bookings; the framework now refuses to run them when `APP_ENV=production`. |
| Login page test asserts the `<title>` and form `action` | Catches a wrong `APP_NAME` and a renamed `login` route. `escape: false` because we match raw HTML. |

### Time: store UTC, display Dubai
- The brief says "the app timezone is Asia/Dubai, store datetimes in UTC". In Laravel those are two
  different settings. `config('app.timezone')` is what PHP and Eloquent use when they *create and save*
  dates, so it stays **UTC**. We added `config('app.display_timezone')` = `Asia/Dubai` for the edges:
  Blade, emails, `.ics` files and API output.
- Why UTC in the database: it is unambiguous, it sorts correctly, it's what servers, logs and APIs use,
  and the app keeps working if a branch outside the UAE is added. The UAE has no daylight saving, so the
  conversion is always +4 h, but code that relies on that breaks the day you add a city that does.
- The test `AppConfigTest` proves `2026-01-05 04:00 UTC` displays as `08:00` Dubai and that the original
  object is unchanged (immutability).

### Concepts to know

**Request lifecycle (Laravel 13).**
1. The web server sends every request to `public/index.php`.
2. It loads Composer's autoloader and creates the application from `bootstrap/app.php`. That file
   configures routing, middleware and exception handling (since Laravel 11 there is no `Http/Kernel.php`).
3. The HTTP kernel boots **service providers**: first every provider's `register()`, then every `boot()`.
   Our `AppServiceProvider::boot()` sets strict mode and the date class here, before any request is handled.
4. The request passes through the global middleware stack, then the router matches a route and runs that
   route's middleware group (`web`: cookies, session, CSRF; `api`: no session).
5. The controller (or closure) runs and returns a response, which travels back out through the middleware,
   is sent to the browser, and then terminable middleware runs.

**Service container and dependency injection.** The container is Laravel's object factory. When a
controller's constructor or method asks for `AvailabilityService $service`, the container builds it and
builds its dependencies too (autowiring through reflection). Service providers can bind an
**interface to an implementation** (`$this->app->bind(AdvisorAssignmentStrategy::class, LeastBookedTodayStrategy::class)`),
which is how Phase 3 swaps strategies without touching the code that uses them, and how tests can swap
in fakes.

**Sail.** Sail is a `compose.yaml` plus a small CLI script. `sail artisan migrate` runs
`docker compose exec laravel.test php artisan migrate`. Inside the network, the app reaches MySQL at
host `mysql` and Mailpit at host `mailpit` (service names), which is why `.env` says `DB_HOST=mysql`.
In CI there is no compose network; the service container listens on `127.0.0.1`, so CI overrides
`DB_HOST`. Real environment variables beat `.env` because Laravel loads dotenv in immutable mode.

**Health check.** `bootstrap/app.php` registers `health: '/up'`. It boots the framework and returns 200,
which load balancers and uptime monitors use. Our `HealthCheckTest` covers it.

### Likely interview questions

**Q1. Why do you store datetimes in UTC if every branch is in Dubai?**
UTC is the single unambiguous reference: it never has daylight saving jumps, it is what servers, logs,
queues and third-party APIs use, and comparisons and sorting are always correct. Converting only at the
edges (views, emails, API responses) keeps one rule for the whole codebase. The UAE has no DST, so
today the offset is always +4, but hard-coding that assumption would break as soon as we add a branch
in a country that does have DST, or integrate a calendar provider that sends UTC.

**Q2. What does `Model::shouldBeStrict()` do, and why not enable it in production?**
It enables three protections: `preventLazyLoading` (accessing a relation that wasn't eager-loaded throws,
which exposes N+1 query problems in tests), `preventSilentlyDiscardingAttributes` (mass-assigning a
field that isn't fillable throws instead of being ignored), and `preventAccessingMissingAttributes`
(reading a column you didn't select throws instead of returning null). In production a missed eager
load should only cost performance, not crash a customer's booking, so we enable it only outside
production, where tests and development catch the bug first.

**Q3. Your CI runs tests against MySQL instead of SQLite. Isn't that slower?**
Yes, a little: the pipeline starts a MySQL container and each test runs in a transaction on a real
database. But our correctness depends on MySQL behaviour that SQLite doesn't share: a generated column
with a unique index prevents double bookings, `SELECT ... FOR UPDATE` row locks, and window functions in
the reports. A test suite that passes on SQLite but fails on MySQL gives false confidence. Matching CI
to production (same MySQL 8.0 image) is worth the extra seconds.

---

## Phase 1 (+2) — Domain model, enums, seed data, staff roles and policies

Scope note: the build was compressed (about 5-6 hours), so the brief's Phase 1 and the data-and-policy half of Phase 2 were done together. HTTP-level 403 tests arrive with the screens that need them (the front desk screens); here the policies are tested at the Gate level.

### What was built
- 12 tables with foreign keys, unique constraints and indexes, each explained in its migration.
- Backed enums (`Role`, `AppointmentStatus`, `AppointmentChannel`, `TicketStatus`) with the allowed status transitions in one place.
- 12 Eloquent models, factories, a deterministic `DemoSeeder` (3 branches, 6 services, 12 advisors, 19 staff users, about 137 appointments, about 148 queue tickets).
- Policies for Branch, Appointment, QueueTicket, Customer and AuditLog, plus a `visibleTo(User)` query scope on Branch, Appointment and QueueTicket.
- Public registration removed. Staff sessions expire after 30 minutes. Login throttling is Breeze's existing 5-attempt limit.

### Why each decision
| Decision | Why |
|---|---|
| `active_slot_key` generated column + unique index | MySQL itself refuses two active bookings for the same advisor and start time, even if application code has a bug. NULLs are distinct in a unique index, so cancelled and expired rows never block a slot. |
| The status list is hard-coded in the migration, with a test comparing it to the enum | A migration must not change meaning when an enum changes later. The test fails if the two ever drift apart. |
| Unique index only covers the same start time | Two bookings of different lengths can overlap without sharing a start. Phase 3/4 add the transaction + `lockForUpdate` + overlap check on top. The index is the last safety net, not the whole defence. |
| `DATETIME` columns (not `TIMESTAMP`) in UTC | TIMESTAMP is converted using the session timezone; DATETIME stores exactly what we write. Also, MySQL requires deterministic expressions for generated columns. |
| `status`, `role` and `branch_id` are not mass-assignable | A request that posts `role=hq_admin` cannot promote anyone. Status changes only through `transitionTo()`, which rejects invalid jumps. |
| `users.role` defaults to `front_desk` | Least privilege: a row created without a role gets the most restricted one. |
| Phone is `encrypted`, email is the lookup key | Encryption output differs every time, so `WHERE phone = ?` can't work and an index is useless. Look customers up by email. A hashed "blind index" column would allow phone search if ever needed. |
| Policies **and** query scopes | A policy answers "may I touch this one record?"; a scope makes a list query return only allowed rows. Using only policies leaks rows into lists, counts and exports. Using only scopes leaves single-record routes open to guessing IDs. |
| Policy checks use `exists()` queries, not `$model->relation` | With `shouldBeStrict()` a lazy-loaded relation throws; a query is also cheaper than loading a model just to compare an id. |
| `AuditLog` throws on update or delete | Append-only is enforced in code, not just by convention. (A database-level privilege restriction would be the production hardening step.) |
| Larastan did not read the enum casts | Fixed with `@property` annotations on the models, which also help IDE autocompletion. |

### Concepts to know
- **N+1:** looping over 100 appointments and reading `$a->customer` runs 1 + 100 queries. `with('customer')` runs 2. Strict mode throws on lazy loading, but only for models loaded as a collection of 2 or more (a single `find()` is exempt).
- **Migrations:** each file is one reversible step with `up()`/`down()`, run in filename order and recorded in the `migrations` table. Never edit a migration that has run in a shared environment; add a new one.
- **Generated columns:** `STORED` computes the value on write and keeps it on disk, so it can carry an index. `VIRTUAL` computes on read.
- **Factories vs seeders:** factories build realistic rows for tests; the seeder composes them into a believable demo world.

### Likely interview questions

**Q1. How do you stop two customers booking the same advisor at the same time?**
In layers. At the database, a generated column `active_slot_key` equals `advisor|start` only while the appointment is active (held, confirmed, checked in, in service) and NULL otherwise, with a unique index on it. MySQL allows many NULLs in a unique index, so cancelled rows never block a slot, but two active rows for one advisor and start are rejected even if the PHP code is wrong. That index only catches identical start times, so the booking action also runs in a transaction that locks the advisor row with `lockForUpdate`, checks for overlapping active appointments (`starts_at < :end AND ends_at > :start`), then inserts, and turns a unique violation into a friendly "slot just taken" message.

**Q2. Why both policies and query scopes for authorization?**
They protect different things. A policy decides whether this user may act on this specific record, which is what guards a URL like `/appointments/42`. A scope filters list queries, so a branch manager's appointment list, counts and exports only ever contain their branch. With only policies, lists leak other branches' rows; with only scopes, someone can guess an ID and open a record directly. Both rules come from the same role and branch data, and the policy matrix test covers every role against every ability.

**Q3. Why isn't `status` in `$fillable`?**
Mass assignment is the classic privilege-escalation hole: any field the user can post becomes writable. Status is a state machine, so the only legal way to change it is `transitionTo()`, which checks the allowed transitions and throws `InvalidStatusTransition` otherwise. Keeping it out of `$fillable` means a bug or a crafted request can't move a completed appointment back to held. The same applies to `role` and `branch_id` on users.
