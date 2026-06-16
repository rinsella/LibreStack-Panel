<?php

namespace App\Services\System;

use App\Support\Validators;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

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
    // File manager (per-site files, owned by the site user)
    // ---------------------------------------------------------------------
    //
    // Every method below operates on a path RELATIVE to the website base
    // /home/{username}/web/{domain}. Relative paths are strictly validated
    // (no absolute paths, no traversal, no null bytes, no Windows drive paths)
    // and the resolved target must stay inside the base. New files/dirs are
    // owned by {username}:{username} when this runs as root.

    public function fileWrite(string $username, string $domain, string $relative, string $content): void
    {
        [, $target] = $this->resolveSiteRelative($username, $domain, $relative, false);

        if (file_put_contents($target, $content) === false) {
            throw new RuntimeException("Failed to write file: {$relative}");
        }
        if (! chmod($target, 0644)) {
            throw new RuntimeException("Failed to set permissions on: {$relative}");
        }
        $this->chownSite($target, $username);
    }

    public function fileUpload(string $username, string $domain, string $relative, string $sourceTmp): void
    {
        $this->assertUploadSource($sourceTmp);
        [, $target] = $this->resolveSiteRelative($username, $domain, $relative, false);

        if (! copy($sourceTmp, $target)) {
            throw new RuntimeException("Failed to store upload: {$relative}");
        }
        if (! chmod($target, 0644)) {
            throw new RuntimeException("Failed to set permissions on: {$relative}");
        }
        $this->chownSite($target, $username);
    }

    public function fileCreate(string $username, string $domain, string $relative): void
    {
        [, $target] = $this->resolveSiteRelative($username, $domain, $relative, false);

        if (file_exists($target)) {
            return;
        }
        if (! touch($target)) {
            throw new RuntimeException("Failed to create file: {$relative}");
        }
        if (! chmod($target, 0644)) {
            throw new RuntimeException("Failed to set permissions on: {$relative}");
        }
        $this->chownSite($target, $username);
    }

    public function dirCreate(string $username, string $domain, string $relative): void
    {
        [, $target] = $this->resolveSiteRelative($username, $domain, $relative, false);

        if (is_dir($target)) {
            return;
        }
        if (! mkdir($target, 0755, true) && ! is_dir($target)) {
            throw new RuntimeException("Failed to create directory: {$relative}");
        }
        $this->chownSite($target, $username);
    }

    public function fileRename(string $username, string $domain, string $from, string $to): void
    {
        [, $src] = $this->resolveSiteRelative($username, $domain, $from, true);
        [, $dst] = $this->resolveSiteRelative($username, $domain, $to, false);

        if (! rename($src, $dst)) {
            throw new RuntimeException('Failed to rename.');
        }
        $this->chownSite($dst, $username);
    }

    public function fileMove(string $username, string $domain, string $from, string $to): void
    {
        $this->fileRename($username, $domain, $from, $to);
    }

    public function fileDelete(string $username, string $domain, string $relative): void
    {
        [$base, $target] = $this->resolveSiteRelative($username, $domain, $relative, true);
        $this->recursiveDelete($target, $base);
    }

    public function fileCopy(string $username, string $domain, string $from, string $to): void
    {
        [$base, $src] = $this->resolveSiteRelative($username, $domain, $from, true);
        [, $dst] = $this->resolveSiteRelative($username, $domain, $to, false);

        if (is_link($src)) {
            $this->assertLinkInside($src, $base);
        }

        if (is_dir($src) && ! is_link($src)) {
            $this->recursiveCopyChecked($src, $dst, $base);
        } elseif (! copy($src, $dst)) {
            throw new RuntimeException('Failed to copy.');
        }
        $this->chownSite($dst, $username);
    }

    public function fileChmod(string $username, string $domain, string $relative, int $mode): void
    {
        $this->assertSafeChmodMode($mode);
        [, $target] = $this->resolveSiteRelative($username, $domain, $relative, true);

        if (! chmod($target, $mode)) {
            throw new RuntimeException("Failed to chmod: {$relative}");
        }
    }

    public function fileZip(string $username, string $domain, string $sourceRel, string $zipRel): void
    {
        [, $source] = $this->resolveSiteRelative($username, $domain, $sourceRel, true);
        [, $target] = $this->resolveSiteRelative($username, $domain, $zipRel, false);

        $this->createZip($source, $target);

        if (is_file($target)) {
            if (! chmod($target, 0644)) {
                throw new RuntimeException('Failed to set permissions on the archive.');
            }
            $this->chownSite($target, $username);
        }
    }

    public function fileUnzip(string $username, string $domain, string $zipRel, string $destRel): void
    {
        [, $archive] = $this->resolveSiteRelative($username, $domain, $zipRel, true);
        [, $dest] = $this->resolveSiteRelative($username, $domain, $destRel, false);

        $this->extractZip($archive, $dest);
        $this->chownSite($dest, $username);
    }

    // ---------------------------------------------------------------------
    // PHP-FPM per-user pools
    // ---------------------------------------------------------------------
    //
    // PHP/WordPress sites run under a dedicated PHP-FPM pool that executes as the
    // site's Linux user, so WordPress can write its own files (media, plugins,
    // updates). Pool files may only live at the fixed managed path.

    public function phpFpmPoolWrite(string $username, string $phpVersion, string $poolConfig): void
    {
        $path = $this->phpFpmPoolPath($username, $phpVersion);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            throw new RuntimeException("PHP-FPM pool directory does not exist: {$dir}");
        }
        if (file_put_contents($path, $poolConfig) === false) {
            throw new RuntimeException("Failed to write PHP-FPM pool: {$path}");
        }
        if (! chmod($path, 0644)) {
            throw new RuntimeException("Failed to set permissions on PHP-FPM pool: {$path}");
        }
    }

    public function phpFpmPoolDelete(string $username, string $phpVersion): void
    {
        $path = $this->phpFpmPoolPath($username, $phpVersion);

        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException("Failed to remove PHP-FPM pool: {$path}");
        }
    }

    public function phpFpmTest(string $phpVersion): void
    {
        $this->assertPhpVersion($phpVersion);

        $binary = "/usr/sbin/php-fpm{$phpVersion}";
        if (! is_executable($binary)) {
            throw new RuntimeException("PHP-FPM binary not found: {$binary}");
        }

        $result = Process::timeout(30)->run([$binary, '-t']);
        if (! $result->successful()) {
            throw new RuntimeException('PHP-FPM config test failed: ' . trim($result->errorOutput() ?: $result->output()));
        }
    }

    public function phpFpmReload(string $phpVersion): void
    {
        $this->assertPhpVersion($phpVersion);

        $result = Process::timeout(30)->run(['systemctl', 'reload', "php{$phpVersion}-fpm"]);
        if (! $result->successful()) {
            throw new RuntimeException('PHP-FPM reload failed: ' . trim($result->errorOutput()));
        }
    }

    /**
     * The pool file path is fixed: only librestack-{username}.conf inside the
     * version's pool.d directory is ever allowed.
     */
    public function phpFpmPoolPath(string $username, string $phpVersion): string
    {
        $this->assertUsername($username);
        $this->assertPhpVersion($phpVersion);

        return "/etc/php/{$phpVersion}/fpm/pool.d/librestack-{$username}.conf";
    }

    protected function assertPhpVersion(string $version): void
    {
        if (! preg_match('/^\d+\.\d+$/', $version)
            || ! in_array($version, (array) config('librestack.php_versions'), true)) {
            throw new InvalidArgumentException("Invalid PHP version: {$version}");
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

    // ---------------------------------------------------------------------
    // File-manager helpers
    // ---------------------------------------------------------------------

    /**
     * Resolve a website-relative path to an absolute one that is guaranteed to
     * stay inside /home/{username}/web/{domain}. Returns [realBase, target].
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveSiteRelative(string $username, string $domain, string $relative, bool $mustExist): array
    {
        $base = $this->siteBase($username, $domain); // validates username + domain
        $this->assertSafeRelative($relative);

        $realBase = realpath($base);
        if ($realBase === false) {
            throw new RuntimeException("Website base does not exist: {$base}");
        }

        $normalized = ltrim(str_replace('\\', '/', $relative), '/');
        $target = $normalized === '' ? $realBase : $realBase . '/' . $normalized;
        $real = realpath($target);

        if ($real === false) {
            if ($mustExist) {
                throw new RuntimeException("Path does not exist: {$relative}");
            }
            $parent = realpath(dirname($target));
            if ($parent === false) {
                throw new RuntimeException('Parent directory does not exist.');
            }
            $this->assertInside($parent, $realBase);

            return [$realBase, $parent . '/' . basename($target)];
        }

        $this->assertInside($real, $realBase);

        return [$realBase, $real];
    }

    protected function assertInside(string $path, string $base): void
    {
        if ($path !== $base && ! str_starts_with($path, $base . '/')) {
            throw new RuntimeException('Path escapes the website directory.');
        }
    }

    /**
     * Reject absolute paths, traversal segments, null bytes and Windows drive
     * paths in a website-relative path.
     */
    protected function assertSafeRelative(string $relative): void
    {
        if (str_contains($relative, "\0")) {
            throw new InvalidArgumentException('Null byte in path.');
        }

        $normalized = str_replace('\\', '/', $relative);

        if ($normalized !== '' && str_starts_with($normalized, '/')) {
            throw new InvalidArgumentException("Absolute paths are not allowed: {$relative}");
        }
        if (preg_match('#^[A-Za-z]:#', $normalized)) {
            throw new InvalidArgumentException("Windows drive paths are not allowed: {$relative}");
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException("Path traversal is not allowed: {$relative}");
            }
        }
    }

    /**
     * Only a small allowlist of chmod modes is permitted from the file manager.
     */
    protected function assertSafeChmodMode(int $mode): void
    {
        $allowed = [0644, 0640, 0755, 0750, 0775];
        if (! in_array($mode, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('chmod mode 0%o is not allowed.', $mode));
        }
    }

    /**
     * Chown a path to the site user. Only meaningful when running as root (the
     * sudo safe-op helper); when running in-process as the runtime user the
     * tree is already owned correctly, so this is intentionally skipped.
     */
    protected function chownSite(string $path, string $username): void
    {
        $this->assertUsername($username);

        if (function_exists('posix_getuid') && posix_getuid() !== 0) {
            return;
        }
        $this->chownRecursive($path, $username, $username);
    }

    /**
     * A symlink encountered during copy must resolve inside the website base.
     */
    protected function assertLinkInside(string $link, string $base): void
    {
        $target = realpath($link);
        if ($target === false || ($target !== $base && ! str_starts_with($target, $base . '/'))) {
            throw new RuntimeException('Refusing to copy a symlink that escapes the website directory.');
        }
    }

    protected function recursiveCopyChecked(string $src, string $dst, string $base): void
    {
        if (! mkdir($dst, 0755, true) && ! is_dir($dst)) {
            throw new RuntimeException("Failed to create directory: {$dst}");
        }

        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $src . '/' . $entry;
            $to = $dst . '/' . $entry;

            if (is_link($from)) {
                $this->assertLinkInside($from, $base);
            }

            if (is_dir($from) && ! is_link($from)) {
                $this->recursiveCopyChecked($from, $to, $base);
            } elseif (! copy($from, $to)) {
                throw new RuntimeException("Failed to copy: {$from}");
            }
        }
    }

    /**
     * The upload source must be an existing readable file inside the system
     * temp dir or the application's storage path — never an arbitrary location.
     */
    protected function assertUploadSource(string $path): void
    {
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Null byte in upload source.');
        }

        $real = realpath($path);
        if ($real === false || ! is_file($real) || ! is_readable($real)) {
            throw new InvalidArgumentException('Upload source is not a readable file.');
        }

        $allowed = array_filter([
            rtrim(sys_get_temp_dir(), '/'),
            rtrim((string) (function_exists('storage_path') ? storage_path() : ''), '/'),
        ]);

        foreach ($allowed as $root) {
            if ($real === $root || str_starts_with($real, $root . '/')) {
                return;
            }
        }

        throw new InvalidArgumentException('Upload source is outside the allowed temp area.');
    }

    protected function createZip(string $source, string $target): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is not available.');
        }

        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create zip archive.');
        }

        if (is_dir($source) && ! is_link($source)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if ($file->isLink()) {
                    continue; // never archive symlinks
                }
                $local = substr($file->getPathname(), strlen($source) + 1);
                $zip->addFile($file->getPathname(), $local);
            }
        } else {
            $zip->addFile($source, basename($source));
        }

        $zip->close();
    }

    protected function extractZip(string $archive, string $dest): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is not available.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Cannot open zip archive.');
        }

        if (! is_dir($dest) && ! mkdir($dest, 0755, true) && ! is_dir($dest)) {
            $zip->close();
            throw new RuntimeException('Failed to create the extraction directory.');
        }

        $realDest = realpath($dest);
        if ($realDest === false) {
            $zip->close();
            throw new RuntimeException('Destination directory could not be resolved.');
        }

        // Zip-bomb defence: cap entry count and total uncompressed size.
        $maxFiles = 10000;
        $maxBytes = 512 * 1024 * 1024;

        if ($zip->numFiles > $maxFiles) {
            $zip->close();
            throw new RuntimeException("Archive has too many entries ({$zip->numFiles} > {$maxFiles}).");
        }

        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();
                throw new RuntimeException('Unreadable zip entry.');
            }

            $totalUncompressed += (int) ($stat['size'] ?? 0);
            if ($totalUncompressed > $maxBytes) {
                $zip->close();
                throw new RuntimeException('Archive uncompressed size exceeds the allowed limit.');
            }

            $this->assertSafeZipEntry((string) $stat['name'], $realDest);
        }

        if (! $zip->extractTo($realDest)) {
            $zip->close();
            throw new RuntimeException('Failed to extract archive.');
        }
        $zip->close();
    }

    /**
     * Reject dangerous zip entries (traversal, absolute paths, drive paths,
     * null bytes) and ensure the resolved destination stays inside $realDest.
     */
    protected function assertSafeZipEntry(string $entry, string $realDest): void
    {
        if ($entry === '' || str_contains($entry, "\0")) {
            throw new RuntimeException('Zip entry contains a null byte or is empty.');
        }

        $normalized = str_replace('\\', '/', $entry);

        if (str_starts_with($normalized, '/') || preg_match('#^[A-Za-z]:#', $normalized)) {
            throw new RuntimeException("Zip entry uses an absolute path: {$entry}");
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new RuntimeException("Zip entry contains a traversal segment: {$entry}");
            }
        }

        $target = $realDest . '/' . ltrim($normalized, '/');
        $check = $target;
        $targetReal = realpath(dirname($target));
        while ($targetReal === false && $check !== '' && $check !== '/' && $check !== '.') {
            $check = dirname($check);
            $targetReal = realpath($check);
        }

        if ($targetReal === false || ! ($targetReal === $realDest || str_starts_with($targetReal . '/', $realDest . '/'))) {
            throw new RuntimeException("Zip entry escapes the destination: {$entry}");
        }
    }
}
