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
12. [AI Test Builder](#ai-test-builder)
13. [Running Tests](#running-tests)
14. [Reports](#reports)
15. [Scheduled Tasks & Artifact Cleanup](#scheduled-tasks--artifact-cleanup)
16. [Artisan Commands Reference](#artisan-commands-reference)
17. [REST API](#rest-api)
18. [Branding Settings](#branding-settings)
19. [SSO (Single Sign-On)](#sso-single-sign-on)
20. [Slack Notifications](#slack-notifications)
21. [macOS Companion App - SignalDeck CI](#macos-companion-app-signaldeck-ci)
22. [Project Structure](#project-structure)
23. [Architecture Overview](#architecture-overview)
24. [Database Schema](#database-schema)
25. [Deployment (VPS + Cloudflare)](#deployment)
26. [Git Repository Setup](#git-repository-setup)
27. [Troubleshooting](#troubleshooting)

---

## Features

- **Dual runner support** — Cypress and Playwright, configured per-project
- **Multi-client branding** — per-client logo, colours, and footer text on all reports
- **Multi-project** — each project maps to a separate Git repository with its own deploy key
- **Test suites** — define spec patterns, branch overrides, and env vars per suite
- **One-click test runs** — trigger tests from the admin UI, no CI pipeline required
- **Live console output** — watch test output stream in real time via Server-Sent Events (SSE); no WebSocket server required
- **Result parsing** — Mochawesome (Cypress) and Playwright JSON reports parsed into the database
- **Playwright project discovery** — auto-detect available browsers/devices from `playwright.config.ts`
- **Performance tuning** — admin-only parallel workers and retry overrides for Playwright suites
- **Branded HTML reports** — fully self-contained, per-client styled reports, plus a branded PDF summary (stats and per-spec pass/fail) rendered on demand with headless Chrome
- **Screenshots & videos** — stored and displayed inline with lightbox modal in reports
- **Shareable links** — 30-day expiring HMAC-signed URLs for client delivery, no login required
- **Run comparison** — compare any two completed runs side-by-side to spot regressions
- **Flaky test tracking** — identify tests that intermittently pass and fail across runs
- **Test history** — per-test trend view across multiple runs
- **Role-based access** — Admin (full access) and PM (run tests, view reports only)
- **User management** — admin panel to create and manage user accounts
- **Single Sign-On (SSO)** — Google and GitHub OAuth login, configurable from the admin UI
- **Scheduled runs** — per-suite cron expressions with timezone support; runs are dispatched automatically by `signaldeck:run-scheduled` every minute
- **Suite health score** — pass rate computed from the last 10 completed runs per suite; configurable SLA threshold triggers email and Slack breach alerts with a 1-hour cooldown
- **Slack DM notifications** — bot token integration sends a DM to the triggering user when a run completes
- **Slack breach alerts** — health threshold breaches post a Block Kit message to a configured channel (separate from per-user DMs)
- **REST API v1** — full Sanctum token-authenticated API for all resources; consumed by the macOS companion app
- **macOS companion app** — native SwiftUI desktop app for monitoring and triggering runs (separate repo)
- **Artifact cleanup** — scheduled command to purge screenshots, videos, and reports older than N days
- **Re-run** — trigger a new run from any completed run with one click
- **Re-run failures** — re-run only the failing spec files from a completed run
- **Email notifications** — automated email to the triggering user when a run completes (pass or fail)
- **Test Suite Generator** — generate a ready-to-run Cypress or Playwright e-commerce test scaffold (Magento-focused) as a downloadable ZIP from the admin UI or API
- **AI Test Builder** — describe a test in plain English (or record it in the browser) and the AI writes Cypress or Playwright specs, using a crawl of the live page for real selectors
- **Live verification** — every generated test is run against the target site, with a bounded number of AI fix-up attempts if it fails
- **Managed suites** — save generated tests as a suite stored in the dashboard itself, no Git repository required
- **Flow recorder** — a Chrome extension records clicks, inputs and form submits across page loads and turns them into a test
- **Automated repair** (opt-in) — when a managed suite keeps failing, the AI diagnoses why and, if the tests look out of date, emails a verified fix for human review
- **Anthropic or OpenAI-compatible AI** — Claude, or any `/chat/completions` endpoint including local Ollama

---

## Tech Stack

| Layer               | Technology                                              |
| ------------------- | ------------------------------------------------------- |
| Backend framework   | Laravel 12                                              |
| Admin panel         | Filament v3                                             |
| Frontend reactivity | Livewire 3 + Alpine.js                                  |
| Asset pipeline      | Vite                                                    |
| Real-time transport | Server-Sent Events (SSE)                                |
| API authentication  | Laravel Sanctum (Bearer tokens)                         |
| OAuth / SSO         | Laravel Socialite + filament-socialite                  |
| Queue driver        | Database (dev) / Redis (production)                     |
| Cache driver        | File (dev) / Redis (production)                         |
| Session driver      | Database                                                |
| Database            | SQLite (development) / MySQL or PostgreSQL (production) |
| Test runners        | Cypress and Playwright (installed per-project via npm)  |
| Report parsing      | Mochawesome JSON (Cypress) / Playwright JSON reporter   |

---

## Prerequisites

Install the following before running the application:

| Requirement | Notes                                                                             |
| ----------- | --------------------------------------------------------------------------------- |
| PHP 8.2+    | Extensions: `pdo`, `openssl`, `mbstring`, `xml`, `curl`                           |
| Composer    | PHP dependency manager                                                            |
| Node.js 18+ | Runtime for Vite, Cypress, and Playwright                                         |
| npm         | Package manager for frontend and test runners                                     |
| Redis       | Recommended for production. Not needed locally — see Queue, Cache & Session below |
| Git         | For cloning test repositories                                                     |
| SSH         | Required if using private Git repositories                                        |

---

## Local Development Setup

```bash
# 1. Clone the repository
git clone <your-repo-url> signaldeck-ci-server
cd signaldeck-ci-server

# 2. Copy the environment file (must exist before composer runs artisan commands)
cp .env.example .env

# 3. Create required directories (excluded from git, needed before composer install)
mkdir -p bootstrap/cache storage/framework/{sessions,views,cache} storage/logs

# 4. Create the SQLite database file (must exist before composer runs artisan commands)
touch database/database.sqlite

# 5. Install PHP dependencies
composer install

# 6. Install Node dependencies
npm install

# 7. Generate the application key
php artisan key:generate

# 8. Configure .env (see Environment Variables section below)

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

# Terminal 2 — Queue worker (processes test jobs; must be running to execute test suites)
php artisan queue:work --queue=cypress --timeout=3600 --tries=1

# Terminal 3 — Vite dev server (hot module reloading)
npm run dev
```

> **Report CSS:** The branded HTML report inlines its CSS from the compiled Vite build (`public/build/`). `npm run dev` does **not** update report styles — run `npm run build` then regenerate the report to see changes to `branded.blade.php`.

> **SSE (live log streaming):** When running via `php artisan serve`, Server-Sent Events work out of the box — no extra configuration required. If you are running locally via **Laravel Herd or Valet** (which use Nginx), add the same SSE location block described in [Step 6 of the Deployment section](#step-6--nginx) to your site's Nginx config; without `fastcgi_buffering off` and `fastcgi_read_timeout 600s`, Nginx will buffer the stream and no log lines will appear in the run view until the job finishes.

---

## Environment Variables

Copy `.env.example` to `.env` and fill in the values below.

### Application

```env
APP_NAME=SignalDeck CI
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

### AI Test Builder (optional)

```env
# Hostnames the builder may crawl and verify against even though they resolve
# to private IP addresses (self-hosted intranet sites). Comma-separated.
# Leave empty on hosted installs — private addresses are blocked by default.
AI_ALLOWED_PRIVATE_HOSTS=
```

AI provider credentials are not set in `.env`; configure them in **Settings → AI Provider**.

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

| Email               | Password   | Role  |
| ------------------- | ---------- | ----- |
| `admin@example.com` | `password` | Admin |
| `pm@example.com`    | `password` | PM    |

> **Change these immediately on any non-local environment.** You can do this from **Admin → Users** in the panel.

The seeder also creates two demo clients (Acme Corp and Globex Solutions) with projects and test suites, so you can explore the UI without configuring real repositories first.

---

## Storage Setup

```bash
# Must be run after every fresh deployment
php artisan storage:link
```

### What is stored where

| Path                                               | Disk              | Access                     | Contents         |
| -------------------------------------------------- | ----------------- | -------------------------- | ---------------- |
| `storage/app/private/reports/run-{id}/report.html` | `local` (private) | Auth-gated controller      | HTML reports     |
| `storage/app/public/runs/{id}/screenshots/`        | `public`          | Public URL via `/storage/` | Test screenshots |
| `storage/app/public/runs/{id}/videos/`             | `public`          | Public URL via `/storage/` | Test videos      |

Reports are intentionally **not** stored in the public disk. They are served through Laravel controller routes that enforce authentication (`/reports/run/{id}/html`) or HMAC token validation (`/reports/share/{id}/{token}`). There is no way to access a report by guessing a storage path.

---

## Queue & Real-time Workers

All test runs are processed asynchronously by a queue worker. Live log output streams to connected clients via Server-Sent Events (SSE) — no separate WebSocket process is required.

### Starting workers (development)

```bash
php artisan queue:work --queue=cypress --timeout=3600 --tries=1
php artisan queue:work --queue=default --tries=3 --timeout=60
```

> **Important:** Both Cypress and Playwright jobs dispatch to the `cypress` queue. Email notifications, Slack alerts, and health breach checks dispatch to the `default` queue. Both workers must be running to process all job types.

> The queue worker caches the application config on startup. After any change to `.env`, restart the worker: `php artisan queue:restart` (or kill and restart the process).

### Production (Supervisor)

Use Supervisor to keep the queue worker running and automatically restart on failure.

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

## Webhook Integration

Webhook triggers let you start a test run from any CI pipeline without storing an API token — requests are authenticated via an HMAC-SHA256 signature computed with a per-project secret.

### How it works

1. Your CI pipeline builds a JSON payload and computes `HMAC-SHA256(raw_body, webhook_secret)`.
2. The signature is sent in the `X-Webhook-Signature` request header (hex digest).
3. The server verifies the signature before dispatching the run. Unsigned or incorrectly signed requests are rejected with `403`.
4. **NOTE** - The webhook endpoint must be publicly accessible.

### Endpoint

```
POST /api/v1/webhook/trigger
Content-Type: application/json
X-Webhook-Signature: <hmac-sha256-hex-of-body>
```

### Payload fields

| Field      | Type      | Required | Description                                              |
| ---------- | --------- | -------- | -------------------------------------------------------- |
| `suite_id` | `integer` | Yes      | ID of the test suite to run.                             |
| `branch`   | `string`  | No       | Branch to check out. Defaults to the suite's configured branch. |
| `env`      | `object`  | No       | Key/value pairs that override environment variables for this run only. |

Example payload:

```json
{
  "suite_id": 1,
  "branch": "main",
  "env": {
    "BASE_URL": "https://staging.example.com"
  }
}
```

### Generate / rotate the webhook secret

In the admin panel: **Management → Projects → Edit Project → Webhook Secret → Generate Secret** (or **Rotate Secret** to replace an existing one). The plaintext secret is shown once — store it immediately in your CI provider's secret manager.

### GitHub Actions

Store the secret as `TD_WEBHOOK_SECRET` in **Settings → Secrets and variables → Actions**.

```yaml
# .github/workflows/trigger-tests.yml
name: Trigger Test Dashboard
on:
  push:
    branches: [main]
  workflow_dispatch:
    inputs:
      suite_id:
        description: 'Suite ID to run'
        required: true
        default: '1'
      branch:
        description: 'Branch to test (leave blank for triggering branch)'
        required: false

jobs:
  trigger:
    runs-on: ubuntu-latest
    steps:
      - name: Trigger test run
        env:
          TD_SECRET: ${{ secrets.TD_WEBHOOK_SECRET }}
          SUITE_ID: ${{ inputs.suite_id || '1' }}
          BRANCH: ${{ inputs.branch || github.ref_name }}
        run: |
          PAYLOAD=$(jq -cn --argjson suite_id "$SUITE_ID" --arg branch "$BRANCH" \
            '{suite_id: $suite_id, branch: $branch}')
          SIG=$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$TD_SECRET" | awk '{print $NF}')
          curl -sf -X POST "https://your-domain.com/api/v1/webhook/trigger" \
            -H "Content-Type: application/json" \
            -H "X-Webhook-Signature: $SIG" \
            -d "$PAYLOAD"
```

### GitLab CI

Store the secret as `TD_WEBHOOK_SECRET` in **Settings → CI/CD → Variables** (masked, protected).

```yaml
# .gitlab-ci.yml (job)
trigger_tests:
  stage: .pre
  image: alpine:latest
  variables:
    SUITE_ID: "1"
    BRANCH: $CI_COMMIT_REF_NAME
  before_script:
    - apk add --no-cache curl openssl jq
  script:
    - >
      PAYLOAD=$(jq -cn --argjson suite_id "$SUITE_ID" --arg branch "$BRANCH"
      '{suite_id: $suite_id, branch: $branch}')
    - SIG=$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$TD_WEBHOOK_SECRET" | awk '{print $NF}')
    - >
      curl -sf -X POST "https://your-domain.com/api/v1/webhook/trigger"
      -H "Content-Type: application/json"
      -H "X-Webhook-Signature: $SIG"
      -d "$PAYLOAD"
  only:
    - main
```

### Bitbucket Pipelines

Store the secret as `TD_WEBHOOK_SECRET` in **Settings → Pipelines → Repository variables**.

```yaml
# bitbucket-pipelines.yml
pipelines:
  branches:
    main:
      - step:
          name: Trigger Test Dashboard
          image: alpine:latest
          script:
            - apk add --no-cache curl openssl jq
            - >
              PAYLOAD=$(jq -cn
              --argjson suite_id "${SUITE_ID:-1}"
              --arg branch "${OVERRIDE_BRANCH:-$BITBUCKET_BRANCH}"
              '{suite_id: $suite_id, branch: $branch}')
            - SIG=$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$TD_WEBHOOK_SECRET" | awk '{print $NF}')
            - >
              curl -sf -X POST "https://your-domain.com/api/v1/webhook/trigger"
              -H "Content-Type: application/json"
              -H "X-Webhook-Signature: $SIG"
              -d "$PAYLOAD"

# Repository variables:
#   TD_WEBHOOK_SECRET  — the generated secret
#   SUITE_ID           — optional; defaults to 1
#   OVERRIDE_BRANCH    — optional; defaults to the triggering branch
```

### Shell / cURL

```bash
#!/usr/bin/env bash
set -euo pipefail

TD_SECRET="${TD_WEBHOOK_SECRET}"
SUITE_ID=1

PAYLOAD=$(jq -cn --argjson suite_id "$SUITE_ID" --arg branch "main" \
  '{suite_id: $suite_id, branch: $branch}')
SIG=$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$TD_SECRET" | awk '{print $NF}')

curl -sf -X POST "https://your-domain.com/api/v1/webhook/trigger" \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Signature: $SIG" \
  -d "$PAYLOAD"
```

> **Note:** `jq` is used to build the payload because it correctly handles branch names containing `/` or special characters. If `jq` is unavailable, ensure your manual JSON escaping is correct — the HMAC is computed over the exact bytes sent as the request body.

---

## Roles & Permissions

| Capability                         | Admin | PM  |
| ---------------------------------- | ----- | --- |
| View test runs                     | ✅    | ✅  |
| Trigger test runs                  | ✅    | ✅  |
| View / download reports            | ✅    | ✅  |
| Share report links                 | ✅    | ✅  |
| Re-run a test                      | ✅    | ✅  |
| Re-run failures only               | ✅    | ✅  |
| Compare runs                       | ✅    | ✅  |
| View flaky tests                   | ✅    | ✅  |
| View test history                  | ✅    | ✅  |
| Use the AI Test Builder            | ✅    | ✅  |
| Manage clients                     | ✅    | ❌  |
| Manage projects                    | ✅    | ❌  |
| Manage test suites                 | ✅    | ❌  |
| Playwright performance tuning      | ✅    | ❌  |
| Manage users                       | ✅    | ❌  |
| Delete test runs                   | ✅    | ❌  |
| Manage settings (mail, SSO, Slack, AI) | ✅ | ❌  |
| Generate API tokens                | ✅    | ❌  |

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
- **Scheduled Runs** — enable a cron expression (e.g. `0 9 * * 1-5` for weekdays at 9am) with an optional timezone; runs are dispatched automatically when the scheduler fires
- **Health & SLA threshold** — set a minimum pass rate (%); a breach alert is sent via email and Slack when the suite's rolling pass rate drops below it

For **Playwright suites**:

- **Playwright Projects** — select which browsers/devices to test (e.g. chromium, firefox, webkit)
- **Performance Tuning** (admin only) — override parallel workers and retry count via CLI flags

### 4. Run Tests

Go to **Testing → Test Runs** and click **Run Tests** in the top-right. Select project, suite, and branch, then click **Run**. The job is dispatched to the queue immediately.

Click **View** on the queued run to open the live view, where console output streams in real time via SSE.

### 5. Generate Test Scaffolds (optional)

**Testing → Test Generator**

A multi-step wizard generates a ready-to-run Cypress or Playwright e-commerce test suite for Magento-based stores. Select the test scenarios you need, provide your store's base URL and credentials, and download a ZIP containing fully configured spec files and supporting config. Available to Admin and PM roles.

### 6. Build Tests with AI (optional)

**Testing → AI Test Builder** — write or record tests without a repository. See [AI Test Builder](#ai-test-builder).

---

## AI Test Builder

**Testing → AI Test Builder** (Admin and PM). The page only appears once an admin has configured an [AI provider](#ai-provider).

### Building a test

1. Select a project.
2. Optionally **crawl** the page you want to test. The builder loads it in headless Chromium and gives the AI the page's real structure (forms, buttons, links), so generated selectors match the live site.
3. Describe the test in the chat, e.g. *"log in with an invalid password and check the error message"*. Keep chatting to refine it.
4. Pick **Playwright** or **Cypress**. Switching converts the existing tests to the other framework.
5. Save the result as a [managed suite](#managed-suites) (**Save as managed suite**, or **Update Suite** if the conversation is already linked to one), or download it as a ZIP to commit to your own repository.

### Verification

Each time tests are generated, `VerifyGeneratedTestJob` runs them against the live site on the queue. If they fail, the error is sent back to the AI for a fix, up to **Verification attempts** times (Settings → AI Provider). The builder shows the outcome:

- **Verified — passed against the live site**
- **Not verified — review before saving** (still failing after the allowed fixes)
- **Not verified — …** when verification was skipped, e.g. no target URL or the test needs extra npm dependencies

Saving is blocked only while verification is still running; unverified tests can still be saved after review.

### Recording a flow

Instead of describing a test, you can record one:

1. Install the **SignalDeck Flow Recorder** Chrome extension once per machine (`chrome://extensions` → Developer mode → **Load unpacked** → select `browser-extension/`). See [`browser-extension/README.md`](browser-extension/README.md).
2. In the builder, click **Record a Flow**, then use the target site normally. Clicks, field changes and form submits are captured across page loads, including onto other domains such as a payment gateway. Password and other sensitive fields are never recorded.
3. Open the extension popup and choose **Stop & save recording**, then click **Generate test from recording** in the builder.

Recording sessions expire after 2 hours. Before shipping the extension beyond local development, update the dashboard origin in `browser-extension/manifest.json`.

### Managed suites

A managed suite stores its test files in the database (`managed_test_files`) instead of cloning a Git repository. At run time the files are written to a temporary directory, with a default `cypress.config.js` or `playwright.config.ts` generated if none is included. Otherwise managed suites behave like any other suite: schedules, health thresholds, reports and notifications all work the same.

- The **Source** column on a project's Test Suites tab shows *Managed* or *Repository*.
- Edit a managed suite's files directly in the suite form (**Test Files**), or click **AI Builder** on the suite to keep refining it in the chat.
- Set the suite's **Base URL** to the site it tests. It is required for [automated repair](#automated-repair).

### Private and intranet sites

To stop the builder being used to reach internal services, crawling and verification refuse URLs that resolve to private or loopback addresses. To test a self-hosted intranet site, list its hostname in `AI_ALLOWED_PRIVATE_HOSTS`.

### AI Provider

The AI Test Builder supports two kinds of provider, chosen by an admin at **Settings > AI Provider**:

- **Anthropic Claude** — highest quality. Defaults to Haiku 4.5 for cost; Sonnet is selectable.
- **OpenAI-compatible** — Ollama, Groq, Gemini, OpenRouter, or any custom `/chat/completions` endpoint.

#### Free / local with Ollama

```bash
ollama pull qwen2.5-coder:14b
ollama serve   # http://localhost:11434
```

Choose *OpenAI-compatible* > *Ollama* preset, then use **Test connection**. Ollama must be reachable from the app server (and the queue worker). Ollama's default context window is small (4,096 tokens on many versions) and it silently truncates longer prompts, so start it with a larger one, e.g. `OLLAMA_CONTEXT_LENGTH=16384 ollama serve`. Small models have limited context: set **Max prompt size** if responses degrade. Hosted free tiers may train on your prompts, so prefer Ollama for client work.

#### Cost controls

- **Verification attempts** caps AI fix-up calls per generated test.
- Token usage is logged (`AI usage`) and shown per conversation in the builder.

### Automated repair

Off by default. Turn it on under **Settings > AI Provider** (*Automated Repair* section). When a managed suite with a base URL fails its last N runs in a row (**Consecutive failures before repair**, minimum 3), the AI diagnoses the failure. If it judges the tests out of date it proposes a fix, which is verified against the live site and emailed for review. Fixes are never applied automatically. At most one attempt per suite per 24 hours; **Automated repairs per day** caps attempts across all suites (0 = unlimited).

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
- Console output is flushed to the database as it arrives and streamed to connected clients via SSE at `/api/v1/test-runs/{id}/stream`
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
- **PDF Report** — server-rendered summary PDF via Browsershot. Needs `npm install` (Puppeteer, no browser download) and Chrome; set `PDF_CHROME_PATH` if it isn't in a standard location

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

| Time           | Command                       | Description                                             |
| -------------- | ----------------------------- | ------------------------------------------------------- |
| Every minute   | `signaldeck:run-scheduled`    | Dispatches runs for suites whose cron schedule is due   |
| Daily at 02:00 | `runs:cleanup`                | Deletes artifacts for completed runs older than 30 days |

The cleanup command removes:

- HTML and PDF reports from the local (private) disk
- Screenshots and videos from the public disk
- Nulls out the corresponding database paths

Report metadata (pass counts, status, test results) is retained in the database indefinitely.

---

## Artisan Commands Reference

### `make:admin`

Creates a new admin user or promotes an existing user to admin.

```bash
# Interactive prompts
php artisan make:admin

# Non-interactive
php artisan make:admin --name="Alice" --email="alice@example.com" --password="secret"
```

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

### `signaldeck:run-scheduled`

Checks all active suites with `schedule_enabled = true` and dispatches a test run for any whose cron expression is due. Called automatically by the Laravel scheduler every minute — also safe to run manually for testing.

```bash
php artisan signaldeck:run-scheduled
```

Dispatched runs have `trigger_source = schedule` and `triggered_by = null`. A 50-second dedup window on `last_scheduled_at` prevents double-firing if the command is called twice in quick succession.

### `runs:regenerate-reports`

Regenerates HTML reports for all completed runs. Useful after changes to the report template.

```bash
php artisan runs:regenerate-reports
```

### `purge-runs`

Permanently deletes test runs (and their associated results and artifacts) by ID or criteria. Use with care — this is irreversible.

```bash
php artisan purge-runs
```

### `test-s3-connection`

Validates the S3 configuration in `.env` by attempting a test upload and delete. Useful for confirming credentials and bucket access before migrating artifacts.

```bash
php artisan test-s3-connection
```

### `artifact-migration`

Migrates existing local run artifacts (screenshots, videos, reports) to S3 storage. Run once after configuring S3 credentials to move historical artifacts without re-running tests.

```bash
php artisan artifact-migration
```

### Standard Laravel Commands

```bash
php artisan migrate                              # Run pending migrations
php artisan migrate:fresh --seed                 # Drop all tables, re-run, and seed
php artisan storage:link                         # Create public disk symlink (run after fresh deploy)
php artisan queue:work --queue=cypress --timeout=3600  # Start queue worker
php artisan queue:restart                        # Signal running workers to restart after next job
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

| Ability         | Grants                                                       |
| --------------- | ------------------------------------------------------------ |
| `desktop:read`  | Read all resources (runs, results, logs, reports, analytics) |
| `desktop:write` | Trigger and cancel test runs                                 |
| `desktop:admin` | Full CRUD on clients, projects, suites, users, and settings  |

### Key endpoints

| Method | Path                             | Description                      |
| ------ | -------------------------------- | -------------------------------- |
| `GET`  | `/api/v1/health`                 | Health check (unauthenticated)   |
| `POST` | `/api/v1/auth/login`             | Obtain a Sanctum token           |
| `POST` | `/api/v1/auth/logout`            | Revoke current token             |
| `GET`  | `/api/v1/auth/user`              | Authenticated user profile       |
| `GET`  | `/api/v1/auth/sso/providers`     | List enabled SSO providers       |
| `GET`  | `/api/v1/dashboard/stats`        | Summary counts for the dashboard |
| `GET`  | `/api/v1/clients`                | List clients                     |
| `GET`  | `/api/v1/projects`               | List projects                    |
| `GET`  | `/api/v1/projects/{id}/suites`   | List suites for a project        |
| `GET`  | `/api/v1/test-runs`              | List test runs (filterable)      |
| `POST` | `/api/v1/test-runs`              | Trigger a new test run           |
| `GET`  | `/api/v1/test-runs/{id}`         | Run detail                       |
| `GET`  | `/api/v1/test-runs/{id}/results` | Per-test results                 |
| `GET`  | `/api/v1/test-runs/{id}/logs`    | Raw console output               |
| `GET`  | `/api/v1/test-runs/{id}/report`  | Report URL / download            |
| `GET`  | `/api/v1/test-runs/compare`      | Compare two runs                 |
| `POST` | `/api/v1/test-runs/{id}/cancel`  | Cancel a queued/running run      |
| `GET`  | `/api/v1/flaky-tests`            | Flaky test analytics             |
| `GET`  | `/api/v1/test-history`           | Per-test run history             |
| `GET`  | `/api/v1/settings`               | Read admin settings              |
| `PUT`  | `/api/v1/settings/slack`         | Update Slack settings            |
| `PUT`  | `/api/v1/settings/sso`           | Update SSO settings              |
| `POST` | `/api/v1/ai-builder/conversations` | Start an AI builder conversation (`desktop:write`) |
| `GET`  | `/api/v1/ai-builder/conversations/{ulid}` | Conversation detail, messages and generated files |
| `POST` | `/api/v1/ai-builder/conversations/{ulid}/messages` | Send a message; returns the AI's reply and files |
| `POST` | `/api/v1/ai-builder/conversations/{ulid}/crawl` | Crawl a URL to give the AI live page context |
| `POST` | `/api/v1/ai-builder/conversations/{ulid}/save-suite` | Save generated files as a managed suite |

The flow recorder uses three unauthenticated endpoints under `/api/v1/recordings/{token}` (`GET /`, `POST /actions`, `POST /complete`). They are scoped by the unguessable session token, which expires after 2 hours, and rate-limited to 120 requests per minute per token.

---

## Branding Settings

Panel-level branding (name, logo, primary colour, favicon, and legal name) can be configured at runtime from **Settings → Branding** in the admin panel, removing the need to edit `.env` for cosmetic changes. The `BRAND_*` and `COMPANY_LEGAL_NAME` environment variables remain as fallback defaults when no database value is set.

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

### Run completion DMs

When enabled, the dashboard sends a Slack DM to the user who triggered a test run when it completes (pass or fail).

#### Setup

1. Create a **Slack App** at [api.slack.com/apps](https://api.slack.com/apps):
    - Add the `users:read.email` and `chat:write` OAuth scopes under **Bot Token Scopes**
    - Install the app to your workspace
    - Copy the **Bot User OAuth Token** (`xoxb-...`)
2. In the admin panel, go to **Settings → Slack**, enable notifications, paste the bot token, and click **Test Connection**.

#### How it works

- When a run finishes with status `passing` or `failed`, a `TestRunStatusChanged` event is fired.
- The `SendTestRunSlackNotification` listener looks up the triggering user's Slack ID via their email address (`users.lookupByEmail` API).
- A Block Kit DM is sent to that user with the run summary (client, project, suite, pass/fail counts, and a link to the report).
- Each run sends at most one DM (deduplicated via cache).

#### Per-user Slack ID override

If a user's Slack account uses a different email than their dashboard account, an admin can set a manual **Slack User ID** override on the user's profile in **Admin → Users**.

### Health breach alerts

When a suite's rolling pass rate drops below its configured threshold, a Block Kit message is posted to a configured Slack **channel** (not a DM — breach alerts are project-level and may have no triggering user for scheduled or webhook runs).

#### Setup

1. Invite the SignalDeck bot to your alert channel in Slack (`/invite @BotName`).
2. Copy the channel ID (right-click the channel → **Copy link** → the ID is the last segment, e.g. `C01234ABCDE`).
3. In the admin panel, go to **Settings → Slack → Health Breach Channel ID**, paste the channel ID, and save.
4. Use **Send Test Breach Alert** to verify the bot can post to the channel.

#### How it works

- After every run completes, `CheckSuiteHealthJob` evaluates the suite's pass rate across the last 10 completed runs.
- If the pass rate is below the configured threshold and no breach alert was sent in the last hour, a `SuiteHealthBreached` event is fired.
- `SendSuiteHealthBreachSlack` posts a Block Kit message to the configured channel.
- The 1-hour cooldown prevents alert storms when multiple runs fail in quick succession.

---

## macOS Companion App SignalDeck CI

A native **SwiftUI macOS app** provides a lightweight desktop interface for monitoring runs and triggering new ones without opening a browser. It connects to the dashboard REST API using a Sanctum token and supports SSO login via a custom URL scheme.

The macOS app lives in a **separate private repository**. And is not subject to the open source approach as the rest of the project.

---

## Project Structure

```
cypress-dashboard/
├── app/
│   ├── Console/Commands/
│   │   ├── ArtifactMigrationCommand.php  # artifact-migration (move local artifacts to S3)
│   │   ├── CleanupOldArtifacts.php       # runs:cleanup
│   │   ├── MakeAdminUser.php             # make:admin
│   │   ├── PurgeRunsCommand.php          # purge-runs
│   │   ├── RegenerateReports.php         # runs:regenerate-reports
│   │   ├── ScheduledRunCommand.php       # signaldeck:run-scheduled
│   │   ├── SendTransactionalEmail.php    # send-transactional-email
│   │   └── TestS3Connection.php          # test-s3-connection
│   ├── Events/
│   │   ├── SuiteHealthBelowThreshold.php # Fired on every below-threshold check (drives auto-repair)
│   │   ├── TestRunStatusChanged.php      # Broadcast: status, counts, report URLs
│   │   └── TestRunLogReceived.php        # Broadcast: live log lines
│   ├── Filament/
│   │   ├── Pages/
│   │   │   ├── CompareRuns.php           # Side-by-side run comparison
│   │   │   ├── FlakyTests.php            # Flaky test analytics
│   │   │   ├── TestHistory.php           # Per-test history trend
│   │   │   ├── TestGeneratorPage.php     # E-commerce test scaffold wizard
│   │   │   ├── AiTestBuilderPage.php     # AI chat builder, crawl, recording, verification
│   │   │   ├── AiSettingsPage.php        # AI provider, cost controls, automated repair
│   │   │   ├── BrandingSettingsPage.php  # Brand colours, logo, and panel customisation
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
│   │   │   │   ├── TestGeneratorController.php
│   │   │   │   ├── AiBuilderController.php
│   │   │   │   ├── TestRecordingController.php  # Public, token-scoped flow recorder endpoints
│   │   │   │   ├── UserController.php
│   │   │   │   └── HealthController.php
│   │   │   └── ReportController.php      # html(), share() — serves report files
│   │   └── Middleware/
│   │       └── EnsureApiTokenAbility.php # Sanctum ability checks per route group
│   ├── Jobs/
│   │   ├── Concerns/
│   │   │   └── RunsTestSuite.php         # Shared trait: clone, install, stream, cleanup
│   │   ├── RunCypressTestJob.php         # Cypress: run → parse mochawesome → report
│   │   ├── RunPlaywrightTestJob.php      # Playwright: install browsers → run → parse JSON → report
│   │   ├── VerifyGeneratedTestJob.php    # Runs AI-generated tests against the live site, with AI fix-ups
│   │   └── NotifyRepairCompletedJob.php  # Emails the outcome of an automated repair
│   ├── Listeners/
│   │   ├── TriggerSuiteRepair.php             # Gates and starts automated repair
│   │   ├── SendTestRunCompletedEmail.php      # Queued email to triggering user on run completion
│   │   └── SendTestRunSlackNotification.php  # DMs the triggering user on run completion
│   ├── Models/
│   │   ├── AiConversation.php           # Builder chat, generated files, verification, repair assessment
│   │   ├── AppSetting.php               # Key/value store for DB-backed settings
│   │   ├── Client.php
│   │   ├── ManagedTestFile.php          # Test files for managed (repo-less) suites
│   │   ├── Project.php                  # Encrypted deploy key + env vars
│   │   ├── RunEvent.php                 # Run status change events (SSE streaming)
│   │   ├── TestRun.php                  # Status constants, URL accessors
│   │   ├── TestRecordingSession.php     # Flow recorder session (token, captured actions)
│   │   ├── TestResult.php               # Per-test outcomes, media paths
│   │   ├── TestSuite.php                # Spec patterns, branch override, source type
│   │   └── User.php                     # isAdmin(), isPM(), canAccessPanel()
│   ├── Providers/Filament/
│   │   └── AdminPanelProvider.php       # Panel config, nav groups, colours
│   ├── Enums/
│   │   ├── RunnerType.php               # Cypress | Playwright enum
│   │   ├── SourceType.php               # Repo | Managed
│   │   ├── VerificationStatus.php       # Outcome of live verification
│   │   └── RecordingStatus.php
│   ├── Support/
│   │   └── UrlSafetyValidator.php       # Blocks crawls/verification of private addresses
│   └── Services/
│       ├── Ai/                           # AiProvider interface: Anthropic + OpenAI-compatible
│       ├── AiTestGeneratorService.php    # Prompts, parses generated files, framework conversion
│       ├── ManagedSuiteService.php       # Creates managed suites (builder page + API)
│       ├── SiteCrawlerService.php        # Headless Chromium page crawl for AI context
│       ├── TestExecutionService.php      # Runs tests in a temp dir for verification
│       ├── TestRepairService.php         # Diagnose → fix → verify → notify for automated repair
│       ├── MochawesomeParserService.php  # Parses Cypress merged JSON → TestResult rows
│       ├── PlaywrightParserService.php   # Parses Playwright JSON → TestResult rows
│       ├── PlaywrightConfigReaderService.php  # Discovers browser projects from repo config
│       ├── ReportGeneratorService.php    # Renders HTML report
│       ├── S3ConfigService.php           # S3 configuration and connection helpers
│       ├── ScheduledRunsService.php      # Evaluates and dispatches due scheduled runs
│       ├── SlackService.php              # Slack API: token validation, user lookup, DM sending
│       ├── SsoConfigService.php          # Reads SSO provider config from DB or .env
│       └── TestGeneratorService.php      # Generates Cypress/Playwright e-commerce test ZIPs
├── database/
│   ├── migrations/                      # All schema migrations
│   └── seeders/
│       └── DatabaseSeeder.php           # Demo users, clients, projects, suites
├── browser-extension/                   # SignalDeck Flow Recorder (Chrome, load unpacked)
├── resources/scripts/
│   └── crawl-page.cjs                   # Playwright script used by SiteCrawlerService
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
  │     SSE (EventSource) → live log + status updates per run
  │
  ├── Report Controller (/reports/...)
  │     /run/{id}/html    — requires auth middleware
  │     /share/{id}/{tok} — HMAC + expiry validation (no auth needed)
  │
  └── REST API (/api/v1)
        Sanctum Bearer token authentication
        Ability-scoped routes: desktop:read / desktop:write / desktop:admin
        SSE streams: /test-runs/{id}/stream  (per-run log + status)
                     /events/stream          (global run updates + dashboard stats)

Queue Worker (--queue=cypress)
  ├── RunCypressTestJob
  │     Clone → Install → Run Cypress → Parse Mochawesome → Store → Report
  └── RunPlaywrightTestJob
        Clone → Install → Install Browsers → Run Playwright → Parse JSON → Store → Report

  Both use RunsTestSuite trait: shared clone, install, stream, cleanup logic
  Console output flushed to DB as it arrives and streamed to clients via SSE

  Status changes recorded in run_events table; global SSE stream tails this table

  On completion → TestRunStatusChanged event
    ├── SendTestRunCompletedEmail listener → email to triggering user
    └── SendTestRunSlackNotification listener → Slack DM to triggering user

  CheckSuiteHealthJob → SuiteHealthBelowThreshold event
    └── TriggerSuiteRepair listener (if enabled, streak met, under daily cap)
          → TestRepairService: crawl page → AI diagnosis → proposed fix
          → VerifyGeneratedTestJob → NotifyRepairCompletedJob (email for review)

AI Test Builder
  Chat → AI provider (Anthropic or OpenAI-compatible) → generated spec files
  Optional crawl (headless Chromium) or recording (browser extension) as context
  VerifyGeneratedTestJob runs the files against the live site, with AI fix-ups
  Saved as a managed suite: files in managed_test_files, written to disk at run time

Storage
  ├── local disk (private)   — HTML reports
  └── public disk            — Screenshots + videos (served via /storage/)

AppSetting model (key/value)
  ├── Slack: bot token, notification toggle
  ├── SSO: provider credentials, enabled flags
  └── AI: provider, model, API key, cost controls, automated repair
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
  crawl_data (JSON, nullable),
  active, deleted_at, timestamps

test_suites
  id, project_id, name, slug, description,
  source_type (repo|managed), runner_type (nullable, overrides project),
  spec_pattern, base_url (nullable), branch_override,
  env_variables (encrypted JSON),
  playwright_projects (JSON, nullable),
  playwright_workers (nullable),
  playwright_retries (nullable),
  timeout_minutes,
  schedule_cron (nullable), schedule_enabled, schedule_timezone (nullable),
  last_scheduled_at (nullable),
  pass_rate_threshold (nullable), last_breach_at (nullable),
  active, deleted_at, timestamps

test_runs
  id, project_id, test_suite_id, triggered_by (user_id),
  trigger_source (manual|schedule|webhook|api, nullable),
  storage_disk (local|s3, nullable),
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
  avatar_url (nullable, populated via OAuth/SSO),
  slack_user_id (nullable override),
  timestamps

app_settings
  id, key, value, timestamps

ai_conversations
  id, ulid, user_id (nullable for automated repairs), project_id, test_suite_id (nullable),
  title, messages (JSON), crawl_data (JSON), recording_data (JSON),
  framework, verification_status, verification_output,
  repair_assessment (JSON), total_tokens, provider, model,
  status, timestamps

managed_test_files
  id, test_suite_id, file_path, content, version,
  generated_by (user_id, nullable), timestamps

test_recording_sessions
  id, token, project_id, ai_conversation_id (nullable),
  status, actions (JSON), expires_at, timestamps
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

    # SSE streams — disable buffering and extend read timeout for long-lived connections
    location ~ ^/api/v1/(test-runs/[0-9]+/stream|events/stream) {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        include fastcgi_params;
        fastcgi_buffering    off;
        fastcgi_read_timeout 600s;
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

[program:cypress-default-queue]
command=php /var/www/cypress-dashboard/artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=60
directory=/var/www/cypress-dashboard
user=cypressapp
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/cypress-default-queue.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
sudo supervisorctl status
```

> **Note:** `--timeout=3600` on the cypress queue worker is important. Test runs can take up to an hour and the default 60-second timeout will kill jobs mid-run. `--queue=cypress` is required — both Cypress and Playwright jobs dispatch to this queue. The `default` queue handles email notifications, Slack alerts, and health breach checks — it must also be running for these to process.

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

#### AI Test Builder: Chromium for the page crawler

The page crawler launches Chromium through the app's own `playwright` npm package. `deploy.sh` downloads the matching Chromium build as the app user on every deploy (a no-op once it's cached), so no manual step is needed.

Chromium also needs system libraries, which require root. The install scripts already set these up. On a server that wasn't set up with them, run this once from the app directory:

```bash
sudo npx playwright install-deps chromium
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
git commit -m "Initial commit: SignalDeck CI"

# Add your remote
git remote add origin git@github.com:your-org/signaldeck-ci-server.git

# Push
git push -u origin main
```

### What is excluded by .gitignore

The default Laravel `.gitignore` already excludes everything sensitive:

| Excluded            | Why                                   |
| ------------------- | ------------------------------------- |
| `.env`              | Contains secrets — **never commit**   |
| `/vendor/`          | Restored via `composer install`       |
| `/node_modules/`    | Restored via `npm install`            |
| `/storage/app/`     | Runtime data                          |
| `/storage/logs/`    | Runtime logs                          |
| `/bootstrap/cache/` | Generated on deploy                   |
| `/public/build/`    | Generated by Vite                     |
| `/public/storage`   | Symlink, recreated via `storage:link` |

### Secrets never to commit

- `APP_KEY`
- `DB_PASSWORD`
- `REDIS_PASSWORD`
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` (if using S3)
- `GOOGLE_CLIENT_SECRET`, `GITHUB_CLIENT_SECRET` (if using SSO)

---

## Troubleshooting

**Report URLs are broken / wrong domain in links**
`APP_URL` in `.env` is wrong or the queue worker started with a stale value. Update `APP_URL` and restart the worker: `php artisan queue:restart`.

**Share link returns 403 immediately**
The link was generated before the most recent `APP_KEY` change (changing the key invalidates all HMAC tokens), or the link is over 30 days old. Generate a new link from the run view.

**Live log not updating in the run view**
Verify the queue worker is running (log output is only written during job execution). In production, confirm the Nginx SSE location block for `/api/v1/test-runs/{id}/stream` has `fastcgi_buffering off` and `fastcgi_read_timeout 600s` — without these, Nginx will buffer the stream and no events will reach the browser.

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
