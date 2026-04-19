#!/usr/bin/env bash
# =============================================================================
# SignalDeck CI — Install on Existing LEMP Stack
# For servers that already have Nginx, MySQL, PHP and Node running.
# Adds the app alongside your existing sites without touching their config.
# All names, paths and ports are configurable — safe to run multiple instances.
# Safe to re-run at any point — all steps are idempotent.
# =============================================================================
set -e

# -----------------------------------------------------------------------------
# Colours
# -----------------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

info()    { echo -e "${BLUE}▶ $1${NC}"; }
success() { echo -e "${GREEN}✔ $1${NC}"; }
warning() { echo -e "${YELLOW}⚠ $1${NC}"; }
error()   { echo -e "${RED}✖ $1${NC}"; exit 1; }
skip()    { echo -e "${YELLOW}↷ $1 — skipping.${NC}"; }
header()  { echo -e "\n${BOLD}${BLUE}══════════════════════════════════════${NC}"; echo -e "${BOLD} $1${NC}"; echo -e "${BOLD}${BLUE}══════════════════════════════════════${NC}\n"; }

# Helper: prompt with a default value
prompt() {
    local var_name="$1" prompt_text="$2" default="$3"
    local input
    if [[ -n "$default" ]]; then
        read -rp "$(echo -e "${BOLD}${prompt_text}${NC} [${default}]: ")" input
        eval "${var_name}=\"${input:-$default}\""
    else
        read -rp "$(echo -e "${BOLD}${prompt_text}${NC}: ")" input
        eval "${var_name}=\"${input}\""
    fi
}

# -----------------------------------------------------------------------------
# Must run as root
# -----------------------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
    error "This script must be run as root. Try: sudo bash install-existing-lemp.sh"
fi

# -----------------------------------------------------------------------------
# Step 1 — Validate existing stack
# -----------------------------------------------------------------------------
header "Step 1 — Validating existing LEMP stack"

ERRORS=0

# PHP 8.4
if command -v php &>/dev/null; then
    PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
    if [[ "$PHP_VERSION" == "8.4" ]]; then
        success "PHP ${PHP_VERSION} found"
    else
        echo -e "${RED}✖ PHP ${PHP_VERSION} found — PHP 8.4 is required.${NC}"
        ERRORS=$((ERRORS + 1))
    fi
else
    echo -e "${RED}✖ PHP not found${NC}"
    ERRORS=$((ERRORS + 1))
fi

# PHP-FPM
if [[ -S /var/run/php/php8.4-fpm.sock ]] || systemctl is-active --quiet php8.4-fpm 2>/dev/null; then
    success "PHP-FPM 8.4 running"
else
    echo -e "${RED}✖ PHP-FPM 8.4 not running — install php8.4-fpm and start the service.${NC}"
    ERRORS=$((ERRORS + 1))
fi

# Required PHP extensions
REQUIRED_EXTS="pdo_mysql mbstring xml curl zip bcmath intl"
MISSING_EXTS=0
for ext in $REQUIRED_EXTS; do
    if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
        # pdo_mysql is provided by php8.4-mysql, not php8.4-pdo_mysql
        local pkg="php8.4-${ext}"
        [[ "$ext" == "pdo_mysql" ]] && pkg="php8.4-mysql"
        echo -e "${RED}✖ PHP extension '${ext}' missing — install ${pkg}${NC}"
        ERRORS=$((ERRORS + 1))
        MISSING_EXTS=$((MISSING_EXTS + 1))
    fi
done
if [[ $MISSING_EXTS -eq 0 ]]; then
    success "All required PHP extensions present"
fi

# Nginx
if command -v nginx &>/dev/null && systemctl is-active --quiet nginx; then
    success "Nginx running ($(nginx -v 2>&1 | cut -d/ -f2))"
else
    echo -e "${RED}✖ Nginx not found or not running${NC}"
    ERRORS=$((ERRORS + 1))
fi

# MySQL CLI client (needed to create the database)
if command -v mysql &>/dev/null; then
    success "MySQL CLI client available"
else
    echo -e "${RED}✖ MySQL CLI client not found — install mysql-client${NC}"
    ERRORS=$((ERRORS + 1))
