<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Website;
use App\Jobs\IssueCertificateJob;
use App\Jobs\RenewCertificateJob;
use App\Services\SSL\SslService;
use App\Support\Audit;
use App\Support\Validators;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SslController extends Controller
{
    public function __construct(protected SslService $ssl)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $websites = Website::with('sslCertificate')
            ->where('type', '!=', 'node_proxy')
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->get();

        return view('ssl.index', [
            'websites' => $websites,
            'sslEmail' => Setting::get('ssl_email', Setting::get('admin_email', '')),
        ]);
    }

    public function issue(Request $request, Website $website): RedirectResponse
    {
        $this->authorize('update', $website);

        $email = (string) $request->input('email', Setting::get('ssl_email', Setting::get('admin_email')));

        if (! Validators::isValidEmail($email)) {
            return back()->with('error', 'A valid email is required to issue SSL.');
        }

        IssueCertificateJob::dispatch($website->id, $email);

        Audit::log('ssl.issued', 'website', (string) $website->id, ['domain' => $website->domain, 'email' => $email]);

        return back()->with('success', "SSL issuance queued for {$website->domain}.");
    }

    public function renew(Website $website): RedirectResponse
    {
        $this->authorize('update', $website);

        RenewCertificateJob::dispatch($website->id);

        Audit::log('ssl.renewed', 'website', (string) $website->id, ['domain' => $website->domain]);

        return back()->with('success', "SSL renewal queued for {$website->domain}.");
    }

    public function destroy(Website $website): RedirectResponse
    {
        $this->authorize('update', $website);

        $this->ssl->delete($website);

        Audit::log('ssl.deleted', 'website', (string) $website->id, ['domain' => $website->domain]);

        return back()->with('success', "SSL removed for {$website->domain}.");
    }
}
