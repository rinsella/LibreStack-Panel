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
# Add a documented PHP repository so the requested PHP version is available
# on every supported distro (Ubuntu 22.04 ships PHP 8.1, Debian 12 ships 8.2).
#   - Ubuntu: ppa:ondrej/php  (https://launchpad.net/~ondrej/+archive/ubuntu/php)
#   - Debian: packages.sury.org/php  (https://deb.sury.org/)
#
# Brand-new / pre-release Ubuntu versions (e.g. 26.04 "resolute") are not always
# published on the PPA yet, which makes `add-apt-repository` leave a broken source
# behind and `apt-get update` fail with a 404. Those releases already ship PHP
# >= 8.3 themselves, so we skip the PPA for them rather than abort the install.
# --------------------------------------------------------------------------

# True when the ondrej/php PPA actually publishes packages for the given Ubuntu
# codename (i.e. a dists/<codename>/Release file exists). A definitive 404 means
# the release simply isn't published yet; transient network errors are retried so
# we don't wrongly skip the PPA on a supported release.
ondrej_php_ppa_available() {
    [ -n "${1:-}" ] || return 1
    local url="https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${1}/Release"
    local code attempt
    for attempt in 1 2 3; do
        code="$(curl -fsSL --max-time 15 -o /dev/null -w '%{http_code}' "${url}" 2>/dev/null)" \
            && [ "${code}" = "200" ] && return 0
        [ "${code}" = "404" ] && return 1
        sleep 2
    done
    return 1
}

setup_php_repo() {
    log "Configuring PHP ${PHP_VERSION_DEFAULT} package repository for ${ID}…"
    apt-get install -y ca-certificates apt-transport-https lsb-release gnupg curl >/dev/null

    case "${ID}" in
        ubuntu)
            apt-get install -y software-properties-common >/dev/null
            local codename="${UBUNTU_CODENAME:-${VERSION_CODENAME:-}}"
            if ondrej_php_ppa_available "${codename}"; then
                if grep -rq "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null; then
                    ok "ppa:ondrej/php is already configured."
                else
                    LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php \
                        || warn "Could not add ppa:ondrej/php; relying on the distro's default PHP packages."
                fi
            else
                warn "ppa:ondrej/php has no packages for Ubuntu '${codename:-unknown}' yet; relying on the distro's own PHP (Ubuntu 24.04+ already ships PHP >= 8.3)."
                # Drop any half-configured PPA entry left by a previous failed run
                # so it doesn't break apt-get update for the rest of the install.
                rm -f /etc/apt/sources.list.d/*ondrej*php*.list \
                      /etc/apt/sources.list.d/*ondrej*php*.sources 2>/dev/null || true
            fi
            ;;
        debian)
            install -d -m 0755 /etc/apt/keyrings
            curl -fsSL https://packages.sury.org/php/apt.gpg \
                | gpg --dearmor -o /etc/apt/keyrings/sury-php.gpg
            echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
                > /etc/apt/sources.list.d/sury-php.list
            ;;
        *)
            warn "Unknown distro '${ID}'; relying on the distro's default PHP packages."
            ;;
    esac
    apt-get update -y || warn "apt-get update reported errors; continuing best-effort."
}

# --------------------------------------------------------------------------
# Detect the PHP version to use. Prefer a version that actually has PHP-FPM
# installed (it has an /etc/php/<v>/fpm directory) because the panel runs every
# PHP/WordPress site through a per-user PHP-FPM pool. Falls back to any php
# config dir, then the running php-cli version, then the requested default.
# --------------------------------------------------------------------------
detect_php_version() {
    local v
    # 1. Highest version that has PHP-FPM installed (a pool.d dir exists).
    v="$(ls -d /etc/php/*/fpm/pool.d 2>/dev/null | awk -F/ '{print $4}' \
         | grep -E '^[0-9]+\.[0-9]+$' | sort -V | tail -n1)"
    # 2. Else the highest version that has any /etc/php config dir.
    [ -z "${v}" ] && v="$(ls /etc/php/ 2>/dev/null | grep -E '^[0-9]+\.[0-9]+$' | sort -V | tail -n1)"
    # 3. Else the running php-cli version.
    if [ -z "${v}" ] && command -v php >/dev/null 2>&1; then
        v="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)"
    fi
    echo "${v:-${PHP_VERSION_DEFAULT}}"
}

