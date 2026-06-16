<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Nginx\NginxService;
use Illuminate\Console\Command;

/**
 * Regenerate and redeploy every managed site's nginx vhost.
 *
 * Useful after upgrading the panel: it rewrites each vhost with the current
 * template (including the panel-owned HTTPS server block), repairing sites whose
 * config drifted — e.g. an SSL block that an earlier redeploy used to wipe out.
 */
class RedeploySites extends Command
{
    protected $signature = 'librestack:redeploy-sites {domain? : Only redeploy this domain}';

    protected $description = 'Regenerate and redeploy nginx vhosts for managed sites.';

    public function handle(NginxService $nginx): int
    {
        $query = Website::query()->where('status', '!=', 'deleting');

        if ($domain = $this->argument('domain')) {
            $query->where('domain', $domain);
        }

        $sites = $query->get();

        if ($sites->isEmpty()) {
            $this->warn('No matching sites found.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($sites as $site) {
            $result = $nginx->deploy($site);

            if ($result->ok || $result->disabled) {
                $this->info("OK   {$site->domain}");
            } else {
                $failures++;
                $this->error("FAIL {$site->domain}: " . $result->combined());
            }
        }

        $this->line('');
        $this->info("Redeployed {$sites->count()} site(s), {$failures} failure(s).");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
