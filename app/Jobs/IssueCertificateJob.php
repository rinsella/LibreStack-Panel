<?php

namespace App\Jobs;

use App\Models\SystemJob;
use App\Models\Website;
use App\Services\SSL\SslService;
use RuntimeException;

class IssueCertificateJob extends BaseSystemJob
{
    public function __construct(public int $websiteId, public string $email)
    {
        parent::__construct();
    }

    protected function type(): string
    {
        return 'ssl.issue';
    }

    protected function payload(): array
    {
        return ['website_id' => $this->websiteId, 'email' => $this->email];
    }

    protected function execute(SystemJob $job): string
    {
        $website = Website::findOrFail($this->websiteId);
        $result = app(SslService::class)->issue($website, $this->email);

        $job->log($result->combined() ?: 'certbot finished');

        if (! $result->ok && ! $result->disabled) {
            throw new RuntimeException('certbot failed: ' . $result->combined());
        }

        return $result->disabled
            ? 'SSL recorded (system commands disabled in dev).'
            : "SSL issued for {$website->domain}.";
    }
}
