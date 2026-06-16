<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Services\Nginx\NginxService;
use App\Services\Support\CommandResult;
use App\Services\System\PhpFpmService;
use App\Services\System\PrivilegedFs;
use App\Services\System\SafeOps;
use App\Services\Support\CommandRunner;
use App\Services\Website\WebsiteProvisioner;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Per-user PHP-FPM pools: PHP/WordPress sites run as their own user and Nginx
 * points at /run/php/librestack-{username}.sock, never the global socket.
 */
class PhpFpmPoolTest extends TestCase
{
    private function website(string $type = 'php', string $username = 'webuser'): Website
    {
        return new Website([
            'domain'          => 'pool-test.com',
            'type'            => $type,
            'php_version'     => '8.3',
            'document_root'   => "/home/{$username}/web/pool-test.com/public_html",
            'system_username' => $username,
        ]);
    }

    public function test_php_website_nginx_config_uses_per_user_socket(): void
    {
        $config = app(NginxService::class)->generateConfig($this->website('php'));

        $this->assertStringContainsString('fastcgi_pass unix:/run/php/librestack-webuser.sock', $config);
        $this->assertStringNotContainsString('php8.3-fpm.sock', $config);
    }

    public function test_wordpress_nginx_config_uses_per_user_socket(): void
    {
        $config = app(NginxService::class)->generateConfig($this->website('wordpress'));

        $this->assertStringContainsString('fastcgi_pass unix:/run/php/librestack-webuser.sock', $config);
    }

    public function test_pool_config_is_generated_correctly(): void
    {
        config(['librestack.paths.web_root' => '/home']);
        $pool = app(PhpFpmService::class)->poolConfig('webuser', '8.3');

        $this->assertStringContainsString('[librestack-webuser]', $pool);
        $this->assertStringContainsString('user = webuser', $pool);
        $this->assertStringContainsString('group = webuser', $pool);
        $this->assertStringContainsString('listen = /run/php/librestack-webuser.sock', $pool);
        $this->assertStringContainsString('listen.owner = www-data', $pool);
        $this->assertStringContainsString('open_basedir', $pool);
        $this->assertStringContainsString('disable_functions', $pool);
    }

    public function test_invalid_username_cannot_create_pool(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(PhpFpmService::class)->poolConfig('1bad user', '8.3');
    }

    public function test_invalid_php_version_cannot_create_pool(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(PhpFpmService::class)->poolConfig('webuser', '9.9');
    }

    public function test_version_not_in_config_is_accepted_when_fpm_is_installed(): void
    {
        // Simulate a host whose installed FPM (e.g. Ubuntu 26.04 → 8.5) is not
        // in the configured php_versions list. The on-disk pool.d must make it
        // valid so provisioning is never blocked by a stale config list.
        config(['librestack.php_versions' => ['8.1', '8.2', '8.3', '8.4']]);

        $fpmDir = '/etc/php/8.5/fpm/pool.d';
        $created = false;
        if (! is_dir($fpmDir)) {
            $created = @mkdir($fpmDir, 0755, true);
        }

        if (! is_dir($fpmDir)) {
            $this->markTestSkipped('Cannot create /etc/php/8.5/fpm/pool.d in this environment.');
        }

        try {
            $pool = app(PhpFpmService::class)->poolConfig('webuser', '8.5');
            $this->assertStringContainsString('[librestack-webuser]', $pool);
        } finally {
            if ($created) {
                @rmdir($fpmDir);
                @rmdir('/etc/php/8.5/fpm');
                @rmdir('/etc/php/8.5');
            }
        }
    }

    public function test_failed_php_fpm_test_fails_provisioning_and_rolls_back_pool(): void
    {
        config(['librestack.system_enabled' => true]);

        // A PrivilegedFs whose php-fpm test fails; track the rollback delete.
        $fs = new class(app(CommandRunner::class), app(SafeOps::class)) extends PrivilegedFs {
            public bool $deleted = false;

            public function phpFpmPoolWrite(string $username, string $phpVersion, string $poolConfig): CommandResult
            {
                return new CommandResult(true, 0, 'ok', '');
            }

            public function phpFpmTest(string $phpVersion): CommandResult
            {
                return new CommandResult(false, 1, '', 'pool config invalid');
            }

            public function phpFpmPoolDelete(string $username, string $phpVersion): CommandResult
            {
                $this->deleted = true;

                return new CommandResult(true, 0, 'ok', '');
            }

            public function phpFpmReload(string $phpVersion): CommandResult
            {
                return new CommandResult(true, 0, 'ok', '');
            }
        };

        $service = new PhpFpmService($fs);
        $result = $service->ensurePool('webuser', '8.3');

        $this->assertFalse($result->ok);
        $this->assertTrue($fs->deleted, 'A failed php-fpm test must roll back the pool file.');
    }