# Guarantee PHP-FPM (and the extensions the panel needs) for a given version is
# installed, so /etc/php/<v>/fpm/pool.d exists and per-user pools can be written.
# This repairs boxes where only the php-cli was pulled in (e.g. as a composer
# dependency). Errors are shown, not swallowed, so a genuine failure is visible.
# Install one PHP version's FPM package (critical) in isolation, then its panel
# extensions best-effort. Isolating the -fpm package means a single missing
# extension can never prevent the pool.d directory from being created. Returns
# success iff /etc/php/<v>/fpm/pool.d exists afterwards.
install_php_fpm_version() {
    local cv="$1"
    apt-get install -y "php${cv}-fpm" || return 1
    # Extensions the panel/WordPress need — best-effort, never fatal.
    local ext
    for ext in cli sqlite3 mysql curl zip mbstring xml bcmath common gd intl; do
        apt-get install -y "php${cv}-${ext}" >/dev/null 2>&1 || true
    done
    [ -d "/etc/php/${cv}/fpm/pool.d" ]
}

# Guarantee PHP-FPM (and the extensions the panel needs) is installed, so
# /etc/php/<v>/fpm/pool.d exists and per-user pools can be written. This repairs
# boxes where only the php-cli was pulled in (e.g. as a composer dependency) and
# adapts to brand-new distros that ship a newer default (e.g. Ubuntu 26.04 →
# php8.5). Errors are shown, not swallowed, so a genuine failure is visible.
# Returns success only if some FPM pool.d directory exists afterwards.
ensure_php_fpm_installed() {
    local v="$1"
    [ -d "/etc/php/${v}/fpm/pool.d" ] && return 0

    log "Ensuring PHP-FPM and required extensions are installed…"

    # php*-fpm lives in the 'universe' component on Ubuntu; make sure it's on.
    if [ "${ID}" = "ubuntu" ] && command -v add-apt-repository >/dev/null 2>&1; then
        add-apt-repository -y universe >/dev/null 2>&1 || true
    fi
    apt-get update -y || warn "apt-get update reported errors; continuing best-effort."

    # 1. Try the requested version.
    install_php_fpm_version "${v}" && return 0

    # 2. Discover the version this archive actually publishes (e.g. php8.5-fpm on
    #    a distro whose default PHP moved past the requested version) and install
    #    that. Pick the highest available.
    local cand cv
    cand="$(apt-cache search --names-only '^php[0-9]+\.[0-9]+-fpm$' 2>/dev/null \
            | awk '{print $1}' | sort -V | tail -n1)"
    if [ -n "${cand}" ]; then
        cv="${cand#php}"; cv="${cv%-fpm}"
        if [ "${cv}" != "${v}" ]; then
            log "php${v}-fpm is unavailable; using the version this OS ships: ${cand} (PHP ${cv})…"
        fi
        install_php_fpm_version "${cv}" && return 0
    fi

    # 3. Fall back to the generic php-fpm metapackage (tracks the distro default).
    log "Trying the generic php-fpm metapackage…"
    apt-get install -y php-fpm || true
    [ -n "$(ls -d /etc/php/*/fpm/pool.d 2>/dev/null)" ] && return 0

    warn "No PHP-FPM package could be installed. Diagnostics:"
    echo "    php*-fpm packages available in this archive:"
    apt-cache search --names-only '^php[0-9.]*-fpm$' 2>/dev/null | sed 's/^/      /' || true

    # Success if any FPM pool dir now exists.
    [ -n "$(ls -d /etc/php/*/fpm/pool.d 2>/dev/null)" ]
}

# Find an existing PHP-FPM unix socket, preferring the detected version.
detect_php_socket() {
    local v="$1"
    local sock="/run/php/php${v}-fpm.sock"
    if [ -S "${sock}" ]; then
        echo "${sock}"
        return
    fi
    ls /run/php/php*-fpm.sock 2>/dev/null | head -n1 || echo "${sock}"
}

