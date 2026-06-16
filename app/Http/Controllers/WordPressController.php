<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Jobs\InstallWordPressJob;
use App\Services\WordPress\WordPressService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WordPressController extends Controller
{
    public function __construct(protected WordPressService $wordpress)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $sites = Website::whereIn('type', ['wordpress', 'php'])
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->latest()->get()
            ->map(function (Website $site) {
                $site->wp_version = $this->wordpress->detectVersion($site);

                return $site;
            });

        return view('wordpress.index', compact('sites'));
    }

    public function install(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'website_id' => ['required', 'exists:websites,id'],
            'confirm'    => ['nullable'],
        ]);

        $website = Website::findOrFail($data['website_id']);
        $this->authorize('update', $website);

        // Refuse to overwrite a non-empty docroot unless confirmed.
        $docroot = $website->document_root;
        $notEmpty = is_dir($docroot) && count(array_diff(scandir($docroot) ?: [], ['.', '..', 'index.html'])) > 0;

        if ($notEmpty && ! $request->boolean('confirm')) {
            return back()->with('error', 'Document root is not empty. Confirm overwrite to continue.');
        }

        InstallWordPressJob::dispatch($website->id);

        Audit::log('wordpress.installed', 'website', (string) $website->id, ['domain' => $website->domain]);

        return back()->with('success',
            "WordPress installation queued for {$website->domain}. "
            . 'Database credentials are written to wp-config.php (mode 0640). Track progress on the Jobs page.');
    }
}
