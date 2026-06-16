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
     * PHP versions that actually have PHP-FPM installed on this host, detected
     * from the presence of /etc/php/<version>/fpm/pool.d (created by the
     * phpX.Y-fpm package). Sorted ascending. Empty on dev boxes / non-system
     * mode where no real PHP-FPM is present.
     *
     * @return array<int, string>
     */
    public function installedVersions(): array
    {
        $found = [];
        foreach (glob('/etc/php/*/fpm/pool.d', GLOB_ONLYDIR) ?: [] as $dir) {
            $version = basename(dirname(dirname($dir)));
            if (preg_match('/^\d+\.\d+$/', $version)) {
                $found[] = $version;
            }
        }
        sort($found, SORT_NATURAL);

        return $found;
    }

    /**
     * Resolve a requested PHP version to one whose PHP-FPM is actually
     * installed. If the requested version has no FPM (e.g. the site was created
     * offering a version that was never installed) the highest installed
     * version is used instead so provisioning can still succeed. In non-system
     * mode (dev) the requested version is returned unchanged.
     */
    public function resolveInstalledVersion(string $preferred): string
    {
        if (! config('librestack.system_enabled')) {
            return $preferred;
        }

        $installed = $this->installedVersions();
        if ($installed === [] || in_array($preferred, $installed, true)) {
            return $preferred;
        }

        return (string) end($installed);
    }

    /**
     * Build the pool configuration for a user. The pool runs as {username} and
     * confines PHP with open_basedir + disabled dangerous functions.
     *
     * @param  array<string, string>  $settings  Managed php.ini overrides
     *                                            (validated) to inject as
     *                                            php_admin_value entries.
     */
    public function poolConfig(string $username, string $phpVersion, array $settings = []): string
    {
        $this->assertUsername($username);
        $this->assertPhpVersion($phpVersion);

        $webRoot = rtrim((string) config('librestack.paths.web_root'), '/');
        $socket = $this->socketPath($username);

        $config = <<<CONF
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

        // Inject the managed php.ini directives. Every value is re-validated here
        // so a malformed value can never reach the pool file (defence in depth).
        foreach (PhpSettings::sanitize($settings) as $key => $value) {
            $config .= "php_admin_value[{$key}] = {$value}\n";
        }

        return $config;
    }

    /**
     * Create/update the user's pool, test the PHP-FPM config and reload. Rolls
     * back the pool file if the config test fails. Returns the final result;
     * a "disabled" result (dev mode) is treated as a successful no-op.
     *
     * @param  array<string, string>  $settings  Managed php.ini overrides.
     */
    public function ensurePool(string $username, string $phpVersion, array $settings = []): CommandResult
    {
        $this->assertUsername($username);
        $this->assertPhpVersion($phpVersion);

        $write = $this->fs->phpFpmPoolWrite($username, $phpVersion, $this->poolConfig($username, $phpVersion, $settings));
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
        if (! preg_match('/^\d+\.\d+$/', $version)) {
            throw new InvalidArgumentException("Invalid PHP version: {$version}");
        }

        // Accept a version if it's either in the configured allowlist OR has
        // PHP-FPM actually installed on this host. The on-disk check prevents a
        // correctly-installed version (e.g. 8.5 on Ubuntu 26.04) from being
        // rejected because a stale/incomplete config list doesn't mention it.
        $allowed = in_array($version, (array) config('librestack.php_versions'), true)
            || is_dir("/etc/php/{$version}/fpm/pool.d");

        if (! $allowed) {
            throw new InvalidArgumentException("Invalid PHP version: {$version}");
        }
    }
}
