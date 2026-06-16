# Roadmap

LibreStack Panel is free forever. The roadmap below is community-driven and contains no "premium" tier.

## Shipped (MVP)

- First-run setup wizard, authentication, roles & permissions
- Dashboard with real server metrics
- Website manager (static / PHP / WordPress / reverse proxy) with Nginx generation, testing and rollback
- SSL manager (Let's Encrypt via certbot) with auto-renew scheduler
- Database manager (MariaDB/MySQL)
- Secure file manager
- Backup manager with schedules and retention
- One-click WordPress installer
- Service manager, firewall manager, cron manager
- Log viewer, audit logs, job system, settings

## Planned

- **TOTP 2FA** — currently a "Coming soon" page; full enrolment + recovery codes.
- **Node.js process manager** — manage upstream apps via systemd units (reverse proxy already works).
- **DNS manager** — manage records for supported providers.
- **Email server integration** — mailboxes, aliases, DKIM/SPF helpers.
- **Multi-server support** — manage several nodes from one panel.
- **Backup to remote storage** — S3-compatible / SFTP targets.
- **Per-site resource limits** — PHP-FPM pool tuning per website.
- **One-click app templates** beyond WordPress.

## Principles

- No paywalls, no license server, no telemetry, no vendor lock-in.
- Security first: allowlisted commands, strict validation, sandboxed file access.
- Easy to install, debug and run on a fresh Ubuntu/Debian VPS.

Have an idea? Open an issue or pull request.
