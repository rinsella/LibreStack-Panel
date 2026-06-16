<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Services\Nginx\NginxService;
use App\Services\Support\CommandRunner;
use Tests\TestCase;

/**
 * Verifies the privileged-command architecture and installer assumptions:
 *  - privileged binaries are wrapped with `sudo -n` only when use_sudo is on;
 *  - generated PHP-FPM configs always reference a concrete fpm socket;
 *  - the supported PHP versions advertised by the installer match config.
 */
class SystemCommandTest extends TestCase
{
    public function test_privileged_binary_is_wrapped_with_sudo_when_enabled(): void
    {
        config([
            'librestack.system_enabled' => true,
            'librestack.use_sudo'       => true,
        ]);

        $runner = new class extends CommandRunner {
            public function expose(string $binary, array $args): array
            {
                return $this->buildCommand($binary, $args);
            }
        };

        $this->assertSame(
            ['sudo', '-n', 'systemctl', 'reload', 'nginx'],
            $runner->expose('systemctl', ['reload', 'nginx'])
        );
    }

    public function test_non_privileged_binary_is_not_wrapped(): void
    {
        config([
            'librestack.system_enabled' => true,
            'librestack.use_sudo'       => true,
        ]);

        $runner = new class extends CommandRunner {
            public function expose(string $binary, array $args): array
            {
                return $this->buildCommand($binary, $args);
            }
        };

        $this->assertSame(['uname', '-a'], $runner->expose('uname', ['-a']));
    }

    public function test_privileged_binary_not_wrapped_when_sudo_disabled(): void
    {
        config([
            'librestack.system_enabled' => true,
            'librestack.use_sudo'       => false,
        ]);

        $runner = new class extends CommandRunner {
            public function expose(string $binary, array $args): array
            {
                return $this->buildCommand($binary, $args);
            }
        };

        $this->assertSame(['systemctl', 'status', 'nginx'], $runner->expose('systemctl', ['status', 'nginx']));
    }

    public function test_disabled_mode_returns_disabled_result(): void
    {
        config(['librestack.system_enabled' => false]);

        $result = (new CommandRunner())->run('systemctl', ['status', 'nginx']);

        $this->assertTrue($result->disabled);
    }

    public function test_generated_php_config_references_a_concrete_socket(): void
    {
        $website = new Website([
            'domain'          => 'fpm-test.com',
            'type'            => 'php',
            'php_version'     => config('librestack.default_php'),
            'document_root'   => '/home/webuser/web/fpm-test.com/public_html',
            'system_username' => 'webuser',
        ]);

        $config = app(NginxService::class)->generateConfig($website);

        // PHP/WordPress sites use the dedicated per-user PHP-FPM pool socket,
        // never the shared global socket.
        $this->assertStringContainsString('fastcgi_pass unix:/run/php/librestack-webuser.sock', $config);
        $this->assertStringNotContainsString('php-fpm.sock', $config);
    }

    public function test_installer_default_php_is_a_supported_version(): void
    {
        // The installer detects and writes LIBRESTACK_DEFAULT_PHP; whatever the
        // default is, it must be one the panel knows how to generate configs for.
        $this->assertContains(
            config('librestack.default_php'),
            config('librestack.php_versions')
        );
    }
}
