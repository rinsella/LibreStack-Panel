<?php

namespace App\Services\Website;

use App\Models\Website;
use App\Services\Nginx\NginxService;
use App\Services\Support\CommandResult;
use App\Support\Validators;

/**
 * Provisions the on-disk structure for a website and deploys its Nginx config.
 */
class WebsiteProvisioner
{
    public function __construct(protected NginxService $nginx)
    {
    }

    /**
     * Compute the standard document root for a domain/user.
     */
    public function documentRoot(string $username, string $domain): string
    {
        $webRoot = rtrim((string) config('librestack.paths.web_root'), '/');

        return "{$webRoot}/{$username}/web/{$domain}/public_html";
    }

    public function siteBase(string $username, string $domain): string
    {
        $webRoot = rtrim((string) config('librestack.paths.web_root'), '/');

        return "{$webRoot}/{$username}/web/{$domain}";
    }

    public function provision(Website $website): CommandResult
    {
        $this->createDirectories($website);
        $this->createIndex($website);

        return $this->nginx->deploy($website);
    }

    public function createDirectories(Website $website): void
    {
        $base = dirname($website->document_root);

        foreach ([
            $website->document_root,
            $base . '/logs',
            $base . '/backups',
        ] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    public function createIndex(Website $website): void
    {
        if ($website->type === 'reverse_proxy' || $website->type === 'node_proxy') {
            return;
        }

        $index = rtrim($website->document_root, '/') . '/index.html';
        if (is_file($index)) {
            return;
        }

        $domain = htmlspecialchars($website->domain, ENT_QUOTES);
        @file_put_contents($index, $this->indexHtml($domain));
    }

    public function remove(Website $website, bool $deleteFiles = false): void
    {
        $this->nginx->deleteConfig($website->domain);

        if ($deleteFiles) {
            $base = dirname($website->document_root);
            if (is_dir($base) && str_starts_with($base, (string) config('librestack.paths.web_root'))) {
                $this->recursiveDelete($base);
            }
        }
    }

    protected function indexHtml(string $domain): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$domain}</title>
    <style>
        body { font-family: system-ui, sans-serif; background:#0f1626; color:#e2e8f0;
               display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
        .card { text-align:center; }
        h1 { font-size:2rem; margin-bottom:.5rem; }
        p { color:#94a3b8; }
        .badge { display:inline-block; margin-top:1rem; padding:.35rem .75rem; border-radius:9999px;
                 background:#1d4ed8; color:#fff; font-size:.8rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{$domain}</h1>
        <p>Your website is live and managed by LibreStack Panel.</p>
        <span class="badge">LibreStack Panel</span>
    </div>
</body>
</html>
HTML;
    }

    protected function recursiveDelete(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->recursiveDelete($path . '/' . $entry);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}
