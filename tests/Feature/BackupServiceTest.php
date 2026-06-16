<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\PanelDatabase;
use App\Models\Website;
use App\Services\Backup\BackupService;
use App\Services\Database\DatabaseService;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['librestack.paths.backups' => sys_get_temp_dir() . '/ls_backups_' . uniqid()]);
    }

    private function website(): Website
    {
        return Website::create([
            'domain'          => 'backup-test.com',
            'type'            => 'full',
            'document_root'   => sys_get_temp_dir() . '/ls_bk_' . uniqid(),
            'system_username' => 'webuser',
        ]);
    }

    public function test_failed_archive_marks_backup_failed(): void
    {
        config(['librestack.system_enabled' => true]);

        // A runner whose tar command always fails.
        $runner = new class extends CommandRunner {
            public function run(string $binary, array $args = [], ?int $timeout = 60, ?string $input = null): CommandResult
            {
                return new CommandResult(false, 1, '', 'boom');
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };

        $service = new BackupService($runner, app(DatabaseService::class));
        $backup = $service->create($this->website(), 'files');

        $this->assertSame('failed', $backup->status);
        $this->assertDatabaseHas('backups', ['id' => $backup->id, 'status' => 'failed']);
    }

    public function test_failed_database_dump_marks_full_backup_failed(): void
    {
        config(['librestack.system_enabled' => true]);

        $website = $this->website();
        PanelDatabase::create(['name' => 'somedb', 'website_id' => $website->id]);

        // DatabaseService whose export fails (not disabled).
        $databases = new class(app(CommandRunner::class)) extends DatabaseService {
            public function export(string $name, string $destination): CommandResult
            {
                return new CommandResult(false, 1, '', 'dump failed');
            }
        };

        // tar would succeed, but the db dump fails first.
        $runner = new class extends CommandRunner {
            public function run(string $binary, array $args = [], ?int $timeout = 60, ?string $input = null): CommandResult
            {
                return new CommandResult(true, 0, '', '');
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };

        $service = new BackupService($runner, $databases);
        $backup = $service->create($website, 'full');

        $this->assertSame('failed', $backup->status);
    }

    public function test_successful_dev_backup_is_marked_success(): void
    {
        config(['librestack.system_enabled' => false]);

        $service = app(BackupService::class);
        $backup = $service->create($this->website(), 'full');

        $this->assertSame('success', $backup->status);
        $this->assertNotNull($backup->path);
    }
}
