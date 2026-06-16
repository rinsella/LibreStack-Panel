# LibreStack Panel

**A free forever, open-source, self-hosted hosting control panel for VPS servers.**

LibreStack Panel is an alternative to aaPanel, CyberPanel, EasyPanel, CloudPanel and HestiaCP — without paywalls, license checks, locked "premium" features, telemetry, or vendor lock-in.

> **LibreStack Panel is free forever. No premium features. No license server. No telemetry. No vendor lock-in.**

Built with Laravel, PHP 8.2+, SQLite (default), Blade + Tailwind CSS + Alpine.js, and Vite.

---

## Features

- **First-run setup wizard** — create your first admin account; no default credentials ever.
- **Authentication** — session auth, CSRF protection, rate-limited login, remember me, password change, 2FA page (coming soon).
- **Users & roles** — `super_admin`, `admin`, `reseller`, `site_owner`, `auditor` with granular permissions.
- **Dashboard** — real server metrics (CPU, RAM, disk, load, uptime, services) from safe `/proc` reads and allowlisted commands.
- **Website manager** — full CRUD, static/PHP/WordPress/reverse-proxy site types, directory provisioning, generated & tested Nginx configs with rollback.
- **Nginx manager** — generate, validate (`nginx -t`), enable/disable, reload/restart, view logs.
- **SSL manager** — Let's Encrypt via certbot, issue/renew/delete, expiry tracking, auto-renew scheduler.
- **Database manager** — MariaDB/MySQL databases & users, strong passwords, export/import, encrypted admin credentials.
- **File manager** — sandboxed browse/edit/upload/download/zip with realpath path-traversal protection.
- **Backup manager** — manual & scheduled backups of files and/or databases, restore, download, retention pruning.
- **WordPress manager** — one-click install with auto database, `wp-config.php` and unique salts.
- **Reverse proxy** — proxy domains to local apps with WebSocket support (process manager: coming soon).
- **Service manager** — control allowlisted systemd services and view journal logs.
- **Firewall manager** — UFW status, rules, presets, with lock-out warnings.
- **Cron manager** — validated schedules synced into the system crontab.
- **Log viewer** — bounded, searchable panel/Nginx/PHP-FPM/journal logs.
- **Audit logs** — every important action recorded with user, IP, user agent and metadata.
- **Job system** — long-running operations tracked with status, progress and full logs.
- **Settings** — encrypted secrets, masked in the UI.

## Requirements

- Ubuntu 22.04 / Ubuntu 24.04 / Debian 12
- PHP 8.2+
- Nginx, MariaDB/MySQL (optional for the DB module), Composer, Node.js + npm
- certbot + python3-certbot-nginx (for SSL)

## Quick install (VPS)

```bash
git clone https://github.com/rinsella/LibreStack-Panel.git
cd LibreStack-Panel
sudo bash scripts/install.sh
```

The installer prints your setup URL when finished, e.g.:

```
http://SERVER_IP:8080/setup
```

Open it and create your first admin account.

## Manual install (development)

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Then visit `http://127.0.0.1:8000/setup`.

> In development, `LIBRESTACK_SYSTEM_ENABLED=false` (the default) means the panel never runs privileged system commands — every system action degrades gracefully so the UI keeps working without a real Linux server underneath. On a real server the installer sets this to `true`.

## Creating the first admin

There is **no default admin account**. On first launch every route redirects to `/setup`, where you create the first `super_admin`. After setup completes, the login page becomes active.

## Security notes

- A single safe **CommandRunner** is the only place allowed to execute system commands. Binaries must be on an allowlist, and arguments are always passed as an array to Symfony Process — user input is never concatenated into a shell string.
- All domains, usernames, paths, ports, database names and service names are strictly validated.
- The file manager resolves every path with `realpath` and rejects anything outside the website base directory or inside system locations (`/etc`, `/root`, `/var/lib/mysql`, ...).
- Sensitive settings are encrypted at rest and masked in the UI; secrets are redacted from audit metadata.

See [docs/security.md](docs/security.md) for details.

## Documentation

- [Installation](docs/installation.md)
- [Security](docs/security.md)
- [Architecture](docs/architecture.md)
- [Development](docs/development.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Roadmap](docs/roadmap.md)

## Common operations

```bash
# Restart the panel queue worker
sudo systemctl restart librestack-queue

# View panel logs
tail -f storage/logs/laravel.log
tail -f /var/log/librestack/panel-error.log

# Update the panel
sudo bash scripts/update.sh

# Uninstall (keeps backups unless you choose otherwise)
sudo bash scripts/uninstall.sh
```

## Roadmap

See [docs/roadmap.md](docs/roadmap.md). Highlights: TOTP 2FA, systemd process manager for Node apps, DNS manager, email server integration.

## Contributing

Contributions are welcome. Please open an issue or pull request. Keep the project free of paywalls, telemetry and license checks — that is the whole point.

## License

LibreStack Panel is licensed under the GNU Affero General Public License v3.0 or later.

SPDX-License-Identifier: AGPL-3.0-or-later

LibreStack Panel is free forever. No premium features. No license server. No telemetry. No vendor lock-in.