# --------------------------------------------------------------------------
# Detect the server's PUBLIC IP address.
#
# `hostname -I` returns the PRIMARY interface address, which on cloud VPSes
# (AWS/GCP/Azure) is a PRIVATE NAT address (10.x / 172.16-31.x / 192.168.x) —
# NOT the address users reach the panel on. We query the cloud metadata services
# first, then fall back to a public echo service, and finally to the local
# interface so the installer always advertises a usable address.
# --------------------------------------------------------------------------
is_public_ip() {
    local ip="$1"
    [[ "${ip}" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || return 1
    case "${ip}" in
        10.*|127.*|169.254.*|192.168.*|0.*|255.*) return 1 ;;
        172.1[6-9].*|172.2[0-9].*|172.3[0-1].*)   return 1 ;;
        100.6[4-9].*|100.[7-9][0-9].*|100.1[01][0-9].*|100.12[0-7].*) return 1 ;;
    esac
    return 0
}

detect_public_ip() {
    local ip token url

    # AWS IMDSv2 (token-protected) then IMDSv1.
    token="$(curl -fsS --max-time 3 -X PUT 'http://169.254.169.254/latest/api/token' \
        -H 'X-aws-ec2-metadata-token-ttl-seconds: 60' 2>/dev/null || true)"
    if [ -n "${token}" ]; then
        ip="$(curl -fsS --max-time 3 -H "X-aws-ec2-metadata-token: ${token}" \
            'http://169.254.169.254/latest/meta-data/public-ipv4' 2>/dev/null || true)"
        is_public_ip "${ip}" && { echo "${ip}"; return 0; }
    fi
    ip="$(curl -fsS --max-time 3 'http://169.254.169.254/latest/meta-data/public-ipv4' 2>/dev/null || true)"
    is_public_ip "${ip}" && { echo "${ip}"; return 0; }

    # Google Cloud.
    ip="$(curl -fsS --max-time 3 -H 'Metadata-Flavor: Google' \
        'http://metadata.google.internal/computeMetadata/v1/instance/network-interfaces/0/access-configs/0/external-ip' 2>/dev/null || true)"
    is_public_ip "${ip}" && { echo "${ip}"; return 0; }

    # Azure IMDS.
    ip="$(curl -fsS --max-time 3 -H 'Metadata: true' \
        'http://169.254.169.254/metadata/instance/network/interface/0/ipv4/ipAddress/0/publicIpAddress?api-version=2021-02-01&format=text' 2>/dev/null || true)"
    is_public_ip "${ip}" && { echo "${ip}"; return 0; }

    # Generic public echo services (only if the box has outbound internet).
    for url in 'https://api.ipify.org' 'https://ifconfig.me/ip' 'https://icanhazip.com'; do
        ip="$(curl -fsS --max-time 5 "${url}" 2>/dev/null | tr -d '[:space:]' || true)"
        is_public_ip "${ip}" && { echo "${ip}"; return 0; }
    done

    return 1
}

# Advertise the public IP when one is found, otherwise the primary local address.
detect_server_ip() {
    local public
    public="$(detect_public_ip || true)"
    if [ -n "${public}" ]; then
        echo "${public}"
        return 0
    fi
    hostname -I 2>/dev/null | awk '{print $1}'
}

# --------------------------------------------------------------------------
# Install system packages
# --------------------------------------------------------------------------
setup_php_repo

log "Updating apt and installing dependencies (this can take a few minutes)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y || warn "apt-get update reported errors; continuing best-effort."

PHP="php${PHP_VERSION_DEFAULT}"
PACKAGES=(
    nginx
    "${PHP}-cli" "${PHP}-fpm" "${PHP}-sqlite3" "${PHP}-mysql" "${PHP}-curl"
    "${PHP}-zip" "${PHP}-mbstring" "${PHP}-xml" "${PHP}-bcmath" "${PHP}-common"
    composer nodejs npm
    mariadb-server
    certbot python3-certbot-nginx
    cron
    unzip curl git ufw sudo
)

