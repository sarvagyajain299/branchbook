# BranchBook — conventions for AI assistants and contributors

BranchBook is a bank-branch appointment and queue system for **Northwind Bank** (fictional, UAE).
The full brief is in `PROJECT_BRIEF.md`. Work happens one phase at a time; each phase is one
feature branch and one PR into `develop`.

## Environment
- Runs **only** through Laravel Sail (Docker) inside WSL2. There is no PHP on the Windows host.
- Prefix every PHP, Composer, Artisan and npm command with `./vendor/bin/sail` (alias `sail`).
- Stack: PHP 8.3, Laravel 13, MySQL 8.0, Mailpit, Pest 4, Pint, Larastan (level 6).
- Tests run against the MySQL `testing` database, never SQLite: the schema uses MySQL-only
  features (generated columns, window functions).

## Commands
| What | Command |
|---|---|
| Start / stop | `sail up -d` / `sail stop` |
| Fix code style | `sail composer lint` |
| Check style (CI) | `sail composer lint:check` |
| Static analysis | `sail composer stan` |
| Tests | `sail composer test` (or `sail pest --filter=Name`) |
| Everything CI runs | `sail composer ci` |

A change is done only when `lint:check`, `stan` and `test` all pass. Report real numbers from real runs.

## Code rules
- `declare(strict_types=1);` in every PHP file (Pint enforces it).
- **Thin controllers.** Controllers validate (Form Request), authorize (Policy), call one Action, return a response.
- Business logic lives in `app/Actions` (one public `handle()` / `__invoke()` per use case) and
  `app/Services` (reusable domain logic such as availability). Backpack controllers and Blade views
  contain no business logic.
- Structured input → **DTOs** (`app/Data`, `final readonly` classes). Statuses and roles → **backed enums** (`app/Enums`) with `label()`.
- Where behaviour can vary (advisor assignment, SMS/reminder channel) → an **interface** bound in a service provider.
- Authorization in **Policies** *and* query scopes (branch scoping).
- Eager-load relations on every list. `Model::shouldBeStrict()` is on outside production, so lazy loading throws.

## Time
- DB and app timezone: **UTC**. Display timezone: `config('app.display_timezone')` = `Asia/Dubai`.
- Convert only at the edges (views, emails, .ics, API output). Use `CarbonImmutable` everywhere (it is the default `Date` class).

## Security and privacy (it's a bank)
- Never collect Emirates ID, account or card numbers.
- Store only hashes of OTP codes and manage-booking tokens; compare with `hash_equals`.
- Phone numbers use the `encrypted` cast. Mask contact details in staff UIs unless the role allows full view.
- Never log full PII or secrets. Validate every input with a Form Request. Rate-limit public endpoints.

## Git
- Never commit to `main`. Integration branch: `develop`. Feature branch per phase: `feature/phase-N-short-name`.
- Conventional Commits (`feat:`, `fix:`, `test:`, `docs:`, `refactor:`, `chore:`, `ci:`); small commits whose body explains *why*.
- End of phase: append to `docs/INTERVIEW_NOTES.md` and write `docs/prs/phase-N.md`.
- Leave 1–2 `// TODO(sarvagya):` learning tasks per phase, with a hint.
