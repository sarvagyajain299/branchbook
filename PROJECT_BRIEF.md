# Claude Code Prompt — Project 2: BranchBook (Laravel Bank Branch Appointment & Queue System)

> **How to use:** Create an empty folder `branchbook/`, put this file in it as `PROJECT_BRIEF.md`, open Claude Code there and say:
> *"Read PROJECT_BRIEF.md. Start in plan mode. Do Phase 0 only, then stop for my review."*
> Run one phase at a time. Review, test and merge each phase's pull request before starting the next.

---

## 1. Role and goal

You are a senior Laravel engineer at a Dubai digital agency that builds secure portals and admin systems for banks, government entities and enterprises, using Laravel with Laravel Backpack admin panels. You are pairing with me, a final-year Computer Science student, to build a **production-quality portfolio project** that I must be able to explain line by line in a developer interview.

Build **BranchBook** for **Northwind Bank**, a fictional UAE retail bank with branches in Dubai, Abu Dhabi and Sharjah. BranchBook lets customers book in-branch appointments online and runs the branch floor on the day:

1. **Customers** pick a service (account opening, mortgage consultation, card services, etc.), a branch and a time slot, verify their email with a one-time code, and get a booking reference, a calendar invite and a checklist of documents to bring. They can reschedule or cancel through a secure link and get a reminder the day before.
2. **Availability** is calculated from branch opening hours, UAE public holidays and closures, service duration, and which advisors are qualified, on shift and free. Double bookings must be impossible.
3. **Front desk staff** check in arriving customers and issue walk-in tickets. Both get a **queue token** (for example `A-042`).
4. **Advisors** press **Call next** at their desk. The system picks the right customer, and a **waiting-room display** shows "A-042 → Desk 3".
5. **Managers and HQ admins** manage branches, services, advisors and schedules in an **admin panel**, and see **reports** on utilisation, no-shows and wait times.
6. A token-authenticated **REST API** exposes availability, bookings and queue operations.

Northwind Bank is a fictional brand. Do not use any real bank's name, logo, colours or data. All seed data is invented.

## 2. Non-negotiable engineering rules