# Fall back to distro default php-* metapackages if the versioned ones are absent.
if ! apt-get install -y "${PACKAGES[@]}"; then
    warn "Versioned PHP packages unavailable; falling back to generic php-* metapackages."
    if ! apt-get install -y nginx php-cli php-fpm php-sqlite3 php-mysql php-curl php-zip \
        php-mbstring php-xml php-bcmath php-common composer nodejs npm mariadb-server \
        certbot python3-certbot-nginx cron unzip curl git ufw sudo; then
        # A brand-new distro may rename/withhold a package, which would abort the
        # whole atomic install. Install each package best-effort so one missing
        # name doesn't block the rest; PHP-FPM is guaranteed separately below.
        warn "Some packages were unavailable as a group; installing them individually (best-effort)…"
        for pkg in nginx php-cli php-fpm php-sqlite3 php-mysql php-curl php-zip \
                   php-mbstring php-xml php-bcmath php-common composer nodejs npm \
                   mariadb-server certbot python3-certbot-nginx cron unzip curl git ufw sudo; do
            apt-get install -y "${pkg}" >/dev/null 2>&1 || warn "  could not install: ${pkg}"
        done
    fi
fi

# Critical packages the panel cannot run without.
command -v nginx >/dev/null 2>&1 || die "nginx is required but could not be installed."

# sudo is mandatory: the panel performs all privileged work through it.
command -v sudo >/dev/null 2>&1 || die "sudo is required but could not be installed."
command -v visudo >/dev/null 2>&1 || die "visudo is required but could not be installed."
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
# Detect the PHP-FPM version that actually got installed and the real socket.
PHP_VERSION="$(detect_php_version)"

# Guarantee the installed PHP meets the composer requirement (>= 8.3).
php_at_least() {
    php -r 'exit((PHP_VERSION_ID >= '"$1"') ? 0 : 1);' >/dev/null 2>&1
}
if ! php_at_least 80300; then
    die "PHP 8.3+ is required (composer.json needs ^8.3) but $(php -r 'echo PHP_VERSION;') is active. Install/enable PHP 8.3 and retry."
fi

# The panel runs every PHP/WordPress site through a per-user PHP-FPM pool, which
# requires /etc/php/<v>/fpm/pool.d to exist. Make sure PHP-FPM for the chosen
# version is installed (repairs boxes that only got the php-cli), then re-detect.
if ! ensure_php_fpm_installed "${PHP_VERSION}"; then
    die "PHP-FPM could not be installed from this OS's package archive.

This usually means the distro is too new/pre-release for the PHP packages to be
published yet (this box is '${PRETTY_NAME}'). The panel requires PHP-FPM for
PHP/WordPress sites.

How to fix:
  • Recommended: install on a supported LTS release — Ubuntu 24.04 or 22.04, or
    Debian 12 — where 'php-fpm' is available out of the box.
  • Or, if a PHP-FPM package IS available under a different name, install it
    manually and re-run this installer, e.g.:
        apt-cache search --names-only '^php.*-fpm\$'
        sudo apt-get install -y <that-package>
        sudo bash scripts/install.sh"
fi
# Re-detect: an FPM for a DIFFERENT version than requested may have been
# installed (e.g. the archive only ships php8.5-fpm). The panel adapts to it.
PHP_VERSION="$(detect_php_version)"
ok "PHP-FPM ${PHP_VERSION} is installed (pool dir: /etc/php/${PHP_VERSION}/fpm/pool.d)."

# Make sure the PHP-FPM service for the detected version is enabled and running
# so the socket exists before we detect it.
FPM_SERVICE="php${PHP_VERSION}-fpm"
if systemctl list-unit-files 2>/dev/null | grep -q "^${FPM_SERVICE}"; then
    systemctl enable --now "${FPM_SERVICE}" >/dev/null 2>&1 || systemctl restart "${FPM_SERVICE}" || true
fi

