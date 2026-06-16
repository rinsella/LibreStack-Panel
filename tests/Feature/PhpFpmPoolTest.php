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
            public function ensurePool(string $username, string $phpVersion): CommandResult
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
}
