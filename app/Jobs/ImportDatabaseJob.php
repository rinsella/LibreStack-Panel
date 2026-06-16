<?php

namespace App\Jobs;

use App\Models\PanelDatabase;
use App\Models\SystemJob;
use App\Services\Database\DatabaseService;
use RuntimeException;

class ImportDatabaseJob extends BaseSystemJob
{
    public function __construct(public int $databaseId, public string $sqlPath)
    {
        parent::__construct();
    }

    protected function type(): string
    {
        return 'database.import';
    }

    protected function payload(): array
    {
        // Never store the uploaded file path's contents; only the database id.
        return ['database_id' => $this->databaseId];
    }

    protected function execute(SystemJob $job): string
    {
        $database = PanelDatabase::findOrFail($this->databaseId);

        try {
            $result = app(DatabaseService::class)->import($database->name, $this->sqlPath);

            if (! $result->ok && ! $result->disabled) {
                throw new RuntimeException('Import failed: ' . $result->combined());
            }

            return $result->disabled
                ? 'Import recorded (system commands disabled in dev).'
                : "Imported SQL into {$database->name}.";
        } finally {
            @unlink($this->sqlPath);
        }
    }
}
