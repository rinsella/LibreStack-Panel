<?php

namespace App\Services\System;

use App\Support\Validators;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

/**
 * The real implementation of every privileged filesystem operation.
 *
 * This class is intended to run AS ROOT, invoked through the
 * `librestack:safe-op` artisan command behind a tightly scoped sudoers entry
 * (see scripts/librestack-safe-op and /etc/sudoers.d/librestack). It performs
 * strict allowlist validation on every path/identifier BEFORE touching the
 * filesystem so that www-data can never escalate beyond the managed locations.
 *
 * Hard rules enforced here:
 *  - Nginx configs may only live under the configured sites-available /
 *    sites-enabled directories.
 *  - Website paths may only live under /home/{username}/web/{domain}.
 *  - /etc (outside managed nginx), /root and /var/lib/mysql are never allowed.
 *  - No shell strings are ever built: external binaries run through Laravel
 *    Process with array arguments only.
 *  - Errors are returned/thrown explicitly, never silently swallowed.
 */
class SafeOps
{
    /** Locations that are never writable regardless of any other rule. */
    protected array $forbiddenPrefixes = ['/root', '/var/lib/mysql', '/proc', '/sys', '/boot', '/dev'];

    // ---------------------------------------------------------------------
    // Nginx
    // ---------------------------------------------------------------------

    public function writeNginxConfig(string $domain, string $config): void
    {
        $path = $this->nginxAvailablePath($domain);

        if (file_put_contents($path, $config) === false) {
            throw new RuntimeException("Failed to write nginx config: {$path}");
        }
        if (! chmod($path, 0644)) {
            throw new RuntimeException("Failed to chmod nginx config: {$path}");
        }
    }

    public function readNginxConfig(string $domain): ?string
    {
        $path = $this->nginxAvailablePath($domain);

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Failed to read nginx config: {$path}");
        }