PHP_SOCK="$(detect_php_socket "${PHP_VERSION}")"
[ -S "${PHP_SOCK}" ] || warn "PHP-FPM socket ${PHP_SOCK} not found yet; it should appear once php-fpm is running."
ok "Using PHP ${PHP_VERSION} (FPM socket: ${PHP_SOCK})."

# Detect the server's PUBLIC IP for APP_URL (falls back to the local address).
log "Detecting the server's public IP address…"
LOCAL_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
SERVER_IP="$(detect_server_ip)"
APP_URL_HOST="${SERVER_IP:-127.0.0.1}"
if [ -n "${SERVER_IP}" ] && [ "${SERVER_IP}" != "${LOCAL_IP}" ]; then
    ok "Public IP: ${SERVER_IP} (local interface: ${LOCAL_IP:-n/a})."
else
    warn "Could not determine a public IP; using ${APP_URL_HOST}. If this is a private address, set APP_URL manually in .env."
fi

if [ ! -f .env ]; then
    log "Creating .env from template…"
    cp .env.example .env
fi

# Always (re)write the runtime-detected settings so generated configs are valid.
sed -i 's/^LIBRESTACK_SYSTEM_ENABLED=.*/LIBRESTACK_SYSTEM_ENABLED=true/' .env
sed -i 's/^LIBRESTACK_USE_SUDO=.*/LIBRESTACK_USE_SUDO=true/' .env
grep -q '^LIBRESTACK_USE_SUDO=' .env || echo 'LIBRESTACK_USE_SUDO=true' >> .env
sed -i "s/^LIBRESTACK_DEFAULT_PHP=.*/LIBRESTACK_DEFAULT_PHP=${PHP_VERSION}/" .env
grep -q '^LIBRESTACK_DEFAULT_PHP=' .env || echo "LIBRESTACK_DEFAULT_PHP=${PHP_VERSION}" >> .env
sed -i "s#^APP_URL=.*#APP_URL=http://${APP_URL_HOST}:${PANEL_PORT}#" .env
# Point the safe-op helper at the install directory.
grep -q '^LIBRESTACK_SAFE_OP_SCRIPT=' .env \
    || echo "LIBRESTACK_SAFE_OP_SCRIPT=${APP_DIR}/scripts/librestack-safe-op" >> .env

log "Installing PHP dependencies…"
composer install --no-dev --optimize-autoloader --no-interaction

log "Generating application key…"
php artisan key:generate --force

log "Preparing SQLite database…"
touch database/database.sqlite
chown "${SERVICE_USER}:${SERVICE_USER}" database/database.sqlite
php artisan migrate --force --seed

log "Building front-end assets…"
npm install --no-audit --no-fund
npm run build

php artisan storage:link || true

chown -R "${SERVICE_USER}:${SERVICE_USER}" "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" "${APP_DIR}/database"
chmod -R ug+rw "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"

# Cache config + routes. Closure routes were removed, so route:cache must pass.
php artisan config:cache
php artisan route:cache
php artisan view:cache || true
ok "Application prepared."

# --------------------------------------------------------------------------
# Privileged command allowlist (sudoers)
# --------------------------------------------------------------------------
# The panel and its queue worker run as ${SERVICE_USER} (never as root, and
# PHP-FPM is NOT run as root). Privileged FILESYSTEM work (writing /etc/nginx,
# creating /home/<user>/web trees, chown/chmod, tar copy/extract) goes through
# ONE root helper: scripts/librestack-safe-op, which validates every path.
# A few service-control binaries (systemctl, nginx -t/reload, certbot, ufw,
# mariadb client, useradd, crontab) are additionally allowed for the specific
# operations the panel performs with strictly validated array arguments.
log "Installing sudoers allowlist for ${SERVICE_USER}…"
resolve_bin() { command -v "$1" 2>/dev/null || echo ""; }

SAFE_OP="${APP_DIR}/scripts/librestack-safe-op"
chmod 0755 "${SAFE_OP}" 2>/dev/null || true

SUDO_BINS=()
for b in systemctl nginx certbot ufw mysql mysqldump crontab journalctl useradd; do
    p="$(resolve_bin "$b")"
    [ -n "${p}" ] && SUDO_BINS+=("${p}")
done

