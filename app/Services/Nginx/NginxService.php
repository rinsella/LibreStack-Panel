<?php

namespace App\Services\Nginx;

use App\Models\Website;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
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
    public function __construct(protected CommandRunner $runner)
    {
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
    {
        return "# Managed by LibreStack Panel. Do not edit manually.\n"
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
        $php = $website->php_version ?: config('librestack.default_php');
        $socket = "unix:/run/php/php{$php}-fpm.sock";

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
     * Deploy a website's config: write, test, rollback on failure, reload.
     */
    public function deploy(Website $website): CommandResult
    {
        $config = $this->generateConfig($website);

        if (! $this->runner->isEnabled()) {
            // Dev mode: stash a preview so nothing breaks and we still return ok.
            $this->stashPreview($website, $config);

            return new CommandResult(true, 0, "[dev] config generated for {$website->domain}", '');
        }

        $available = $this->availablePath($website->domain);
        $previous = is_file($available) ? (string) file_get_contents($available) : null;

        file_put_contents($available, $config);
        $this->symlink($website->domain);

        $test = $this->test();

        if (! $test->ok) {
            // Rollback
            if ($previous !== null) {
                file_put_contents($available, $previous);
            } else {
                @unlink($available);
                @unlink($this->enabledPath($website->domain));
            }

            return $test;
        }

        return $this->reload();
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

        $this->symlink($domain);

        return $this->reload();
    }

    public function disable(string $domain): CommandResult
    {
        if (! $this->runner->isEnabled()) {
            return new CommandResult(true, 0, '[dev] disabled', '');
        }

        @unlink($this->enabledPath($domain));

        return $this->reload();
    }

    public function deleteConfig(string $domain): void
    {
        @unlink($this->enabledPath($domain));
        @unlink($this->availablePath($domain));

        if ($this->runner->isEnabled()) {
            $this->reload();
        }
    }

    public function status(): string
    {
        $result = $this->runner->run('systemctl', ['is-active', 'nginx'], 10);

        return $result->disabled ? 'unknown' : (trim($result->output) ?: 'inactive');
    }

    protected function symlink(string $domain): void
    {
        $available = $this->availablePath($domain);
        $enabled = $this->enabledPath($domain);

        if (! is_link($enabled) && ! is_file($enabled)) {
            @symlink($available, $enabled);
        }
    }

    protected function stashPreview(Website $website, string $config): void
    {
        $dir = storage_path('app/nginx-preview');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . '/' . $this->slug($website->domain) . '.conf', $config);
    }

    protected function slug(string $domain): string
    {
        if (! Validators::isValidDomain($domain)) {
            throw new InvalidArgumentException("Invalid domain: {$domain}");
        }

        return Validators::domainSlug($domain);
    }
}
