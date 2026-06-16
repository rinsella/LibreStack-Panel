<?php

namespace App\Jobs;

use App\Models\SystemJob;
use App\Models\Website;
use App\Services\SSL\SslService;
use RuntimeException;

class RenewCertificateJob extends BaseSystemJob
{
    public function __construct(public int $websiteId)
    {
        parent::__construct();
    }

    protected function type(): string
    {
        return 'ssl.renew';
    }

    protected function payload(): array
    {
        return ['website_id' => $this->websiteId];
    }

    protected function execute(SystemJob $job): string
    {
        $website = Website::findOrFail($this->websiteId);
        $result = app(SslService::class)->renew($website);

        $job->log($result->combined() ?: 'certbot renew finished');

        if (! $result->ok && ! $result->disabled) {
            throw new RuntimeException('certbot renew failed: ' . $result->combined());
        }

        return "SSL renewed for {$website->domain}.";
    }
}
