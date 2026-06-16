<?php

namespace App\Jobs;

use App\Models\SystemJob;
use App\Models\Website;
use App\Services\Website\WebsiteProvisioner;
use RuntimeException;

class ProvisionWebsiteJob extends BaseSystemJob
{
    public function __construct(public int $websiteId)
    {
        parent::__construct();
    }

    protected function type(): string
    {
        return 'website.create';
    }

    protected function payload(): array
    {
        return ['website_id' => $this->websiteId];
    }

    protected function execute(SystemJob $job): string
    {
        $website = Website::findOrFail($this->websiteId);
        $result = app(WebsiteProvisioner::class)->provision($website);

        $job->log($result->combined() ?: 'Provisioned');

        // Record the PHP settings actually applied to the pool so the job detail
        // shows them (and makes a stale worker obvious: old code never logs this).
        if (in_array($website->type, ['php', 'wordpress'], true)) {
            $applied = collect($website->phpSettings())
                ->map(fn ($v, $k) => "{$k}={$v}")
                ->implode(', ');
            $job->log("PHP settings applied: {$applied}");
        }

        if (! $result->ok && ! $result->disabled) {
            throw new RuntimeException('Nginx deploy failed: ' . $result->combined());
        }

        return "Website {$website->domain} provisioned.";
    }
}
