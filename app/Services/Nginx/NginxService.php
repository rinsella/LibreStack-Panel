<?php

namespace App\Services\Nginx;

use App\Models\Website;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use App\Services\System\PrivilegedFs;
use App\Support\Validators;
use InvalidArgumentException;

/**
 * Generates and manages Nginx server blocks for panel-managed websites.
 *
 * Config generation is pure (returns a string) so it can be unit-tested without
 * touching the filesystem. Privileged file operations (writing to /etc/nginx,
 * symlinking, reloading) only run when the panel is in system mode.
 */
class NginxService
{
    public function __construct(
        protected CommandRunner $runner,
        protected PrivilegedFs $fs,
    ) {
    }

    public function availablePath(string $domain): string
    {
        return rtrim((string) config('librestack.paths.nginx_available'), '/')
            . '/' . $this->slug($domain) . '.conf';
    }

    public function enabledPath(string $domain): string
    {
        return rtrim((string) config('librestack.paths.nginx_enabled'), '/')
            . '/' . $this->slug($domain) . '.conf';
    }

    /**
     * Build the full Nginx config text for a website (pure function).
     */
    public function generateConfig(Website $website): string
    {
        if (! Validators::isValidDomain($website->domain)) {
            throw new InvalidArgumentException("Invalid domain: {$website->domain}");
        }

        return match ($website->type) {
            'static'                       => $this->staticConfig($website),
            'php', 'wordpress'             => $this->phpConfig($website),
            'reverse_proxy', 'node_proxy'  => $this->proxyConfig($website),
            default                        => $this->staticConfig($website),
        };
    }

    protected function serverNames(Website $website): string
    {
        $names = [$website->domain];

        if ($website->www_alias) {
            $names[] = 'www.' . $website->domain;
        }

        foreach ($website->aliases as $alias) {
            if (Validators::isValidDomain($alias->domain)) {
                $names[] = $alias->domain;
            }
        }

        return implode(' ', array_unique($names));
    }

    protected function header(Website $website): string
    {        return "# Managed by LibreStack Panel. Do not edit manually.\n"
            . "# Website: {$website->domain} (type: {$website->type})\n";
    }

    protected function logBlock(Website $website): string
    {
        $logDir = dirname($website->document_root) . '/logs';

        return "    access_log {$logDir}/access.log;\n"
            . "    error_log {$logDir}/error.log;\n";
    }

    protected function staticConfig(Website $website): string
    {
        $root = $website->document_root;

        return $this->header($website)
            . "server {\n"
            . "    listen 80;\n"
            . "    listen [::]:80;\n"
            . "    server_name {$this->serverNames($website)};\n\n"
            . "    root {$root};\n"
            . "    index index.html index.htm;\n\n"
            . $this->logBlock($website) . "\n"
            . "    location / {\n"
            . "        try_files \$uri \$uri/ =404;\n"
            . "    }\n"
            . "}\n";
    }

    protected function phpConfig(Website $website): string
    {
        $root = $website->document_root;
        $socket = $this->fpmSocket($website);

        return $this->header($website)
            . "server {\n"
            . "    listen 80;\n"
            . "    listen [::]:80;\n"
            . "    server_name {$this->serverNames($website)};\n\n"
            . "    root {$root};\n"
            . "    index index.php index.html;\n\n"
            . $this->logBlock($website) . "\n"
            . "    location / {\n"
            . "        try_files \$uri \$uri/ /index.php?\$query_string;\n"
            . "    }\n\n"
            . "    location ~ \\.php$ {\n"
            . "        include snippets/fastcgi-php.conf;\n"
            . "        fastcgi_pass {$socket};\n"
            . "    }\n\n"
            . "    location ~ /\\.(?!well-known).* {\n"
            . "        deny all;\n"
            . "    }\n"
            . "}\n";
    }

    /**
     * Resolve the PHP-FPM socket for a website. PHP/WordPress sites run under a
     * dedicated per-user PHP-FPM pool (provisioned by PhpFpmService) so the
     * config always points at /run/php/librestack-{username}.sock — never the
     * shared global socket.
     */
    protected function fpmSocket(Website $website): string
    {
        $username = $website->system_username;

        if (! Validators::isValidUsername((string) $username)) {
            throw new InvalidArgumentException("Invalid system username: {$username}");
        }

        return "unix:/run/php/librestack-{$username}.sock";
    }

