<?php

namespace App\Jobs;

use App\Models\SystemJob;
use App\Models\Website;
use App\Services\Backup\BackupService;
use RuntimeException;

class CreateBackupJob extends BaseSystemJob
{
    public function __construct(
        public int $websiteId,
        public string $backupType,
        public ?int $createdBy = null,
    ) {
        parent::__construct();
    }

    protected function type(): string
    {
        return 'backup.create';
    }

    protected function payload(): array
    {
        return ['website_id' => $this->websiteId, 'type' => $this->backupType];
    }

    protected function execute(SystemJob $job): string
    {
        $website = Website::findOrFail($this->websiteId);
        $backup = app(BackupService::class)->create($website, $this->backupType, $this->createdBy);

        $job->update(['payload' => array_merge($job->payload ?? [], ['backup_id' => $backup->id])]);

        if ($backup->status !== 'success') {
            throw new RuntimeException('Backup failed.');
        }

        return "Backup created for {$website->domain} ({$this->backupType}).";
    }
}
