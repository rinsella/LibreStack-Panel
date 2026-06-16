<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\PanelDatabase;
use App\Models\Website;
use App\Services\Backup\BackupService;
use App\Services\Database\DatabaseService;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use App\Services\System\PrivilegedFs;
use App\Services\System\SafeOps;
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

    private function enabledRunner(): CommandRunner
    {
        return new class extends CommandRunner {
            public function run(string $binary, array $args = [], ?int $timeout = 60, ?string $input = null): CommandResult
            {
                return new CommandResult(true, 0, '', '');
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };
    }

    private function fs(bool $tarOk): PrivilegedFs
    {
        return new class($tarOk) extends PrivilegedFs {
            public function __construct(public bool $tarOk)
            {
                parent::__construct(app(CommandRunner::class), app(SafeOps::class));
            }

            public function createTar(string $archive, array $tarArgs): CommandResult
            {
                if ($this->tarOk) {
                    file_put_contents($archive, 'archive');

                    return new CommandResult(true, 0, 'ok', '');
                }

                return new CommandResult(false, 1, '', 'tar boom');
            }
        };
    }

    public function test_failed_archive_marks_backup_failed(): void
    {
        config(['librestack.system_enabled' => true]);

        $service = new BackupService($this->enabledRunner(), app(DatabaseService::class), $this->fs(false));
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

        $service = new BackupService($this->enabledRunner(), $databases, $this->fs(true));
        $backup = $service->create($website, 'full');

        $this->assertSame('failed', $backup->status);
    }

    public function test_full_backup_records_attached_databases_in_metadata(): void
    {
        config(['librestack.system_enabled' => true]);

        $website = $this->website();
        PanelDatabase::create(['name' => 'wp_demo', 'website_id' => $website->id]);

        // DatabaseService whose export succeeds and writes a dummy dump.
        $databases = new class(app(CommandRunner::class)) extends DatabaseService {
            public function export(string $name, string $destination): CommandResult
            {
                file_put_contents($destination, "-- dump for {$name}\n");

                return new CommandResult(true, 0, '', '');
            }
        };

        $captured = ['meta' => []];
        $fs = new class($captured) extends PrivilegedFs {
            public function __construct(public array &$captured)
            {
                parent::__construct(app(CommandRunner::class), app(SafeOps::class));
            }

            public function createTar(string $archive, array $tarArgs): CommandResult
            {
                // The staging dir is the first -C target; read its metadata.json.
                $staging = $tarArgs[1] ?? '';
                $this->captured['meta'] = json_decode((string) @file_get_contents($staging . '/metadata.json'), true) ?: [];
                file_put_contents($archive, 'archive');

                return new CommandResult(true, 0, 'ok', '');
            }
        };

        $service = new BackupService($this->enabledRunner(), $databases, $fs);
        $backup = $service->create($website, 'full');

        $this->assertSame('success', $backup->status);
        $this->assertContains('wp_demo', $captured['meta']['databases'] ?? []);
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
