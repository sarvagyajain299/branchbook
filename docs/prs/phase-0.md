# PR: Phase 0 — project setup, conventions and CI

**Branch:** `feature/phase-0-setup` → `develop`

## Summary
Bootstraps BranchBook: a Laravel 13.43 app on PHP 8.3 in Sail (MySQL 8.0, Mailpit), Breeze (Blade)
staff auth, Pest 4, Pint, Larastan level 6, a GitHub Actions CI workflow, and the project docs.
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
- **CI:** `.github/workflows/ci.yml` (GitHub Actions) runs composer install → npm build → Pint →
  Larastan → migrate → Pest against a `mysql:8.0` service container, on every PR and on pushes to
  `develop` and `main`. A newer push cancels the older run on the same branch.
  (CI first ran on Bitbucket Pipelines, then moved to GitHub; the commit history shows both.)
- **Docs:** `README.md`, `CLAUDE.md`, `AGENTS.md`, `PROJECT_BRIEF.md`, `docs/ARCHITECTURE.md`
  (3 Mermaid diagrams), `docs/INTERVIEW_NOTES.md` (Phase 0).

## Results (real run, 2026-11-17, inside Sail)
```
$ sail composer ci
pint --test                      -> exit 0
phpstan analyse (level 6)        -> [OK] No errors
artisan test                     -> Tests: 30 passed (70 assertions), 4.76s
```
Versions: PHP 8.3.36, Laravel 13.43.0, MySQL 8.0.46, Pest 4.7.8, Larastan 3.15.0, Breeze 2.4.2.
Coverage isn't measured yet: the Sail image has no Xdebug/PCOV enabled. It gets added when there's
domain code worth measuring.

## Learning tasks (`TODO(sarvagya)`) — done
1. `tests/Feature/StaffLoginPageTest.php` checks the login page returns 200, has `<title>BranchBook</title>`,
   and the form posts to `route('login')`.
2. `AppServiceProvider` calls `DB::prohibitDestructiveCommands($this->app->isProduction())`, so
   `migrate:fresh`, `migrate:refresh`, `migrate:reset` and `db:wipe` refuse to run in production.

Be ready to explain both lines in review.

## How to test
1. `cd ~/code/branchbook && sail up -d`
2. Open http://localhost/up → green "Application up" page.
3. Open http://localhost/register, create a user → you land on the "verify your email" page.
4. Open http://localhost:8026 → the verification email is there; click the link → dashboard.
5. `sail composer ci` → all green.

## Checking the CI workflow on GitHub
- **On the PR:** the "Checks" section at the bottom shows `CI / Lint, static analysis and tests`.
  Click **Details** to see each step's log.
- **Invalid workflow file:** GitHub shows it in the **Actions** tab as a failed run with
  "Invalid workflow file" and the exact line, before any step runs.
- **Locally:** the YAML parses; triggers are `pull_request` plus pushes to `develop` and `main`.
- **Recommended once merged:** Settings → Branches → add a rule for `develop` and `main` requiring
  the `Lint, static analysis and tests` check, so nothing red can be merged.

## Risks / notes
- **Public registration is still on** (Breeze default). Staff accounts should be created by admins only;
  Phase 2 removes `/register`.
- **Actions minutes:** unlimited for public repos; private repos on the free plan get 2,000
  minutes/month. One run takes ~2–3 minutes with caches warm.
- Breeze installs Tailwind **v3** (`tailwind.config.js`), replacing Laravel 13's default v4 setup.
  That's fine for now. Revisit if we need v4-only features.

## Screenshots to take
1. http://localhost/up "Application up" page.
2. Breeze login page at http://localhost/login.
3. Terminal output of `sail composer ci` (green).
4. Mailpit inbox with the verification email.
5. GitHub PR page with the green CI check.
