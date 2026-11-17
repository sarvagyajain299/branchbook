# PR: Phase 0 — project setup, conventions and CI

**Branch:** `feature/phase-0-setup` → `develop`

## Summary
Bootstraps BranchBook: a Laravel 13.43 app on PHP 8.3 in Sail (MySQL 8.0, Mailpit), Breeze (Blade)
staff auth, Pest 4, Pint, Larastan level 6, a Bitbucket Pipelines config, and the project docs.
No domain code yet; that starts in Phase 1.

## Changes
- **Environment:** Sail with `mysql:8.0` (pinned; Sail defaults to 8.4) and Mailpit on host port
  **8026** (8025 is used by another local project). `.env.example` documents every host port.
- **Time:** `app.timezone` stays UTC for storage; new `app.display_timezone` = `Asia/Dubai` for output.
- **Safety defaults** in `AppServiceProvider`: `Model::shouldBeStrict()` outside production,
  `CarbonImmutable` as the default date class.
- **Auth:** Breeze Blade scaffold. `User` now implements `MustVerifyEmail`, so staff must verify
  their email before reaching authenticated pages.
- **Static analysis fixes** in Breeze code (Larastan level 6, no baseline):
  `VerifyEmailController` narrows the nullable user; `LoginRequest::throttleKey()` no longer passes a
  `Stringable` where a `string` is required.
- **Tooling:** `pint.json` (Laravel preset + `declare_strict_types`, applied to every PHP file),
  `phpstan.neon` (level 6), composer scripts `lint`, `lint:check`, `stan`, `test`, `ci`.
- **Tests:** `HealthCheckTest` (`/up`), `AppConfigTest` (UTC storage, Dubai display, immutable dates,
  UTC→Dubai conversion), plus Breeze's auth/profile tests. All run on MySQL `testing`, not SQLite.
- **CI:** `bitbucket-pipelines.yml` runs composer install → npm build → Pint → Larastan → migrate →
  Pest against a `mysql:8.0` service, on every PR and on pushes to `develop` and `main`.
- **Docs:** `README.md`, `CLAUDE.md`, `AGENTS.md`, `PROJECT_BRIEF.md`, `docs/ARCHITECTURE.md`
  (3 Mermaid diagrams), `docs/INTERVIEW_NOTES.md` (Phase 0).

## Results (real run, 2026-11-17, inside Sail)
```
$ sail composer ci
pint --test                      -> exit 0
phpstan analyse (level 6)        -> [OK] No errors
artisan test                     -> Tests: 1 todo, 29 passed (67 assertions), 2.47s
```
Versions: PHP 8.3.36, Laravel 13.43.0, MySQL 8.0.46, Pest 4.7.8, Larastan 3.15.0, Breeze 2.4.2.
Coverage isn't measured yet: the Sail image has no Xdebug/PCOV enabled. It gets added when there's
domain code worth measuring.

## Your tasks before merge — `TODO(sarvagya)`
1. `tests/Feature/StaffLoginPageTest.php`: write the login-page test and remove `->todo()`.
2. `app/Providers/AppServiceProvider.php`: block destructive Artisan commands in production.

Then run `sail composer ci`. It must be green with **30 passed, 0 todo**.

## How to test
1. `cd ~/code/branchbook && sail up -d`
2. Open http://localhost/up → green "Application up" page.
3. Open http://localhost/register, create a user → you land on the "verify your email" page.
4. Open http://localhost:8026 → the verification email is there; click the link → dashboard.
5. `sail composer ci` → all green.

## Push to Bitbucket (one-time)
1. On bitbucket.org: **Create repository** → name `branchbook`, **no** README/.gitignore (keep it empty).
2. Make sure WSL has an SSH key added to Bitbucket (Personal settings → SSH keys):
   `ls ~/.ssh/id_ed25519.pub || ssh-keygen -t ed25519 -C "bitbucket"`, then paste
   `cat ~/.ssh/id_ed25519.pub` into Bitbucket.
3. Push all three branches:
   ```bash
   git remote add origin git@bitbucket.org:<workspace>/branchbook.git
   git push -u origin main develop feature/phase-0-setup
   ```
4. Repository settings → Pipelines → **Settings** → enable Pipelines.
5. Create the PR `feature/phase-0-setup` → `develop`, paste this file as the description.

## Checking the pipeline config in Bitbucket
- **Before pushing:** Bitbucket has an online `bitbucket-pipelines.yml` validator (search "Bitbucket
  Pipelines validator"). Paste the file in; it checks structure and keywords.
- **After pushing:** the PR page shows a Pipelines build. An invalid file fails immediately with a
  "Configuration error" before any step runs; a valid one runs the `Lint, static analysis and tests`
  step. Click it to see each command's log.
- Locally we confirmed the YAML parses, has no tabs, and defines `pull-requests` plus
  `develop`/`main` branch triggers.

## Risks / notes
- **Public registration is still on** (Breeze default). Staff accounts should be created by admins only;
  Phase 2 removes `/register`.
- **First pipeline run is slow** (~5–8 min): it installs PHP extensions and Node on `php:8.3-cli`.
  A prebuilt CI image would cut that; noted for Phase 9.
- **Bitbucket free plan** includes 50 build minutes/month. The `size: 2x` step uses minutes twice as
  fast; drop to the default size if you hit the limit.
- Breeze installs Tailwind **v3** (`tailwind.config.js`), replacing Laravel 13's default v4 setup.
  That's fine for now. Revisit if we need v4-only features.

## Screenshots to take
1. http://localhost/up "Application up" page.
2. Breeze login page at http://localhost/login.
3. Terminal output of `sail composer ci` (green).
4. Mailpit inbox with the verification email.
5. Bitbucket PR page with the green pipeline (after you push).
