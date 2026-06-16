#!/usr/bin/env bash
#
# LibreStack Panel — updater.
# Pulls the latest code, updates dependencies, migrates and restarts services.
#
# Usage:  sudo bash scripts/update.sh
#
set -euo pipefail

APP_DIR="/opt/librestack"
SERVICE_USER="www-data"

C_GREEN='\033[0;32m'; C_BLUE='\033[0;34m'; C_RED='\033[0;31m'; C_RESET='\033[0m'
log() { echo -e "${C_BLUE}==>${C_RESET} $*"; }
die() { echo -e "${C_RED}[x]${C_RESET} $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root (sudo)."
[ -d "${APP_DIR}" ] || die "${APP_DIR} not found. Is LibreStack installed?"

cd "${APP_DIR}"

log "Enabling maintenance mode…"
php artisan down --render="errors::503" || true

trap 'php artisan up || true' EXIT

if [ -d .git ]; then
    log "Pulling latest code…"
    git pull --ff-only || die "git pull failed. Resolve manually."
fi

log "Updating PHP dependencies…"
composer install --no-dev --optimize-autoloader --no-interaction

log "Installing and building front-end assets…"
npm install --no-audit --no-fund
npm run build

log "Running migrations…"
php artisan migrate --force

log "Refreshing caches…"
php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R "${SERVICE_USER}:${SERVICE_USER}" storage bootstrap/cache database

log "Restarting queue worker…"
systemctl restart librestack-queue.service || true

log "Bringing the application back online…"
php artisan up
trap - EXIT

echo -e "${C_GREEN}LibreStack Panel updated successfully.${C_RESET}"
