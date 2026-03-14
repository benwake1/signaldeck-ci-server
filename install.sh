#!/usr/bin/env bash
# =============================================================================
# Cypress Dashboard — Production Install Script
# Ubuntu 24.04 LTS + Nginx + MySQL + Supervisor + Cloudflare
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

# -----------------------------------------------------------------------------
# Must run as root
# -----------------------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
    error "This script must be run as root. Try: sudo bash install.sh"
fi

# -----------------------------------------------------------------------------
# Gather inputs up front
# -----------------------------------------------------------------------------
header "Cypress Dashboard Installer"

echo -e "${BOLD}This script will install and configure Cypress Dashboard on this server.${NC}"
echo -e "It is safe to re-run if a previous attempt failed.\n"
echo -e "You will need your ${BOLD}domain name${NC} and a ${BOLD}database password${NC} ready.\n"

read -rp "$(echo -e "${BOLD}Domain name${NC} (e.g. dashboard.yourdomain.com): ")" DOMAIN
[[ -z "$DOMAIN" ]] && error "Domain name is required."

read -rp "$(echo -e "${BOLD}Repository URL${NC} (GitHub HTTPS URL): ")" REPO_URL
[[ -z "$REPO_URL" ]] && error "Repository URL is required."

read -rsp "$(echo -e "${BOLD}MySQL password${NC} for the app user (leave blank to generate): ")" DB_PASSWORD
echo
if [[ -z "$DB_PASSWORD" ]]; then
    DB_PASSWORD=$(openssl rand -base64 24 | tr -d '/+=')
    warning "Generated MySQL password: ${BOLD}${DB_PASSWORD}${NC} — save this now!"
fi

DB_NAME="cypress_dashboard"
DB_USER="cypressapp"
APP_DIR="/var/www/cypress-dashboard"
APP_USER="cypressapp"

# On re-run, use the DB password already saved in .env to stay in sync
if [[ -f "${APP_DIR}/.env" ]] && grep -q "^APP_KEY=base64:" "${APP_DIR}/.env" 2>/dev/null; then
    SAVED_DB_PASSWORD=$(grep "^DB_PASSWORD=" "${APP_DIR}/.env" 2>/dev/null | cut -d'=' -f2-)
    if [[ -n "${SAVED_DB_PASSWORD}" ]]; then
        DB_PASSWORD="${SAVED_DB_PASSWORD}"
        echo -e "${YELLOW}↷ Existing .env found — using saved DB password.${NC}"
    fi
fi

echo ""
echo -e "${BOLD}Summary:${NC}"
echo -e "  Domain:   ${DOMAIN}"
echo -e "  Repo:     ${REPO_URL}"
echo -e "  App dir:  ${APP_DIR}"
echo -e "  DB:       ${DB_NAME} / ${DB_USER}"
echo ""
read -rp "$(echo -e "${BOLD}Proceed?${NC} [y/N] ")" CONFIRM
[[ "$CONFIRM" != "y" && "$CONFIRM" != "Y" ]] && error "Aborted."

# -----------------------------------------------------------------------------
# Step 1 — System packages
# -----------------------------------------------------------------------------
header "Step 1 — System packages"

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a                    # suppress "restart services?" prompts
mkdir -p /etc/needrestart/conf.d
echo "\$nrconf{restart} = 'a';" > /etc/needrestart/conf.d/99-installer.conf

info "Updating package lists..."
apt-get update -qq || true   # mirror sync errors are non-fatal

info "Installing base dependencies..."
apt-get install -y -qq software-properties-common curl git unzip

info "Adding PHP 8.4 PPA (ondrej/php)..."
add-apt-repository -y ppa:ondrej/php > /dev/null 2>&1
apt-get update -qq || true   # mirror sync errors are non-fatal

info "Installing PHP 8.4..."
apt-get install -y -qq \
    php8.4 php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring \
    php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-common php8.4-intl

info "Installing Nginx, MySQL, Supervisor..."
apt-get install -y -qq nginx mysql-server supervisor

