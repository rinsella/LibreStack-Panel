<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Models\SystemJob;
use App\Services\Backup\BackupService;
use RuntimeException;

class RestoreBackupJob extends BaseSystemJob
{
    public function __construct(public int $backupId)
    {
        parent::__construct();
    }

    protected function type(): string
    {
        return 'backup.restore';
    }

    protected function payload(): array
    {
        return ['backup_id' => $this->backupId];
    }

    protected function execute(SystemJob $job): string
    {
        $backup = Backup::findOrFail($this->backupId);

        if (! app(BackupService::class)->restore($backup)) {
            throw new RuntimeException('Restore failed.');
        }

        return "Backup #{$backup->id} restored for {$backup->domain}.";
    }
}
