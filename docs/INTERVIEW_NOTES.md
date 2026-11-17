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

---

## Phase 3 — Availability engine

### What was built
- `AvailabilityService`: pure domain logic (no HTTP) that returns bookable `Slot`s for a branch, service and date (or a run of days), plus `freeAdvisorsAt()` for an exact start time.
- `AdvisorAssignmentStrategy` interface with `LeastBookedTodayStrategy` (default) and `RandomStrategy`, bound in `AppServiceProvider` and switched with `ASSIGNMENT_STRATEGY`.
- `AvailabilityCache` (60 s) with instant invalidation through an `AppointmentChanged` event and a `ForgetAvailabilityCache` listener.
- 42 tests covering every edge case in the brief.

### The algorithm
1. Closed? A branch-specific or all-branch `closures` row for the date means no slots.
2. Opening hours for the weekday, split into two windows around the break.
3. Candidate advisors: active, at this branch, qualified for the service (one query, with shifts, leave and appointments eager loaded).
4. Walk a 15-minute grid. A slot needs `duration + buffer` minutes and must fit inside one window (the last slot may end exactly at closing).
5. An advisor is free when the slot is inside one of their shifts and overlaps neither leave nor an active appointment. An appointment also occupies its own buffer after it. A hold whose `hold_expires_at` has passed does not block, even if the scheduler has not marked it expired yet.
6. Apply `min_notice_hours` and `max_days_ahead`.

### Why each decision
| Decision | Why |
|---|---|
| All comparisons use UNIX timestamps | A timestamp is an absolute instant. Comparing integers removes timezone and DST bugs from the overlap maths. Wall-clock times (opening hours, shifts) are converted once. |
| Overlap test `busyStart < end && busyEnd > start` | Two intervals overlap exactly when each starts before the other ends. Touching intervals (one ends 09:00, the next starts 09:00) correctly do not overlap. |
| Minimum notice is applied after the cache | The cached list doesn't depend on "now", so a cached result can never let a too-soon slot through. |
| Version number in the cache key instead of cache tags | Tags are not supported by the `database` cache driver. Bumping a per-(branch, date) version makes all old keys unreachable at once, on any driver. |
| Event + listener instead of clearing the cache inside the model | The model only announces "this changed"; what to do about it lives in one listener. Later features (audit log, notifications) can listen too. |
| `freeAdvisorsAt()` is uncached | A booking decision must use fresh data; the cache is only for browsing. |
| Slot end shows the meeting length, the buffer is reserved silently | Customers see "09:00-09:45"; the advisor's wrap-up time is protected without confusing the customer. |
| Strategy pattern | Booking code depends on the `AdvisorAssignmentStrategy` interface, so the policy changes in config or in a test without touching booking code. |
| Fixed query count | Everything is eager loaded; a test asserts at most 8 queries with 6 advisors, so the cost doesn't grow with advisor count. |

### Concepts to know
- **Strategy pattern + dependency injection:** the service container resolves an interface to a concrete class chosen in a service provider. Callers never use `new`.
- **Events and listeners:** `AppointmentChanged::dispatch(...)` is fired from the model's `saved`/`deleted` hooks; Laravel auto-discovers `ForgetAvailabilityCache` from the type-hint on its `handle()` method.
- **Eager loading with constraints:** `with(['shifts' => fn ($q) => $q->where('weekday', 1)])` loads only the rows needed, in one extra query per relation.
- **Why caching needs invalidation:** a stale "slot is free" would let a customer start a booking that then fails. The cache is a speed-up; the database unique index and row lock remain the source of truth.

### Likely interview questions

**Q1. Walk me through how you calculate available slots.**
Check closures and opening hours for the weekday, which gives one or two windows around the break. Load the qualified, active advisors at the branch with their shifts, leave and active appointments in a fixed number of queries. Step through a 15-minute grid; a slot needs duration plus buffer and must fit inside a window. An advisor counts as free if the slot is inside a shift and overlaps no leave or appointment, using the interval rule `startA < endB && endA > startB`. Then apply minimum notice and maximum days ahead. The result is a list of slots with the number of free advisors.

**Q2. How does your caching stay correct?**
Results are cached for 60 seconds per branch, service and date. Every cache key includes a version number for that branch and date. When an appointment is saved or deleted, an event fires and a listener bumps the version, so the next request recomputes. A rescheduled appointment clears both the old and the new date. Changes to shifts or leave are not announced, so they wait out the 60-second TTL, which is acceptable for browsing. Booking never trusts the cache: it re-checks with `freeAdvisorsAt()` inside a transaction.

**Q3. Why an interface for advisor assignment?**
Different branches may want different policies, such as balancing workload, rotating, or preferring a named advisor. With an `AdvisorAssignmentStrategy` interface, the booking action asks the container for "an assignment strategy" and gets whichever is configured. Adding a policy means adding a class and one line in the provider. Tests can also bind a fake that returns a chosen advisor, which makes booking tests deterministic.

---

## Phase 4 — Customer booking journey

Scope note: reschedule, "nearest branch" (Haversine SQL) and geolocation were cut to fit the 5-6 hour budget. Everything else in the brief's Phase 4 is built.

