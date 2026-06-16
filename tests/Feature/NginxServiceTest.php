<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Services\Nginx\NginxService;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use App\Services\System\PrivilegedFs;
use App\Services\System\SafeOps;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Atomic Nginx deploy behaviour: success, failed write, failed test (rollback),
 * failed reload and invalid-domain rejection.
 */
class NginxServiceTest extends TestCase
{
    private function website(string $domain = 'deploy-test.com'): Website
    {
        return new Website([
            'domain'          => $domain,
            'type'            => 'php',
            'php_version'     => '8.3',
            'document_root'   => '/home/webuser/web/' . $domain . '/public_html',
            'system_username' => 'webuser',
        ]);
    }

    /**
     * @param  array<string,bool>  $fsResults
     */
    private function fs(array $fsResults): PrivilegedFs
    {
        return new class($fsResults) extends PrivilegedFs {
            public function __construct(public array $results)
            {
            }

            private function r(string $key): CommandResult
            {
                return ($this->results[$key] ?? true)
                    ? new CommandResult(true, 0, 'ok', '')
                    : new CommandResult(false, 1, '', $key . ' failed');
            }

            public function readNginxConfig(string $domain): ?string
            {
                return null; // nothing existed before
            }

            public function writeNginxConfig(string $domain, string $config): CommandResult
            {
                return $this->r('write');
            }

            public function enableNginxSite(string $domain): CommandResult
            {
                return $this->r('enable');
            }

            public function deleteNginxSite(string $domain): CommandResult
            {
                return $this->r('delete');
            }
        };
    }

    /**
     * @param  array<string,bool>  $runnerResults  keyed by 'test' and 'reload'
     */
    private function runner(array $runnerResults): CommandRunner
    {
        return new class($runnerResults) extends CommandRunner {
            public function __construct(public array $results)
            {
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function run(string $binary, array $args = [], ?int $timeout = 60, ?string $input = null): CommandResult
            {
                $key = ($args[0] ?? '') === '-t' ? 'test' : 'reload';

                return ($this->results[$key] ?? true)
                    ? new CommandResult(true, 0, 'ok', '')
                    : new CommandResult(false, 1, '', $key . ' failed');
            }
        };
    }

    public function test_successful_deploy(): void
    {
        config(['librestack.system_enabled' => true]);
        $service = new NginxService($this->runner(['test' => true, 'reload' => true]), $this->fs([]));

        $this->assertTrue($service->deploy($this->website())->ok);
    }

    public function test_failed_write_returns_failure(): void
    {
        config(['librestack.system_enabled' => true]);
        $service = new NginxService($this->runner([]), $this->fs(['write' => false]));

        $this->assertFalse($service->deploy($this->website())->ok);
    }

    public function test_failed_nginx_test_rolls_back_and_fails(): void
    {
        config(['librestack.system_enabled' => true]);
        $service = new NginxService($this->runner(['test' => false]), $this->fs([]));

        $result = $service->deploy($this->website());
        $this->assertFalse($result->ok);
    }

    public function test_failed_reload_returns_failure(): void
    {
        config(['librestack.system_enabled' => true]);
        $service = new NginxService($this->runner(['test' => true, 'reload' => false]), $this->fs([]));

        $this->assertFalse($service->deploy($this->website())->ok);
    }

    public function test_invalid_domain_is_rejected(): void
    {
        config(['librestack.system_enabled' => true]);
        $service = app(NginxService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->deploy($this->website('not a domain'));
    }

    public function test_php_site_without_ssl_only_listens_on_port_80(): void
    {
        $config = app(NginxService::class)->generateConfig($this->website());

        $this->assertStringContainsString('listen 80;', $config);
        $this->assertStringNotContainsString('listen 443', $config);
        $this->assertStringNotContainsString('ssl_certificate', $config);
    }

    public function test_ssl_enabled_php_site_emits_https_block_and_redirect(): void
    {
        // Dev mode: sslActive() trusts the ssl_enabled flag (no /etc/letsencrypt
        // to read), so the generated vhost includes the full :443 server block.
        $website = $this->website('secure-test.com');
        $website->ssl_enabled = true;
        $website->force_https = true;

        $config = app(NginxService::class)->generateConfig($website);

        // HTTPS server block with the Let's Encrypt cert paths.
        $this->assertStringContainsString('listen 443 ssl;', $config);
        $this->assertStringContainsString(
            'ssl_certificate /etc/letsencrypt/live/secure-test.com/fullchain.pem;',
            $config
        );
        $this->assertStringContainsString(
            'ssl_certificate_key /etc/letsencrypt/live/secure-test.com/privkey.pem;',
            $config
        );

        // The :80 server redirects to HTTPS but still serves the ACME challenge.
        $this->assertStringContainsString('return 301 https://$host$request_uri;', $config);
        $this->assertStringContainsString('location /.well-known/acme-challenge/', $config);

        // The HTTPS block still carries the per-user FPM socket + body size.
        $this->assertStringContainsString('fastcgi_pass unix:/run/php/librestack-webuser.sock', $config);
        $this->assertStringContainsString('client_max_body_size', $config);
    }

    public function test_ssl_enabled_site_keeps_https_block_after_php_settings_change(): void
    {
        // Regression: saving PHP settings triggers a redeploy. The HTTPS block
        // must survive (the old certbot-edited vhost used to be wiped here).
        $website = $this->website('persist-test.com');
        $website->ssl_enabled = true;
        $website->force_https = true;
        $website->meta = ['php_settings' => ['upload_max_filesize' => '800M', 'post_max_size' => '800M']];

        $config = app(NginxService::class)->generateConfig($website);

        $this->assertStringContainsString('listen 443 ssl;', $config);
        $this->assertStringContainsString('client_max_body_size 800m;', $config);
    }

    public function test_https_block_is_emitted_in_system_mode_without_reading_letsencrypt(): void
    {
        // Regression for the critical bug: the www-data queue worker runs the
        // redeploy in system mode but CANNOT stat root-only /etc/letsencrypt.
        // The HTTPS block must depend ONLY on the ssl_enabled flag, never on a
        // filesystem check — otherwise SSL silently breaks on every settings
        // change processed by the worker.
        config(['librestack.system_enabled' => true]);

        $website = $this->website('worker-test.com');
        $website->ssl_enabled = true;
        $website->force_https = true;

        $config = app(NginxService::class)->generateConfig($website);

        $this->assertStringContainsString('listen 443 ssl;', $config);
        $this->assertStringContainsString(
            'ssl_certificate /etc/letsencrypt/live/worker-test.com/fullchain.pem;',
            $config
        );
    }
}