1. **Teaching mode.** I need to understand everything. After each phase, append a section to `docs/INTERVIEW_NOTES.md` covering: what was built, why each design decision was made, the Laravel and database concepts involved (for example the request lifecycle, service container and dependency injection, routing, middleware, controllers, Form Requests, Eloquent relationships, eager loading and N+1, migrations, transactions and row locks, queues, scheduler, policies, events/observers), and 3 likely interview questions with model answers. In each phase, leave 1–2 small, well-scoped tasks marked `// TODO(sarvagya):` with a hint, and ask me to implement them before you merge.
2. **Plan before code.** At the start of each phase, give me a short plan (files, approach, risks) and wait for approval.
3. **Git workflow on Bitbucket.** The remote is a **Bitbucket** repository. Never commit directly to `main`. Use `develop` as the integration branch and one feature branch per phase (`feature/phase-3-availability-engine`). Use Conventional Commits (`feat:`, `fix:`, `test:`, `docs:`, `refactor:`, `chore:`), with small commits whose messages explain *why*. At the end of each phase, write the pull request description in `docs/prs/phase-N.md` (summary, changes, test steps, risks, screenshots to take) so I can open the PR into `develop` and self-review it.
4. **Standards.** PHP 8.3, latest stable Laravel (check and tell me which), `declare(strict_types=1)`, Laravel Pint, Larastan level 6+. Keep controllers thin. Business logic lives in **Action and Service classes**, with **DTOs** for structured input, **backed enums** for statuses and roles, **interfaces** where behaviour can vary (advisor assignment strategy, SMS driver), and **Policies** for authorization. No business logic in Blade views or Backpack controllers.
5. **Security and privacy by design (it's a bank).** Validate every input with Form Requests. Use mass-assignment protection, CSRF on web routes, rate limiting on public endpoints and OTP, and constant-time comparison for codes and tokens. Store only **hashes** of OTP codes and manage-booking tokens. **Never collect** Emirates ID numbers, account numbers or card numbers: say so on the booking form. Encrypt the phone number at rest (Laravel `encrypted` cast) and explain the search trade-off. Mask customer contact details in staff UIs unless the role allows full view. Never log full PII or secrets.
6. **Tests are part of done.** Use Pest (unit + feature), factories and seeders. A phase isn't done until its tests pass and Pint and Larastan are clean. Report **real** test counts and coverage from actual runs, with the command shown. Never invent numbers.
7. **Time is hard: be explicit.** The app timezone is `Asia/Dubai`. Store datetimes in UTC and convert at the edges, and explain why. All slot logic must use `CarbonImmutable`.
8. **Windows host.** I am on Windows. Use **Laravel Sail** (Docker) inside WSL2: MySQL 8.0, Mailpit, Redis optional. Give commands that work in a WSL terminal.
9. **Stop if blocked.** If something needs a paid licence (for example Backpack PRO add-ons), an account or a decision from me, stop and ask. Use only free/open-source packages unless I approve otherwise.

## 3. Tech stack

- Laravel (latest stable), PHP 8.3, **MySQL 8.0**, Sail, Mailpit.
- **Admin panel:** **Laravel Backpack** (free core CRUD), which mirrors what the target agency uses for government portals. Where a feature is PRO-only (for example charts or some field types), build it yourself as a custom Backpack operation or Blade page with Chart.js.
- **Staff auth:** Laravel Breeze (Blade) with session login. **API auth:** Laravel Sanctum tokens with abilities. **Customers have no passwords**: they verify with an email OTP and manage bookings with a signed, hashed-token link.
- **Front-end:** Blade + Tailwind + small vanilla JS modules (or Alpine.js). Mobile-first, WCAG AA accessible, RTL-ready (use logical CSS properties).
- **Queues:** database driver, with failed-job handling. **Scheduler:** reminders, hold expiry, no-show marking, data retention.
- **Calendar invites:** generate `.ics` files (`spatie/icalendar-generator` or hand-built, explaining the format).
- **API docs:** OpenAPI 3 (`docs/openapi.yaml`, hand-written or generated with Scribe) plus a Bruno/Postman collection.
- **Quality:** Pest, Pint, Larastan. CI on **Bitbucket Pipelines**.

## 4. Domain model (MySQL)

Design migrations with correct types, foreign keys, **unique constraints and indexes**, and explain each index in the notes.

| Table | Key columns | Notes |
|---|---|---|
| `users` | name, email, password, `role` (enum: `hq_admin`, `branch_manager`, `advisor`, `front_desk`), branch_id (nullable for HQ) | Staff only |
| `branches` | code (unique, e.g. `DXB-MAR`), name, city, address, latitude, longitude, phone, is_active | |
| `branch_hours` | branch_id, weekday (1–7), opens_at, closes_at, break_start, break_end (nullable) | Unique (branch_id, weekday). Seed UAE banking hours: Mon–Thu 08:00–15:00, Fri 08:00–12:00, Sat–Sun closed |
| `closures` | branch_id (nullable = all branches), date, reason | UAE public holidays + ad-hoc closures. Islamic holiday dates shift every year, so admins can edit them |
| `services` | code (unique), name, category, description, duration_minutes, buffer_minutes, queue_prefix (e.g. `A`, `M`, `C`), required_documents (JSON), min_notice_hours, max_days_ahead, is_active | |
| `advisors` | user_id (unique), branch_id, desk_label, is_active | |
| `advisor_service` | advisor_id, service_id | Which services each advisor is qualified for |
| `advisor_shifts` | advisor_id, weekday, starts_at, ends_at | Recurring weekly pattern |
| `advisor_time_off` | advisor_id, starts_at, ends_at, reason | Leave and training |
| `customers` | first_name, last_name, email (indexed), phone (encrypted), email_verified_at, marketing_opt_in, last_activity_at | No ID or account numbers, ever |
| `otp_codes` | email, purpose, code_hash, expires_at, attempts, consumed_at | Max 5 attempts, 10-minute expiry |
| `appointments` | reference (unique, e.g. `NWB-7KQ4-P2XD`), customer_id, branch_id, service_id, advisor_id, starts_at, ends_at, status (enum below), channel (`online`, `staff`), hold_expires_at, manage_token_hash, cancelled_reason, rescheduled_from_id, notes | See the double-booking design below |
| `queue_tickets` | branch_id, service_date, service_id, sequence, display_code (`A-042`), appointment_id (nullable for walk-ins), status (`waiting`, `called`, `serving`, `completed`, `no_show`, `cancelled`), issued_at, called_at, served_at, completed_at, advisor_id, desk_label | Unique (branch_id, service_date, queue_prefix, sequence) |
| `audit_logs` | actor_type, actor_id, action, subject_type, subject_id, changes (JSON, PII masked), ip_hash, created_at | Append-only. Written by model observers and actions |

**Appointment statuses:** `held` → `confirmed` → `checked_in` → `in_service` → `completed`, plus `cancelled`, `no_show` and `expired` (a hold that was never verified). Enforce allowed transitions in one place (a state transition method on the enum or a small state machine) and reject invalid ones.

**Double-booking prevention (explain this carefully in the notes):**
- Add a MySQL **generated column** `active_slot_key` that equals `CONCAT(advisor_id, '|', starts_at)` when the status is `held`, `confirmed`, `checked_in` or `in_service`, and `NULL` otherwise, with a **unique index** on it. MySQL allows many `NULL`s in a unique index, so cancelled and expired rows never block a slot, but two active bookings for the same advisor and start time can't exist even if application code has a bug.
- On top of that, the booking action runs in a **transaction** that locks the advisor row (`lockForUpdate`), checks for **overlapping** active appointments (`starts_at < :end AND ends_at > :start`, since services have different lengths), then inserts. Catch the unique-violation exception and return a friendly "that slot was just taken" error.

Model relationships: Branch hasMany Advisors, BranchHours, Closures, Appointments, QueueTickets; Advisor belongsTo User and Branch, belongsToMany Services, hasMany Shifts/TimeOff/Appointments; Appointment belongsTo Customer, Branch, Service, Advisor, hasOne QueueTicket. Use eager loading on every list, and enable `Model::preventLazyLoading()` outside production so N+1 queries fail loudly in tests.

## 5. Phases (one branch and PR per phase)

### Phase 0 — Project setup, conventions and CI
- New Laravel app with Sail (MySQL, Mailpit), Breeze (Blade), Pest, Pint, Larastan, `.env.example`, `README.md` (setup in under 10 commands), `CLAUDE.md` (conventions), and `docs/ARCHITECTURE.md` with Mermaid diagrams of the customer booking flow, the branch-day flow (check-in → queue → call → serve), and the components (web, API, queue worker, scheduler, MySQL, mail).
- `bitbucket-pipelines.yml`: on every PR and push, run `composer install`, Pint (test mode), Larastan and Pest against a **MySQL 8 service container**, with Composer caching.
- **Done when:** the app boots in Sail, the pipeline config is valid (explain how to check it in Bitbucket), and one example Pest test passes.

### Phase 1 — Domain model, enums and seed data
- Migrations (including the generated column and unique index), models, casts (including `encrypted` for phone), backed enums with labels, relationships, factories.
- `DemoSeeder`: 3 branches (Dubai Marina, Abu Dhabi Corniche, Sharjah Al Majaz, all fictional addresses), UAE banking hours, UAE public holidays for 2026–2027 (marked "verify Islamic dates"), 6 services (Personal Account Opening, Mortgage Consultation, Card Services, Wealth Advisory, Remittances, Business Account Opening) with realistic durations and document checklists, 12 advisors with mixed skills and shifts, staff users for every role, about 150 past and future appointments and a week of queue history (so the reports in Phase 8 have data).
- **Tests:** relationship tests; the generated-column unique index rejects two active bookings for the same advisor slot but allows an active one alongside a cancelled one; invalid status transitions throw.

### Phase 2 — Staff auth, roles and branch-scoped policies
- Breeze login for staff. Role enum on users. Policies for Branch, Service, Advisor, Appointment, QueueTicket, Customer and AuditLog.
- **Scoping rules:** `hq_admin` sees everything. `branch_manager` manages only their own branch. `front_desk` checks in and issues tickets at their branch and sees masked contact details. `advisor` sees their own schedule and queue. Apply scoping in policies **and** in queries (a reusable query scope), and explain why both are needed.
- Session timeout for staff, and login throttling.
- **Tests:** a policy matrix test covering every role × ability, plus cross-branch access attempts returning 403.

### Phase 3 — Availability engine (the core algorithm)
Build an `AvailabilityService` as a pure, heavily tested service. It doesn't depend on HTTP.
- **Input:** branch, service, date (or date range). **Output:** a list of slots, each with a start time, end time and the number of qualified, free advisors.
- **Algorithm:**
  1. branch hours for that weekday, minus breaks, minus closures (branch-specific and all-branch),
  2. qualified, active advisors for the service, intersected with their shift that day, minus time off,
  3. step through candidate start times on a 15-minute grid, where a slot needs `duration + buffer` minutes and must end before closing or a break,
  4. remove advisor windows that overlap active appointments or unexpired holds,
  5. apply `min_notice_hours` (no same-hour bookings) and `max_days_ahead`.
- **Advisor assignment** behind an `AdvisorAssignmentStrategy` interface, with a default `LeastBookedTodayStrategy` (load balancing) and a `RandomStrategy` for comparison, bound in a service provider. Explain the strategy pattern and dependency injection in the notes.
- Cache availability per (branch, service, date) for 60 seconds, and bust the cache on any booking change for that branch and date (event + listener).
- **Tests (be thorough, this is the part interviewers will probe):** a normal day; the Friday short day; a closed weekend; a public holiday; a lunch break splitting slots; the last slot that fits exactly before closing and the one that doesn't; an advisor on leave; no qualified advisors; overlapping bookings of different lengths; minimum notice; maximum days ahead; a 1-minute-past boundary; and the cache being invalidated after a booking.

### Phase 4 — Customer booking journey
Mobile-first, accessible Blade pages with a progress indicator:
1. **Choose a service:** cards with duration and "what to bring".
2. **Choose a branch:** filter by city, plus **"Nearest to me"** using browser geolocation (with permission) and a **Haversine distance query in SQL** (explain the formula and its accuracy).
3. **Choose a date and time:** a week view of available slots from `AvailabilityService`, loaded via a small JSON endpoint with no full page reload.
4. **Your details:** name, email, phone (UAE format), consent, and a clear notice that the bank will never ask for card or account numbers here.
5. **Verify:** create a `held` appointment with a **10-minute hold**, email a 6-digit OTP (hashed in the database, 5 attempts, resend throttled), and confirm on a correct code.
6. **Confirmed:** reference number, summary, documents checklist, an "Add to calendar" `.ics` download, and the same in a queued confirmation email.
- **Manage booking:** a link in the email (`/booking/{reference}?token=…`, token stored hashed) to view, **reschedule** (the new slot is booked transactionally before the old one is released, with `rescheduled_from_id` kept) or **cancel** with a reason, up to a cut-off (2 hours before the start).
- **Scheduled jobs:** release expired holds every minute; send reminders 24 hours before (queued, idempotent so a retry never double-sends); mark `no_show` 30 minutes after the start if not checked in.
- **SMS/WhatsApp reminders** behind a `ReminderChannel` interface, with a `LogChannel` default driver that writes to the log. Document how a real provider would plug in. Don't integrate a paid provider.
- **Tests:** the full happy path; a slot taken by someone else between viewing and booking (friendly error); OTP expiry, wrong code and lockout; a hold expiring and freeing the slot; reschedule and cancel rules; the reminder sent exactly once.

### Phase 5 — Admin panel (Laravel Backpack)
Admin modules, each with list filters, columns and Form Request validation, and every query branch-scoped:
- **Branches**, with nested opening hours and closures.
- **Services**, with a repeatable document-checklist field and duration/buffer/notice settings.
- **Advisors**, with skills (many-to-many), weekly shifts, time off and desk label.
- **Appointments:** filter by branch, date, service, status and advisor. Actions: book on behalf of a customer (uses the same booking action), reschedule, cancel, mark no-show. Show the status history from the audit log.
- **Customers:** masked contact details by default, with a "reveal" action limited by role and **written to the audit log**. Show booking history.
- **Audit log:** a read-only viewer with filters by actor, action, subject and date.
- **Users and roles** (HQ admins only).
- **Dashboard:** today's bookings per branch, current queue length and average wait, and no-shows this week (custom Blade + Chart.js if charts need Backpack PRO).
- Document which parts used Backpack free core and which you built custom, and why.

### Phase 6 — Branch-day operations: front desk, queue and waiting-room display
- **Front desk screen** `/branch/{branch}/desk`: today's appointments sorted by time with check-in buttons. Checking in moves the appointment to `checked_in` and issues a **queue ticket** with the service's prefix (`M-007`). A **walk-in** form (service + optional name) issues a walk-in ticket. Ticket numbering resets daily per branch and prefix, generated safely under concurrency (locked counter row or a unique-key retry; explain the choice).
- **Advisor screen** `/branch/{branch}/my-desk`: **Call next**, **Start**, **Complete**, **No-show**, **Recall**. The `CallNextTicket` action picks, in a transaction with `lockForUpdate`: (1) checked-in appointment tickets for services the advisor is qualified for whose appointment time has arrived, earliest first; then (2) walk-in tickets for qualified services, in FIFO order. Two advisors pressing "Call next" at the same moment must never get the same customer.
- **Waiting-room display** `/display/{branch}`: a full-screen, high-contrast, large-type page showing the **now-serving** list (`A-042 → Desk 3`), the next few tickets, and an optional chime. It polls a lightweight JSON endpoint every 3–5 seconds. Optional stretch: real-time updates with **Laravel Reverb** websockets, comparing polling vs websockets in the notes.
- Record `issued_at`, `called_at`, `served_at` and `completed_at` on every ticket, since Phase 8's wait-time reports depend on them.
- **Tests:** ticket numbering per prefix and day; the call-next priority rules; skill filtering; a concurrency test that two simultaneous calls produce two different tickets (simulate it if true concurrency is hard in tests, and say so); no-show and recall transitions.

### Phase 7 — REST API (Sanctum)
Versioned under `/api/v1`, with JSON API Resources, a consistent error format (`{"message","errors"}`), pagination meta, per-token abilities and rate limits.
- **Public (rate-limited):** `GET /branches` (filter by city; `?lat=&lng=` for distance sort), `GET /services`, `GET /branches/{branch}/availability?service=&date=`.
- **Booking:** `POST /bookings` (creates a hold and sends the OTP), `POST /bookings/{reference}/verify`, `GET /bookings/{reference}`, `PATCH /bookings/{reference}` (reschedule), `DELETE /bookings/{reference}` (cancel). The last three require the manage token in a header.
- **Staff (token abilities):** `POST /auth/tokens`, `DELETE /auth/tokens/current`, `GET /branches/{branch}/queue`, `POST /branches/{branch}/queue/walk-ins`, `POST /queue/call-next`, `PATCH /queue-tickets/{ticket}` (start/complete/no-show).
- API endpoints reuse the **same Action classes** as the web screens, with no duplicated logic.
- `docs/openapi.yaml` plus a Bruno/Postman collection with example requests.
- **Tests:** auth and abilities enforced, response shapes match the resources, validation errors return 422, the rate limit returns 429, and cross-branch staff access returns 403.

### Phase 8 — Reports and exports (hand-written SQL)
Write these as **raw SQL with bound parameters** (`DB::select`) to show SQL skill. For each, explain the joins, `GROUP BY`, the indexes used and the `EXPLAIN` output in the notes.
- **Advisor utilisation per week:** booked service minutes ÷ available shift minutes per advisor, using a **window function** (`RANK() OVER (PARTITION BY branch_id ORDER BY utilisation DESC)`) to rank advisors within each branch.
- **No-show rate** by branch × service × weekday.
- **Wait times:** average and 90th-percentile wait (`called_at − issued_at`) per branch and hour of day, split by appointment vs walk-in. Explain how you computed the percentile in MySQL.
- **Peak hours heatmap:** tickets issued by weekday × hour.
- Build one of these again with the query builder and compare readability vs control.
- An admin **Reports** page with date-range and branch filters and Chart.js charts, plus **streamed CSV exports** (`response()->streamDownload` with `lazy()` / chunking) for each report and for appointments.
- **Tests:** report figures match hand-calculated values from a small fixed dataset; CSV headers and row counts are correct; branch managers only see their own branch.

### Phase 9 — Hardening, privacy, observability and release
- **Audit logging** complete: every staff action on appointments, customers and queue tickets, plus contact-detail reveals and logins. PII is masked inside the logged changes.
- **Data retention:** a scheduled command anonymises customers with no activity in 24 months (keeps anonymous stats, removes name, email and phone), with a dry-run mode and tests.
- Security headers (CSP, HSTS-ready, `X-Frame-Options`, `Referrer-Policy`), secure cookie settings, and a dependency audit (`composer audit`).
- Logging: structured context on bookings and queue actions, a separate log channel for security events, and no PII or secrets in logs.
- `php artisan branchbook:health`: checks the DB, queue backlog, failed jobs in the last 24 h, the scheduler heartbeat and mail config. Laravel's built-in `/up` route for uptime checks.
- Final `README.md` (features, screenshots list, architecture diagrams, setup, test and lint commands, API docs link, a "What I'd do next" section).
- `docs/QA.md` end-to-end checklist, and run it: book online → OTP → confirmation + `.ics` → reminder → arrive → front desk check-in → ticket on display → advisor calls → complete → appears in reports. Then reschedule, cancel, no-show, walk-in, and two advisors calling at once.
- `docs/CV_BULLETS.md`: 3–4 truthful CV bullets using only real features and measured numbers (test count, coverage %, etc.).

### Phase 10 (optional stretch) — Arabic and RTL
- Laravel localisation with `lang/en.json` and `lang/ar.json` for all customer-facing pages and emails, a language switcher, `dir="rtl"` layouts using logical CSS properties, Arabic-friendly fonts, and Arabic-Indic vs Western digits handled consistently (decide and document the rule). Have me review the Arabic copy; don't claim it's professionally translated.

## 6. Deliverables checklist

- [ ] Sail environment, one-command setup, realistic demo seed data
- [ ] Availability engine with thorough edge-case tests, and pluggable advisor-assignment strategies
- [ ] Customer booking journey: nearest branch (Haversine SQL), slot picker, OTP verification with holds, confirmation + `.ics`, manage link (reschedule/cancel), reminders, no-show marking
- [ ] Double-booking prevention with a generated-column unique index **and** transactional row locks
- [ ] Backpack admin: branches, hours, closures, services, advisors, shifts, time off, appointments, masked customers, audit log, users, dashboard
- [ ] Front desk check-in, walk-ins, queue tokens, concurrency-safe "Call next", waiting-room display
- [ ] Versioned Sanctum REST API with OpenAPI spec and collection
- [ ] Hand-written SQL reports (window function, percentile, heatmap) with `EXPLAIN`, Chart.js charts and streamed CSV exports
- [ ] Privacy and security: no sensitive banking identifiers collected, encrypted phone, masking, audit log, retention job
- [ ] Pest suite, Pint and Larastan clean, Bitbucket Pipelines green (real numbers recorded)
- [ ] Docs: README, ARCHITECTURE, openapi.yaml, QA, INTERVIEW_NOTES, CV_BULLETS, per-phase PR notes
- [ ] Clean Bitbucket history: feature branches → PRs into `develop` → release merge to `main`

Deployment to a Linux VPS behind Cloudflare is handled by a separate prompt (`03-deploy-vps-cloudflare.md`). Keep all environment differences in `.env` so staging and production run the same code.