### What was built
- Public, mobile-first, accessible Blade pages: choose service, choose branch (city filter), choose time (JSON-loaded week picker), details, verify, confirmed/manage.
- `StartBooking` (hold + emailed code), `VerifyBooking` (check code, confirm, create manage token), `CancelBooking` (2-hour cut-off), plus `HoldAppointment` with the double-booking defence.
- Hand-built `.ics` calendar file, queued confirmation email with the file attached.
- Scheduler: release expired holds (every minute), queue reminders 24 h ahead (every 15 min), mark no-shows 30 min after the start (every 5 min).
- `ReminderChannel` interface with a `LogChannel` default.

### Why each decision
| Decision | Why |
|---|---|
| Lock the advisor row, **then** re-check availability | Under MySQL's default REPEATABLE READ, a transaction's snapshot is fixed by its first normal read. The first statement is therefore `SELECT ... FOR UPDATE` on the advisor; the availability re-check after it sees everything the previous lock holder committed. |
| Try the next free advisor if the preferred one is taken | A lost race for one advisor shouldn't fail the customer while others are free. |
| Unique index as the last guard | Same "slot taken" message if the index rejects an insert. |
| Lapsed holds are expired inside the booking transaction | A hold past 10 minutes still occupies the unique index until marked expired; the booking action clears it for that advisor so customers aren't blocked waiting for the scheduler. |
| OTP: bcrypt hash, 5 attempts, 10 min, bound to the booking reference | A 6-digit code has only 1,000,000 values, so the attempt limit is the real protection and the hash only protects a leaked table. Binding the purpose to the reference means a code for booking A can't confirm booking B. |
| OTP mail is NOT queued | A queued mailable would store the plain code in the `jobs` table. It is sent immediately instead. |
| Confirmation mail is queued **and encrypted** (`ShouldBeEncrypted`) | It carries the manage token; the job payload is encrypted with `APP_KEY`. |
| Manage token: 40 random characters, stored as SHA-256, compared with `hash_equals` | The token is the customer's only credential. High entropy means a fast hash is enough (unlike passwords). Constant-time compare avoids timing leaks. Wrong reference and wrong token both return the same 404. |
| Manage pages send `Cache-Control: no-store` and `Referrer-Policy: no-referrer` | The token is in the URL, so it must not be cached or leak through the Referer header to other sites. |
| The verify page requires the booking reference in the session | Knowing a reference alone can't open someone else's verify page. |
| Reminders claim with `UPDATE ... WHERE reminder_sent_at IS NULL` | An atomic claim makes the job idempotent: a duplicate or retried job changes 0 rows and sends nothing. If sending fails the claim is released so the retry works. |
| Each `throttle` has its own name | `throttle:N,M` routes share ONE per-IP counter unless named, so ordinary page views were eating the resend budget. A test caught this. |
| `withoutScopedBindings()` on the book routes | `{service:code}/{branch:code}` makes Laravel treat branch as a child of service. They are independent models. |
| Slot picker uses `textContent`, never `innerHTML` | Nothing from the server can inject markup. |

### Concepts to know
- **Form Requests:** validation and input cleaning (phone and email normalisation in `prepareForValidation`) live outside the controller.
- **Transactions and `lockForUpdate`:** a pessimistic row lock serialises competing bookings for the same advisor.
- **Queues:** `ShouldQueue` mailables go to the `jobs` table; `ShouldBeEncrypted` encrypts the payload.
- **Scheduler:** `Schedule::command(...)->everyMinute()` entries are run by one cron line calling `schedule:run`; `withoutOverlapping()` stops a slow run from stacking.
- **Honest test limit:** the race is tested sequentially (second booking for a taken slot fails) and at the database level. True parallel requests are not simulated in the Pest suite.

### Likely interview questions

**Q1. Two customers click the last slot at the same moment. What happens?**
Both calls pick the same free advisor and enter `HoldAppointment::attempt` in a transaction. The first statement is `SELECT ... FOR UPDATE` on that advisor row, so one waits. The winner re-checks availability under the lock, inserts a held appointment and commits. The loser then gets the lock, re-checks, sees the new booking, and throws `SlotUnavailable`; the action tries other free advisors, and if none is left the customer sees "that time was just taken". If anything slipped through, the unique index on `active_slot_key` rejects the insert with the same message.

**Q2. How do you secure a login-free "manage my booking" link?**
The link contains a 40-character random token. Only its SHA-256 hash is stored, so a leaked database can't produce working links. It's compared with `hash_equals`, and a wrong reference or wrong token returns the same 404. The page is never cached and sends `no-referrer`. The token travels in an email whose queue payload is encrypted. Cancellation is allowed only until two hours before the start.

**Q3. How do you make the reminder job safe to retry?**
The job starts with one atomic `UPDATE appointments SET reminder_sent_at = now() WHERE id = ? AND status = 'confirmed' AND reminder_sent_at IS NULL`. Only the run that changes a row proceeds to send; a duplicate or retried job changes zero rows and returns. If the provider call throws, the job clears `reminder_sent_at` and rethrows, so the queue's retry can send. The trade-off is at-most-once if the process dies between claiming and sending, which is acceptable for reminders.