fi

# Node.js 20+
if command -v node &>/dev/null; then
    NODE_MAJOR=$(node -v | cut -d. -f1 | tr -d 'v')
    if [[ "$NODE_MAJOR" -ge 20 ]]; then
        success "Node.js $(node -v) found"
    else
        echo -e "${RED}✖ Node.js $(node -v) found — v20+ required${NC}"
        ERRORS=$((ERRORS + 1))
    fi
else
    echo -e "${RED}✖ Node.js not found${NC}"
    ERRORS=$((ERRORS + 1))
fi

# Composer
if command -v composer &>/dev/null; then
    success "Composer found"
else
    echo -e "${RED}✖ Composer not found — install from https://getcomposer.org${NC}"
    ERRORS=$((ERRORS + 1))
fi

# Supervisor
if command -v supervisorctl &>/dev/null; then
    success "Supervisor found"
else
    warning "Supervisor not installed — will install it now."
    apt-get update -qq
    apt-get install -y -qq supervisor
    success "Supervisor installed."
fi

# Bail if prerequisites are missing
if [[ $ERRORS -gt 0 ]]; then
    echo ""
    error "Found ${ERRORS} missing prerequisite(s) above. Fix them and re-run this script."
fi

success "All prerequisites met."

# -----------------------------------------------------------------------------
# Gather inputs — everything is configurable
# -----------------------------------------------------------------------------
header "Configuration"

echo -e "${BOLD}This will add SignalDeck CI alongside your existing sites.${NC}"
echo -e "No existing Nginx configs, databases, or users will be modified."
echo -e "Press Enter to accept the default shown in [brackets].\n"

# ── Core ──
prompt DOMAIN     "Domain name (e.g. dashboard.yourdomain.com)" ""
[[ -z "$DOMAIN" ]] && error "Domain name is required."

prompt REPO_URL   "Repository URL (GitHub HTTPS URL)" ""
[[ -z "$REPO_URL" ]] && error "Repository URL is required."

# ── Instance name — drives all other defaults ──
echo ""
echo -e "${BOLD}Instance name${NC} — used to derive folder, database, user and service names."
echo -e "Use a short slug with no spaces. Examples: ${BOLD}cypress-dashboard${NC}, ${BOLD}cypress-demo${NC}, ${BOLD}client-qa${NC}"
prompt INSTANCE_NAME "Instance name" "cypress-dashboard"

# Derive defaults from instance name
INSTANCE_SLUG=$(echo "$INSTANCE_NAME" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/_/g')

echo ""
echo -e "${BOLD}Paths & names${NC} (derived from instance name — change any if needed):"
prompt APP_DIR    "Install directory"           "/var/www/${INSTANCE_NAME}"
prompt APP_USER   "System user"                 "${INSTANCE_SLUG}app"
prompt DB_NAME    "MySQL database name"         "${INSTANCE_SLUG}"
prompt DB_USER    "MySQL username"              "${INSTANCE_SLUG}app"

read -rsp "$(echo -e "${BOLD}MySQL password${NC} for '${DB_USER}' (leave blank to generate): ")" DB_PASSWORD
echo
if [[ -z "$DB_PASSWORD" ]]; then
    DB_PASSWORD=$(openssl rand -base64 24 | tr -d '/+=')
    warning "Generated MySQL password: ${BOLD}${DB_PASSWORD}${NC} — save this now!"
fi

# ── Supervisor program prefix — unique per instance ──
prompt SUPERVISOR_PREFIX "Supervisor program prefix" "${INSTANCE_SLUG}"

# ── Nginx site name ──
prompt NGINX_SITE_NAME "Nginx site config name" "${INSTANCE_NAME}"

# ── SSL ──
echo ""
echo -e "${BOLD}SSL configuration:${NC}"
echo -e "  1) Cloudflare Origin Certificate (recommended)"
echo -e "  2) Let's Encrypt (certbot)"
echo -e "  3) Existing certificate (I'll provide the paths)"
echo -e "  4) No SSL for now (HTTP only — not recommended)"
read -rp "$(echo -e "${BOLD}Choose [1-4]:${NC} ")" SSL_CHOICE
SSL_CHOICE=${SSL_CHOICE:-1}

case $SSL_CHOICE in
    2)
        if ! command -v certbot &>/dev/null; then
            warning "certbot not found — installing..."
            apt-get install -y -qq certbot python3-certbot-nginx
        fi
        ;;
    3)
        read -rp "$(echo -e "${BOLD}Path to SSL certificate:${NC} ")" SSL_CERT_PATH
        read -rp "$(echo -e "${BOLD}Path to SSL private key:${NC} ")" SSL_KEY_PATH
        [[ ! -f "$SSL_CERT_PATH" ]] && error "Certificate not found: ${SSL_CERT_PATH}"
        [[ ! -f "$SSL_KEY_PATH" ]] && error "Key not found: ${SSL_KEY_PATH}"
        ;;
