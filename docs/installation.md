# Installation

LibreStack Panel runs on Ubuntu 22.04, Ubuntu 24.04 and Debian 12.

## Automated install (recommended)

```bash
git clone https://github.com/rinsella/LibreStack-Panel.git
cd LibreStack-Panel
sudo bash scripts/install.sh
```

The installer will:

1. Verify it is running as root and detect the OS.
2. Install Nginx, PHP (cli/fpm + extensions), Composer, Node.js + npm, MariaDB, certbot and UFW.
3. Copy the application to `/opt/librestack`.
4. Create `/var/lib/librestack`, `/var/log/librestack` and the backups directory.
5. Create `.env`, generate `APP_KEY`, create the SQLite database and run migrations + seeders.
6. Build the front-end assets.
7. Install a systemd queue worker (`librestack-queue`) and a system cron entry for the Laravel scheduler.
8. Configure an Nginx server block for the panel on port **8080**.
9. Add UFW rules for SSH, HTTP, HTTPS and the panel port (without force-enabling UFW).

When it finishes, open:

```
http://SERVER_IP:8080/setup
```

and create your first admin account. **No default admin password is created.**

## Manual / development install

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

Visit `http://127.0.0.1:8000/setup`.

### System mode

`LIBRESTACK_SYSTEM_ENABLED` controls whether privileged commands run:

- `false` (default in dev): commands are not executed; the panel returns a "disabled" result and degrades gracefully.
- `true` (set by the installer on a server): real commands run through the allowlisted CommandRunner.

## Using MySQL/MariaDB for the panel database

SQLite is the default for the easiest install. To use MySQL/MariaDB instead, edit `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=librestack
DB_USERNAME=librestack
DB_PASSWORD=secret
```

Then run `php artisan migrate --seed`.

## Auto-renew SSL

The installer adds a system cron entry that runs the Laravel scheduler every minute:

```
* * * * * cd /opt/librestack && php artisan schedule:run >> /var/log/librestack/scheduler.log 2>&1
```

The scheduler runs `librestack:renew-ssl` daily, renewing certificates that expire within 30 days.
