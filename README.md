# BranchBook

Branch appointment booking and queue management for **Northwind Bank**, a fictional UAE retail bank.
Customers book in-branch appointments online; on the day, front desk staff check them in, advisors
call the next customer, and a waiting-room display shows who goes to which desk.

> Portfolio project. Northwind Bank, its branches and all data are invented.

**Status:** Phase 0 (setup, tooling, CI). See [`PROJECT_BRIEF.md`](PROJECT_BRIEF.md) for the roadmap
and [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for diagrams.

## Stack
Laravel 13 · PHP 8.3 · MySQL 8.0 · Laravel Sail (Docker) · Breeze (Blade + Tailwind) · Pest 4 ·
Pint · Larastan level 6 · GitHub Actions.

## Setup (Windows: run everything in a WSL2 Ubuntu terminal)

Requirements: Docker Desktop with WSL2 integration enabled, and git inside WSL.
Clone into the Linux filesystem (`~/code`), **not** `/mnt/c/...`, or Docker will be very slow.

```bash
git clone https://github.com/sarvagyajain299/branchbook.git ~/code/branchbook && cd ~/code/branchbook
cp .env.example .env
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd)":/var/www/html -w /var/www/html \
  laravelsail/php83-composer:latest composer install --ignore-platform-reqs
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm ci
./vendor/bin/sail npm run build
```

Then open:

| What | URL |
|---|---|
| App | http://localhost |
| Health check | http://localhost/up |
| Mailpit (all outgoing mail) | http://localhost:8026 |

Tip: `alias sail='./vendor/bin/sail'` in `~/.bashrc` so the commands below are shorter.
If port 80, 3306 or 8026 is taken, change `APP_PORT`, `FORWARD_DB_PORT` or
`FORWARD_MAILPIT_DASHBOARD_PORT` in `.env` and run `sail up -d` again.

## Quality checks

| Command | What it does |
|---|---|
| `sail composer lint` | Fix code style (Pint) |
| `sail composer lint:check` | Check style only, as CI does |
| `sail composer stan` | Larastan static analysis (level 6) |
| `sail composer test` | Pest test suite (MySQL `testing` database) |
| `sail composer ci` | All three, in CI order |

## Repository workflow
`main` (releases) ← `develop` (integration) ← `feature/phase-N-*` (one per phase).
Conventional Commits. PR descriptions live in [`docs/prs/`](docs/prs/).
Conventions for contributors and AI assistants: [`CLAUDE.md`](CLAUDE.md).