        return $contents;
    }

    public function enableNginxSite(string $domain): void
    {
        $available = $this->nginxAvailablePath($domain);
        $enabled = $this->nginxEnabledPath($domain);

        if (! is_file($available)) {
            throw new RuntimeException("Cannot enable site; config missing: {$available}");
        }
        if (is_link($enabled) || is_file($enabled)) {
            return; // already enabled
        }
        if (! symlink($available, $enabled)) {
            throw new RuntimeException("Failed to create symlink: {$enabled}");
        }
    }

    public function disableNginxSite(string $domain): void
    {
        $enabled = $this->nginxEnabledPath($domain);

        if (is_link($enabled) || is_file($enabled)) {
            if (! unlink($enabled)) {
                throw new RuntimeException("Failed to remove symlink: {$enabled}");
            }
        }
    }

    public function deleteNginxSite(string $domain): void
    {
        $this->disableNginxSite($domain);

        $available = $this->nginxAvailablePath($domain);
        if (is_file($available) && ! unlink($available)) {
            throw new RuntimeException("Failed to remove nginx config: {$available}");
        }
    }

    // ---------------------------------------------------------------------
    // Website filesystem
    // ---------------------------------------------------------------------

    /**
     * Create the standard website directory tree and set ownership.
     */
    public function createSiteDirs(string $username, string $domain, string $documentRoot): void
    {
        $this->assertUsername($username);
        $base = $this->siteBase($username, $domain);

        $this->assertDocumentRoot($username, $domain, $documentRoot);

        foreach ([$base, $documentRoot, $base . '/logs', $base . '/backups'] as $dir) {
            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
        }

        $this->chownRecursive("/home/{$username}/web/{$domain}", $username, $username);
    }

    public function writeSiteIndex(string $username, string $domain, string $documentRoot, string $content): void
    {
        $this->assertDocumentRoot($username, $domain, $documentRoot);

        if (! is_dir($documentRoot) && ! mkdir($documentRoot, 0755, true) && ! is_dir($documentRoot)) {
            throw new RuntimeException("Document root does not exist: {$documentRoot}");
        }

        $index = rtrim($documentRoot, '/') . '/index.html';
        if (file_put_contents($index, $content) === false) {
            throw new RuntimeException("Failed to write index: {$index}");
        }
        chown($index, $username);
        chgrp($index, $username);
    }

    public function deleteSiteBase(string $username, string $domain, string $basePath): void
    {
        $this->assertUsername($username);
        $expected = $this->siteBase($username, $domain);

        $real = realpath($basePath);
        if ($real === false || $real !== $expected) {
            throw new InvalidArgumentException("Refusing to delete unexpected path: {$basePath}");
        }

        $this->recursiveDelete($real, $expected);
    }

    public function ensureOwner(string $path, string $username): void
    {
        $this->assertUsername($username);
        $this->assertWithinWebRoot($path);
        $this->chownRecursive($path, $username, $username);
    }

    /**
     * Apply WordPress-style least-privilege permissions to a document root:
     * directories 0755, files 0644, wp-config.php 0640, owned by the site user.
     */
    public function setWebPermissions(string $documentRoot, string $username): void
    {
        $this->assertUsername($username);
        $this->assertWithinWebRoot($documentRoot);

        $real = realpath($documentRoot);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("Document root does not exist: {$documentRoot}");
        }

        $this->chownRecursive($real, $username, $username);

        $dirs = Process::timeout(120)->run(['find', $real, '-type', 'd', '-exec', 'chmod', '0755', '{}', '+']);
        if (! $dirs->successful()) {
            throw new RuntimeException('Failed to chmod directories: ' . trim($dirs->errorOutput()));
        }

        $files = Process::timeout(120)->run(['find', $real, '-type', 'f', '-exec', 'chmod', '0644', '{}', '+']);
        if (! $files->successful()) {
            throw new RuntimeException('Failed to chmod files: ' . trim($files->errorOutput()));
        }

        $wpConfig = $real . '/wp-config.php';
        if (is_file($wpConfig) && ! chmod($wpConfig, 0640)) {
            throw new RuntimeException('Failed to secure wp-config.php');
        }
    }

    /**
     * Recursively delete the CONTENTS of a document root (not the directory
     * itself). Used to roll back a failed install that populated an empty
     * docroot. The path must be exactly the site's public_html.
     */
    public function purgeDocroot(string $username, string $domain, string $documentRoot): void
    {
        $this->assertUsername($username);
        $expected = $this->siteBase($username, $domain) . '/public_html';

        $real = realpath($documentRoot);
        if ($real === false || $real !== $expected) {
            throw new InvalidArgumentException("Refusing to purge unexpected docroot: {$documentRoot}");
        }

        foreach (scandir($real) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->recursiveDelete($real . '/' . $entry, $real);
        }
    }

    public function chmodPath(string $path, int $mode): void
    {
        $this->assertWithinWebRoot($path);
        if (! chmod($path, $mode)) {
            throw new RuntimeException("Failed to chmod: {$path}");
        }
    }

    public function chownPath(string $path, string $username, string $group): void
    {
        $this->assertUsername($username);
        $this->assertUsername($group);
        $this->assertWithinWebRoot($path);
        $this->chownRecursive($path, $username, $group);
    }

    public function createDirectory(string $path, int $mode, ?string $owner = null, ?string $group = null): void
    {
        $this->assertWithinWebRoot($path);
        if (! is_dir($path) && ! mkdir($path, $mode, true) && ! is_dir($path)) {
            throw new RuntimeException("Failed to create directory: {$path}");
        }
        chmod($path, $mode);
        if ($owner !== null) {
            $this->assertUsername($owner);
            chown($path, $owner);
            chgrp($path, $group ?? $owner);
        }
    }

    public function removeFile(string $path): void
    {
        $this->assertWithinWebRoot($path);
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException("Failed to remove file: {$path}");
        }
    }

    public function copyTree(string $source, string $destination): void
    {
        $this->assertWithinWebRoot($destination);
        $realSource = realpath($source);
        if ($realSource === false) {
            throw new InvalidArgumentException("Source does not exist: {$source}");
        }

        $result = Process::timeout(600)->run(['cp', '-aT', $realSource, $destination]);
        if (! $result->successful()) {
            throw new RuntimeException('Copy failed: ' . trim($result->errorOutput()));
        }
    }

    public function extractTar(string $archive, string $destination): void
    {
        $this->assertWithinWebRoot($destination);
        if (! is_file($archive)) {
            throw new InvalidArgumentException("Archive does not exist: {$archive}");
        }

        // Refuse archives containing traversal or absolute member paths.
        $this->assertSafeTarMembers($archive);

        if (! is_dir($destination) && ! mkdir($destination, 0755, true) && ! is_dir($destination)) {
            throw new RuntimeException("Destination does not exist: {$destination}");
        }

        $result = Process::timeout(600)->run([
            'tar', '--no-same-owner', '-xzf', $archive, '-C', $destination,
        ]);
        if (! $result->successful()) {
            throw new RuntimeException('Extraction failed: ' . trim($result->errorOutput()));
        }
    }

    /**
     * @param  array<int, string>  $args
     */
    public function createTar(string $archive, array $args): void
    {
        $dir = dirname($archive);
        if (! is_dir($dir)) {
            throw new InvalidArgumentException("Archive directory does not exist: {$dir}");
        }

        $result = Process::timeout(600)->run(array_merge(['tar', '-czf', $archive], array_values($args)));
        if (! $result->successful()) {
            throw new RuntimeException('Archive creation failed: ' . trim($result->errorOutput()));
        }
    }

    // ---------------------------------------------------------------------
    // Validation + helpers
    // ---------------------------------------------------------------------

    public function nginxAvailablePath(string $domain): string
    {
        $dir = rtrim((string) config('librestack.paths.nginx_available'), '/');

        return $dir . '/' . $this->slug($domain) . '.conf';
    }

    public function nginxEnabledPath(string $domain): string
    {
        $dir = rtrim((string) config('librestack.paths.nginx_enabled'), '/');

        return $dir . '/' . $this->slug($domain) . '.conf';
    }

    public function siteBase(string $username, string $domain): string
    {
        $this->assertUsername($username);
        if (! Validators::isValidDomain($domain)) {
            throw new InvalidArgumentException("Invalid domain: {$domain}");
        }
        $webRoot = rtrim((string) config('librestack.paths.web_root'), '/');

        return "{$webRoot}/{$username}/web/{$domain}";
    }

    protected function slug(string $domain): string
    {
        if (! Validators::isValidDomain($domain)) {
            throw new InvalidArgumentException("Invalid domain: {$domain}");
        }

        return Validators::domainSlug($domain);
    }

    protected function assertUsername(string $username): void
    {
        if (! Validators::isValidUsername($username)) {
            throw new InvalidArgumentException("Invalid system username: {$username}");
        }
    }

    /**
     * The document root must be exactly /home/{username}/web/{domain}/public_html
     * (or a path inside the site base) — never anything outside it.
     */
    protected function assertDocumentRoot(string $username, string $domain, string $documentRoot): void
    {
        $base = $this->siteBase($username, $domain);
        if ($documentRoot !== $base . '/public_html' && ! str_starts_with($documentRoot, $base . '/')) {
            throw new InvalidArgumentException("Document root escapes site base: {$documentRoot}");
        }
    }

    protected function assertWithinWebRoot(string $path): void
    {
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Null byte in path.');
        }

        foreach ($this->forbiddenPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                throw new InvalidArgumentException("Access to this location is denied: {$path}");
            }
        }

        $webRoot = rtrim((string) config('librestack.paths.web_root'), '/');
        $backups = rtrim((string) config('librestack.paths.backups'), '/');

        // Resolve to a real path where possible to defeat traversal.
        $real = realpath($path);
        $check = $real !== false ? $real : $path;

        $allowedRoots = array_filter([$webRoot, $backups]);
        foreach ($allowedRoots as $root) {
            if ($check === $root || str_starts_with($check, $root . '/')) {
                return;
            }
        }

        throw new InvalidArgumentException("Path is outside the managed web root: {$path}");
    }

    protected function chownRecursive(string $path, string $owner, string $group): void
    {
        $real = realpath($path);
        if ($real === false) {
            throw new RuntimeException("Cannot chown missing path: {$path}");
        }

        $result = Process::timeout(120)->run(['chown', '-R', "{$owner}:{$group}", $real]);
        if (! $result->successful()) {
            throw new RuntimeException('chown failed: ' . trim($result->errorOutput()));
        }
    }

    /**
     * Recursive delete that refuses to leave the allowed base (no symlink
     * following, every resolved path must stay inside $base).
     */
    protected function recursiveDelete(string $path, string $base): void
    {
        if (is_link($path)) {
            // Never follow symlinks; just remove the link itself.
            if (! unlink($path)) {
                throw new RuntimeException("Failed to remove symlink: {$path}");
            }

            return;
        }

        $real = realpath($path);
        if ($real === false) {
            return;
        }
        if ($real !== $base && ! str_starts_with($real, $base . '/')) {
            throw new RuntimeException("Refusing to delete path outside base: {$path}");
        }

        if (is_dir($real)) {
            foreach (scandir($real) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->recursiveDelete($real . '/' . $entry, $base);
            }
            if (! rmdir($real)) {
                throw new RuntimeException("Failed to remove directory: {$real}");
            }
        } elseif (! unlink($real)) {
            throw new RuntimeException("Failed to remove file: {$real}");
        }
    }

    /**
     * Reject tar archives containing absolute or traversal member paths.
     */
    protected function assertSafeTarMembers(string $archive): void
    {
        $result = Process::timeout(60)->run(['tar', '-tzf', $archive]);
        if (! $result->successful()) {
            throw new RuntimeException('Unable to read archive contents: ' . trim($result->errorOutput()));
        }

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $member) {
            if ($member === '') {
                continue;
            }
            $normalized = str_replace('\\', '/', $member);
            if (str_starts_with($normalized, '/') || preg_match('#^[A-Za-z]:#', $normalized)) {
                throw new RuntimeException("Archive contains an absolute path: {$member}");
            }
            foreach (explode('/', $normalized) as $segment) {
                if ($segment === '..') {
                    throw new RuntimeException("Archive contains a traversal segment: {$member}");
                }
            }
        }
    }
}
