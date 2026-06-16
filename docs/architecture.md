# Architecture

LibreStack Panel is a standard Laravel application with a clear separation between HTTP controllers, domain services and the safe system layer.

## Layers

```
HTTP (routes/web.php)
  → Controllers (app/Http/Controllers)
      → Services (app/Services/*)            domain logic
          → CommandRunner (Support)          the ONLY system command executor
      → Models (app/Models)                  Eloquent persistence
      → Support (app/Support)                Validators, Audit, Permissions, JobRunner, Format
  → Blade views (resources/views)            Tailwind + Alpine UI
```

## Key building blocks

- **CommandRunner / CommandResult** (`app/Services/Support`) — allowlisted, array-argument system command execution. Returns an immutable result with `ok`, `exitCode`, `output`, `error`, `disabled`.
- **Services** (`app/Services/{System,Nginx,SSL,Database,Backup,FileManager,Firewall,Website,WordPress}`) — each module's domain logic. Config generation is pure where possible so it can be unit-tested without a server.
- **JobRunner** (`app/Support/JobRunner`) — wraps long-running work in a tracked `SystemJob` with `JobLog` entries, so operations have a real audit trail even without a queue worker.
- **Audit** (`app/Support/Audit`) — records important actions with user, IP, user agent and redacted metadata.
- **PanelState** (`app/Support/PanelState`) — determines whether first-run setup is complete.

## Middleware

- `EnsureSetupComplete` (appended to the web group) — forces every request to `/setup` until setup is complete, then redirects away from `/setup`.
- `CheckPermission` (alias `permission`) — authorises a request against a panel permission.

## Data model

Migrations live in `database/migrations`. Core tables: `users`, `roles`, `permissions`, `permission_role`, `role_user`, `settings`, `websites`, `website_aliases`, `ssl_certificates`, `panel_databases`, `database_users`, `backups`, `backup_schedules`, `cron_jobs`, `system_jobs`, `job_logs`, `audit_logs`, `file_operations`.

> The databases table is named `panel_databases` (model `PanelDatabase`) to avoid the reserved word `databases`.

## Background work

- **Queue:** `database` driver; the installer runs a systemd `librestack-queue` worker.
- **Scheduler:** `routes/console.php` registers scheduled commands (`librestack:run-backups`, `librestack:renew-ssl`) driven by a single system cron entry calling `php artisan schedule:run`.

## Front-end

- Blade templates with reusable components (`card`, `badge`, `empty`, `confirm`) and partials (`sidebar`, `topbar`, `flash`, `icons`).
- Tailwind CSS v4 (configured via `@theme` in `resources/css/app.css`) with a navy/brand palette.
- Alpine.js for interactivity (modals, dropdowns, toasts).
- Vite for asset bundling.
