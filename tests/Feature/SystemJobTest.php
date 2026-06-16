<?php

namespace Tests\Feature;

use App\Jobs\CreateBackupJob;
use App\Jobs\InstallWordPressJob;
use App\Jobs\IssueCertificateJob;
use App\Jobs\ProvisionWebsiteJob;
use App\Jobs\RestoreBackupJob;
use App\Models\Backup;
use App\Models\SystemJob;
use App\Models\Website;
use App\Services\Backup\BackupService;
use App\Services\SSL\SslService;
use App\Services\Support\CommandResult;
use App\Services\Website\WebsiteProvisioner;
use App\Services\WordPress\WordPressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Throwable;
use Tests\TestCase;

/**
 * Proves queued jobs do not report success when their underlying operation
 * fails: the SystemJob is marked "failed" and never "success".
 */
class SystemJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['librestack.system_enabled' => true]);
    }

    private function website(): Website
    {
        return Website::create([
            'domain'          => 'job-test.com',
            'type'            => 'php',
            'document_root'   => '/home/webuser/web/job-test.com/public_html',
            'system_username' => 'webuser',
        ]);
    }

    private function runAndExpectFailure(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable) {
            // The sync queue re-throws after marking the job failed.
        }
    }

    public function test_failed_nginx_deploy_fails_provision_job(): void
    {
        $site = $this->website();

        $this->app->instance(WebsiteProvisioner::class, new class extends WebsiteProvisioner {
            public function __construct()
            {
            }

            public function provision(Website $website): CommandResult
            {
                return new CommandResult(false, 1, '', 'nginx -t failed');
            }
        });

        $this->runAndExpectFailure(fn () => ProvisionWebsiteJob::dispatch($site->id));

        $this->assertSame('failed', SystemJob::where('type', 'website.create')->latest()->first()->status);
    }

    public function test_failed_certbot_fails_issue_job(): void
    {
        $site = $this->website();

        $this->app->instance(SslService::class, new class extends SslService {
            public function __construct()
            {
            }

            public function issue(Website $website, string $email): CommandResult
            {
                return new CommandResult(false, 1, '', 'certbot failed');
            }
        });

        $this->runAndExpectFailure(fn () => IssueCertificateJob::dispatch($site->id, 'admin@example.com'));

        $this->assertSame('failed', SystemJob::where('type', 'ssl.issue')->latest()->first()->status);
    }

    public function test_failed_wordpress_install_fails_job(): void
    {
        $site = $this->website();

        $this->app->instance(WordPressService::class, new class extends WordPressService {
            public function __construct()
            {
            }

            public function install(Website $website): array
            {
                return ['ok' => false, 'message' => 'download failed', 'db' => []];
            }
        });

        $this->runAndExpectFailure(fn () => InstallWordPressJob::dispatch($site->id));

        $this->assertSame('failed', SystemJob::where('type', 'wordpress.install')->latest()->first()->status);
    }

    public function test_failed_restore_fails_job(): void
    {
        $site = $this->website();
        $backup = Backup::create([
            'website_id' => $site->id, 'domain' => $site->domain,
            'type' => 'full', 'status' => 'success',
        ]);

        $this->app->instance(BackupService::class, new class extends BackupService {
            public function __construct()
            {
            }

            public function restore(Backup $backup): bool
            {
                return false;
            }
        });

        $this->runAndExpectFailure(fn () => RestoreBackupJob::dispatch($backup->id));

        $this->assertSame('failed', SystemJob::where('type', 'backup.restore')->latest()->first()->status);
    }

    public function test_successful_backup_job_marks_success(): void
    {
        $site = $this->website();

        $this->app->instance(BackupService::class, new class extends BackupService {
            public function __construct()
            {
            }

            public function create(Website $website, string $type = 'full', ?int $createdBy = null): Backup
            {
                return Backup::create([
                    'website_id' => $website->id, 'domain' => $website->domain,
                    'type' => $type, 'status' => 'success',
                ]);
            }
        });

        CreateBackupJob::dispatch($site->id, 'full');

        $this->assertSame('success', SystemJob::where('type', 'backup.create')->latest()->first()->status);
    }
}