if node --version 2>/dev/null | grep -q "^v20\."; then
    skip "Node.js 20 already installed ($(node --version))"
else
    info "Installing Node.js 20 LTS..."
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_20.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list
    apt-get update -qq
    apt-get install -y -qq nodejs
fi

if command -v composer &>/dev/null; then
    skip "Composer already installed ($(composer --version --no-ansi 2>/dev/null | head -1))"
else
    info "Installing Composer..."
    curl -sS https://getcomposer.org/installer | php -- --quiet < /dev/null
    mv composer.phar /usr/local/bin/composer
    chmod +x /usr/local/bin/composer
    success "Composer installed."
fi

success "System packages installed."

# -----------------------------------------------------------------------------
# Step 2 — Cypress headless dependencies
# -----------------------------------------------------------------------------
header "Step 2 — Cypress headless dependencies"

apt-get install -y -qq \
    xvfb libgtk-3-0t64 libnotify-dev \
    libnss3 libxss1 libasound2t64 libxtst6 xauth libgbm-dev

success "Cypress headless dependencies installed."

# -----------------------------------------------------------------------------
# Step 3 — MySQL database
# -----------------------------------------------------------------------------
header "Step 3 — MySQL database"

info "Creating database and user..."
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
    success "User '${APP_USER}' created."
fi

# -----------------------------------------------------------------------------
# Step 5 — Clone and install
# -----------------------------------------------------------------------------
header "Step 5 — Clone and install"

if [[ -f "${APP_DIR}/composer.json" ]]; then
    info "Repository already cloned — pulling latest..."
    sudo -u "${APP_USER}" git -C "${APP_DIR}" pull --ff-only
else
    info "Cloning repository..."
    rm -rf "${APP_DIR}"
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
# Sticky group bit — new subdirs created by queue worker inherit www-data group automatically
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

    # Generate Reverb credentials
    REVERB_APP_ID=$(openssl rand -hex 8)
    REVERB_APP_KEY=$(openssl rand -hex 16)
    REVERB_APP_SECRET=$(openssl rand -hex 16)

    info "Writing production .env values..."
    sudo -u "${APP_USER}" bash -c "cat > ${APP_DIR}/.env" <<EOF
APP_NAME="Cypress Dashboard"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://${DOMAIN}
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
SESSION_DRIVER=database
SESSION_LIFETIME=120

BROADCAST_CONNECTION=reverb

REVERB_APP_ID=${REVERB_APP_ID}
REVERB_APP_KEY=${REVERB_APP_KEY}
REVERB_APP_SECRET=${REVERB_APP_SECRET}
REVERB_HOST=${DOMAIN}
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_APP_KEY=${REVERB_APP_KEY}
VITE_REVERB_HOST=${DOMAIN}
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

FILESYSTEM_DISK=local

# Google OAuth (optional — fill in to enable Google SSO)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://${DOMAIN}/admin/oauth/callback/google

# Branding (optional — defaults used if not set)
BRAND_NAME=
BRAND_PRIMARY_COLOR=
BRAND_LOGO_PATH=
BRAND_LOGO_HEIGHT=2rem
BRAND_FAVICON_PATH=
COMPANY_LEGAL_NAME=

# Mail
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="Cypress Dashboard"

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
# Step 8 — Nginx
# -----------------------------------------------------------------------------
header "Step 8 — Nginx"

info "Writing Nginx config..."
cat > /etc/nginx/sites-available/cypress-dashboard <<NGINX
server {
    listen 443 ssl http2;
    server_name ${DOMAIN};

    ssl_certificate     /etc/ssl/cloudflare/origin.pem;
    ssl_certificate_key /etc/ssl/cloudflare/origin.key;

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
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_cache_bypass \$http_upgrade;
        proxy_read_timeout 60s;
    }

    location ~ /\.(?!well-known).* { deny all; }
}

server {
    listen 80;
    server_name ${DOMAIN};
    return 301 https://\$host\$request_uri;
}
NGINX

ln -sf /etc/nginx/sites-available/cypress-dashboard /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

