# Development

## Prerequisites

- PHP 8.2+ with extensions: pdo_sqlite, openssl, mbstring, curl, zip, xml, bcmath
- Composer
- Node.js 18+ and npm

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install
npm run build      # or: npm run dev
php artisan serve
```

Open `http://127.0.0.1:8000/setup` and create the first admin.

Keep `LIBRESTACK_SYSTEM_ENABLED=false` in development — system commands will not run, and every service returns a graceful "disabled" result.

## Project layout

```
app/
  Console/Commands/   scheduled commands (backups, SSL renew)
  Http/Controllers/   one controller per module
  Http/Middleware/    CheckPermission, EnsureSetupComplete
  Models/             Eloquent models
  Services/           domain logic per module
  Support/            Validators, Audit, Permissions, PanelState, JobRunner, Format
resources/views/      Blade views, components and partials
routes/web.php        all panel routes
config/librestack.php allowlists and panel configuration
```

## Conventions

- Validate all external input with `App\Support\Validators` before it reaches a service.
- Never call Symfony Process directly — always go through `CommandRunner`.
- Record important actions with `App\Support\Audit::log(...)`.
- Wrap long-running operations in `App\Support\JobRunner::run(...)`.
- Add new system binaries/services to the allowlists in `config/librestack.php`.

## Running tests

```bash
php artisan test
```

## Code style

```bash
./vendor/bin/pint
```