esac

# On re-run, use the DB password already saved in .env to stay in sync
if [[ -f "${APP_DIR}/.env" ]] && grep -q "^APP_KEY=base64:" "${APP_DIR}/.env" 2>/dev/null; then
    SAVED_DB_PASSWORD=$(grep "^DB_PASSWORD=" "${APP_DIR}/.env" 2>/dev/null | cut -d'=' -f2-)
    if [[ -n "${SAVED_DB_PASSWORD}" ]]; then
        DB_PASSWORD="${SAVED_DB_PASSWORD}"
        skip "Existing .env found — using saved DB password"
    fi
fi

# Determine scheme
if [[ "$SSL_CHOICE" == "4" ]]; then
    APP_SCHEME="http"
else
    APP_SCHEME="https"
fi

# ── Confirmation ──
echo ""
echo -e "${BOLD}═══ Summary ═══${NC}"
echo -e "  Instance:     ${BOLD}${INSTANCE_NAME}${NC}"
echo -e "  Domain:       ${DOMAIN}"
echo -e "  Repo:         ${REPO_URL}"
echo -e "  App dir:      ${APP_DIR}"
echo -e "  System user:  ${APP_USER}"
echo -e "  DB:           ${DB_NAME} / ${DB_USER}"
echo -e "  Supervisor:   ${SUPERVISOR_PREFIX}-queue, ${SUPERVISOR_PREFIX}-notifications"
echo -e "  Nginx site:   ${NGINX_SITE_NAME}"
echo -e "  SSL:          $(case $SSL_CHOICE in 1) echo "Cloudflare";; 2) echo "Let's Encrypt";; 3) echo "Custom cert";; 4) echo "None (HTTP)";; esac)"
echo ""
read -rp "$(echo -e "${BOLD}Proceed?${NC} [y/N] ")" CONFIRM
[[ "$CONFIRM" != "y" && "$CONFIRM" != "Y" ]] && error "Aborted."

# -----------------------------------------------------------------------------
# Step 2 — Cypress headless dependencies
# -----------------------------------------------------------------------------
header "Step 2 — Browser headless dependencies"

info "Installing headless browser dependencies..."
apt-get install -y -qq \
    xvfb libgtk-3-0t64 libnotify-dev \
    libnss3 libxss1 libasound2t64 libxtst6 xauth libgbm-dev

info "Installing Playwright system dependencies..."
npx playwright install-deps 2>&1 || true

if ! command -v google-chrome-stable &>/dev/null && ! command -v google-chrome &>/dev/null; then
    info "Installing Google Chrome (Cypress cannot use snap-confined Chromium)..."
    curl -fsSL https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb -o /tmp/google-chrome.deb
    apt-get install -y -qq /tmp/google-chrome.deb || apt-get install -f -y -qq
    rm -f /tmp/google-chrome.deb
else
    skip "Google Chrome already installed"
fi

info "Creating Chrome wrapper with server-optimised flags..."
cat > /usr/local/bin/chrome-cypress <<'EOF'
#!/usr/bin/env bash
exec /usr/bin/google-chrome-stable \
    --disable-gpu \
    --no-sandbox \
    --disable-dev-shm-usage \
    "$@"
EOF
chmod +x /usr/local/bin/chrome-cypress

