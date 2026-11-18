# PR: Phase 5 — admin panel (Laravel Backpack)

**Branch:** `feature/phase-5-admin-panel` → `develop`

## Summary
An admin panel at `/admin` built on Backpack 7 (free core), sharing the staff login. HQ administrators
and branch managers manage branches, hours, closures, services, advisors (skills, shifts, leave), staff
users, appointments, customers (masked) and read the audit log. Everything is branch-scoped.

## Changes
- **Screens (11 CRUDs):** branches, opening hours, closures and holidays, services, advisors, weekly shifts, time off, staff users, appointments, customers, audit log; plus a dashboard (today's bookings, queue length, average wait, no-shows this week).
- **Access:** Backpack uses the existing `web` guard (its own login/register routes are off); `CheckIfAdmin` lets in HQ and branch managers only (others get 403); managers only reach their own branch through the shared `visibleTo()` scopes and Form Requests.
- **Actions:** staff cancel and no-show on appointments (state machine + audit), audited "reveal contact details" on customers, status history from the audit log.
- **Form Requests** for every CRUD (unique codes, time ordering, one opening-hours row per weekday, strong passwords, managers confined to their branch).
- **Users:** role and branch are set explicitly in `UserCrudController` (they stay non-mass-assignable) with audit entries.
- **No PRO features:** list filters are built by hand (see notes); the document checklist is a one-per-line textarea backed by an accessor.
- **Privacy:** Gravatar avatars replaced by a locally drawn initials badge; Backpack assets are cached and served from our own origin.
- **CSP:** admin pages get their own policy (inline scripts allowed, still no framing or foreign origins).
- Models use Backpack's `CrudTrait`; `phpstan.neon` ignores only Backpack's magic fluent methods in `app/Http/Controllers/Admin`.

## Results (real run in Sail)
```
$ sail composer ci
pint --test      -> PASS
phpstan (lvl 6)  -> [OK] No errors
pest             -> Tests: 434 passed (2975 assertions)
```
33 new admin tests cover: guests, front desk and advisors blocked; the role matrix; per-branch scoping of every
list search; edit/create/delete rules for managers; validation of branches, hours, closures, services, advisors,
shifts, time off and users; role/branch assignment for staff users; appointment filters, cancel and no-show with
audit trail and cross-branch 404s; masked customers and the audited reveal; audit log filters and read-only
behaviour; dashboard figures from hand-checked data; the admin CSP profile.

Live check on the running dev server: login, then all 9 list pages and the dashboard return 200 with locally
served assets and no external script or style URLs.

## How to test
1. `sail artisan migrate:fresh --seed`, `sail artisan storage:link`, `sail artisan basset:cache`, `sail npm run build`
2. Log in at `/login` as the demo HQ admin, then open `/admin` (also try the Dubai manager: only Dubai data).
3. `sail composer ci`

## Risks / notes
- Not built: "book on behalf of a customer" and "reschedule" from the admin appointment list. Both exist for customers and the API; the admin currently offers cancel and no-show.
- The look of the pages was not reviewed by eye (the browser connection was unavailable); behaviour was verified through tests and HTTP.
- First `basset:cache` needs internet access once; after that assets are served locally.
