# CV bullets (truthful: only what exists and was measured)

Measured on the last run: **401 Pest tests, 2,729 assertions**, Pint clean, Larastan level 6 with no
errors, **7/7 parallel concurrency checks**. Code coverage has **not** been measured, so no coverage
percentage is claimed.

**Choose 3-4:**

- Built **BranchBook**, a bank-branch appointment and queue system (Laravel 13, PHP 8.3, MySQL 8, Docker/Sail) with a customer booking flow, front desk check-in, advisor "Call next" and a live waiting-room display; 401 automated tests, Larastan level 6 and GitHub Actions CI on MySQL.
- Prevented double bookings in two layers: a MySQL **generated-column unique index** and **transactional row locks** (`SELECT ... FOR UPDATE`). Verified with parallel processes against the database: six customers racing for one slot produced exactly one booking per free advisor.
- Designed a pure, unit-tested **availability engine** (opening hours, breaks, public holidays, leave, per-service buffers, minimum notice) using timestamp arithmetic to avoid timezone bugs, with a versioned cache that invalidates instantly on booking changes and a pluggable advisor-assignment strategy.
- Found and fixed a **concurrency bug** that passed all unit tests (a `LIMIT 1 FOR UPDATE SKIP LOCKED` query locked the whole queue and starved simultaneous advisors) by writing a parallel load check; ticket numbering stayed unique and gapless across 8 simultaneous desks.
- Wrote the reporting layer as hand-tuned **MySQL 8 SQL** (recursive CTE, `RANK() OVER`, nearest-rank 90th percentile with window functions) with streamed CSV exports and Chart.js charts; verified every figure against hand-calculated data and confirmed an index change with `EXPLAIN` (59,996 rows examined down to 5,246).
- Implemented a privacy-first design for a bank scenario: email-OTP verification with hashed, attempt-limited codes, hashed manage-booking tokens, encrypted phone numbers, role- and branch-scoped policies with query scopes, masked contact data, an append-only audit log containing no personal data, a dedicated security log, a scheduled data-retention job that anonymises customers inactive for 24 months, CSP/security headers and `composer audit` in CI.

**Interview one-liner:** "I built the booking engine and queue for a fictional bank, and what I'm proudest of is
that my own parallel test found a locking bug the 300-test suite couldn't."
