# Troubleshooting

## The panel keeps redirecting to /setup

Setup is considered complete only when the database is migrated, at least one user exists, and the `setup_completed` setting is `1`. Check:

```bash
php artisan migrate --seed
php artisan tinker --execute="echo \App\Models\User::count();"
```

## "Binary not allowed" errors

A command tried to run a binary that is not in `config/librestack.php` → `allowed_binaries`. Add it there if it is legitimately required.

## System actions say "disabled"

`LIBRESTACK_SYSTEM_ENABLED` is `false`. This is expected in development. On a server set it to `true` (the installer does this automatically):

```bash
sed -i 's/^LIBRESTACK_SYSTEM_ENABLED=.*/LIBRESTACK_SYSTEM_ENABLED=true/' .env
php artisan config:clear
```

## Nginx config test fails when creating a website

LibreStack writes the config, runs `nginx -t`, and **rolls back** automatically if the test fails. Inspect the generated config:

```bash
cat /etc/nginx/sites-available/<domain>.conf
sudo nginx -t
```

## Queue worker not processing jobs

```bash
sudo systemctl status librestack-queue
sudo systemctl restart librestack-queue
journalctl -u librestack-queue -n 100 --no-pager
```

## Permissions / 500 errors after deploy

Ensure the web user owns the writable directories:

```bash
sudo chown -R www-data:www-data /opt/librestack/storage /opt/librestack/bootstrap/cache /opt/librestack/database
php artisan config:clear && php artisan view:clear
```

## Reset caches

```bash
php artisan optimize:clear
```

## View logs

```bash
tail -f storage/logs/laravel.log
tail -f /var/log/librestack/panel-error.log
journalctl -u nginx -n 100 --no-pager
```

## SSL issuance fails

- Ensure the domain's DNS A/AAAA record points at the server.
- Ports 80 and 443 must be open in UFW.
- A valid email is required (set it in Settings → SSL email).
