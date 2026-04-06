# SignalDeck - Test Monitoring

A self-hosted **Cypress & Playwright** testing dashboard built with **Laravel 12** and **Filament v3**. Trigger test suites from a web UI, watch live output stream in real time, generate branded per-client HTML reports, and deliver expiring shareable links to clients — no third-party testing service required.

[Found a bug? Report it on Fider](https://feedback.signaldeck.tech)

---

## Table of Contents

1. [Features](#features)
2. [Tech Stack](#tech-stack)
3. [Prerequisites](#prerequisites)
4. [Local Development Setup](#local-development-setup)
5. [Environment Variables](#environment-variables)
6. [Database Setup](#database-setup)
7. [Storage Setup](#storage-setup)
8. [Queue & Real-time Workers](#queue--real-time-workers)
9. [Deploy Keys (SSH)](#deploy-keys-ssh)
10. [Roles & Permissions](#roles--permissions)
11. [Project Walkthrough](#project-walkthrough)
12. [Running Tests](#running-tests)
13. [Reports](#reports)
14. [Scheduled Tasks & Artifact Cleanup](#scheduled-tasks--artifact-cleanup)
15. [Artisan Commands Reference](#artisan-commands-reference)
16. [REST API](#rest-api)
17. [SSO (Single Sign-On)](#sso-single-sign-on)
18. [Slack Notifications](#slack-notifications)
19. [macOS Companion App - SignalDeck CI](#macos-companion-app-signaldeck-ci)
20. [Project Structure](#project-structure)
21. [Architecture Overview](#architecture-overview)
22. [Database Schema](#database-schema)
23. [Deployment (VPS + Cloudflare)](#deployment)
24. [Git Repository Setup](#git-repository-setup)
25. [Troubleshooting](#troubleshooting)

---

## Features

- **Dual runner support** — Cypress and Playwright, configured per-project
- **Multi-client branding** — per-client logo, colours, and footer text on all reports
- **Multi-project** — each project maps to a separate Git repository with its own deploy key
- **Test suites** — define spec patterns, branch overrides, and env vars per suite
- **One-click test runs** — trigger tests from the admin UI, no CI pipeline required
- **Live console output** — watch test output update in real time via WebSockets (Laravel Reverb), with Livewire polling as a fallback
- **Result parsing** — Mochawesome (Cypress) and Playwright JSON reports parsed into the database
- **Playwright project discovery** — auto-detect available browsers/devices from `playwright.config.ts`
- **Performance tuning** — admin-only parallel workers and retry overrides for Playwright suites
- **Branded HTML reports** — fully self-contained, per-client styled reports with a built-in print-to-PDF button
- **Screenshots & videos** — stored and displayed inline with lightbox modal in reports
- **Shareable links** — 30-day expiring HMAC-signed URLs for client delivery, no login required
- **Run comparison** — compare any two completed runs side-by-side to spot regressions
- **Flaky test tracking** — identify tests that intermittently pass and fail across runs
- **Test history** — per-test trend view across multiple runs
- **Role-based access** — Admin (full access) and PM (run tests, view reports only)
- **User management** — admin panel to create and manage user accounts
- **Single Sign-On (SSO)** — Google and GitHub OAuth login, configurable from the admin UI
- **Slack DM notifications** — bot token integration sends a DM to the triggering user when a run completes
- **REST API v1** — full Sanctum token-authenticated API for all resources; consumed by the macOS companion app
- **macOS companion app** — native SwiftUI desktop app for monitoring and triggering runs (separate repo)
- **Artifact cleanup** — scheduled command to purge screenshots, videos, and reports older than N days
- **Re-run** — trigger a new run from any completed run with one click
- **Re-run failures** — re-run only the failing spec files from a completed run

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend framework | Laravel 12 |
| Admin panel | Filament v3 |
| Frontend reactivity | Livewire 3 + Alpine.js |
| Asset pipeline | Vite |
| Real-time transport | Laravel Reverb (WebSockets) |
| API authentication | Laravel Sanctum (Bearer tokens) |
| OAuth / SSO | Laravel Socialite + filament-socialite |
| Queue driver | Database (dev) / Redis (production) |
| Cache driver | File (dev) / Redis (production) |
| Session driver | Database |
| Database | SQLite (development) / MySQL or PostgreSQL (production) |
| Test runners | Cypress and Playwright (installed per-project via npm) |
| Report parsing | Mochawesome JSON (Cypress) / Playwright JSON reporter |

---

## Prerequisites

Install the following before running the application:

| Requirement | Notes |
|---|---|
| PHP 8.2+ | Extensions: `pdo`, `openssl`, `mbstring`, `xml`, `curl` |
| Composer | PHP dependency manager |
| Node.js 18+ | Runtime for Vite, Cypress, and Playwright |
| npm | Package manager for frontend and test runners |
| Redis | Recommended for production. Not needed locally — see Queue, Cache & Session below |
| Git | For cloning test repositories |
| SSH | Required if using private Git repositories |

---

## Local Development Setup

```bash
# 1. Clone the repository
git clone <your-repo-url> cypress-dashboard
cd cypress-dashboard

# 2. Create required directories (excluded from git, needed before composer install)
mkdir -p bootstrap/cache storage/framework/{sessions,views,cache} storage/logs

# 3. Install PHP dependencies
composer install

# 4. Install Node dependencies
npm install

# 5. Copy the environment file
cp .env.example .env

# 6. Generate the application key
php artisan key:generate

# 7. Configure .env (see Environment Variables section)

# 8. Create the SQLite database file (if using SQLite)
touch database/database.sqlite

# 9. Run migrations and seed demo data
php artisan migrate --seed

# 10. Create the public storage symlink
php artisan storage:link

# 11. Build frontend assets
npm run build
```

Then start the required processes — each in a separate terminal:

```bash
# Terminal 1 — Web server (or use Laravel Herd / Valet)
php artisan serve

# Terminal 2 — Queue worker (processes test jobs)
php artisan queue:work --queue=cypress --timeout=3600 --tries=1

# Terminal 3 — Reverb WebSocket server
php artisan reverb:start

# Terminal 4 — Vite dev server (hot module reloading)
npm run dev
```

> **Report CSS:** The branded HTML report inlines its CSS from the compiled Vite build (`public/build/`). `npm run dev` does **not** update report styles — run `npm run build` then regenerate the report to see changes to `branded.blade.php`.

> **Shortcut:** A `Procfile` is included for use with [hivemind](https://github.com/DarthSim/hivemind) or [overmind](https://github.com/DarthSim/overmind). Run `hivemind` from the project root to start the queue worker and Reverb together.

---

## Environment Variables

Copy `.env.example` to `.env` and fill in the values below.

### Application

```env
APP_NAME="Cypress Dashboard"
APP_ENV=local                    # local | production
APP_KEY=                         # Set automatically by php artisan key:generate
APP_DEBUG=true                   # Set to false in production
APP_URL=https://your-domain.com  # Full URL — used in report and share link generation
APP_VERSION=dev                  # Set automatically by deploy.sh from git tag
```

> `APP_URL` must be correct. Report URLs and shareable links are built from this value. If the queue worker starts with the wrong `APP_URL`, restart it after updating `.env`.

### Branding (optional)

```env
BRAND_NAME=                          # Panel display name; defaults to APP_NAME
BRAND_PRIMARY_COLOR=                 # Hex colour, e.g. #4f46e5
BRAND_LOGO_PATH=images/logo.svg      # Light-mode logo, path relative to public/
BRAND_LOGO_DARK_PATH=images/logo-white.svg
BRAND_LOGO_HEIGHT=2rem
BRAND_FAVICON_PATH=                  # e.g. images/favicon.png
COMPANY_LEGAL_NAME="Your Company Ltd"
```

### Database

```env
# SQLite (default, simplest for development)
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/database/database.sqlite

# MySQL (recommended for production)
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=cypress_dashboard
# DB_USERNAME=your_db_user
# DB_PASSWORD=your_db_password
```

### Queue, Cache & Session

For **local development** (no Redis required):

```env
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=14400   # Must be >= CYPRESS_JOB_TIMEOUT to prevent re-queuing mid-run
CACHE_STORE=file
SESSION_DRIVER=database
SESSION_LIFETIME=120
```

For **production** (Redis recommended):

```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database
SESSION_LIFETIME=120

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

> If using `QUEUE_CONNECTION=database` locally, run `php artisan queue:table && php artisan migrate` to create the jobs table.

### Reverb (WebSockets)

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=my-app-id
REVERB_APP_KEY=my-app-key
REVERB_APP_SECRET=my-app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

> In production, Reverb typically runs on a separate port (e.g. 8080) proxied through Nginx. See [Deployment](#deployment) for the Nginx WebSocket proxy configuration.

### Git & Node

```env
# Directory where project SSH deploy keys are written
GIT_SSH_KEY_PATH=/home/www-data/.ssh

# Absolute paths to Node and npm binaries on the server.
# Used by the web server for Playwright project discovery.
# The deploy script auto-detects these via `which node` and `which npm`.
NODE_PATH=/usr/local/bin/node
NPM_PATH=/usr/local/bin/npm
```

Find the correct paths with `which node` and `which npm`. These must be the paths accessible to the user running the queue worker and web server. The `deploy.sh` script auto-detects and updates these on each deployment.

### Storage

```env
FILESYSTEM_DISK=local   # Do not change — reports use the private local disk

# Optional S3 config
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
```

### SSO (optional)

SSO providers can be toggled from **Settings → Single Sign-On** in the admin UI without touching `.env`. The env vars below are only needed if you prefer to configure SSO via environment rather than the UI.

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/admin/oauth/callback/google"
```

---

## Database Setup

```bash
# Run all migrations
php artisan migrate

# Seed with demo data
php artisan db:seed
```

### Seeded demo accounts

| Email | Password | Role |
|---|---|---|
| `admin@example.com` | `password` | Admin |
| `pm@example.com` | `password` | PM |

> **Change these immediately on any non-local environment.** You can do this from **Admin → Users** in the panel.

The seeder also creates two demo clients (Acme Corp and Globex Solutions) with projects and test suites, so you can explore the UI without configuring real repositories first.

---

## Storage Setup

```bash
# Must be run after every fresh deployment
php artisan storage:link
```

### What is stored where

| Path | Disk | Access | Contents |
|---|---|---|---|
| `storage/app/private/reports/run-{id}/report.html` | `local` (private) | Auth-gated controller | HTML reports |
| `storage/app/public/runs/{id}/screenshots/` | `public` | Public URL via `/storage/` | Test screenshots |
| `storage/app/public/runs/{id}/videos/` | `public` | Public URL via `/storage/` | Test videos |

Reports are intentionally **not** stored in the public disk. They are served through Laravel controller routes that enforce authentication (`/reports/run/{id}/html`) or HMAC token validation (`/reports/share/{id}/{token}`). There is no way to access a report by guessing a storage path.

---

## Queue & Real-time Workers

All test runs are processed asynchronously by a queue worker. Live log output is broadcast over WebSockets via Laravel Reverb.

### Starting workers (development)

```bash
php artisan queue:work --queue=cypress --timeout=3600 --tries=1
php artisan reverb:start
```

> **Important:** Both Cypress and Playwright jobs dispatch to the `cypress` queue. The `--queue=cypress` flag is required.

> The queue worker caches the application config on startup. After any change to `.env`, restart the worker: `php artisan queue:restart` (or kill and restart the process).

### Procfile

The included `Procfile` defines worker processes for hivemind/overmind:

```
queue: php artisan queue:work --queue=cypress --timeout=3600
reverb: php artisan reverb:start
```

### Production (Supervisor)

Use Supervisor to keep the queue worker and Reverb running and automatically restart on failure.

Create `/etc/supervisor/conf.d/cypress-dashboard.conf`:

```ini
[program:cypress-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/cypress-dashboard/artisan queue:work --queue=cypress --timeout=3600 --tries=1 --sleep=3
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/cypress-dashboard/storage/logs/queue.log

[program:cypress-reverb]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/cypress-dashboard/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/cypress-dashboard/storage/logs/reverb.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

---

## Deploy Keys (SSH)

Private Git repositories require an SSH deploy key. Each project has its own key pair, with the private key stored encrypted in the database.

### Generate via the admin UI

1. Go to **Management → Projects**
2. Open a project and click **Generate Deploy Key**
3. Copy the displayed public key
4. Add it as a **Deploy Key** (read-only) in your Git provider:
   - **GitHub:** Repository → Settings → Deploy keys → Add deploy key
   - **GitLab:** Repository → Settings → Repository → Deploy keys
   - **Bitbucket:** Repository → Repository settings → Access keys

### SSH host key

The queue worker connects to GitHub/GitLab over SSH. On first run, SSH may pause to verify the host key. Pre-accept it by running once as the web server user:

```bash
sudo -u www-data ssh -T git@github.com -o StrictHostKeyChecking=accept-new
```

### Using HTTPS instead of SSH

If you prefer HTTPS with a Personal Access Token, set the repo URL directly in the project:

```
https://TOKEN@github.com/your-org/your-repo.git
```

Leave the deploy key fields blank.

---

## Roles & Permissions

| Capability | Admin | PM |
|---|---|---|
| View test runs | ✅ | ✅ |
| Trigger test runs | ✅ | ✅ |
| View / download reports | ✅ | ✅ |
| Share report links | ✅ | ✅ |
| Re-run a test | ✅ | ✅ |
| Re-run failures only | ✅ | ✅ |
| Compare runs | ✅ | ✅ |
| View flaky tests | ✅ | ✅ |
| View test history | ✅ | ✅ |
| Manage clients | ✅ | ❌ |
| Manage projects | ✅ | ❌ |
| Manage test suites | ✅ | ❌ |
| Playwright performance tuning | ✅ | ❌ |
| Manage users | ✅ | ❌ |
| Delete test runs | ✅ | ❌ |
| Manage settings (mail, SSO, Slack) | ✅ | ❌ |
| Generate API tokens | ✅ | ❌ |

Roles are stored as a `role` string on the `users` table (`admin` or `pm`). Manage users at **Admin → Users** in the panel.

---

## Project Walkthrough

### 1. Create a Client

**Management → Clients → New Client**

- Enter the client's name, contact details, and website
- Upload a logo and set primary, secondary, and accent colours
- These are applied to all HTML and PDF reports for the client's projects

### 2. Create a Project

**Management → Projects → New Project**

- Select the client and enter the repository URL and default branch
- Choose the **Runner Type** — Cypress or Playwright
- Generate a deploy key and add the public key to your Git provider (see [Deploy Keys](#deploy-keys-ssh))
- Add any environment variables that all test suites in this project need (e.g. `CYPRESS_BASE_URL`)
- For Playwright projects, use **Discover Projects** to auto-detect available browsers/devices from the repo's `playwright.config.ts`

### 3. Add Test Suites

On the project page, open the **Test Suites** tab and create a suite:

- **Spec pattern** — e.g. `cypress/e2e/**/*.cy.js` (Cypress) or left blank for Playwright (uses config)
- **Branch override** — leave blank to use the project's default branch
- **Environment variables** — suite-specific overrides (merged on top of project-level vars)
- **Timeout** — maximum minutes before the run is killed (default: 60)

For **Playwright suites**:
- **Playwright Projects** — select which browsers/devices to test (e.g. chromium, firefox, webkit)
- **Performance Tuning** (admin only) — override parallel workers and retry count via CLI flags

### 4. Run Tests

Go to **Testing → Test Runs** and click **Run Tests** in the top-right. Select project, suite, and branch, then click **Run**. The job is dispatched to the queue immediately.

Click **View** on the queued run to open the live view, where console output updates in real time via WebSockets.

---

## Running Tests

Runner type is set at the project level. Each run snapshots the runner type at creation time, so historical runs remain valid.

### Cypress Pipeline (`RunCypressTestJob`)

1. Clone repository → `npm install` → build tailwind (if defined)
2. Run `npx cypress run --spec "{spec_pattern}"` with merged env vars
3. Merge mochawesome JSON reports → parse into `test_results` rows
4. Map screenshots and videos to test results
5. Generate branded HTML report
6. Clean up temporary directory

### Playwright Pipeline (`RunPlaywrightTestJob`)

1. Clone repository → `npm install` → `npx playwright install --with-deps`
2. Build tailwind (if defined)
3. Run `npx playwright test` with `--reporter=line,json`, `--project` flags, and optional `--workers`/`--retries` overrides
4. Parse Playwright JSON output into `test_results` rows
5. Map screenshots (`.png`) and videos (`.webm`) from `test-results/` directory
6. Generate branded HTML report
7. Clean up temporary directory

### Shared behaviour

- Both runners use a shared `RunsTestSuite` trait for clone, install, streaming, and cleanup
- Console output is broadcast in real time over WebSockets (Laravel Reverb) and also flushed to DB for reconnections
- Status progresses through `pending` → `cloning` → `installing` → `running` → `passing`/`failed`/`error`
- If 0 tests are found or executed, the run is marked `error`
- If any step fails, the run is marked `error` and the error message is stored

---

## Reports

### HTML Report

Generated automatically after every run. Served via an authenticated controller route — not a direct storage URL.

**Access:** Test Runs table → **HTML Report** button, or the run detail view header.

**Features:**
- Client logo, brand colours, and footer text
- Executive summary: pass rate, duration, pass/fail/skip counts
- Per-spec file breakdown with status badges
- Failure details: error message, stack trace, test code
- Screenshots and videos in an inline lightbox
- **Save as PDF** floating button — uses the browser's native print-to-PDF, no server dependencies

### Shareable Links

Produce a link that lets a client view the HTML report without logging in.

**Access:** **Share Link** button on any completed run (table or detail view).

The link embeds a 30-day UTC expiry timestamp and an HMAC-SHA256 token signed with your `APP_KEY`. After 30 days the link returns 403. Generate a new link at any time from the run view.

```
https://your-dashboard.com/reports/share/{run_id}/{token}?expires={unix_timestamp}
```

### Run Comparison

**Testing → Compare Runs** — select any two completed runs from the same project to compare results side-by-side. Highlights tests that changed status between runs.

---

## Scheduled Tasks & Artifact Cleanup

Register the Laravel scheduler with your server's cron (run once, runs all tasks):

```bash
# crontab -e
* * * * * cd /var/www/cypress-dashboard && php artisan schedule:run >> /dev/null 2>&1
```

### Registered schedule

| Time | Command | Description |
|---|---|---|
| Daily at 02:00 | `runs:cleanup` | Deletes artifacts for completed runs older than 30 days |

The cleanup command removes:
- HTML and PDF reports from the local (private) disk
- Screenshots and videos from the public disk
- Nulls out the corresponding database paths

Report metadata (pass counts, status, test results) is retained in the database indefinitely.

---

## Artisan Commands Reference

### `runs:cleanup`

Deletes screenshots, videos, and reports for completed runs older than a configurable threshold.

```bash
# Preview what would be deleted (no changes made)
php artisan runs:cleanup --dry-run

# Delete artifacts older than 30 days (default)
php artisan runs:cleanup

# Delete artifacts older than 60 days
php artisan runs:cleanup --days=60
```

### `runs:regenerate-reports`

Regenerates HTML reports for all completed runs. Useful after changes to the report template.

```bash
php artisan runs:regenerate-reports
```

### Standard Laravel Commands

```bash
php artisan migrate                              # Run pending migrations
php artisan migrate:fresh --seed                 # Drop all tables, re-run, and seed
php artisan storage:link                         # Create public disk symlink (run after fresh deploy)
php artisan queue:work --queue=cypress --timeout=3600  # Start queue worker
php artisan queue:restart                        # Signal running workers to restart after next job
php artisan reverb:start                         # Start WebSocket server
php artisan schedule:run                         # Run due scheduled tasks (called by cron)
php artisan config:cache                         # Cache config (use in production)
php artisan route:cache                          # Cache routes (use in production)
php artisan view:cache                           # Cache views (use in production)
php artisan cache:clear                          # Clear application cache
```

---

## REST API

The dashboard exposes a versioned REST API at `/api/v1`, authenticated with **Laravel Sanctum** Bearer tokens. This is the same API consumed by the macOS companion app.

### Authentication

```
POST /api/v1/auth/login
Content-Type: application/json

{ "email": "admin@example.com", "password": "password" }
```

Returns a Sanctum token. Pass it as `Authorization: Bearer {token}` on subsequent requests.

### Token abilities

Tokens are scoped with abilities. Admin users can generate tokens in **Settings → API Tokens**.

| Ability | Grants |
|---|---|
| `desktop:read` | Read all resources (runs, results, logs, reports, analytics) |
| `desktop:write` | Trigger and cancel test runs |
| `desktop:admin` | Full CRUD on clients, projects, suites, users, and settings |

### Key endpoints

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/v1/health` | Health check (unauthenticated) |
| `POST` | `/api/v1/auth/login` | Obtain a Sanctum token |
| `POST` | `/api/v1/auth/logout` | Revoke current token |
| `GET` | `/api/v1/auth/user` | Authenticated user profile |
| `GET` | `/api/v1/auth/sso/providers` | List enabled SSO providers |
| `GET` | `/api/v1/dashboard/stats` | Summary counts for the dashboard |
| `GET` | `/api/v1/clients` | List clients |
| `GET` | `/api/v1/projects` | List projects |
| `GET` | `/api/v1/projects/{id}/suites` | List suites for a project |
| `GET` | `/api/v1/test-runs` | List test runs (filterable) |
| `POST` | `/api/v1/test-runs` | Trigger a new test run |
| `GET` | `/api/v1/test-runs/{id}` | Run detail |
| `GET` | `/api/v1/test-runs/{id}/results` | Per-test results |
| `GET` | `/api/v1/test-runs/{id}/logs` | Raw console output |
| `GET` | `/api/v1/test-runs/{id}/report` | Report URL / download |
| `GET` | `/api/v1/test-runs/compare` | Compare two runs |
| `POST` | `/api/v1/test-runs/{id}/cancel` | Cancel a queued/running run |
| `GET` | `/api/v1/flaky-tests` | Flaky test analytics |
| `GET` | `/api/v1/test-history` | Per-test run history |
| `GET` | `/api/v1/settings` | Read admin settings |
| `PUT` | `/api/v1/settings/slack` | Update Slack settings |
| `PUT` | `/api/v1/settings/sso` | Update SSO settings |

---

## SSO (Single Sign-On)

The dashboard supports Google and GitHub OAuth login. SSO is configured from **Settings → Single Sign-On** in the admin panel — no `.env` changes are required once the app credentials are set.

### Google OAuth setup

1. In [Google Cloud Console](https://console.cloud.google.com/) → **APIs & Services → Credentials**, create an OAuth 2.0 client ID.
2. Add the following to **Authorised redirect URIs**:
   ```
   https://your-domain.com/admin/oauth/callback/google
   ```
3. In the admin panel, go to **Settings → Single Sign-On**, enable Google, and enter your Client ID and Secret.
4. Save. The "Sign in with Google" button will appear on the login page immediately.

### GitHub OAuth setup

1. In GitHub → **Settings → Developer settings → OAuth Apps**, create a new app.
2. Set **Authorization callback URL** to:
   ```
   https://your-domain.com/admin/oauth/callback/github
   ```
3. In the admin panel, go to **Settings → Single Sign-On**, enable GitHub, and enter your Client ID and Secret.

### Notes

- Users are matched by email. If no user exists with the SSO email, login is rejected (no self-registration).
- SSO and password login can coexist — users can still log in with email/password.
- The macOS companion app uses a dedicated SSO flow via `/api/v1/auth/sso/*` with a custom URL scheme callback (`cypressdashboard://`).

---

## Slack Notifications

When enabled, the dashboard sends a Slack DM to the user who triggered a test run when it completes (pass or fail).

### Setup

1. Create a **Slack App** at [api.slack.com/apps](https://api.slack.com/apps):
   - Add the `users:read.email` and `chat:write` OAuth scopes under **Bot Token Scopes**
   - Install the app to your workspace
   - Copy the **Bot User OAuth Token** (`xoxb-...`)
2. In the admin panel, go to **Settings → Slack**, enable notifications, paste the bot token, and click **Test Connection**.

### How it works

- When a run finishes with status `passing` or `failed`, a `TestRunStatusChanged` event is fired.
- The `SendTestRunSlackNotification` listener looks up the triggering user's Slack ID via their email address (`users.lookupByEmail` API).
- A Block Kit DM is sent to that user with the run summary (client, project, suite, pass/fail counts, and a link to the report).
- Each run sends at most one DM (deduplicated via cache).

### Per-user Slack ID override

If a user's Slack account uses a different email than their dashboard account, an admin can set a manual **Slack User ID** override on the user's profile in **Admin → Users**.

---

## macOS Companion App SignalDeck CI

A native **SwiftUI macOS app** provides a lightweight desktop interface for monitoring runs and triggering new ones without opening a browser. It connects to the dashboard REST API using a Sanctum token and supports SSO login via a custom URL scheme.

The macOS app lives in a **separate private repository**. And is not subjet to the open source approach as the rest of the project.

---

## Project Structure

```
cypress-dashboard/
├── app/
│   ├── Console/Commands/
│   │   ├── CleanupOldArtifacts.php       # runs:cleanup
│   │   └── RegenerateReports.php         # runs:regenerate-reports
│   ├── Events/
│   │   ├── TestRunStatusChanged.php      # Broadcast: status, counts, report URLs
│   │   └── TestRunLogReceived.php        # Broadcast: live log lines
│   ├── Filament/
│   │   ├── Pages/
│   │   │   ├── CompareRuns.php           # Side-by-side run comparison
│   │   │   ├── FlakyTests.php            # Flaky test analytics
│   │   │   ├── TestHistory.php           # Per-test history trend
│   │   │   ├── MailSettingsPage.php      # SMTP mail configuration
│   │   │   ├── SlackSettingsPage.php     # Slack bot token + notification toggle
│   │   │   ├── SsoSettingsPage.php       # OAuth provider configuration
│   │   │   └── SettingsPage.php          # General settings
│   │   └── Resources/
│   │       ├── ClientResource.php        # Admin-only: client CRUD
│   │       ├── ProjectResource.php       # Admin-only: project CRUD
│   │       ├── TestRunResource.php       # All users: test runs table + trigger action
│   │       └── UserResource.php          # Admin-only: user management
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/V1/                   # REST API controllers (Sanctum-protected)
│   │   │   │   ├── AuthController.php
│   │   │   │   ├── SsoAuthController.php
│   │   │   │   ├── ClientController.php
│   │   │   │   ├── ProjectController.php
│   │   │   │   ├── TestRunController.php
│   │   │   │   ├── TestSuiteController.php
│   │   │   │   ├── DashboardController.php
│   │   │   │   ├── FlakyTestController.php
│   │   │   │   ├── TestHistoryController.php
│   │   │   │   ├── SettingsController.php
│   │   │   │   ├── UserController.php
│   │   │   │   └── HealthController.php
│   │   │   └── ReportController.php      # html(), share() — serves report files
│   │   └── Middleware/
│   │       └── EnsureApiTokenAbility.php # Sanctum ability checks per route group
│   ├── Jobs/
│   │   ├── Concerns/
│   │   │   └── RunsTestSuite.php         # Shared trait: clone, install, stream, cleanup
│   │   ├── RunCypressTestJob.php         # Cypress: run → parse mochawesome → report
│   │   └── RunPlaywrightTestJob.php      # Playwright: install browsers → run → parse JSON → report
│   ├── Listeners/
│   │   └── SendTestRunSlackNotification.php  # DMs the triggering user on run completion
│   ├── Models/
│   │   ├── AppSetting.php               # Key/value store for DB-backed settings
│   │   ├── Client.php
│   │   ├── Project.php                  # Encrypted deploy key + env vars
│   │   ├── TestRun.php                  # Status constants, URL accessors
│   │   ├── TestResult.php               # Per-test outcomes, media paths
│   │   ├── TestSuite.php                # Spec patterns, branch override
│   │   └── User.php                     # isAdmin(), isPM(), canAccessPanel()
│   ├── Providers/Filament/
│   │   └── AdminPanelProvider.php       # Panel config, nav groups, colours
│   ├── Enums/
│   │   └── RunnerType.php               # Cypress | Playwright enum
│   └── Services/
│       ├── MochawesomeParserService.php  # Parses Cypress merged JSON → TestResult rows
│       ├── PlaywrightParserService.php   # Parses Playwright JSON → TestResult rows
│       ├── PlaywrightConfigReaderService.php  # Discovers browser projects from repo config
│       ├── ReportGeneratorService.php    # Renders HTML report
│       ├── SlackService.php              # Slack API: token validation, user lookup, DM sending
│       └── SsoConfigService.php          # Reads SSO provider config from DB or .env
├── database/
│   ├── migrations/                      # All schema migrations
│   └── seeders/
│       └── DatabaseSeeder.php           # Demo users, clients, projects, suites
├── resources/views/
│   ├── filament/
│   │   ├── modals/share-link.blade.php  # Shareable link copy modal
│   │   └── test-run/view.blade.php      # Run detail view (Alpine + Livewire)
│   └── reports/
│       └── branded.blade.php            # Self-contained HTML report template
├── routes/
│   ├── api.php                          # REST API routes (/api/v1)
│   ├── web.php                          # Web + report routes
│   └── console.php                      # Scheduled tasks
├── .env.example                         # Environment variable template
├── Procfile                             # Process definitions for hivemind/overmind
├── composer.json
└── package.json
```

---

## Architecture Overview

```
Browser / macOS App
  │
  ├── Filament Admin Panel (/admin)
  │     Livewire + Alpine.js
  │     WebSocket (Reverb) → live log + status updates
  │     wire:poll fallback → pollStatus() on reconnect
  │
  ├── Report Controller (/reports/...)
  │     /run/{id}/html    — requires auth middleware
  │     /share/{id}/{tok} — HMAC + expiry validation (no auth needed)
  │
  └── REST API (/api/v1)
        Sanctum Bearer token authentication
        Ability-scoped routes: desktop:read / desktop:write / desktop:admin

Queue Worker (--queue=cypress)
  ├── RunCypressTestJob
  │     Clone → Install → Run Cypress → Parse Mochawesome → Store → Report
  └── RunPlaywrightTestJob
        Clone → Install → Install Browsers → Run Playwright → Parse JSON → Store → Report

  Both use RunsTestSuite trait: shared clone, install, stream, cleanup logic
  Console output broadcast over WebSockets (Reverb) + flushed to DB every 3s

  On completion → TestRunStatusChanged event
    └── SendTestRunSlackNotification listener → Slack DM to triggering user

Reverb WebSocket Server
  Channels: test-run.{id}  →  log lines + status changes

Storage
  ├── local disk (private)   — HTML reports
  └── public disk            — Screenshots + videos (served via /storage/)

AppSetting model (key/value)
  ├── Slack: bot token, notification toggle
  └── SSO: provider credentials, enabled flags
```

---

## Database Schema

```
clients
  id, name, slug, logo_path,
  primary_colour, secondary_colour, accent_colour,
  contact_name, contact_email, website, report_footer_text,
  active, deleted_at, timestamps

projects
  id, client_id, name, slug, description,
  repo_url, repo_provider, default_branch,
  runner_type (cypress|playwright),
  deploy_key_private (encrypted), deploy_key_public,
  env_variables (encrypted JSON),
  playwright_available_projects (JSON, nullable),
  active, deleted_at, timestamps

test_suites
  id, project_id, name, slug, description,
  spec_pattern, branch_override,
  env_variables (encrypted JSON),
  playwright_projects (JSON, nullable),
  playwright_workers (nullable),
  playwright_retries (nullable),
  timeout_minutes, active, deleted_at, timestamps

test_runs
  id, project_id, test_suite_id, triggered_by (user_id),
  runner_type (cypress|playwright),
  status, branch, commit_sha,
  total_tests, passed_tests, failed_tests, pending_tests,
  duration_ms, log_output, error_message,
  report_html_path, merged_json_path,
  spec_override, parent_run_id,
  started_at, finished_at, timestamps

test_results
  id, test_run_id, spec_file, suite_title, test_title, full_title,
  status, duration_ms, error_message, error_stack, test_code,
  screenshot_paths (JSON array), video_path, attempt, timestamps

users
  id, name, email, password, role (admin|pm),
  slack_user_id (nullable override),
  timestamps

app_settings
  id, key, value, timestamps
```

---

## Deployment

The following steps cover a production deployment on **Ubuntu 24.04 LTS** behind **Cloudflare** (free plan). Cloudflare provides the SSL certificate to the browser; a Cloudflare Origin Certificate is installed on the server so traffic is encrypted end-to-end.

### One-command install

An automated install script is included. Run it on a fresh Ubuntu 24.04 server as root:

```bash
git clone https://github.com/your-org/cypress-gui.git /tmp/cypress-setup
chmod +x /tmp/cypress-setup/install.sh
sudo bash /tmp/cypress-setup/install.sh
```

The script will prompt for your domain and database password, then handle everything through Step 10. Follow the printed post-install checklist for the Cloudflare certificate and DNS steps which require manual action in the Cloudflare dashboard.

The manual steps below document what the script does if you prefer to run them yourself.

---

### Step 1 — Server preparation

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.4
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.4 php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-common php8.4-intl

# Nginx, MySQL, Supervisor
sudo apt install -y nginx mysql-server supervisor

# Node.js 20 LTS
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

---

### Step 2 — Test runner headless dependencies

Cypress and Playwright require system libraries for headless browser execution:

```bash
# Cypress (Electron) dependencies
sudo apt install -y \
  xvfb libgtk-3-0t64 libnotify-dev \
  libnss3 libxss1 libasound2t64 libxtst6 xauth libgbm-dev

# Playwright system dependencies (installs shared libraries via apt-get)
npx playwright install-deps
```

> The `install.sh` and `install-existing-lemp.sh` scripts handle both of these automatically. Playwright browser binaries are downloaded per-user by the queue worker on the first test run.

---

### Step 3 — Database

```bash
sudo mysql_secure_installation

sudo mysql -u root -p
```

```sql
CREATE DATABASE cypress_dashboard;
CREATE USER 'cypress'@'localhost' IDENTIFIED BY 'your-strong-password';
GRANT ALL PRIVILEGES ON cypress_dashboard.* TO 'cypress'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

### Step 4 — Application user and deploy

```bash
# Dedicated app user (don't run as root or www-data)
sudo useradd -m -s /bin/bash cypressapp
sudo mkdir -p /var/www/cypress-dashboard
sudo chown cypressapp:cypressapp /var/www/cypress-dashboard

# Clone and install
sudo -u cypressapp git clone https://github.com/your-org/cypress-gui.git /var/www/cypress-dashboard
cd /var/www/cypress-dashboard

sudo -u cypressapp composer install --no-dev --optimize-autoloader
sudo -u cypressapp npm ci
sudo -u cypressapp npm run build

# Environment
sudo -u cypressapp cp .env.example .env
sudo nano /var/www/cypress-dashboard/.env   # fill in all values — see .env.example

sudo -u cypressapp php artisan key:generate
sudo -u cypressapp php artisan migrate --force
sudo -u cypressapp php artisan storage:link
sudo -u cypressapp php artisan filament:assets
sudo -u cypressapp php artisan config:cache
sudo -u cypressapp php artisan route:cache
sudo -u cypressapp php artisan view:cache

# Permissions
sudo chown -R cypressapp:www-data /var/www/cypress-dashboard/storage
sudo chmod -R 775 /var/www/cypress-dashboard/storage
sudo chown -R cypressapp:www-data /var/www/cypress-dashboard/bootstrap/cache
sudo chmod -R 775 /var/www/cypress-dashboard/bootstrap/cache
```

---

### Step 5 — Cloudflare Origin Certificate

In the Cloudflare dashboard: **SSL/TLS → Origin Server → Create Certificate**

Select your domain, choose 15 years validity, and copy the certificate and key.

```bash
sudo mkdir -p /etc/ssl/cloudflare
sudo nano /etc/ssl/cloudflare/origin.pem   # paste the certificate
sudo nano /etc/ssl/cloudflare/origin.key   # paste the private key
sudo chmod 600 /etc/ssl/cloudflare/origin.key
```

Then in Cloudflare:
- **SSL/TLS → Overview** → set mode to **Full (strict)**

---

### Step 6 — Nginx

Create `/etc/nginx/sites-available/cypress-dashboard`:

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;

    ssl_certificate     /etc/ssl/cloudflare/origin.pem;
    ssl_certificate_key /etc/ssl/cloudflare/origin.key;

    root /var/www/cypress-dashboard/public;
    index index.php;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    # Main Laravel app
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Reverb WebSocket proxy
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_cache_bypass $http_upgrade;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}

# Redirect HTTP → HTTPS
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}
```

```bash
sudo ln -s /etc/nginx/sites-available/cypress-dashboard /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

---

### Step 7 — Supervisor

Create `/etc/supervisor/conf.d/cypress-dashboard.conf`:

```ini
[program:cypress-queue]
command=php /var/www/cypress-dashboard/artisan queue:work --queue=cypress --sleep=3 --tries=1 --timeout=3600
directory=/var/www/cypress-dashboard
user=cypressapp
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/cypress-queue.log

[program:cypress-reverb]
command=php /var/www/cypress-dashboard/artisan reverb:start --host=0.0.0.0 --port=8080
directory=/var/www/cypress-dashboard
user=cypressapp
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/cypress-reverb.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start cypress-queue cypress-reverb
sudo supervisorctl status
```

> **Note:** `--timeout=3600` on the queue worker is important. Test runs can take up to an hour and the default 60-second timeout will kill jobs mid-run. `--queue=cypress` is required — both Cypress and Playwright jobs dispatch to this queue.

---

### Step 8 — Cloudflare DNS

In Cloudflare DNS, add an **A record** pointing your domain to the VPS IP with the orange cloud (proxied) enabled. Cloudflare handles SSL to the browser; Nginx uses the Origin Certificate for the server ↔ Cloudflare leg.

---

### Step 9 — Cron (scheduled tasks)

```bash
sudo crontab -u cypressapp -e
```

Add:

```
* * * * * cd /var/www/cypress-dashboard && php artisan schedule:run >> /dev/null 2>&1
```

---

### Step 10 — Production .env values

Key differences from local development:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=cypress_dashboard
DB_USERNAME=cypress
DB_PASSWORD=your-strong-password

QUEUE_CONNECTION=database

REVERB_HOST=your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https
```

---

### Deployment checklist (every deploy)

A `deploy.sh` script is included that handles all of this automatically:

```bash
./deploy.sh
```

Or manually:

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan filament:assets
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
php artisan queue:restart
```

> The deploy script also auto-detects `NODE_PATH` and `NPM_PATH` and sets `APP_VERSION` from the latest git tag.

---

## Git Repository Setup

### First push to a new repo

```bash
cd /path/to/your/project

# Initialise git
git init -b main

# Review what will be committed (ensure .env and /vendor are excluded)
git status

# Stage all files
git add .

# Initial commit
git commit -m "Initial commit: Cypress Dashboard"

# Add your remote
git remote add origin git@github.com:your-org/cypress-dashboard.git

# Push
git push -u origin main
```

### What is excluded by .gitignore

The default Laravel `.gitignore` already excludes everything sensitive:

| Excluded | Why |
|---|---|
| `.env` | Contains secrets — **never commit** |
| `/vendor/` | Restored via `composer install` |
| `/node_modules/` | Restored via `npm install` |
| `/storage/app/` | Runtime data |
| `/storage/logs/` | Runtime logs |
| `/bootstrap/cache/` | Generated on deploy |
| `/public/build/` | Generated by Vite |
| `/public/storage` | Symlink, recreated via `storage:link` |

### Secrets never to commit

- `APP_KEY`
- `DB_PASSWORD`
- `REDIS_PASSWORD`
- `REVERB_APP_SECRET`
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` (if using S3)
- `GOOGLE_CLIENT_SECRET`, `GITHUB_CLIENT_SECRET` (if using SSO)

---

## Troubleshooting

**Report URLs are broken / wrong domain in links**
`APP_URL` in `.env` is wrong or the queue worker started with a stale value. Update `APP_URL` and restart the worker: `php artisan queue:restart`.

**Share link returns 403 immediately**
The link was generated before the most recent `APP_KEY` change (changing the key invalidates all HMAC tokens), or the link is over 30 days old. Generate a new link from the run view.

**Live log not updating in the run view**
Check that Reverb is running (`php artisan reverb:start`) and that the `VITE_REVERB_*` env vars match `REVERB_*`. In production, verify the Nginx WebSocket proxy for `/app` is configured correctly. The view will fall back to polling if WebSockets are unavailable.

**Queue jobs not running / stuck in pending**
Ensure the queue worker is listening on the correct queue: `php artisan queue:work --queue=cypress`. If using Redis, confirm it's running (`redis-cli ping` → `PONG`) and `QUEUE_CONNECTION=redis` is set.

**Playwright "npm not found" during project discovery**
The web server has a minimal `PATH`. Ensure `NPM_PATH` and `NODE_PATH` are set in `.env` to the absolute paths of your node/npm binaries. Run `which node` and `which npm` to find them. The `deploy.sh` script auto-detects these.

**Git clone fails**
Test SSH access manually as the web server user:
```bash
sudo -u www-data ssh -T git@github.com -o StrictHostKeyChecking=accept-new
```

**Cypress/Playwright not found in the job**
Confirm `NODE_PATH` and `NPM_PATH` in `.env` point to binaries accessible by the queue worker user:
```bash
sudo -u www-data /usr/local/bin/npx cypress --version
sudo -u www-data /usr/local/bin/npx playwright --version
```

**Auth redirect loop**
`routes/web.php` must define `Route::get('/login', ...)` pointing to `/admin/login`. Laravel's `auth` middleware redirects to `route('login')` — without this named route, it will loop or 404.

**Playwright discovery: EACCES `/var/www/.npm`**
npm uses `~/.npm` as its cache directory. The web server user (`www-data`) typically has `$HOME` set to `/var/www/`, so the cache lands at `/var/www/.npm`. Fix ownership:
```bash
sudo mkdir -p /var/www/.npm && sudo chown -R www-data:www-data /var/www/.npm
```

**SSO login fails / redirect error**
Check that the redirect URI registered in your OAuth provider exactly matches `APP_URL/admin/oauth/callback/{provider}`. Ensure the provider is enabled in **Settings → Single Sign-On** and the client ID/secret are saved.

**Slack DM not received**
- Verify the bot token is valid via **Settings → Slack → Test Connection**
- Confirm the Slack app has `users:read.email` and `chat:write` bot scopes and is installed to the workspace
- Check `storage/logs/laravel.log` for `Slack users.lookupByEmail` or `chat.postMessage` warnings
- If the user's Slack email differs from their dashboard email, set a manual **Slack User ID** override on their user profile

**API token returning 401**
Sanctum tokens are ability-scoped. Ensure the token has the required ability (`desktop:read`, `desktop:write`, or `desktop:admin`) for the endpoint you are calling.

---

## Security Notes

- **Deploy keys** are stored encrypted at rest using Laravel's `Crypt` facade (AES-256-CBC via `APP_KEY`)
- **Project and suite environment variables** are also encrypted at rest
- **Slack bot token** is stored encrypted in the `app_settings` table
- **SSO client secrets** are stored encrypted in the `app_settings` table
- **Reports** are served through authenticated routes — never accessible via direct `/storage/` URL
- **Shareable links** use HMAC-SHA256 — unforgeable without the `APP_KEY`, and expire after 30 days
- **API tokens** are Sanctum Personal Access Tokens stored as hashed values; they cannot be retrieved after creation
- **`APP_KEY`** is the root secret for encryption and HMAC signing — back it up securely and never rotate it without re-encrypting stored data

---

## Licence

MIT
