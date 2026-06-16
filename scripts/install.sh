#!/usr/bin/env bash
#
# LibreStack Panel — installer for Ubuntu 22.04 / 24.04 and Debian 12.
# Free forever. No license server. No telemetry.
#
# Usage:  sudo bash scripts/install.sh
#
set -euo pipefail

# --------------------------------------------------------------------------
# Configuration
# --------------------------------------------------------------------------
APP_DIR="/opt/librestack"
DATA_DIR="/var/lib/librestack"
LOG_DIR="/var/log/librestack"
BACKUP_DIR="${DATA_DIR}/backups"
PANEL_PORT="8080"
PHP_VERSION_DEFAULT="8.3"
SERVICE_USER="www-data"

# Colours
C_GREEN='\033[0;32m'; C_RED='\033[0;31m'; C_BLUE='\033[0;34m'; C_YELLOW='\033[0;33m'; C_RESET='\033[0m'
log()  { echo -e "${C_BLUE}==>${C_RESET} $*"; }
ok()   { echo -e "${C_GREEN}[ok]${C_RESET} $*"; }
warn() { echo -e "${C_YELLOW}[!]${C_RESET} $*"; }
die()  { echo -e "${C_RED}[x]${C_RESET} $*" >&2; exit 1; }

# --------------------------------------------------------------------------
# Pre-flight checks
# --------------------------------------------------------------------------
[ "$(id -u)" -eq 0 ] || die "This installer must be run as root (use sudo)."

if [ ! -f /etc/os-release ]; then
    die "Cannot detect the operating system (/etc/os-release missing)."
fi
. /etc/os-release

case "${ID}:${VERSION_ID:-}" in
    ubuntu:22.04|ubuntu:24.04|debian:12) ok "Detected supported OS: ${PRETTY_NAME}";;
    *) warn "Unsupported OS '${PRETTY_NAME}'. Continuing best-effort; only Ubuntu 22.04/24.04 and Debian 12 are tested.";;
esac

# Resolve the directory this repo currently lives in.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# --------------------------------------------------------------------------
# Install system packages
# --------------------------------------------------------------------------
log "Updating apt and installing dependencies (this can take a few minutes)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y

PHP="php${PHP_VERSION_DEFAULT}"
PACKAGES=(
    nginx
    "${PHP}-cli" "${PHP}-fpm" "${PHP}-sqlite3" "${PHP}-mysql" "${PHP}-curl"
    "${PHP}-zip" "${PHP}-mbstring" "${PHP}-xml" "${PHP}-bcmath" "${PHP}-common"
    composer nodejs npm
    mariadb-server
    certbot python3-certbot-nginx
    unzip curl git ufw
)

# Fall back to distro default php-* metapackages if the versioned ones are absent.
if ! apt-get install -y "${PACKAGES[@]}"; then
    warn "Versioned PHP packages unavailable; falling back to generic php-* metapackages."
    apt-get install -y nginx php-cli php-fpm php-sqlite3 php-mysql php-curl php-zip \
        php-mbstring php-xml php-bcmath php-common composer nodejs npm mariadb-server \
        certbot python3-certbot-nginx unzip curl git ufw
fi
ok "System packages installed."

# --------------------------------------------------------------------------
# Place the application in /opt/librestack
# --------------------------------------------------------------------------
if [ "${SCRIPT_DIR}" != "${APP_DIR}" ]; then
    log "Copying application to ${APP_DIR}…"
    mkdir -p "${APP_DIR}"
    cp -a "${SCRIPT_DIR}/." "${APP_DIR}/"
fi
cd "${APP_DIR}"

# --------------------------------------------------------------------------
# Directories
# --------------------------------------------------------------------------
log "Creating data directories…"
mkdir -p "${DATA_DIR}" "${LOG_DIR}" "${BACKUP_DIR}"
chown -R "${SERVICE_USER}:${SERVICE_USER}" "${DATA_DIR}" "${LOG_DIR}"

# --------------------------------------------------------------------------
# Environment + dependencies
# --------------------------------------------------------------------------
if [ ! -f .env ]; then
    log "Creating .env from template…"
    cp .env.example .env
    # Enable real system commands on the server.
    sed -i 's/^LIBRESTACK_SYSTEM_ENABLED=.*/LIBRESTACK_SYSTEM_ENABLED=true/' .env
    sed -i "s/^LIBRESTACK_DEFAULT_PHP=.*/LIBRESTACK_DEFAULT_PHP=${PHP_VERSION_DEFAULT}/" .env
    sed -i "s#^APP_URL=.*#APP_URL=http://127.0.0.1:${PANEL_PORT}#" .env
