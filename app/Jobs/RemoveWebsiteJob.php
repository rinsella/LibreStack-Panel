<?php

namespace App\Jobs;

use App\Models\SystemJob;
use App\Models\Website;
use App\Services\Website\WebsiteProvisioner;

/**
 * Tears down a website's on-disk structure, its per-user PHP-FPM pool and its
 * Nginx config, then removes the database record.
 *
 * This runs on the QUEUE WORKER (a separate process) rather than in the web
 * request, because the teardown reloads php{version}-fpm — the same PHP-FPM
 * master that serves the panel itself. Reloading it from inside the request it
 * is serving would kill that request (HTTP 502). Doing it on the worker keeps
 * the panel responsive.
 */
class RemoveWebsiteJob extends BaseSystemJob
{
    public function __construct(public int $websiteId, public bool $deleteFiles = false)
    {
        parent::__construct();
    }

    protected function type(): string
    {
        return 'website.delete';
    }

    protected function payload(): array
    {
        return ['website_id' => $this->websiteId, 'delete_files' => $this->deleteFiles];
    }

    protected function execute(SystemJob $job): string
    {
        $website = Website::find($this->websiteId);
        if (! $website) {
            return 'Website already removed.';
        }

        $domain = $website->domain;
        $result = app(WebsiteProvisioner::class)->remove($website, $this->deleteFiles);

        if (! $result->ok && ! $result->disabled) {
            // The on-disk teardown is best-effort: the Nginx vhost is removed
            // first, so the site is already offline. Log the issue but still
            // remove the record so the deletion the operator asked for completes.
            $job->log('Teardown reported an issue: ' . $result->combined(), 'error');
        } else {
            $job->log($result->combined() ?: 'Removed');
        }

        $website->delete();

        return "Website {$domain} removed.";
    }
}
