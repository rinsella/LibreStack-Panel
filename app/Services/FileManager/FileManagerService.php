<?php

namespace App\Services\FileManager;

use App\Models\Website;
use App\Services\Support\CommandResult;
use App\Services\System\PrivilegedFs;
use InvalidArgumentException;
use RuntimeException;

/**
 * Secure file manager.
 *
 * Read-only operations (browse/list/read/resolve) run directly because the web
 * process can always read the website tree. Every WRITE/mutation operation is
 * routed through the privileged safe-op layer (PrivilegedFs → SafeOps) so it
 * works on a real VPS where the website files are owned by the site's Linux
 * user rather than www-data. Path traversal, symlink escapes, zip-slip and
 * zip-bombs are all rejected inside SafeOps.
 */
class FileManagerService
{
    /** Paths that must never be accessible regardless of base. */
    protected array $denied = [
        '/etc', '/root', '/var/lib/mysql', '/proc', '/sys', '/boot', '/dev', '/opt/librestack',
    ];

    public function __construct(protected PrivilegedFs $fs)
    {
    }

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

        $content = (string) file_get_contents($path);

        if ($this->looksBinary($content)) {
            throw new RuntimeException('Refusing to edit a binary file.');
        }

        return $content;
    }

    /**
     * Heuristic binary detection: any NUL byte, or a high proportion of
     * non-text control bytes, means we refuse to open it in the text editor.
     */
    protected function looksBinary(string $content): bool
    {
        if ($content === '') {
            return false;
        }
        if (str_contains($content, "\0")) {
            return true;
        }

        $sample = substr($content, 0, 8000);
        $nonText = preg_replace('/[\x09\x0A\x0D\x20-\x7E\x80-\xFF]/', '', $sample);

        return $nonText !== null && strlen($nonText) > strlen($sample) * 0.1;
    }

    // ---------------------------------------------------------------------
    // Write operations — all routed through the privileged safe-op layer so
    // they succeed on a real VPS where the files are owned by the site user.
    // ---------------------------------------------------------------------

    public function write(Website $website, string $relative, string $content): void
    {
        $this->run($this->fs->fileWrite($website->system_username, $website->domain, $relative, $content));
    }

    public function upload(Website $website, string $relative, string $sourceTmp): void
    {
        $this->run($this->fs->fileUpload($website->system_username, $website->domain, $relative, $sourceTmp));
    }

    public function makeDirectory(Website $website, string $relative): void
    {
        $this->run($this->fs->dirCreate($website->system_username, $website->domain, $relative));
    }

    public function createFile(Website $website, string $relative): void
    {
        $this->run($this->fs->fileCreate($website->system_username, $website->domain, $relative));
    }

    public function delete(Website $website, string $relative): void
    {
        $this->run($this->fs->fileDelete($website->system_username, $website->domain, $relative));
    }

    public function rename(Website $website, string $from, string $to): void
    {
        $this->run($this->fs->fileRename($website->system_username, $website->domain, $from, $to));
    }

    public function move(Website $website, string $from, string $to): void
    {
        $this->run($this->fs->fileMove($website->system_username, $website->domain, $from, $to));
    }

    public function copy(Website $website, string $from, string $to): void
    {
        $this->run($this->fs->fileCopy($website->system_username, $website->domain, $from, $to));
    }

    public function chmod(Website $website, string $relative, string $octalMode): void
    {
        $this->run($this->fs->fileChmod($website->system_username, $website->domain, $relative, $octalMode));
    }

    public function zip(Website $website, string $sourceRelative, string $zipRelative): void
    {
        $this->run($this->fs->fileZip($website->system_username, $website->domain, $sourceRelative, $zipRelative));
    }

    public function unzip(Website $website, string $zipRelative, string $destRelative): void
    {
        $this->run($this->fs->fileUnzip($website->system_username, $website->domain, $zipRelative, $destRelative));
    }

    /**
     * Surface a failed privileged operation as a clear exception so the UI shows
     * an error instead of pretending the write succeeded.
     */
    protected function run(CommandResult $result): void
    {
        if (! $result->ok && ! $result->disabled) {
            throw new RuntimeException($result->error !== '' ? $result->error : 'File operation failed.');
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