success "Browser headless dependencies installed."

# -----------------------------------------------------------------------------
# Step 3 — MySQL database (additive — no existing DBs touched)
# -----------------------------------------------------------------------------
header "Step 3 — MySQL database"

info "Creating database '${DB_NAME}' and user '${DB_USER}' (existing databases are not affected)..."
mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

success "Database '${DB_NAME}' and user '${DB_USER}' ready."

# -----------------------------------------------------------------------------
# Step 4 — App user
# -----------------------------------------------------------------------------
header "Step 4 — Application user"

if id "${APP_USER}" &>/dev/null; then
    skip "User '${APP_USER}' already exists"
else
    info "Creating system user '${APP_USER}'..."
    useradd -m -s /bin/bash "${APP_USER}"
    usermod -aG www-data "${APP_USER}"
    success "User '${APP_USER}' created and added to www-data group."
fi

# ── Deploy user ──
# Create a dedicated 'deployer' user (if not already present) with tightly
# scoped sudo: it can only run git and the deploy script as app users.
# Set DEPLOY_USER=deployer in your CI secrets.
if ! id "deployer" &>/dev/null; then
    info "Creating 'deployer' system user for CI/CD..."
    useradd -m -s /bin/bash deployer
    success "User 'deployer' created."
else
    skip "User 'deployer' already exists"
fi

SUDOERS_FILE="/etc/sudoers.d/deployer-${APP_USER}"
info "Writing scoped sudoers rule (deployer → ${APP_USER})..."
cat > "${SUDOERS_FILE}" <<SUDOERS
# Allow deployer to run only git and the deploy script as ${APP_USER}
deployer ALL=(${APP_USER}) NOPASSWD: /usr/bin/git, /usr/bin/bash ${APP_DIR}/deploy.sh
SUDOERS
chmod 440 "${SUDOERS_FILE}"
success "Sudoers rule: deployer can run git + deploy.sh as '${APP_USER}'"

# -----------------------------------------------------------------------------
# Step 5 — Clone and install
# -----------------------------------------------------------------------------
header "Step 5 — Clone and install"

if [[ -f "${APP_DIR}/composer.json" ]]; then
    info "Repository already cloned — pulling latest..."
    sudo -u "${APP_USER}" git -C "${APP_DIR}" pull --ff-only
else
    info "Cloning repository into ${APP_DIR}..."
    rm -rf "${APP_DIR}"
    mkdir -p "$(dirname "${APP_DIR}")"
    mkdir -p "${APP_DIR}"
    chown "${APP_USER}:${APP_USER}" "${APP_DIR}"
    sudo -u "${APP_USER}" git clone "${REPO_URL}" "${APP_DIR}"
fi

info "Ensuring writable directories exist..."
mkdir -p "${APP_DIR}/bootstrap/cache"
mkdir -p "${APP_DIR}/storage/logs"
mkdir -p "${APP_DIR}/storage/framework/cache/data"
mkdir -p "${APP_DIR}/storage/framework/sessions"
mkdir -p "${APP_DIR}/storage/framework/views"
mkdir -p "${APP_DIR}/storage/app/private/reports"
chown -R "${APP_USER}:www-data" "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
chmod -R 775 "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
chmod g+s "${APP_DIR}/storage/app/private"
chmod g+s "${APP_DIR}/storage/app/private/reports"

info "Installing PHP dependencies..."
sudo -u "${APP_USER}" COMPOSER_NO_INTERACTION=1 composer install \
    --no-dev --optimize-autoloader -q --no-interaction --ignore-platform-reqs --no-scripts \
    --working-dir="${APP_DIR}"

info "Installing Node dependencies and building assets..."
sudo -u "${APP_USER}" bash -c "cd ${APP_DIR} && npm ci && npm run build"

success "Dependencies installed and assets built."

# -----------------------------------------------------------------------------
# Step 6 — Environment file
# -----------------------------------------------------------------------------
header "Step 6 — Environment file"

if [[ -f "${APP_DIR}/.env" ]] && grep -q "^APP_KEY=base64:" "${APP_DIR}/.env" 2>/dev/null; then
    skip ".env already configured with APP_KEY"
