# PR: Phase 9 (rest) — data retention, security log, dependency audit

**Branch:** `feature/phase-9-retention-security` → `develop`

## Summary
Completes the hardening list from the brief: a retention job that anonymises long-inactive customers,
a dedicated security log channel, and a dependency audit in CI.

## Changes
- `branchbook:anonymise-customers` (`--dry-run`, `--months`), scheduled daily 03:00; `AnonymiseInactiveCustomers` action.
- Migration: `customers.anonymised_at` (indexed).
- `security` log channel (daily, 90 days): failed staff logins (hashed email), API token creation, booking-code lockouts.
- CI: `composer audit` step in `.github/workflows/ci.yml` (`composer audit` reports no advisories today).

## Results (real run in Sail)
```
$ sail composer ci
pint --test      -> PASS
phpstan (lvl 6)  -> [OK] No errors
pest             -> Tests: 380 passed (2618 assertions)
```
11 new tests: anonymises at 24 months and keeps appointments; 23 and 23.9 months untouched; sign-up date fallback;
skips customers with upcoming active appointments; dry run changes nothing; idempotent; OTP rows deleted;
audit entry holds only a count; custom months; command output and schedule; security log contains only a hash.

## How to test
1. `sail composer ci`
2. `sail artisan branchbook:anonymise-customers --dry-run`
3. `sail artisan schedule:list` shows the daily job.

## Risks / notes
- The job is destructive by design: always run `--dry-run` first on real data.
- Advisor leave handling and other items remain in the README "What I'd do next".