if [[ -f /etc/ssl/cloudflare/origin.pem && -f /etc/ssl/cloudflare/origin.key ]]; then
    nginx -t && systemctl reload nginx
    success "Nginx configured and reloaded."
else
    warning "Nginx config written but NOT loaded — SSL certificates missing."
    warning "Add your Cloudflare certs, then run: nginx -t && systemctl reload nginx"
fi

# -----------------------------------------------------------------------------
# Step 9 — Supervisor
# -----------------------------------------------------------------------------
header "Step 9 — Supervisor"

info "Writing Supervisor config..."
cat > /etc/supervisor/conf.d/cypress-dashboard.conf <<SUPERVISOR
[program:cypress-queue]
command=php ${APP_DIR}/artisan queue:work --sleep=3 --tries=1 --timeout=14400
directory=${APP_DIR}
user=${APP_USER}
umask=002
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/cypress-queue.log

[program:cypress-reverb]
command=php ${APP_DIR}/artisan reverb:start --host=127.0.0.1 --port=8080
directory=${APP_DIR}
user=${APP_USER}
umask=002
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/cypress-reverb.log
SUPERVISOR

supervisorctl reread
supervisorctl update

success "Supervisor configured."

# -----------------------------------------------------------------------------
# Step 10 — Cron
# -----------------------------------------------------------------------------
header "Step 10 — Cron"

CRON_LINE="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
(crontab -u "${APP_USER}" -l 2>/dev/null | grep -v "artisan schedule:run"; echo "${CRON_LINE}") \
    | crontab -u "${APP_USER}" -

success "Cron registered."

# -----------------------------------------------------------------------------
# Done — manual steps remaining
# -----------------------------------------------------------------------------
header "Installation complete!"

echo -e "${GREEN}${BOLD}✔ Cypress Dashboard is installed.${NC}\n"
echo -e "${BOLD}Manual steps still required:${NC}\n"

echo -e "${YELLOW}1. Cloudflare Origin Certificate${NC}"
echo -e "   In Cloudflare: SSL/TLS → Origin Server → Create Certificate"
echo -e "   Then on this server:"
echo -e "   ${BOLD}mkdir -p /etc/ssl/cloudflare${NC}"
echo -e "   ${BOLD}nano /etc/ssl/cloudflare/origin.pem${NC}  # paste certificate"
echo -e "   ${BOLD}nano /etc/ssl/cloudflare/origin.key${NC}  # paste private key"
echo -e "   ${BOLD}chmod 600 /etc/ssl/cloudflare/origin.key${NC}"
echo -e "   Then: ${BOLD}nginx -t && systemctl reload nginx${NC}"
echo -e "   Then in Cloudflare: SSL/TLS → Full (strict), Network → WebSockets ON\n"

echo -e "${YELLOW}2. Cloudflare DNS${NC}"
echo -e "   Add an A record: ${BOLD}${DOMAIN} → $(curl -s ifconfig.me 2>/dev/null || echo 'YOUR_SERVER_IP')${NC} (proxied)\n"

echo -e "${YELLOW}3. Start Supervisor workers${NC}"
echo -e "   ${BOLD}supervisorctl start cypress-queue cypress-reverb${NC}"
echo -e "   ${BOLD}supervisorctl status${NC}\n"

echo -e "${YELLOW}4. Create your admin user${NC}"
echo -e "   ${BOLD}sudo -u ${APP_USER} php ${APP_DIR}/artisan make:filament-user${NC}\n"

echo -e "${YELLOW}5. (Optional) Google OAuth${NC}"
echo -e "   Edit ${APP_DIR}/.env and set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET"
echo -e "   Add redirect URI in Google Cloud Console: https://${DOMAIN}/admin/oauth/callback/google\n"

echo -e "${BOLD}Saved credentials:${NC}"
echo -e "  DB password: ${BOLD}${DB_PASSWORD}${NC}"
echo -e "  App URL:     ${BOLD}https://${DOMAIN}${NC}"
echo -e "  Admin panel: ${BOLD}https://${DOMAIN}/admin${NC}\n"
