# Testing LibreStack Panel on a real VPS

This is the end-to-end smoke test for a production install on a fresh server.
LibreStack Panel targets **Ubuntu 22.04 / 24.04** and **Debian 12**.

## 0. Prerequisites

- A fresh VPS with root (or sudo) access.
- A domain you control, with an A record you can point at the server when you
  reach the SSL step.

## 1. Provision a fresh server

Start from a clean **Ubuntu 24.04** image.

## 2. Run the installer

```bash
git clone https://github.com/rinsella/LibreStack-Panel.git
cd LibreStack-Panel
sudo bash scripts/install.sh
```

The installer will:

- install PHP 8.3 (via `ppa:ondrej/php` on Ubuntu, `packages.sury.org` on Debian),
  Nginx, MariaDB, certbot, ufw and **sudo**;
- detect the real PHP-FPM version + socket and write them into `.env`;
- install the scoped `/etc/sudoers.d/librestack` allowlist (validated with
  `visudo -cf` — the install **fails** if validation fails);
- build assets, migrate + seed, cache config/routes/views;
- enable and start `nginx`, `mariadb`, `phpX.Y-fpm` and the queue worker;
- run `php artisan librestack:doctor --install` as a preflight check.

If anything required is missing, the installer stops with a clear error.

## 3. Open the setup wizard

```
http://SERVER_IP:8080/setup
```

## 4. Create the admin account

Complete the wizard to create your first super-admin.

## 5. Create a website

In **Websites → New**, create `example.com` (type: PHP) with a system username
such as `siteone`.

## 6. Check the directory tree

```bash
ls -la /home/siteone/web/example.com/
# expect: public_html/  logs/  backups/  (owned by siteone:siteone)
ls -la /home/siteone/web/example.com/public_html/index.html
```

## 7. Check the Nginx config

```bash
cat /etc/nginx/sites-available/example.com.conf
ls -l /etc/nginx/sites-enabled/example.com.conf   # symlink present
```

## 8. Validate Nginx

```bash
sudo nginx -t
```

## 9. Verify the site serves

```bash
curl -H 'Host: example.com' http://127.0.0.1/
# expect the LibreStack placeholder index page
```

## 10. Issue SSL

Point `example.com`'s DNS A record at the server, wait for propagation, then use
**SSL → Issue** in the panel. Verify:

```bash
sudo certbot certificates
curl -I https://example.com/
```

## 11. Create a database

In **Databases → New**, create a database + user. The password is shown once.

## 12. Install WordPress

In **WordPress**, install onto a PHP website. Confirm:

```bash
ls /home/siteone/web/example.com/public_html/wp-settings.php
stat -c '%a' /home/siteone/web/example.com/public_html/wp-config.php   # 640
```

The WordPress database also appears under **Databases** (tracked automatically).

## 13. Create a full backup

In **Backups**, create a `full` backup of the WordPress site. Confirm a single
archive under `/var/lib/librestack/backups/<domain>/` and that the job is marked
**success** only when files **and** database dumps succeeded.

## 14. Restore the full backup

Trigger a restore from the panel. Files are restored only into the website's own
document root and each SQL dump is restored to its database.

## 15. Confirm the database restored

```bash
mysql -e "SHOW DATABASES;" | grep wp_
mysql wp_xxxxxxxx -e "SHOW TABLES;"
```

## Troubleshooting

Run the diagnostics any time:

```bash
sudo -u www-data php /opt/librestack/artisan librestack:doctor
```

It checks PHP version + extensions, writable paths, the database connection,
`sudo`, the safe-op helper, Nginx, the PHP-FPM socket, the MariaDB client,
certbot and ufw.
