# BranchBook — interview notes

One section per phase: what was built, why, the concepts behind it, and questions an interviewer
is likely to ask. Read these out loud before an interview; if you can't explain a line, ask.

---

## Phase 0 — Project setup, conventions and CI

### What was built
- A Laravel **13** app (latest stable on 2026-11-17, `laravel/framework` v13.43) on **PHP 8.3**, running in
  **Laravel Sail** (Docker) inside WSL2, with **MySQL 8.0** and **Mailpit**.
- **Breeze (Blade)** for staff login, **Pest 4** for tests, **Pint** for code style, **Larastan level 6**
  for static analysis, composer scripts that chain them, and **Bitbucket Pipelines** running all of it
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
