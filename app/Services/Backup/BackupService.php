<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\PanelDatabase;
use App\Models\Website;
use App\Services\Database\DatabaseService;
use App\Services\Support\CommandRunner;
use App\Services\System\PrivilegedFs;
use App\Support\Validators;
use InvalidArgumentException;
use RuntimeException;

/**
 * Creates and restores backups of website files and/or databases.
 * Archives are stored under the configured backup path, namespaced per domain.
 *
 * All root-owned filesystem work (reading /home website files, extracting into
 * a site, copying files back) goes through the privileged safe-op layer; the
 * web process never touches those paths directly.
 */
class BackupService
{
    public function __construct(
        protected CommandRunner $runner,
        protected DatabaseService $databases,
        protected PrivilegedFs $fs,
    ) {
    }

    public function backupRoot(string $domain): string
    {
        if (! Validators::isValidDomain($domain)) {
            throw new InvalidArgumentException('Invalid domain.');
        }

        $root = rtrim((string) config('librestack.paths.backups'), '/')
            . '/' . Validators::domainSlug($domain);

        if (! is_dir($root) && ! mkdir($root, 0750, true) && ! is_dir($root)) {
            throw new RuntimeException("Failed to create backup directory: {$root}");
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
            if (! is_dir($staging) && ! mkdir($staging, 0750, true) && ! is_dir($staging)) {
                throw new RuntimeException('Failed to create backup staging directory.');
            }

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
        if (! is_dir($dbDir) && ! mkdir($dbDir, 0750, true) && ! is_dir($dbDir)) {
            throw new RuntimeException('Failed to create database dump directory.');
        }

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
     * into a single gzip archive via the privileged safe-op. Throws on failure.
     */
    protected function buildArchive(Website $website, string $type, string $staging, string $path): void
    {
        if (! $this->runner->isEnabled()) {
            // Dev mode: tar is unavailable; write a placeholder so the workflow
            // completes and remains testable.
            if (file_put_contents($path, "LibreStack dev backup for {$website->domain} ({$type})\n") === false) {
                throw new RuntimeException('Failed to write dev backup placeholder.');
            }

            return;
        }

        $args = ['-C', $staging, '.'];

        if (in_array($type, ['files', 'full'], true) && is_dir($website->document_root)) {
            $args[] = '-C';
            $args[] = dirname($website->document_root);
            $args[] = basename($website->document_root);
        }

        $result = $this->fs->createTar($path, $args);

        if (! $result->ok && ! $result->disabled) {
            throw new RuntimeException('Archive creation failed: ' . $result->combined());
        }
    }

    /**
     * Restore files and databases from a backup bundle. Returns true only when
     * every part of the restore succeeded. Files are restored ONLY into the
     * website's own document root (never outside it) via the safe-op layer.
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

        // Extract into a staging area under the (managed) backup root so the
        // privileged extractor accepts the destination and traversal is checked.
        $tmp = $this->backupRoot($website->domain) . '/.restore_' . uniqid();

        try {
            $extract = $this->fs->extractTar($backup->path, $tmp);
            if (! $extract->ok && ! $extract->disabled) {
                return false;
            }

            // Restore files into the website's own document root only.
            $filesDir = $tmp . '/' . basename($website->document_root);
            if (is_dir($filesDir)) {
                $copy = $this->fs->copyTree($filesDir, $website->document_root);
                if (! $copy->ok && ! $copy->disabled) {
                    return false;
                }
            }

            // Restore databases that belong to this website (per metadata).
            $dbDir = $tmp . '/databases';
            if (is_dir($dbDir)) {
                $allowed = $this->allowedDatabaseNames($website, $tmp);
                foreach (glob($dbDir . '/*.sql') ?: [] as $sql) {
                    $name = basename($sql, '.sql');
                    if ($allowed !== null && ! in_array($name, $allowed, true)) {
                        continue; // never import a DB not recorded for this site
                    }
                    $result = $this->databases->import($name, $sql);
                    if (! $result->ok && ! $result->disabled) {
                        return false;
                    }
                }
            }

            return true;
        } finally {
            // Best-effort cleanup of the staging area.
            $this->recursiveDelete($tmp);
        }
    }

    /**
     * The database names this website is allowed to restore, taken from the
     * archive metadata (falling back to the live PanelDatabase records).
     *
     * @return array<int, string>|null  null = no metadata, allow recorded DBs
     */
    protected function allowedDatabaseNames(Website $website, string $extractDir): ?array
    {
        $metaFile = $extractDir . '/metadata.json';
        if (is_file($metaFile)) {
            $meta = json_decode((string) file_get_contents($metaFile), true);
            if (is_array($meta) && isset($meta['databases']) && is_array($meta['databases'])) {
                return array_values(array_filter($meta['databases'], 'is_string'));
            }
        }

        return PanelDatabase::where('website_id', $website->id)->pluck('name')->all();
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
