<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\PanelDatabase;
use App\Models\Website;
use App\Services\Database\DatabaseService;
use App\Services\Support\CommandRunner;
use App\Support\Validators;
use InvalidArgumentException;
use RuntimeException;

/**
 * Creates and restores backups of website files and/or databases.
 * Archives are stored under the configured backup path, namespaced per domain.
 */
class BackupService
{
    public function __construct(
        protected CommandRunner $runner,
        protected DatabaseService $databases,
    ) {
    }

    public function backupRoot(string $domain): string
    {
        if (! Validators::isValidDomain($domain)) {
            throw new InvalidArgumentException('Invalid domain.');
        }

        $root = rtrim((string) config('librestack.paths.backups'), '/')
            . '/' . Validators::domainSlug($domain);

        if (! is_dir($root)) {
            @mkdir($root, 0750, true);
        }

        return $root;
    }

    /**
     * Create a backup record and perform the archive operation.
     */
    public function create(Website $website, string $type = 'full', ?int $createdBy = null): Backup
    {
        $timestamp = now()->format('Ymd_His');
        $root = $this->backupRoot($website->domain);
        $filename = "{$type}_{$timestamp}.tar.gz";
        $path = $root . '/' . $filename;
        $staging = $root . '/.staging_' . $timestamp;

        $backup = Backup::create([
            'website_id' => $website->id,
            'domain'     => $website->domain,
            'type'       => $type,
            'path'       => $path,
            'status'     => 'running',
            'created_by' => $createdBy,
        ]);

        try {
            @mkdir($staging, 0750, true);

            $databases = [];
            if (in_array($type, ['database', 'full'], true)) {
                $databases = $this->dumpDatabases($website, $staging);
            }

            $metadata = [
                'domain'         => $website->domain,
                'type'           => $type,
                'document_root'  => $website->document_root,
                'files_basename' => basename($website->document_root),
                'includes_files' => in_array($type, ['files', 'full'], true),
                'databases'      => $databases,
                'created_at'     => now()->toIso8601String(),
            ];
            file_put_contents(
                $staging . '/metadata.json',
                json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            $this->buildArchive($website, $type, $staging, $path);

            if (! is_file($path)) {
                throw new RuntimeException('Backup archive was not created.');
            }

            $backup->update(['status' => 'success', 'size_bytes' => filesize($path) ?: null]);
        } catch (\Throwable $e) {
            @unlink($path);
            $backup->update(['status' => 'failed']);
        } finally {
            $this->recursiveDelete($staging);
        }

        return $backup->fresh();
    }

    /**
     * Dump every database attached to the website into the staging directory.
     * Throws if any dump genuinely fails (a "disabled" result in dev mode is
     * treated as a skipped no-op).
     *
     * @return array<int, string> names of databases included
     */
    protected function dumpDatabases(Website $website, string $staging): array
    {
        $dbDir = $staging . '/databases';
        @mkdir($dbDir, 0750, true);

        $included = [];
        foreach (PanelDatabase::where('website_id', $website->id)->get() as $db) {
            $dest = $dbDir . '/' . $db->name . '.sql';
            $result = $this->databases->export($db->name, $dest);

            if (! $result->ok && ! $result->disabled) {
                throw new RuntimeException("Database dump failed for {$db->name}: " . $result->combined());
            }

            $included[] = $db->name;
        }

        return $included;
    }

    /**
     * Bundle the staged metadata/dumps and (for files/full) the document root
     * into a single gzip archive. Throws on tar failure.
     */
    protected function buildArchive(Website $website, string $type, string $staging, string $path): void
    {
        if (! $this->runner->isEnabled()) {
            // Dev mode: tar is unavailable; write a placeholder so the workflow
            // completes and remains testable.
            @file_put_contents($path, "LibreStack dev backup for {$website->domain} ({$type})\n");

            return;
        }

        $args = ['-czf', $path, '-C', $staging, '.'];

        if (in_array($type, ['files', 'full'], true) && is_dir($website->document_root)) {
            $args[] = '-C';
            $args[] = dirname($website->document_root);
            $args[] = basename($website->document_root);
        }

        $result = $this->runner->run('tar', $args, 600);

        if (! $result->ok) {
            throw new RuntimeException('tar failed: ' . $result->combined());
        }
    }

    /**
     * Restore files and databases from a backup bundle. Returns true only when
     * every part of the restore succeeded.
     */
    public function restore(Backup $backup): bool
    {
        if (! $backup->path || ! is_file($backup->path)) {
            return false;
        }

        if (! $this->runner->isEnabled()) {
            // Dev mode: the archive is a placeholder; nothing to extract.
            return true;
        }

        $website = $backup->website;
        if (! $website) {
            return false;
        }

        $tmp = sys_get_temp_dir() . '/ls_restore_' . uniqid();
        @mkdir($tmp, 0750, true);

        try {
            $extract = $this->runner->run('tar', ['-xzf', $backup->path, '-C', $tmp], 600);
            if (! $extract->ok) {
                return false;
            }

            // Restore files.
            $filesDir = $tmp . '/' . basename($website->document_root);
            if (is_dir($filesDir)) {
                if (! is_dir($website->document_root)) {
                    @mkdir($website->document_root, 0755, true);
                }
                $copy = $this->runner->run('cp', ['-aT', $filesDir, $website->document_root], 600);
                if (! $copy->ok) {
                    return false;
                }
            }

            // Restore databases.
            $dbDir = $tmp . '/databases';
            if (is_dir($dbDir)) {
                foreach (glob($dbDir . '/*.sql') ?: [] as $sql) {
                    $name = basename($sql, '.sql');
                    $result = $this->databases->import($name, $sql);
                    if (! $result->ok && ! $result->disabled) {
                        return false;
                    }
                }
            }

            return true;
        } finally {
            $this->recursiveDelete($tmp);
        }
    }

    public function delete(Backup $backup): void
    {
        if ($backup->path && is_file($backup->path)) {
            @unlink($backup->path);
        }
        $backup->delete();
    }

    protected function recursiveDelete(string $path): void
    {
        if ($path === '' || ! file_exists($path)) {
            return;
        }

        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->recursiveDelete($path . '/' . $entry);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}
