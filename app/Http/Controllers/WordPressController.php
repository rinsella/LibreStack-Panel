<?php

namespace App\Http\Controllers;

use App\Models\PanelDatabase;
use App\Models\Website;
use App\Services\WordPress\WordPressService;
use App\Support\Audit;
use App\Support\JobRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WordPressController extends Controller
{
    public function __construct(protected WordPressService $wordpress)
    {
    }

    public function index()
    {
        $sites = Website::whereIn('type', ['wordpress', 'php'])->latest()->get()
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

        // Refuse to overwrite a non-empty docroot unless confirmed.
        $docroot = $website->document_root;
        $notEmpty = is_dir($docroot) && count(array_diff(scandir($docroot) ?: [], ['.', '..', 'index.html'])) > 0;

        if ($notEmpty && ! $request->boolean('confirm')) {
            return back()->with('error', 'Document root is not empty. Confirm overwrite to continue.');
        }

        $result = null;
        $job = JobRunner::run('wordpress.install', ['domain' => $website->domain], function ($job) use ($website, &$result) {
            $result = $this->wordpress->install($website);
            $job->log($result['message']);

            if (! $result['ok']) {
                throw new \RuntimeException($result['message']);
            }

            $website->update(['type' => 'wordpress']);

            // Record the provisioned database for visibility.
            if (! empty($result['db']['name'])) {
                PanelDatabase::firstOrCreate(
                    ['name' => $result['db']['name']],
                    ['website_id' => $website->id, 'user_id' => $website->user_id]
                );
            }

            return 'WordPress installed for ' . $website->domain;
        });

        Audit::log('wordpress.installed', 'website', (string) $website->id, ['domain' => $website->domain]);

        if ($job->status === 'success' && $result) {
            return back()->with('success', $result['message'])
                ->with('wp_db', $result['db']);
        }

        return back()->with('error', $job->message ?: 'WordPress installation failed.');
    }
}