else
    if [[ ! -f "${APP_DIR}/.env" ]]; then
        info "Creating .env from .env.example..."
        sudo -u "${APP_USER}" cp "${APP_DIR}/.env.example" "${APP_DIR}/.env"
    fi

    info "Writing production .env values..."
    sudo -u "${APP_USER}" bash -c "cat > ${APP_DIR}/.env" <<EOF
APP_NAME="SignalDeck CI"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=${APP_SCHEME}://${DOMAIN}
APP_VERSION=dev

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}

CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=14400
SESSION_DRIVER=database
SESSION_LIFETIME=120

BROADCAST_CONNECTION=log

FILESYSTEM_DISK=local

# Branding (optional — defaults used if not set)
BRAND_NAME=
BRAND_PRIMARY_COLOR=
BRAND_LOGO_PATH=
BRAND_LOGO_HEIGHT=2rem
BRAND_FAVICON_PATH=
COMPANY_LEGAL_NAME=

# Mail (can also be configured via Settings → Mail in the admin panel)
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="SignalDeck CI"

# Node binary paths — update if different on this server
NODE_PATH=$(which node)
NPM_PATH=$(which npm)
EOF

    info "Generating application key..."
    sudo -u "${APP_USER}" php "${APP_DIR}/artisan" key:generate --force

    success ".env configured."
fi

# -----------------------------------------------------------------------------
# Step 7 — Artisan setup
# -----------------------------------------------------------------------------
header "Step 7 — Database and assets"

info "Discovering packages..."
sudo -u "${APP_USER}" php "${APP_DIR}/artisan" package:discover --ansi

info "Running migrations..."
sudo -u "${APP_USER}" php "${APP_DIR}/artisan" migrate --force

if [[ -L "${APP_DIR}/public/storage" ]]; then
    skip "Storage symlink already exists"
else
    info "Creating storage symlink..."
    sudo -u "${APP_USER}" php "${APP_DIR}/artisan" storage:link
fi

info "Publishing Filament assets..."
sudo -u "${APP_USER}" php "${APP_DIR}/artisan" filament:assets

info "Caching config, routes, and views..."
sudo -u "${APP_USER}" php "${APP_DIR}/artisan" config:cache
sudo -u "${APP_USER}" php "${APP_DIR}/artisan" route:cache
sudo -u "${APP_USER}" php "${APP_DIR}/artisan" view:cache

success "Database migrated and assets ready."

# -----------------------------------------------------------------------------
# Step 8 — Nginx (additive — existing sites not touched)
# -----------------------------------------------------------------------------
header "Step 8 — Nginx vhost"

# Detect PHP-FPM socket path
FPM_SOCK=$(find /var/run/php/ -name "php8.4-fpm*.sock" -print -quit 2>/dev/null || echo "/var/run/php/php8.4-fpm.sock")
info "Using PHP-FPM socket: ${FPM_SOCK}"

# Check for existing nginx config with same name
if [[ -f "/etc/nginx/sites-available/${NGINX_SITE_NAME}" ]]; then
    warning "Nginx config '${NGINX_SITE_NAME}' already exists — it will be overwritten."
fi

# Build SSL block based on user's choice
case $SSL_CHOICE in
    1)
        SSL_BLOCK="    ssl_certificate     /etc/ssl/cloudflare/origin.pem;
    ssl_certificate_key /etc/ssl/cloudflare/origin.key;"
        LISTEN_DIRECTIVE="listen 443 ssl http2;"
        ;;
    2)
        SSL_BLOCK=""
        LISTEN_DIRECTIVE="listen 80;"
        ;;
    3)
        SSL_BLOCK="    ssl_certificate     ${SSL_CERT_PATH};
    ssl_certificate_key ${SSL_KEY_PATH};"
        LISTEN_DIRECTIVE="listen 443 ssl http2;"
        ;;
    4)
        SSL_BLOCK=""
        LISTEN_DIRECTIVE="listen 80;"
        ;;
esac

