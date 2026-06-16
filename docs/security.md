# Security

Security is a first-class concern in LibreStack Panel. This document summarises the controls in place.

## Command execution

- **Single choke point.** `App\Services\Support\CommandRunner` is the only place allowed to execute system commands.
- **Binary allowlist.** Every binary must appear in `config/librestack.php` → `allowed_binaries`. Anything else throws `InvalidArgumentException`.
- **No shell strings.** Arguments are always passed as an array to Symfony Process. User input is never concatenated into a shell command, so there is no shell interpolation or injection surface.
- **Argument validation.** Arguments must be scalars and may not contain null bytes.
- **System mode switch.** When `LIBRESTACK_SYSTEM_ENABLED=false`, commands are not executed at all.

## Input validation

`App\Support\Validators` provides strict allowlist-based validation for:

- Domains (RFC-style label validation)
- Linux usernames (`^[a-z][a-z0-9_-]{2,31}$`)
- Database names and users (`[A-Za-z0-9_]`)
- Service names (must be on the allowlist)
- Ports (1–65535)
- Email addresses
- PHP versions (allowlist)
- Cron schedules (5 fields, restricted character set)

## File manager

- Every path is resolved with `realpath` and checked to stay within the selected website's base directory.
- System-sensitive locations (`/etc`, `/root`, `/var/lib/mysql`, `/proc`, `/sys`, `/boot`, `/dev`) are always denied.
- Null bytes are rejected; uploaded filenames are stripped of directory components.

## Secrets

- Settings listed in `encrypted_settings` (e.g. the database admin password) are encrypted at rest with Laravel encryption.
- Secrets are masked in the UI and never re-displayed after saving.
- Audit metadata is redacted: keys containing `password`, `secret`, `token`, `key`, etc. are replaced with `********`.

## Authentication

- Passwords are hashed with bcrypt (configurable rounds).
- Login is rate-limited (`throttle:10,1`).
- CSRF protection is enabled on all state-changing requests.
- Sessions are regenerated on login and invalidated on logout.
- Suspended accounts cannot log in.

## Authorisation

- Role/permission system with middleware (`permission:<name>`).
- `super_admin` implicitly has every permission.
- Destructive actions require explicit confirmation in the UI.

## Firewall safety

- The installer never force-enables UFW, and always allows SSH (22) first.
- The UI warns before disabling the firewall or exposing risky ports (e.g. MySQL 3306).

## Reporting vulnerabilities

Please open a private security advisory or contact the maintainers before public disclosure.
