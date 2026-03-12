# Cypress Dashboard

A self-hosted Cypress testing dashboard built with **Laravel 11** and **Filament v3**. Trigger Cypress test suites from a web UI, watch live output stream in real time, generate branded per-client HTML and PDF reports, and deliver expiring shareable links to clients — no third-party testing service required.

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
16. [Project Structure](#project-structure)
17. [Architecture Overview](#architecture-overview)
18. [Database Schema](#database-schema)
19. [Deployment](#deployment)
20. [Git Repository Setup](#git-repository-setup)
21. [Troubleshooting](#troubleshooting)

---

## Features

- **Multi-client branding** — per-client logo, colours, and footer text on all reports
- **Multi-project** — each project maps to a separate Git repository with its own deploy key
- **Test suites** — define spec patterns, branch overrides, and env vars per suite
- **One-click test runs** — trigger Cypress from the admin UI, no CI pipeline required
- **Live log streaming** — watch Cypress output line by line via WebSocket (Laravel Reverb)
- **Mochawesome parsing** — automatically merges and parses JSON test reports into the database
- **Branded HTML reports** — fully self-contained, per-client styled reports
- **PDF export** — downloadable PDFs via Browsershot/Chromium, with in-browser print fallback
- **Screenshots & videos** — stored and displayed inline with lightbox modal in reports
- **Shareable links** — 30-day expiring HMAC-signed URLs for client delivery, no login required
- **Role-based access** — Admin (full access) and PM (run tests, view reports only)
- **User management** — admin panel to create and manage user accounts
- **Artifact cleanup** — scheduled command to purge screenshots, videos, and reports older than N days
- **Re-run** — trigger a new run from any completed run with one click

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend framework | Laravel 11 |
| Admin panel | Filament v3 |
| Real-time / WebSocket | Laravel Reverb |
| Frontend reactivity | Livewire 3 + Alpine.js |
| Asset pipeline | Vite |
| Queue driver | Redis |
| Cache driver | Redis |
| Session driver | Database |
| Database | SQLite (development) / MySQL or PostgreSQL (production) |
| PDF generation | Spatie Browsershot + Chromium |
| Test runner | Cypress (installed per-project via npm) |
| Report parsing | Mochawesome JSON |

---

## Prerequisites

Install the following before running the application:

| Requirement | Notes |
|---|---|
| PHP 8.2+ | Extensions: `pdo`, `openssl`, `mbstring`, `xml`, `curl`, `redis` |
| Composer | PHP dependency manager |
| Node.js 18+ | Runtime for Vite and Cypress |
| npm | Package manager for frontend and Cypress |
| Redis | Required for queue and cache |
| Git | For cloning test repositories |
| Chromium / Chrome | Optional — required for PDF report generation |
| SSH | Required if using private Git repositories |

---

## Local Development Setup

```bash
# 1. Clone the repository
git clone <your-repo-url> cypress-dashboard
cd cypress-dashboard

# 2. Install PHP dependencies
composer install

# 3. Install Node dependencies
npm install

# 4. Copy the environment file
cp .env.example .env

# 5. Generate the application key
php artisan key:generate

# 6. Configure .env (see Environment Variables section)

# 7. Create the SQLite database file (if using SQLite)
touch database/database.sqlite

# 8. Run migrations and seed demo data
php artisan migrate --seed

# 9. Create the public storage symlink
php artisan storage:link

# 10. Build frontend assets
npm run build
```

Then start the required processes — each in a separate terminal:

```bash
# Terminal 1 — Web server (or use Laravel Herd / Valet)
php artisan serve

# Terminal 2 — Queue worker (processes Cypress test jobs)
php artisan queue:work --timeout=3600 --tries=1

# Terminal 3 — Reverb WebSocket server (live log streaming)
php artisan reverb:start

# Terminal 4 — Vite dev server (hot module reloading)
npm run dev
```

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
```

> `APP_URL` must be correct. Report URLs and shareable links are built from this value. If the queue worker starts with the wrong `APP_URL`, restart it after updating `.env`.

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

```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database
SESSION_LIFETIME=120

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### Broadcasting (Reverb WebSocket)

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=cypress-dashboard
REVERB_APP_KEY=your-reverb-key        # Any unique string
REVERB_APP_SECRET=your-reverb-secret  # Any unique string
REVERB_HOST=localhost                 # Your domain in production
REVERB_PORT=8080
REVERB_SCHEME=http                    # Use https in production

# Passed to the browser via Vite — must match the REVERB_ values above
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

> Reverb credentials are internal — they are not tied to any external service. Choose any unique strings.

### Git & Node

```env
# Directory where project SSH deploy keys are written
GIT_SSH_KEY_PATH=/home/www-data/.ssh

# Absolute paths to Node and npm binaries on the server
NODE_PATH=/usr/local/bin/node
NPM_PATH=/usr/local/bin/npm
```

Find the correct paths with `which node` and `which npm`. These must be the paths accessible to the user running the queue worker (e.g. `www-data`).

### Storage

```env
FILESYSTEM_DISK=local   # Do not change — reports use the private local disk
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
| `storage/app/private/reports/run-{id}/report.pdf` | `local` (private) | Auth-gated controller | PDF reports |
| `storage/app/public/runs/{id}/screenshots/` | `public` | Public URL via `/storage/` | Test screenshots |
| `storage/app/public/runs/{id}/videos/` | `public` | Public URL via `/storage/` | Test videos |

Reports are intentionally **not** stored in the public disk. They are served through Laravel controller routes that enforce authentication (`/reports/run/{id}/html`) or HMAC token validation (`/reports/share/{id}/{token}`). There is no way to access a report by guessing a storage path.

---

## Queue & Real-time Workers

All test runs are processed asynchronously by a queue worker. Live log output is broadcast to the browser via the Reverb WebSocket server.

### Starting workers (development)

```bash
php artisan queue:work --timeout=3600 --tries=1
php artisan reverb:start
```

> **Important:** The queue worker caches the application config on startup. After any change to `.env`, restart the worker: `php artisan queue:restart` (or kill and restart the process).

### Procfile

The included `Procfile` defines both worker processes for hivemind/overmind:

```
queue: php artisan queue:work --timeout=3600
reverb: php artisan reverb:start
```

### Production (Supervisor)

Use Supervisor to keep both processes running and automatically restart them on failure.

Create `/etc/supervisor/conf.d/cypress-dashboard.conf`:

```ini
[program:cypress-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/cypress-dashboard/artisan queue:work --timeout=3600 --tries=1 --sleep=3
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/cypress-dashboard/storage/logs/queue.log

[program:cypress-reverb]
process_name=%(program_name)s
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
| Manage clients | ✅ | ❌ |
| Manage projects | ✅ | ❌ |
| Manage test suites | ✅ | ❌ |
| Manage users | ✅ | ❌ |
| Delete test runs | ✅ | ❌ |

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
- Generate a deploy key and add the public key to your Git provider (see [Deploy Keys](#deploy-keys-ssh))
- Add any environment variables that all test suites in this project need (e.g. `CYPRESS_BASE_URL`)

### 3. Add Test Suites

On the project page, open the **Test Suites** tab and create a suite:

- **Spec pattern** — e.g. `cypress/e2e/**/*.cy.js` or `cypress/e2e/smoke/*.cy.js`
- **Branch override** — leave blank to use the project's default branch
- **Environment variables** — suite-specific overrides (merged on top of project-level vars)
- **Timeout** — maximum minutes before the run is killed (default: 60)

### 4. Run Tests

Go to **Testing → Test Runs** and click **Run Tests** in the top-right. Select project, suite, and branch, then click **Run**. The job is dispatched to the queue immediately.

Click **View** on the queued run to open the live view, where log output streams in as Cypress runs.

---

## Running Tests

The test job (`RunCypressTestJob`) performs these steps in order:

1. Clones the repository into a temporary directory
2. Writes the SSH deploy key to disk and configures the `GIT_SSH_COMMAND` env var
3. Sets status → `cloning` → `installing` → `running` (broadcast to UI at each step)
4. Runs `npm install`
5. Runs `npm run build:tailwind` if that script is defined in `package.json`
6. Runs `npx cypress run --spec "{spec_pattern}"` with merged env vars
7. Runs `npx mochawesome-merge` to combine per-spec JSON files
8. Copies the merged JSON to the public storage disk
9. Parses results into `test_results` rows in the database
10. Maps video files to spec results
11. Maps screenshot files to failed test results
12. Generates the branded HTML report (stored on the private local disk)
13. Generates a PDF report via Browsershot (falls back to HTML if unavailable)
14. Broadcasts a final `status.changed` event to the browser
15. Cleans up the temporary directory

If any step fails, the run is marked `error` and the error message is stored.

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
- **Save as PDF** print button (floating, hidden during print)

### PDF Report

Generated via Spatie Browsershot (requires Chromium on the server). Falls back to the HTML report if Browsershot is unavailable.

**Access:** Test Runs table → **PDF Report** button, or the run detail view header.

### Shareable Links

Produce a link that lets a client view the HTML report without logging in.

**Access:** **Share Link** button on any completed run (table or detail view).

The link embeds a 30-day UTC expiry timestamp and an HMAC-SHA256 token signed with your `APP_KEY`. After 30 days the link returns 403. Generate a new link at any time from the run view.

```
https://your-dashboard.com/reports/share/{run_id}/{token}?expires={unix_timestamp}
```

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
php artisan migrate              # Run pending migrations
php artisan migrate:fresh --seed # Drop all tables, re-run, and seed
php artisan storage:link         # Create public disk symlink (run after fresh deploy)
php artisan queue:work           # Start queue worker
php artisan queue:restart        # Signal running workers to restart after next job
php artisan reverb:start         # Start WebSocket server
php artisan schedule:run         # Run due scheduled tasks (called by cron)
php artisan config:cache         # Cache config (use in production)
php artisan route:cache          # Cache routes (use in production)
php artisan view:cache           # Cache views (use in production)
php artisan cache:clear          # Clear application cache
```

---

## Project Structure

```
cypress-dashboard/
├── app/
│   ├── Console/Commands/
│   │   ├── CleanupOldArtifacts.php      # runs:cleanup
│   │   └── RegenerateReports.php        # runs:regenerate-reports
│   ├── Events/
│   │   ├── TestRunStatusChanged.php     # Broadcast: status, counts, report URLs
│   │   └── TestRunLogReceived.php       # Broadcast: live log lines
│   ├── Filament/Resources/
│   │   ├── ClientResource.php           # Admin-only: client CRUD
│   │   ├── ProjectResource.php          # Admin-only: project CRUD
│   │   ├── TestRunResource.php          # All users: test runs table + trigger action
│   │   ├── UserResource.php             # Admin-only: user management
│   │   └── TestRunResource/Pages/
│   │       ├── ListTestRuns.php
│   │       └── ViewTestRun.php          # Live polling, share/download/re-run actions
│   ├── Http/Controllers/
│   │   └── ReportController.php         # html(), pdf(), share() — serves report files
│   ├── Jobs/
│   │   └── RunCypressTestJob.php        # Core job: clone → install → run → report
│   ├── Models/
│   │   ├── Client.php
│   │   ├── Project.php                  # Encrypted deploy key + env vars
│   │   ├── TestRun.php                  # Status constants, URL accessors
│   │   ├── TestResult.php               # Per-test outcomes, media paths
│   │   ├── TestSuite.php                # Spec patterns, branch override
│   │   └── User.php                     # isAdmin(), isPM(), canAccessPanel()
│   ├── Providers/Filament/
│   │   └── AdminPanelProvider.php       # Panel config, nav groups, colours
│   └── Services/
│       ├── MochawesomeParserService.php # Parses merged JSON → TestResult rows
│       └── ReportGeneratorService.php   # Renders HTML report, generates PDF
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
Browser
  │
  ├── Filament Admin Panel (/admin)
  │     Livewire + Alpine.js
  │     wire:poll → pollStatus() → dispatch('run-status-updated')
  │     Alpine listens on window → updates status, reloads on completion
  │
  ├── Report Controller (/reports/...)
  │     /run/{id}/html    — requires auth middleware
  │     /run/{id}/pdf     — requires auth middleware
  │     /share/{id}/{tok} — HMAC + expiry validation (no auth needed)
  │
  └── Reverb WebSocket (:8080)
        Laravel Echo subscribes to test-run.{id} channel
        Receives: status.changed, log.received events

Queue Worker
  └── RunCypressTestJob
        Clone → Install → Run Cypress → Parse → Store → Report
        Broadcasts events to Reverb at each stage

Storage
  ├── local disk (private)   — HTML + PDF reports
  └── public disk            — Screenshots + videos (served via /storage/)
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
  deploy_key_private (encrypted), deploy_key_public,
  env_variables (encrypted JSON),
  active, deleted_at, timestamps

test_suites
  id, project_id, name, slug, description,
  spec_pattern, branch_override,
  env_variables (encrypted JSON),
  timeout_minutes, active, deleted_at, timestamps

test_runs
  id, project_id, test_suite_id, triggered_by (user_id),
  status, branch, commit_sha,
  total_tests, passed_tests, failed_tests, pending_tests,
  duration_ms, log_output, error_message,
  report_html_path, report_pdf_path, merged_json_path,
  started_at, finished_at, timestamps

test_results
  id, test_run_id, spec_file, suite_title, test_title, full_title,
  status, duration_ms, error_message, error_stack, test_code,
  screenshot_paths (JSON array), video_path, attempt, timestamps

users
  id, name, email, password, role (admin|pm), timestamps
```

---

## Deployment

### Web server (Nginx)

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/cypress-dashboard/public;

    index index.php;
    charset utf-8;
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

For HTTPS, use Certbot: `sudo certbot --nginx -d your-domain.com`

### Deployment checklist (every deploy)

```bash
# Pull latest code
git pull origin main

# Install PHP dependencies (production, no dev)
composer install --no-dev --optimize-autoloader

# Build frontend assets
npm ci
npm run build

# Run any new migrations
php artisan migrate --force

# Cache config, routes, and views
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Ensure storage symlink exists
php artisan storage:link

# Set permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Restart queue worker (picks up new code and config)
php artisan queue:restart

# Restart Reverb
sudo supervisorctl restart cypress-reverb
```

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
- `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` (if using S3)

Store these as environment variables or encrypted secrets in your hosting platform (e.g. Forge, Ploi, GitHub Actions secrets).

---

## Troubleshooting

**Report URLs are broken / wrong domain in links**
`APP_URL` in `.env` is wrong or the queue worker started with a stale value. Update `APP_URL` and restart the worker: `php artisan queue:restart`.

**Share link returns 403 immediately**
The link was generated before the most recent `APP_KEY` change (changing the key invalidates all HMAC tokens), or the link is over 30 days old. Generate a new link from the run view.

**Run view stuck at "Cypress Tests running" after completion**
Ensure the Reverb WebSocket server is running and `VITE_REVERB_*` values match `REVERB_*` in `.env`. The view also polls every 3 seconds as a fallback — if polling works but the WebSocket does not, check the Reverb server and browser console for connection errors.

**Queue jobs not running / stuck in pending**
Confirm Redis is running (`redis-cli ping` → `PONG`) and `QUEUE_CONNECTION=redis` is set. Start the worker: `php artisan queue:work --verbose`.

**Git clone fails**
Test SSH access manually as the web server user:
```bash
sudo -u www-data ssh -T git@github.com -o StrictHostKeyChecking=accept-new
```

**Cypress not found in the job**
Confirm `NODE_PATH` and `NPM_PATH` in `.env` point to binaries accessible by the queue worker user:
```bash
sudo -u www-data /usr/local/bin/npx cypress --version
```

**PDF not generating**
Confirm Chromium is installed and accessible. Test via Tinker:
```bash
php artisan tinker
>>> \Spatie\Browsershot\Browsershot::url('https://example.com')->bodyHtml();
```

**Auth redirect loop**
`routes/web.php` must define `Route::get('/login', ...)` pointing to `/admin/login`. Laravel's `auth` middleware redirects to `route('login')` — without this named route, it will loop or 404.

**`Class "App\Http\Controllers\Controller" not found`**
Laravel 11 removed the base `Controller` class from the default skeleton. `ReportController` does not extend it. If you have other controllers that do, remove the `extends Controller` line.

---

## Security Notes

- **Deploy keys** are stored encrypted at rest using Laravel's `Crypt` facade (AES-256-CBC via `APP_KEY`)
- **Project and suite environment variables** are also encrypted at rest
- **Reports** are served through authenticated routes — never accessible via direct `/storage/` URL
- **Shareable links** use HMAC-SHA256 — unforgeable without the `APP_KEY`, and expire after 30 days
- **`APP_KEY`** is the root secret for encryption and HMAC signing — back it up securely and never rotate it without re-encrypting stored data

---

## Licence

MIT
