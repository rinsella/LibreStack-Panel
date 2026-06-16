<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Website;
use App\Services\SSL\SslService;
use App\Support\Audit;
use App\Support\JobRunner;
use App\Support\Validators;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SslController extends Controller
{
    public function __construct(protected SslService $ssl)
    {
    }

    public function index()
    {
        $websites = Website::with('sslCertificate')
            ->where('type', '!=', 'node_proxy')
            ->latest()
            ->get();

        return view('ssl.index', [
            'websites' => $websites,
            'sslEmail' => Setting::get('ssl_email', Setting::get('admin_email', '')),
        ]);
    }

    public function issue(Request $request, Website $website): RedirectResponse
    {
        $email = (string) $request->input('email', Setting::get('ssl_email', Setting::get('admin_email')));

        if (! Validators::isValidEmail($email)) {
            return back()->with('error', 'A valid email is required to issue SSL.');
        }

        $job = JobRunner::run('ssl.issue', ['domain' => $website->domain], function ($job) use ($website, $email) {
            $result = $this->ssl->issue($website, $email);
            $job->log($result->combined() ?: 'certbot finished');

            if (! $result->ok && ! $result->disabled) {
                throw new \RuntimeException('certbot failed: ' . $result->combined());
            }

            return $result->disabled
                ? 'SSL recorded (system commands disabled in dev).'
                : "SSL issued for {$website->domain}.";
        });

        Audit::log('ssl.issued', 'website', (string) $website->id, ['domain' => $website->domain, 'email' => $email]);

        return back()->with($job->status === 'success' ? 'success' : 'error', $job->message);
    }

    public function renew(Website $website): RedirectResponse
    {
        $job = JobRunner::run('ssl.renew', ['domain' => $website->domain], function ($job) use ($website) {
            $result = $this->ssl->renew($website);
            $job->log($result->combined() ?: 'certbot renew finished');

            if (! $result->ok && ! $result->disabled) {
                throw new \RuntimeException('certbot renew failed: ' . $result->combined());
            }

            return "SSL renewed for {$website->domain}.";
        });

        Audit::log('ssl.renewed', 'website', (string) $website->id, ['domain' => $website->domain]);

        return back()->with($job->status === 'success' ? 'success' : 'error', $job->message);
    }

    public function destroy(Website $website): RedirectResponse
    {
        $this->ssl->delete($website);

        Audit::log('ssl.deleted', 'website', (string) $website->id, ['domain' => $website->domain]);

        return back()->with('success', "SSL removed for {$website->domain}.");
    }
}
