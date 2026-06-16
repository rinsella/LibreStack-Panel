<?php

namespace App\Services\System;

use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use App\Support\Validators;
use InvalidArgumentException;

/**
 * Safely manages Linux system users that own website files.
 *
 * Users are created without shell access by default (/usr/sbin/nologin) so a
 * compromised site cannot get an interactive login. Reserved/system accounts
 * are always refused. All commands run through the allowlisted CommandRunner
 * (useradd/id) with array arguments — never a shell string.
 */
class SystemUserService
{
    /** Accounts the panel must never create or manage. */
    protected array $reserved = [
        'root', 'www-data', 'nginx', 'mysql', 'mariadb', 'daemon', 'bin', 'sys',
        'sync', 'games', 'man', 'lp', 'mail', 'news', 'uucp', 'proxy', 'backup',
        'list', 'irc', 'gnats', 'nobody', 'systemd-network', 'systemd-resolve', 'sshd',
    ];

    public function __construct(protected CommandRunner $runner)
    {
    }

    public function isReserved(string $username): bool
    {
        return in_array(strtolower($username), $this->reserved, true);
    }

    public function assertAllowed(string $username): void
    {
        if (! Validators::isValidUsername($username)) {
            throw new InvalidArgumentException("Invalid system username: {$username}");
        }
        if ($this->isReserved($username)) {
            throw new InvalidArgumentException("Refusing to manage reserved account: {$username}");
        }
    }

    /**
     * Whether a Linux user already exists. In non-system mode this is always
     * reported false (no real users on a dev box).
     */
    public function exists(string $username): bool
    {
        $this->assertAllowed($username);

        if (! $this->runner->isEnabled()) {
            return false;
        }

        return $this->runner->run('id', ['-u', $username], 10)->ok;
    }

    /**
     * Ensure the Linux user exists, creating it (with a home dir, no shell)
     * if necessary. Returns a CommandResult describing the outcome.
     */
    public function ensure(string $username): CommandResult
    {
        $this->assertAllowed($username);

        if (! $this->runner->isEnabled()) {
            return CommandResult::disabled();
        }

        if ($this->exists($username)) {
            return new CommandResult(true, 0, "user {$username} already exists", '');
        }

        $webRoot = rtrim((string) config('librestack.paths.web_root'), '/');

        return $this->runner->run('useradd', [
            '--create-home',
            '--home-dir', "{$webRoot}/{$username}",
            '--shell', '/usr/sbin/nologin',
            '--user-group',
            $username,
        ], 30);
    }
}
