<?php

namespace App\Services\FileManager;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Secure file manager. Every path is resolved with realpath and checked to be
 * inside an allowed base directory, defeating path traversal (../) attacks.
 * System-sensitive locations are always denied.
 */
class FileManagerService
{
    /** Paths that must never be accessible regardless of base. */
    protected array $denied = [
        '/etc', '/root', '/var/lib/mysql', '/proc', '/sys', '/boot', '/dev',
    ];

    /**
     * Resolve a relative path against a base directory, enforcing that the
     * result stays inside the base and outside denied locations.
     */
    public function resolve(string $base, string $relative = ''): string
    {
        if (str_contains($relative, "\0") || str_contains($base, "\0")) {
            throw new InvalidArgumentException('Null byte in path.');
        }

        $realBase = realpath($base);
        if ($realBase === false) {
            throw new RuntimeException("Base path does not exist: {$base}");
        }

        $this->assertNotDenied($realBase);

        $target = $realBase . '/' . ltrim($relative, '/');
        $real = realpath($target);

        if ($real === false) {
            // Path may not exist yet (create operations): validate its parent.
            $parent = realpath(dirname($target));
            if ($parent === false) {
                throw new RuntimeException('Parent directory does not exist.');
            }
            $this->assertWithin($parent, $realBase);
            $this->assertNotDenied($parent);

            return $parent . '/' . basename($target);
        }

        $this->assertWithin($real, $realBase);
        $this->assertNotDenied($real);

        return $real;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(string $base, string $relative = ''): array
    {
        $dir = $this->resolve($base, $relative);

        if (! is_dir($dir)) {
            throw new RuntimeException('Not a directory.');
        }

        $items = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            $items[] = [
                'name'        => $entry,
                'is_dir'      => is_dir($full),
                'size'        => is_file($full) ? filesize($full) : null,
                'permissions' => substr(sprintf('%o', fileperms($full)), -4),
                'modified'    => date('Y-m-d H:i', filemtime($full)),
            ];
        }

        usort($items, fn ($a, $b) => [$b['is_dir'], $a['name']] <=> [$a['is_dir'], $b['name']]);

        return $items;
    }

    public function read(string $base, string $relative): string
    {
        $path = $this->resolve($base, $relative);
        if (! is_file($path)) {
            throw new RuntimeException('Not a file.');
        }
        if (filesize($path) > 2 * 1024 * 1024) {
            throw new RuntimeException('File too large to edit (2MB limit).');
        }

        return (string) file_get_contents($path);
    }

    public function write(string $base, string $relative, string $content): void
    {
        $path = $this->resolve($base, $relative);
        file_put_contents($path, $content);
    }

    public function makeDirectory(string $base, string $relative): void
    {
        $path = $this->resolve($base, $relative);
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    public function createFile(string $base, string $relative): void
    {
        $path = $this->resolve($base, $relative);
        if (! file_exists($path)) {
            touch($path);
        }
    }

    public function delete(string $base, string $relative): void
    {
        $path = $this->resolve($base, $relative);
        $this->recursiveDelete($path);
    }

    public function rename(string $base, string $from, string $to): void
    {
        $src = $this->resolve($base, $from);
        $dst = $this->resolve($base, $to);
        rename($src, $dst);
    }

    public function copy(string $base, string $from, string $to): void
    {
        $src = $this->resolve($base, $from);
        $dst = $this->resolve($base, $to);

        if (is_dir($src)) {
            $this->recursiveCopy($src, $dst);
        } else {
            copy($src, $dst);
        }
    }

    public function chmod(string $base, string $relative, int $mode): void
    {
        $path = $this->resolve($base, $relative);
        // Accept octal value such as 0644.
        chmod($path, $mode);
    }

    public function zip(string $base, string $relative, string $zipRelative): void
    {
        $source = $this->resolve($base, $relative);
        $target = $this->resolve($base, $zipRelative);

        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create zip archive.');
        }

        if (is_dir($source)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                $local = substr($file->getPathname(), strlen($source) + 1);
                $zip->addFile($file->getPathname(), $local);
            }
        } else {
            $zip->addFile($source, basename($source));
        }
        $zip->close();
    }

    public function unzip(string $base, string $relative, string $destRelative): void
    {
        $archive = $this->resolve($base, $relative);
        $dest = $this->resolve($base, $destRelative);

        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Cannot open zip archive.');
        }

        if (! is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $realDest = realpath($dest);
        if ($realDest === false) {
            $zip->close();
            throw new RuntimeException('Destination directory could not be resolved.');
        }
        // The destination must itself stay inside the website base directory.
        $this->assertNotDenied($realDest);

        // ZIP-slip defence: validate EVERY entry before extracting anything.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                $zip->close();
                throw new RuntimeException('Unreadable zip entry.');
            }
            $this->assertSafeZipEntry($entry, $realDest);
        }

        if (! $zip->extractTo($realDest)) {
            $zip->close();
            throw new RuntimeException('Failed to extract archive.');
        }
        $zip->close();
    }

    /**
     * Reject dangerous zip entries (path traversal, absolute paths, drive paths,
     * null bytes) and ensure the resolved destination stays inside $realDest.
     */
    protected function assertSafeZipEntry(string $entry, string $realDest): void
    {
        if ($entry === '' || str_contains($entry, "\0")) {
            throw new RuntimeException('Zip entry contains a null byte or is empty.');
        }

        // Normalise Windows separators for inspection.
        $normalized = str_replace('\\', '/', $entry);

        // Absolute POSIX path or Windows drive path (e.g. C:\ or C:/).
        if (str_starts_with($normalized, '/') || preg_match('#^[A-Za-z]:#', $normalized)) {
            throw new RuntimeException("Zip entry uses an absolute path: {$entry}");
        }

        // Any traversal segment.
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new RuntimeException("Zip entry contains a traversal segment: {$entry}");
            }
        }

        // Final containment check on the resolved target path.
        $target = $realDest . '/' . ltrim($normalized, '/');
        $targetReal = realpath(dirname($target));
        // dirname may not exist yet for nested entries; walk up to an existing parent.
        $check = $target;
        while ($targetReal === false && $check !== '' && $check !== '/' && $check !== '.') {
            $check = dirname($check);
            $targetReal = realpath($check);
        }

        if ($targetReal === false || ! ($targetReal === $realDest || str_starts_with($targetReal . '/', $realDest . '/'))) {
            throw new RuntimeException("Zip entry escapes the destination: {$entry}");
        }
    }

    protected function recursiveDelete(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->recursiveDelete($path . '/' . $entry);
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }

    protected function recursiveCopy(string $src, string $dst): void
    {
        mkdir($dst, 0755, true);
        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $src . '/' . $entry;
            $to = $dst . '/' . $entry;
            is_dir($from) ? $this->recursiveCopy($from, $to) : copy($from, $to);
        }
    }

    protected function assertWithin(string $path, string $base): void
    {
        if ($path !== $base && ! str_starts_with($path, $base . '/')) {
            throw new RuntimeException('Path is outside of the allowed directory.');
        }
    }

    protected function assertNotDenied(string $path): void
    {
        foreach ($this->denied as $denied) {
            if ($path === $denied || str_starts_with($path, $denied . '/')) {
                throw new RuntimeException('Access to this location is denied.');
            }
        }
    }
}
