<?php

namespace App\Services\System;

use App\Services\Support\CommandResult;
use App\Support\Validators;
use InvalidArgumentException;

/**
 * Manages per-user PHP-FPM pools so PHP/WordPress sites execute as their own
 * Linux user (not the global www-data pool). This lets WordPress write its own
 * files — media uploads, plugin/theme installs and core updates — while the
 * panel still talks to a www-data-owned unix socket.
 *
 * All privileged work (writing the pool file, testing and reloading PHP-FPM)
 * goes through the safe-op layer; the pool path is fixed and validated.
 */
class PhpFpmService
{
    public function __construct(protected PrivilegedFs $fs)
    {
    }

    /**
     * The per-user FPM socket the Nginx config points at.
     */
    public function socketPath(string $username): string
    {
        $this->assertUsername($username);

        return "/run/php/librestack-{$username}.sock";
    }

    /**
     * Build the pool configuration for a user. The pool runs as {username} and
     * confines PHP with open_basedir + disabled dangerous functions.
     */
    public function poolConfig(string $username, string $phpVersion): string
    {
        $this->assertUsername($username);
        $this->assertPhpVersion($phpVersion);

        $webRoot = rtrim((string) config('librestack.paths.web_root'), '/');
        $socket = $this->socketPath($username);

        return <<<CONF
[librestack-{$username}]
user = {$username}
group = {$username}
listen = {$socket}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 10s
pm.max_requests = 500
php_admin_value[open_basedir] = {$webRoot}/{$username}/web:/tmp
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen

CONF;
    }

    /**
     * Create/update the user's pool, test the PHP-FPM config and reload. Rolls
     * back the pool file if the config test fails. Returns the final result;
     * a "disabled" result (dev mode) is treated as a successful no-op.
     */
    public function ensurePool(string $username, string $phpVersion): CommandResult
    {
        $this->assertUsername($username);
        $this->assertPhpVersion($phpVersion);

        $write = $this->fs->phpFpmPoolWrite($username, $phpVersion, $this->poolConfig($username, $phpVersion));
        if (! $write->ok && ! $write->disabled) {
            return $write;
        }

        $test = $this->fs->phpFpmTest($phpVersion);
        if (! $test->ok && ! $test->disabled) {
            // Roll back the freshly written pool so a broken config is not left
            // behind to break the whole PHP-FPM service.
            $this->fs->phpFpmPoolDelete($username, $phpVersion);

            return $test;
        }

        return $this->fs->phpFpmReload($phpVersion);
    }

    /**
     * Remove the user's pool and reload PHP-FPM.
     */
    public function removePool(string $username, string $phpVersion): CommandResult
    {
        $this->assertUsername($username);
        $this->assertPhpVersion($phpVersion);

        $delete = $this->fs->phpFpmPoolDelete($username, $phpVersion);
        if (! $delete->ok && ! $delete->disabled) {
            return $delete;
        }

        return $this->fs->phpFpmReload($phpVersion);
    }

    protected function assertUsername(string $username): void
    {
        if (! Validators::isValidUsername($username)) {
            throw new InvalidArgumentException("Invalid system username: {$username}");
        }
    }

    protected function assertPhpVersion(string $version): void
    {
        if (! preg_match('/^\d+\.\d+$/', $version)
            || ! in_array($version, (array) config('librestack.php_versions'), true)) {
            throw new InvalidArgumentException("Invalid PHP version: {$version}");
        }
    }
}
