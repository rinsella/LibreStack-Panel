<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Website;
use App\Services\Database\DatabaseService;
use App\Services\Support\CommandRunner;
use App\Support\Validators;
use InvalidArgumentException;

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
    public function create(Website $website, string $type = 'full'): Backup
    {
        $timestamp = now()->format('Ymd_His');
        $root = $this->backupRoot($website->domain);
        $filename = "{$type}_{$timestamp}.tar.gz";
        $path = $root . '/' . $filename;

        $backup = Backup::create([
            'website_id' => $website->id,
            'domain'     => $website->domain,
            'type'       => $type,
            'path'       => $path,
            'status'     => 'running',
        ]);

        try {
            if (in_array($type, ['files', 'full'], true)) {
                $this->archiveFiles($website, $path);
            }

            if (in_array($type, ['database', 'full'], true)) {
                $this->dumpDatabases($website, $root, $timestamp);
            }

            $size = is_file($path) ? filesize($path) : null;
            $backup->update(['status' => 'success', 'size_bytes' => $size]);
        } catch (\Throwable $e) {
            $backup->update(['status' => 'failed']);
        }

        return $backup->fresh();
    }

    protected function archiveFiles(Website $website, string $path): void
    {
        $docroot = $website->document_root;

        if (! $this->runner->isEnabled()) {
            // Dev mode: write a placeholder so the workflow completes.
            @file_put_contents($path, "LibreStack dev backup placeholder for {$website->domain}\n");

            return;
        }

        $parent = dirname($docroot);
        $base = basename($docroot);

        $this->runner->run('tar', ['-czf', $path, '-C', $parent, $base], 600);
    }

    protected function dumpDatabases(Website $website, string $root, string $timestamp): void
    {
        foreach (\App\Models\PanelDatabase::where('website_id', $website->id)->get() as $db) {
            $dest = $root . '/db_' . $db->name . '_' . $timestamp . '.sql';
            $this->databases->export($db->name, $dest);
        }
    }

    public function restore(Backup $backup): bool
    {
        if (! $backup->path || ! is_file($backup->path)) {
            return false;
        }

        if (! $this->runner->isEnabled()) {
            return true;
        }

        $website = $backup->website;
        if (! $website) {
            return false;
        }

        $parent = dirname($website->document_root);
        $result = $this->runner->run('tar', ['-xzf', $backup->path, '-C', $parent], 600);

        return $result->ok;
    }

    public function delete(Backup $backup): void
    {
        if ($backup->path && is_file($backup->path)) {
            @unlink($backup->path);
        }
        $backup->delete();
    }
}