SUDOERS_FILE="/etc/sudoers.d/librestack"
SUDOERS_TMP="$(mktemp)"
{
    echo "# Managed by LibreStack Panel. Do not edit manually."
    echo "# Primary privilege boundary: the root-owned safe-op helper only."
    printf '%s ALL=(root) NOPASSWD: %s *\n' "${SERVICE_USER}" "${SAFE_OP}"
    echo "# Service-control binaries used with strictly validated arguments."
    if [ "${#SUDO_BINS[@]}" -gt 0 ]; then
        printf '%s ALL=(root) NOPASSWD: %s\n' "${SERVICE_USER}" "$(IFS=, ; echo "${SUDO_BINS[*]}")"
    fi
} > "${SUDOERS_TMP}"

if visudo -cf "${SUDOERS_TMP}" >/dev/null 2>&1; then
    install -m 0440 -o root -g root "${SUDOERS_TMP}" "${SUDOERS_FILE}"
    rm -f "${SUDOERS_TMP}"
    ok "Sudoers allowlist installed at ${SUDOERS_FILE}."
else
    rm -f "${SUDOERS_TMP}"
    die "Generated sudoers file failed visudo validation. Aborting to avoid a broken/insecure sudoers."
fi

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
# `enable --now` does NOT restart an already-running worker, so on a re-run the
# old worker would keep serving STALE code/config in memory (a long-running
# daemon loads everything once at boot). Force a restart so updates take effect.
systemctl restart librestack-queue.service || true
ok "Queue worker running."

# --------------------------------------------------------------------------
# Ensure core services are enabled and running
# --------------------------------------------------------------------------
log "Enabling core services (nginx, mariadb, php-fpm)…"
for svc in nginx mariadb "${FPM_SERVICE}"; do
    if systemctl list-unit-files 2>/dev/null | grep -q "^${svc}"; then
        systemctl enable --now "${svc}" >/dev/null 2>&1 || warn "Could not enable/start ${svc}."
    fi
done
ok "Core services enabled."

# --------------------------------------------------------------------------
# Laravel scheduler (system cron)
# --------------------------------------------------------------------------
log "Installing scheduler cron entry…"
CRON_LINE="* * * * * cd ${APP_DIR} && /usr/bin/php artisan schedule:run >> ${LOG_DIR}/scheduler.log 2>&1"
if command -v crontab >/dev/null 2>&1; then
    # Make sure the cron daemon is enabled so the schedule actually fires.
    systemctl enable --now cron >/dev/null 2>&1 \
        || systemctl enable --now cronie >/dev/null 2>&1 || true
    if ( crontab -l 2>/dev/null | grep -v 'artisan schedule:run' ; echo "${CRON_LINE}" ) | crontab -; then
        ok "Scheduler installed."
    else
        warn "Could not install the scheduler cron entry. Add it manually with 'crontab -e':"
        warn "  ${CRON_LINE}"
    fi
else
    warn "crontab not found; skipping scheduler. Install the 'cron' package, then add with 'crontab -e':"
    warn "  ${CRON_LINE}"
fi

# --------------------------------------------------------------------------
# Nginx site for the panel
# --------------------------------------------------------------------------
log "Configuring Nginx for the panel on port ${PANEL_PORT}…"
# PHP_SOCK was detected earlier from the actually-installed PHP-FPM.
[ -S "${PHP_SOCK}" ] || PHP_SOCK="$(detect_php_socket "${PHP_VERSION}")"

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

# Remove the distro's default catch-all site so it does not shadow managed
# domains with the "Welcome to nginx" page on port 80.
rm -f /etc/nginx/sites-enabled/default

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
# Preflight diagnostics
# --------------------------------------------------------------------------
log "Running preflight diagnostics (librestack:doctor)…"
sudo -u "${SERVICE_USER}" php "${APP_DIR}/artisan" librestack:doctor --install || \
    warn "Some preflight checks reported issues. Review the output above before going live."

# --------------------------------------------------------------------------
# Done
# --------------------------------------------------------------------------
SERVER_IP="${SERVER_IP:-$(detect_server_ip)}"
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
