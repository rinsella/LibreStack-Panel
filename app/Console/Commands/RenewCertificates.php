<?php

namespace App\Console\Commands;

use App\Models\SslCertificate;
use App\Services\SSL\SslService;
use Illuminate\Console\Command;

class RenewCertificates extends Command
{
    protected $signature = 'librestack:renew-ssl {--days=30}';

    protected $description = 'Renew Let\'s Encrypt certificates that expire within the given window.';

    public function handle(SslService $ssl): int
    {
        $days = (int) $this->option('days');

        $due = SslCertificate::with('website')
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($days))
            ->get();

        foreach ($due as $cert) {
            if (! $cert->website) {
                continue;
            }
            $this->info("Renewing {$cert->domain}");
            $ssl->renew($cert->website);
        }

        $this->info("Processed {$due->count()} certificate(s).");

        return self::SUCCESS;
    }
}