    public function test_failed_pool_test_fails_website_provisioning(): void
    {
        // system_enabled stays false so the user-ensure/dir steps are no-ops;
        // the stubbed pool failure (which ignores the flag) is what surfaces.
        config(['librestack.system_enabled' => false]);

        // PhpFpmService that always fails ensurePool.
        $phpFpm = new class(app(PrivilegedFs::class)) extends PhpFpmService {
            public function ensurePool(string $username, string $phpVersion, array $settings = []): CommandResult
            {
                return new CommandResult(false, 1, '', 'php-fpm pool failed');
            }
        };

        // Provisioner with stubbed user/dirs that succeed, real pool that fails.
        $provisioner = new class(
            app(NginxService::class),
            app(\App\Services\System\SystemUserService::class),
            app(PrivilegedFs::class),
            $phpFpm,
        ) extends WebsiteProvisioner {
            public function createDirectories(Website $website): CommandResult
            {
                return new CommandResult(true, 0, 'ok', '');
            }
        };

        // user ensure is disabled in dev unless system_enabled; force a user
        // service that reports success.
        $result = $provisioner->provision($this->website('php'));

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('php-fpm pool failed', $result->error);
    }

    public function test_resolve_installed_version_passes_through_in_dev_mode(): void
    {
        // In non-system (dev) mode the requested version is returned unchanged,
        // even if the host has no matching PHP-FPM, so dev boxes keep working.
        config(['librestack.system_enabled' => false]);

        $service = app(PhpFpmService::class);

        $this->assertSame('8.4', $service->resolveInstalledVersion('8.4'));
        $this->assertSame('8.1', $service->resolveInstalledVersion('8.1'));
    }

    public function test_resolve_installed_version_falls_back_to_an_installed_version(): void
    {
        config(['librestack.system_enabled' => true]);

        // Stub installedVersions() to simulate a host that only has 8.3 FPM.
        $service = new class(app(PrivilegedFs::class)) extends PhpFpmService {
            public function installedVersions(): array
            {
                return ['8.3'];
            }
        };

        // A requested-but-uninstalled version is coerced to the installed one…
        $this->assertSame('8.3', $service->resolveInstalledVersion('8.4'));
        // …while a version that IS installed is kept as-is.
        $this->assertSame('8.3', $service->resolveInstalledVersion('8.3'));
    }

    public function test_resolve_installed_version_keeps_preferred_when_nothing_detected(): void
    {
        config(['librestack.system_enabled' => true]);

        // No FPM detected at all (e.g. before php-fpm is installed): keep the
        // requested version rather than guessing.
        $service = new class(app(PrivilegedFs::class)) extends PhpFpmService {
            public function installedVersions(): array
            {
                return [];
            }
        };

        $this->assertSame('8.4', $service->resolveInstalledVersion('8.4'));
    }

    public function test_ensure_pool_prunes_orphan_pools_from_other_php_versions(): void
    {
        config(['librestack.system_enabled' => true]);

        // FS that records which versions were deleted/reloaded so we can assert
        // the orphan pool (8.3) is removed while the active version (8.5) stays.
        $fs = new class(app(CommandRunner::class), app(SafeOps::class)) extends PrivilegedFs {
            /** @var array<int, string> */
            public array $deleted = [];

            /** @var array<int, string> */
            public array $reloaded = [];

            public function phpFpmPoolWrite(string $username, string $phpVersion, string $poolConfig): CommandResult
            {
                return new CommandResult(true, 0, 'ok', '');
            }

            public function phpFpmTest(string $phpVersion): CommandResult
            {
                return new CommandResult(true, 0, 'ok', '');
            }

            public function phpFpmPoolDelete(string $username, string $phpVersion): CommandResult
            {
                $this->deleted[] = $phpVersion;

                return new CommandResult(true, 0, 'ok', '');
            }

            public function phpFpmReload(string $phpVersion): CommandResult
            {
                $this->reloaded[] = $phpVersion;

                return new CommandResult(true, 0, 'ok', '');
            }
        };

        // The host has 8.3 and 8.5 installed; only 8.3 still carries an orphan
        // pool for this user (the site was moved from 8.3 to 8.5).
        $service = new class($fs) extends PhpFpmService {
            public function installedVersions(): array
            {
                return ['8.3', '8.5'];
            }

            protected function poolFileExists(string $username, string $phpVersion): bool
            {
                return $phpVersion === '8.3';
            }
        };

        $result = $service->ensurePool('webuser', '8.5', ['upload_max_filesize' => '800M']);

        $this->assertTrue($result->ok);
        // The stale 8.3 pool is deleted; the active 8.5 pool is never deleted.
        $this->assertSame(['8.3'], $fs->deleted);
        // Both the orphan's version and the active version are reloaded so the
        // socket ends up bound by the 8.5 pool carrying the new settings.
        $this->assertContains('8.3', $fs->reloaded);
        $this->assertContains('8.5', $fs->reloaded);
    }
}
