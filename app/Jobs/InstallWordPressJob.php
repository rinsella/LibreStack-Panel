<?php

namespace App\Jobs;

use App\Models\SystemJob;
use App\Models\Website;
use App\Services\WordPress\WordPressService;
use RuntimeException;

class InstallWordPressJob extends BaseSystemJob
{
    public function __construct(public int $websiteId)
    {
        parent::__construct();
    }

    protected function type(): string
    {
        return 'wordpress.install';
    }

    protected function payload(): array
    {
        return ['website_id' => $this->websiteId];
    }

    protected function execute(SystemJob $job): string
    {
        $website = Website::findOrFail($this->websiteId);
        $result = app(WordPressService::class)->install($website);

        // Never log the database password; only the non-sensitive identifiers.
        if (! empty($result['db']['name'])) {
            $job->log("Provisioned database {$result['db']['name']} (user {$result['db']['user']}). "
                . 'The password is stored in wp-config.php only.');
        }

        if (! $result['ok']) {
            throw new RuntimeException($result['message']);
        }

        $website->update(['type' => 'wordpress']);

        return 'WordPress installed for ' . $website->domain;
    }
}
