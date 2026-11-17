# BranchBook architecture

BranchBook is a single Laravel 13 monolith. One codebase serves four kinds of traffic: customer web
pages, staff web screens (front desk, advisor desk, Backpack admin), a versioned REST API, and
background work (queue worker + scheduler). Everything shares one MySQL 8.0 database.

Diagrams are Mermaid, so they render directly on GitHub and in VS Code's Markdown preview.
Items marked *(Phase N)* are planned for that phase and don't exist yet.

## 1. Components

```mermaid
flowchart LR
    subgraph Clients
        C[Customer browser]
        S[Staff browser<br/>front desk / advisor / admin]
        D[Waiting-room display<br/>TV in branch]
        A[API client<br/>mobile app / partner]
    end

    subgraph App["Laravel app (PHP 8.3, Sail container)"]
        WEB[Web routes<br/>Blade + Breeze session auth]
        ADMIN[Backpack admin<br/>Phase 5]
        API[/api/v1<br/>Sanctum tokens<br/>Phase 7/]
        CORE[Actions + Services<br/>AvailabilityService, BookAppointment,<br/>CallNextTicket ...]
        WORKER[Queue worker<br/>php artisan queue:work]
        SCHED[Scheduler<br/>php artisan schedule:work]
    end

    DB[(MySQL 8.0<br/>app data, jobs, cache,<br/>sessions)]
    MAIL[SMTP<br/>Mailpit in dev]
    SMS[ReminderChannel<br/>LogChannel driver]

    C --> WEB
    S --> WEB
    S --> ADMIN
    D -->|polls JSON every 3-5 s| WEB
    A --> API

    WEB --> CORE
    ADMIN --> CORE
    API --> CORE
    CORE --> DB
    CORE -->|dispatch jobs| DB
    WORKER -->|reads jobs table| DB
    WORKER --> MAIL
    WORKER --> SMS
    SCHED -->|reminders, hold expiry,<br/>no-shows, retention| CORE
```

Why one monolith: the domain is small and transactional (bookings, queue tickets), and every write
needs row locks in the same database. Splitting into services would add network calls inside what
must be a single transaction.

Why the database queue driver: no extra infrastructure (Redis is optional), jobs are visible with
plain SQL, and failed jobs land in `failed_jobs` for retry.

## 2. Customer booking flow *(Phase 3–4)*

```mermaid
sequenceDiagram
    autonumber
    actor Cu as Customer
    participant W as Booking pages
    participant AV as AvailabilityService
    participant BK as BookAppointment action
    participant DB as MySQL
    participant Q as Queue worker
    participant M as Mail

    Cu->>W: choose service, branch, date
    W->>AV: slots(branch, service, date)
    AV->>DB: hours, closures, shifts, time off, active bookings
    AV-->>W: free slots (cached 60 s)
    Cu->>W: pick slot + enter name, email, phone
    W->>BK: hold(slot, customer)
    BK->>DB: BEGIN; lock advisor row; check overlap; INSERT status=held (10 min)
    Note over BK,DB: unique index on active_slot_key<br/>is the final guard against double booking
    BK-->>W: hold created
    W->>Q: send OTP email (6 digits, hash stored)
    Q->>M: OTP
    Cu->>W: enter OTP
    W->>BK: verify(code)
    BK->>DB: compare hash (constant time), status held → confirmed
    W-->>Cu: reference NWB-XXXX-XXXX, checklist, .ics
    W->>Q: confirmation email + manage link (token hash stored)
    Note over Q: Scheduler: expire unverified holds every minute,<br/>reminder 24 h before, no-show 30 min after start
```

## 3. Branch-day flow *(Phase 6)*

```mermaid
flowchart TD
    ARR([Customer arrives]) --> KIND{Has a booking?}
    KIND -->|yes| CHK[Front desk: Check in<br/>appointment → checked_in]
    KIND -->|no| WALK[Front desk: Walk-in form<br/>service + optional name]
    CHK --> TKT[Issue queue ticket<br/>prefix per service, e.g. M-007<br/>status = waiting]
    WALK --> TKT
    TKT --> DISP[Waiting-room display<br/>shows next tickets]
    DISP --> CALL[Advisor presses Call next<br/>transaction + lockForUpdate]
    CALL --> PICK{Who is next?}
    PICK -->|1st priority| P1[Checked-in appointment,<br/>qualified service, time reached,<br/>earliest first]
    PICK -->|2nd priority| P2[Walk-in, qualified service,<br/>FIFO]
    P1 --> CALLED[Ticket called<br/>display: M-007 → Desk 3]
    P2 --> CALLED
    CALLED --> SHOW{Customer comes?}
    SHOW -->|yes| SERVE[Start → serving]
    SHOW -->|no| NS[No-show / Recall]
    SERVE --> DONE[Complete → completed<br/>timestamps feed reports]
```

Each ticket records `issued_at`, `called_at`, `served_at` and `completed_at`; Phase 8's wait-time and
utilisation reports are computed from these.
