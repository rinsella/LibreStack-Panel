<?php

namespace App\Services\Website;

use App\Models\Website;
use App\Services\Nginx\NginxService;
use App\Services\Support\CommandResult;
use App\Services\System\PhpFpmService;
use App\Services\System\PrivilegedFs;
use App\Services\System\SystemUserService;

/**
 * Provisions the on-disk structure for a website and deploys its Nginx config.
 *
 * No root-owned path is ever written directly by the web process: directory
 * creation, the placeholder index and deletion all go through PrivilegedFs
 * (the sudo-scoped safe-op layer). The owning Linux user is ensured first, and
 * PHP/WordPress sites get a dedicated per-user PHP-FPM pool.
 */
class WebsiteProvisioner
{
    public function __construct(
        protected NginxService $nginx,
        protected SystemUserService $users,
        protected PrivilegedFs $fs,
        protected PhpFpmService $phpFpm,
    ) {
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
        // 1. Ensure the owning Linux user exists (no shell access).
        $ensureUser = $this->users->ensure($website->system_username);
        if (! $ensureUser->ok && ! $ensureUser->disabled) {
            return $ensureUser;
        }

        // 2. Create the directory tree (root-owned op, then chowned to the user).
        $dirs = $this->createDirectories($website);
        if (! $dirs->ok && ! $dirs->disabled) {
            return $dirs;
        }

        // 3. PHP/WordPress sites get a dedicated per-user PHP-FPM pool so the
        //    site runs as its own user (writable WordPress, plugins, uploads).
        if ($this->needsPhpFpmPool($website)) {
            // Coerce the site to a PHP version whose FPM is actually installed,
            // then persist it so the config + future operations stay consistent.
            $version = $this->phpVersion($website);
            if ($version !== (string) $website->php_version) {
                $website->forceFill(['php_version' => $version])->save();
            }

            $pool = $this->phpFpm->ensurePool($website->system_username, $version);
            if (! $pool->ok && ! $pool->disabled) {
                return $pool;
            }
        }

        // 4. Drop a placeholder index for non-proxy sites.
        $index = $this->createIndex($website);
        if (! $index->ok && ! $index->disabled) {
            return $index;
        }

        // 5. Deploy the nginx config (uses the per-user FPM socket).
        return $this->nginx->deploy($website);
    }

    protected function needsPhpFpmPool(Website $website): bool
    {
        return in_array($website->type, ['php', 'wordpress'], true);
    }

    protected function phpVersion(Website $website): string
    {
        $preferred = (string) ($website->php_version ?: config('librestack.default_php'));

        return $this->phpFpm->resolveInstalledVersion($preferred);
    }

    public function createDirectories(Website $website): CommandResult
    {
        return $this->fs->createSiteDirs(
            $website->system_username,
            $website->domain,
            $website->document_root,
        );
    }

    public function createIndex(Website $website): CommandResult
    {
        if ($website->type === 'reverse_proxy' || $website->type === 'node_proxy') {
            return new CommandResult(true, 0, 'no index for proxy site', '');
        }

        $domain = htmlspecialchars($website->domain, ENT_QUOTES);

        return $this->fs->writeSiteIndex(
            $website->system_username,
            $website->domain,
            $website->document_root,
            $this->indexHtml($domain),
        );
    }

    public function remove(Website $website, bool $deleteFiles = false): CommandResult
    {
        $result = $this->nginx->deleteConfig($website->domain);

        // Tear down the per-user PHP-FPM pool for PHP/WordPress sites.
        if ($this->needsPhpFpmPool($website)) {
            $this->phpFpm->removePool($website->system_username, $this->phpVersion($website));
        }

        if ($deleteFiles) {
            $base = $this->siteBase($website->system_username, $website->domain);
            $delete = $this->fs->deleteSiteBase($website->system_username, $website->domain, $base);
            if (! $delete->ok && ! $delete->disabled) {
                return $delete;
            }
        }

        return $result;
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
}