fi

log "Installing PHP dependencies…"
composer install --no-dev --optimize-autoloader --no-interaction

log "Generating application key…"
php artisan key:generate --force

log "Preparing SQLite database…"
touch database/database.sqlite
php artisan migrate --force --seed

log "Building front-end assets…"
npm install --no-audit --no-fund
npm run build

php artisan storage:link || true
php artisan config:cache || true
php artisan route:cache || true

chown -R "${SERVICE_USER}:${SERVICE_USER}" "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" "${APP_DIR}/database"
chmod -R ug+rw "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
ok "Application prepared."

# --------------------------------------------------------------------------
# systemd queue worker
# --------------------------------------------------------------------------
log "Installing systemd queue worker…"
cat > /etc/systemd/system/librestack-queue.service <<EOF
[Unit]
Description=LibreStack Panel queue worker
After=network.target

[Service]
User=${SERVICE_USER}
Group=${SERVICE_USER}
Restart=always
RestartSec=3
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php ${APP_DIR}/artisan queue:work --sleep=3 --tries=3 --timeout=600

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now librestack-queue.service
ok "Queue worker running."

# --------------------------------------------------------------------------
# Laravel scheduler (system cron)
# --------------------------------------------------------------------------
log "Installing scheduler cron entry…"
CRON_LINE="* * * * * cd ${APP_DIR} && /usr/bin/php artisan schedule:run >> ${LOG_DIR}/scheduler.log 2>&1"
( crontab -l 2>/dev/null | grep -v 'artisan schedule:run' ; echo "${CRON_LINE}" ) | crontab -
ok "Scheduler installed."

# --------------------------------------------------------------------------
# Nginx site for the panel
# --------------------------------------------------------------------------
log "Configuring Nginx for the panel on port ${PANEL_PORT}…"
PHP_SOCK="/run/php/php${PHP_VERSION_DEFAULT}-fpm.sock"
[ -S "${PHP_SOCK}" ] || PHP_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1 || echo "${PHP_SOCK}")"

cat > /etc/nginx/sites-available/librestack-panel.conf <<EOF
# Managed by LibreStack Panel. Do not edit manually.
server {
    listen ${PANEL_PORT};
    listen [::]:${PANEL_PORT};
    server_name _;

    root ${APP_DIR}/public;
    index index.php;

    access_log ${LOG_DIR}/panel-access.log;
    error_log ${LOG_DIR}/panel-error.log;

    client_max_body_size 128M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

ln -sf /etc/nginx/sites-available/librestack-panel.conf /etc/nginx/sites-enabled/librestack-panel.conf

if nginx -t; then
    systemctl reload nginx
    ok "Nginx configured and reloaded."
else
    die "Nginx config test failed. Review /etc/nginx/sites-available/librestack-panel.conf"
fi

# --------------------------------------------------------------------------
# Firewall (do not lock out SSH)
# --------------------------------------------------------------------------
log "Configuring UFW (allowing SSH, HTTP, HTTPS and the panel port)…"
ufw allow 22/tcp   >/dev/null 2>&1 || true
ufw allow 80/tcp   >/dev/null 2>&1 || true
ufw allow 443/tcp  >/dev/null 2>&1 || true
ufw allow ${PANEL_PORT}/tcp >/dev/null 2>&1 || true
ok "Firewall rules added (UFW not force-enabled; enable it from the panel when ready)."

# --------------------------------------------------------------------------
# Done
# --------------------------------------------------------------------------
SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
echo
echo -e "${C_GREEN}========================================================${C_RESET}"
echo -e "${C_GREEN} LibreStack Panel installed successfully${C_RESET}"
echo -e "${C_GREEN}========================================================${C_RESET}"
echo
echo -e "  Open the setup wizard to create your first admin account:"
echo -e "    ${C_BLUE}http://${SERVER_IP:-SERVER_IP}:${PANEL_PORT}/setup${C_RESET}"
echo
echo -e "  Panel URL after setup:  http://${SERVER_IP:-SERVER_IP}:${PANEL_PORT}"
echo -e "  App directory:          ${APP_DIR}"
echo -e "  Logs:                   ${LOG_DIR}"
echo
echo -e "  Manage the queue worker:  systemctl status librestack-queue"
echo -e "  LibreStack Panel is free forever. No premium features. No telemetry."
echo
