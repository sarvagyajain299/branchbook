# PR: Phase 1 — domain model, enums, seed data, staff roles and policies

**Branch:** `feature/phase-1-domain-model` → `develop`

## Summary
The whole data layer: 12 tables (including the MySQL generated-column guard against double
bookings), enums with a single state machine, models, factories, a deterministic demo seeder, and
role-based, branch-scoped authorization (policies plus query scopes).

## Changes
- **Migrations:** branches, users (+role, branch_id), branch_hours, closures, services, advisors (+skills, shifts, time off), customers, otp_codes, appointments, queue_tickets, audit_logs.
- **Double-booking guard:** `appointments.active_slot_key` stored generated column + unique index.
- **Enums:** `Role`, `AppointmentStatus`, `AppointmentChannel`, `TicketStatus`; invalid transitions throw `InvalidStatusTransition`.
- **Models** with typed relations, casts (`encrypted` phone, immutable dates), non-mass-assignable status/role, append-only `AuditLog`.
- **Authorization:** policies for Branch, Appointment, QueueTicket, Customer, AuditLog and `visibleTo()` scopes.
- **Seeder:** 3 branches, 6 services, 12 advisors, staff for every role, UAE holidays 2026-2027 (Islamic dates flagged "verify"), about 137 appointments, about 148 queue tickets. All logins use password `password` (dev only).
- **Auth:** public `/register` removed; staff session lifetime 30 minutes.
- **Quality:** Larastan needed `@property` annotations for enum casts.

## Results (real run in Sail)
```
$ sail composer ci
pint --test      -> PASS, 103 files
phpstan (lvl 6)  -> [OK] No errors
pest             -> Tests: 173 passed (1375 assertions)
```

## How to test
1. `sail artisan migrate:fresh --seed`
2. Log in at http://localhost/login as `hq@northwind.test` / `password` (also `manager.dxb@`, `desk.dxb@`, `advisor.dxb1@northwind.test`).
3. `sail composer ci`.

## Risks / notes
- Policies are tested at the Gate level; HTTP 403 tests come with the screens in later blocks.
- The unique index only blocks identical start times; overlap protection (transaction + row lock) is added with the booking action.
- Seeded advisor shifts and branch hours have no lunch break; availability tests build their own.