info "Writing Nginx vhost '${NGINX_SITE_NAME}' (existing sites are not affected)..."
cat > "/etc/nginx/sites-available/${NGINX_SITE_NAME}" <<NGINX
server {
    ${LISTEN_DIRECTIVE}
    server_name ${DOMAIN};

${SSL_BLOCK}

    root ${APP_DIR}/public;
    index index.php;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php\$ {
        fastcgi_pass unix:${FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    # SSE streams — disable Nginx buffering so events reach the client immediately.
    # fastcgi_read_timeout must exceed the stream's MAX_STREAM_SECONDS (14400 s).
    location ~ ^/api/v1/(test-runs/[0-9]+/stream|events/stream)\$ {
        fastcgi_pass ${FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$realpath_root/index.php;
        include fastcgi_params;
        fastcgi_buffering off;
        fastcgi_read_timeout 14460s;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
NGINX

# Add HTTPS redirect block if using SSL (not for HTTP-only or certbot)
if [[ "$SSL_CHOICE" == "1" || "$SSL_CHOICE" == "3" ]]; then
    cat >> "/etc/nginx/sites-available/${NGINX_SITE_NAME}" <<NGINX

server {
    listen 80;
    server_name ${DOMAIN};
    return 301 https://\$host\$request_uri;
}
NGINX
fi

# Enable the site (leave all other sites untouched)
ln -sf "/etc/nginx/sites-available/${NGINX_SITE_NAME}" "/etc/nginx/sites-enabled/"

# Test and reload — don't break existing sites if config is bad
if nginx -t 2>/dev/null; then
    systemctl reload nginx
    success "Nginx vhost '${NGINX_SITE_NAME}' enabled and reloaded."
else
    warning "Nginx config test failed — your existing sites are unaffected."
    warning "Fix the issue and run: nginx -t && systemctl reload nginx"
    rm -f "/etc/nginx/sites-enabled/${NGINX_SITE_NAME}"
fi

# Run certbot if chosen
if [[ "$SSL_CHOICE" == "2" ]]; then
    info "Running certbot for Let's Encrypt certificate..."
    certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos --register-unsafely-without-email || {
        warning "certbot failed — you can run it manually later:"
        warning "certbot --nginx -d ${DOMAIN}"
    }
fi

# -----------------------------------------------------------------------------
# Step 9 — Supervisor (additive — existing programs not touched)
# -----------------------------------------------------------------------------
header "Step 9 — Supervisor"

SUPERVISOR_CONF="/etc/supervisor/conf.d/${INSTANCE_NAME}.conf"

info "Writing Supervisor config to ${SUPERVISOR_CONF}..."
cat > "${SUPERVISOR_CONF}" <<SUPERVISOR
[program:${SUPERVISOR_PREFIX}-queue]
command=php ${APP_DIR}/artisan queue:work --queue=cypress --sleep=3 --tries=1 --timeout=14400
directory=${APP_DIR}
user=${APP_USER}
umask=002
numprocs=3
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/${SUPERVISOR_PREFIX}-queue.log

[program:${SUPERVISOR_PREFIX}-notifications]
command=php ${APP_DIR}/artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=60
directory=${APP_DIR}
user=${APP_USER}
umask=002
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/${SUPERVISOR_PREFIX}-notifications.log

SUPERVISOR

supervisorctl reread
supervisorctl update

success "Supervisor programs added (existing programs not affected)."

# -----------------------------------------------------------------------------
# Step 10 — Cron
# -----------------------------------------------------------------------------
header "Step 10 — Cron"

CRON_LINE="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
(crontab -u "${APP_USER}" -l 2>/dev/null | grep -v "artisan schedule:run"; echo "${CRON_LINE}") \
    | crontab -u "${APP_USER}" -

success "Cron registered for user '${APP_USER}'."

# -----------------------------------------------------------------------------
# Done
# -----------------------------------------------------------------------------
header "Installation complete!"

echo -e "${GREEN}${BOLD}✔ SignalDeck CI '${INSTANCE_NAME}' has been added to your server.${NC}\n"
echo -e "${BOLD}Your existing sites, databases, and services have not been modified.${NC}\n"

# Show SSL-specific follow-up steps
case $SSL_CHOICE in
    1)
        echo -e "${YELLOW}SSL — Cloudflare Origin Certificate:${NC}"
        if [[ ! -f /etc/ssl/cloudflare/origin.pem ]]; then
            echo -e "  In Cloudflare: SSL/TLS → Origin Server → Create Certificate"
            echo -e "  Then on this server:"
            echo -e "  ${BOLD}mkdir -p /etc/ssl/cloudflare${NC}"
            echo -e "  ${BOLD}nano /etc/ssl/cloudflare/origin.pem${NC}  # paste certificate"
            echo -e "  ${BOLD}nano /etc/ssl/cloudflare/origin.key${NC}  # paste private key"
            echo -e "  ${BOLD}chmod 600 /etc/ssl/cloudflare/origin.key${NC}"
            echo -e "  Then: ${BOLD}nginx -t && systemctl reload nginx${NC}"
        else
            echo -e "  Cloudflare certs already in place."
        fi
        echo -e "  In Cloudflare: SSL/TLS → Full (strict)\n"
        ;;
    2)
        echo -e "${YELLOW}SSL — Let's Encrypt:${NC}"
        echo -e "  Certificate should have been configured automatically by certbot."
        echo -e "  Verify: ${BOLD}certbot certificates${NC}\n"
        ;;
    3)
        echo -e "${GREEN}SSL — Custom certificate configured.${NC}\n"
        ;;
    4)
        echo -e "${YELLOW}SSL — Not configured. For production, add SSL:${NC}"
        echo -e "  ${BOLD}certbot --nginx -d ${DOMAIN}${NC}\n"
        ;;
esac

echo -e "${YELLOW}DNS:${NC}"
echo -e "  Point ${BOLD}${DOMAIN}${NC} to this server: ${BOLD}$(curl -s ifconfig.me 2>/dev/null || echo 'YOUR_SERVER_IP')${NC}\n"

echo -e "${YELLOW}Verify workers are running:${NC}"
echo -e "  ${BOLD}supervisorctl status${NC}\n"

echo -e "${YELLOW}Create your admin user:${NC}"
echo -e "  ${BOLD}sudo -u ${APP_USER} php ${APP_DIR}/artisan make:admin${NC}\n"

echo -e "${YELLOW}Configure SSO (optional):${NC}"
echo -e "  Visit ${BOLD}${APP_SCHEME}://${DOMAIN}/admin/settings/sso${NC} to enable Google/GitHub SSO.\n"

echo -e "${BOLD}Instance details:${NC}"
echo -e "  Name:        ${INSTANCE_NAME}"
echo -e "  Directory:   ${APP_DIR}"
echo -e "  DB:          ${DB_NAME} / ${DB_USER}"
echo -e "  DB password: ${BOLD}${DB_PASSWORD}${NC}"
echo -e "  Supervisor:  ${SUPERVISOR_CONF}"
echo -e "  Nginx:       /etc/nginx/sites-available/${NGINX_SITE_NAME}"
echo -e "  App URL:     ${BOLD}${APP_SCHEME}://${DOMAIN}${NC}"
echo -e "  Admin panel: ${BOLD}${APP_SCHEME}://${DOMAIN}/admin${NC}\n"

echo -e "${BOLD}To remove this instance later:${NC}"
echo -e "  ${BOLD}supervisorctl stop ${SUPERVISOR_PREFIX}-queue:* ${SUPERVISOR_PREFIX}-notifications:*${NC}"
echo -e "  ${BOLD}rm ${SUPERVISOR_CONF} && supervisorctl reread && supervisorctl update${NC}"
echo -e "  ${BOLD}rm /etc/nginx/sites-enabled/${NGINX_SITE_NAME} /etc/nginx/sites-available/${NGINX_SITE_NAME} && nginx -t && systemctl reload nginx${NC}"
echo -e "  ${BOLD}mysql -u root -e \"DROP DATABASE ${DB_NAME}; DROP USER '${DB_USER}'@'localhost';\"${NC}"
echo -e "  ${BOLD}rm -rf ${APP_DIR}${NC}"
echo -e "  ${BOLD}userdel -r ${APP_USER}${NC}\n"
