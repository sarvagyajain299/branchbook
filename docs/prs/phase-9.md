# PR: Phase 9 (partial) — hardening, audit log, health check and release docs

**Branch:** `feature/phase-9-release` → `develop`

## Summary
Audit logging for staff and customer actions, security headers, a health command with a scheduler
heartbeat, and the final documentation set (README, QA checklist, CV bullets, architecture status).

## Changes
- `Audit::record()` and listeners for login/failed login; wired into check-in, walk-in, call, start, complete, no-show, recall, confirm and cancel. No personal data in `changes`; IP stored as a keyed hash.
- `SecurityHeaders` middleware (CSP, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, opt-in HSTS).
- `branchbook:health` (DB, queue backlog, failed jobs, scheduler heartbeat, mail config) and a per-minute heartbeat.
- Docs: final `README.md`, `docs/QA.md`, `docs/CV_BULLETS.md`, `docs/ARCHITECTURE.md` status section, Phase 9 notes.

## Results (real run in Sail)
```
$ sail composer ci
pint --test      -> PASS
phpstan (lvl 6)  -> [OK] No errors
pest             -> Tests: 313 passed (2092 assertions)
```

## How to test
1. `sail composer ci`
2. `curl -I http://localhost` and look for the security headers.
3. `sail artisan branchbook:health` (scheduler shows FAIL until `schedule:work` runs).
4. Do a check-in, then look at the `audit_logs` table.

## Risks / notes
- CSP includes `'unsafe-eval'` because of Alpine's standard build.
- Not built: data-retention job, separate security log channel, `composer audit` in CI.
