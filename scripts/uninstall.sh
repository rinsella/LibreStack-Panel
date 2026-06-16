#!/usr/bin/env bash
#
# LibreStack Panel — uninstaller.
# Removes the panel's Nginx config, systemd worker and scheduler entry.
# Your website data and (optionally) backups are preserved unless you choose
# to remove them.
#
# Usage:  sudo bash scripts/uninstall.sh
#
set -euo pipefail

APP_DIR="/opt/librestack"
DATA_DIR="/var/lib/librestack"
LOG_DIR="/var/log/librestack"

C_GREEN='\033[0;32m'; C_BLUE='\033[0;34m'; C_RED='\033[0;31m'; C_YELLOW='\033[0;33m'; C_RESET='\033[0m'
log()  { echo -e "${C_BLUE}==>${C_RESET} $*"; }
warn() { echo -e "${C_YELLOW}[!]${C_RESET} $*"; }
die()  { echo -e "${C_RED}[x]${C_RESET} $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root (sudo)."

echo -e "${C_YELLOW}This will remove the LibreStack Panel service, scheduler and Nginx config.${C_RESET}"
read -r -p "Continue? [y/N] " confirm
[[ "${confirm:-N}" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 0; }

log "Stopping and removing the queue worker…"
systemctl disable --now librestack-queue.service 2>/dev/null || true
rm -f /etc/systemd/system/librestack-queue.service
systemctl daemon-reload || true

log "Removing the scheduler cron entry…"
( crontab -l 2>/dev/null | grep -v 'artisan schedule:run' ) | crontab - 2>/dev/null || true

log "Removing the panel Nginx config…"
rm -f /etc/nginx/sites-enabled/librestack-panel.conf /etc/nginx/sites-available/librestack-panel.conf
nginx -t 2>/dev/null && systemctl reload nginx || warn "Nginx not reloaded (check config)."

read -r -p "Remove backups in ${DATA_DIR}/backups too? [y/N] " rmbackups
if [[ "${rmbackups:-N}" =~ ^[Yy]$ ]]; then
    rm -rf "${DATA_DIR}"
    log "Removed ${DATA_DIR}."
else
    warn "Keeping ${DATA_DIR} (backups preserved)."
fi

read -r -p "Remove the application directory ${APP_DIR}? [y/N] " rmapp
if [[ "${rmapp:-N}" =~ ^[Yy]$ ]]; then
    rm -rf "${APP_DIR}"
    log "Removed ${APP_DIR}."
fi

rm -rf "${LOG_DIR}" 2>/dev/null || true

echo -e "${C_GREEN}LibreStack Panel has been uninstalled.${C_RESET}"
echo "Note: system packages (nginx, php, mariadb, certbot, ufw) were left installed."