    protected function proxyConfig(Website $website): string
    {
        $upstream = $website->upstream_url ?: 'http://127.0.0.1:3000';
        $ws = $website->websocket
            ? "        proxy_set_header Upgrade \$http_upgrade;\n"
              . "        proxy_set_header Connection \"upgrade\";\n"
            : '';

        return $this->header($website)
            . "server {\n"
            . "    listen 80;\n"
            . "    listen [::]:80;\n"
            . "    server_name {$this->serverNames($website)};\n\n"
            . $this->logBlock($website) . "\n"
            . "    location / {\n"
            . "        proxy_pass {$upstream};\n"
            . "        proxy_http_version 1.1;\n"
            . "        proxy_set_header Host \$host;\n"
            . "        proxy_set_header X-Real-IP \$remote_addr;\n"
            . "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n"
            . "        proxy_set_header X-Forwarded-Proto \$scheme;\n"
            . $ws
            . "    }\n"
            . "}\n";
    }

    /**
     * Deploy a website's config atomically: write, enable, test, rollback on any
     * failure, then reload. Never reports success unless every step succeeded.
     */
    public function deploy(Website $website): CommandResult
    {
        $config = $this->generateConfig($website);

        if (! $this->runner->isEnabled()) {
            // Dev mode: stash a preview so nothing breaks and we still return ok.
            $this->stashPreview($website, $config);

            return new CommandResult(true, 0, "[dev] config generated for {$website->domain}", '');
        }

        $domain = $website->domain;

        // Snapshot the previous config (if any) so we can roll back.
        $previous = $this->fs->readNginxConfig($domain);
        $existedBefore = $previous !== null;

        // 1. Write the new config.
        $write = $this->fs->writeNginxConfig($domain, $config);
        if (! $write->ok) {
            return $write;
        }

        // 2. Enable the symlink.
        $enable = $this->fs->enableNginxSite($domain);
        if (! $enable->ok) {
            $this->rollback($domain, $previous, $existedBefore);

            return $enable;
        }

        // 3. Test the configuration.
        $test = $this->test();
        if (! $test->ok) {
            $this->rollback($domain, $previous, $existedBefore);

            return $test;
        }

        // 4. Reload nginx.
        return $this->reload();
    }

    protected function rollback(string $domain, ?string $previous, bool $existedBefore): void
    {
        if ($existedBefore && $previous !== null) {
            // Restore the previous config and keep it enabled.
            $this->fs->writeNginxConfig($domain, $previous);
            $this->fs->enableNginxSite($domain);
        } else {
            // Nothing existed before: remove what we just created.
            $this->fs->deleteNginxSite($domain);
        }
    }

    public function test(): CommandResult
    {
        return $this->runner->run('nginx', ['-t'], 20);
    }

    public function reload(): CommandResult
    {
        return $this->runner->run('systemctl', ['reload', 'nginx'], 30);
    }

    public function restart(): CommandResult
    {
        return $this->runner->run('systemctl', ['restart', 'nginx'], 30);
    }

    public function enable(string $domain): CommandResult
    {
        if (! $this->runner->isEnabled()) {
            return new CommandResult(true, 0, '[dev] enabled', '');
        }

        $enable = $this->fs->enableNginxSite($domain);
        if (! $enable->ok) {
            return $enable;
        }

        return $this->reload();
    }

    public function disable(string $domain): CommandResult
    {
        if (! $this->runner->isEnabled()) {
            return new CommandResult(true, 0, '[dev] disabled', '');
        }

        $disable = $this->fs->disableNginxSite($domain);
        if (! $disable->ok) {
            return $disable;
        }

        return $this->reload();
    }

    public function deleteConfig(string $domain): CommandResult
    {
        if (! $this->runner->isEnabled()) {
            return new CommandResult(true, 0, '[dev] config deleted', '');
        }

        $delete = $this->fs->deleteNginxSite($domain);
        if (! $delete->ok) {
            return $delete;
        }

        return $this->reload();
    }

    public function status(): string
    {
        $result = $this->runner->run('systemctl', ['is-active', 'nginx'], 10);

        return $result->disabled ? 'unknown' : (trim($result->output) ?: 'inactive');
    }

    protected function stashPreview(Website $website, string $config): void
    {
        $dir = storage_path('app/nginx-preview');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return;
        }
        file_put_contents($dir . '/' . $this->slug($website->domain) . '.conf', $config);
    }

    protected function slug(string $domain): string
    {
        if (! Validators::isValidDomain($domain)) {
            throw new InvalidArgumentException("Invalid domain: {$domain}");
        }

        return Validators::domainSlug($domain);
    }
}
