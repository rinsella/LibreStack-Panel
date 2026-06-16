<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAlias;
use App\Services\Nginx\NginxService;
use App\Services\Website\WebsiteProvisioner;
use App\Support\Audit;
use App\Support\JobRunner;
use App\Support\Validators;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WebsiteController extends Controller
{
    public function __construct(
        protected WebsiteProvisioner $provisioner,
        protected NginxService $nginx,
    ) {
    }

    public function index(Request $request)
    {
        $search = (string) $request->query('q', '');

        $websites = Website::with('owner')
            ->when($search !== '', fn ($q) => $q->where('domain', 'like', "%{$search}%"))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('websites.index', compact('websites', 'search'));
    }

    public function create()
    {
        return view('websites.create', [
            'owners'    => User::orderBy('name')->get(),
            'siteTypes' => config('librestack.site_types'),
            'phpVersions' => config('librestack.php_versions'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateWebsite($request);

        if (! Validators::isValidDomain($data['domain'])) {
            return back()->withInput()->withErrors(['domain' => 'Invalid domain name.']);
        }
        if (! Validators::isValidUsername($data['system_username'])) {
            return back()->withInput()->withErrors(['system_username' => 'Invalid system username.']);
        }

        $documentRoot = $this->provisioner->documentRoot($data['system_username'], $data['domain']);

        $website = Website::create([
            'domain'          => $data['domain'],
            'user_id'         => $data['user_id'] ?? null,
            'type'            => $data['type'],
            'php_version'     => $data['php_version'] ?? config('librestack.default_php'),
            'document_root'   => $documentRoot,
            'system_username' => $data['system_username'],
            'www_alias'       => $request->boolean('www_alias'),
            'upstream_url'    => $data['upstream_url'] ?? null,
            'websocket'       => $request->boolean('websocket'),
            'status'          => 'active',
            'enabled'         => true,
        ]);

        $this->syncAliases($website, $request->input('aliases', ''));

        $job = JobRunner::run('website.create', ['domain' => $website->domain], function ($job) use ($website) {
            $result = $this->provisioner->provision($website);
            $job->log($result->combined() ?: 'Provisioned');

            if (! $result->ok && ! $result->disabled) {
                throw new \RuntimeException('Nginx deploy failed: ' . $result->combined());
            }

            return "Website {$website->domain} provisioned.";
        });

        Audit::log('website.created', 'website', (string) $website->id, ['domain' => $website->domain]);

        return redirect()
            ->route('websites.show', $website)
            ->with($job->status === 'success' ? 'success' : 'error',
                $job->status === 'success'
                    ? "Website {$website->domain} created."
                    : "Website created but provisioning reported: {$job->message}");
    }

    public function show(Website $website)
    {
        $website->load('owner', 'aliases', 'sslCertificate', 'backups');

        $configPreview = '';
        try {
            $configPreview = $this->nginx->generateConfig($website);
        } catch (\Throwable $e) {
            $configPreview = '# ' . $e->getMessage();
        }

        return view('websites.show', compact('website', 'configPreview'));
    }

    public function edit(Website $website)
    {
        return view('websites.edit', [
            'website'     => $website->load('aliases'),
            'owners'      => User::orderBy('name')->get(),
            'siteTypes'   => config('librestack.site_types'),
            'phpVersions' => config('librestack.php_versions'),
        ]);
    }

    public function update(Request $request, Website $website): RedirectResponse
    {
        $data = $this->validateWebsite($request, $website);

        $website->update([
            'user_id'      => $data['user_id'] ?? null,
            'type'         => $data['type'],
            'php_version'  => $data['php_version'] ?? $website->php_version,
            'www_alias'    => $request->boolean('www_alias'),
            'upstream_url' => $data['upstream_url'] ?? null,
            'websocket'    => $request->boolean('websocket'),
        ]);

        $this->syncAliases($website, $request->input('aliases', ''));

        $this->nginx->deploy($website->fresh());

        Audit::log('website.updated', 'website', (string) $website->id, ['domain' => $website->domain]);

        return redirect()->route('websites.show', $website)->with('success', 'Website updated.');
    }

    public function destroy(Request $request, Website $website): RedirectResponse
    {
        $deleteFiles = $request->boolean('delete_files');
        $domain = $website->domain;

        $this->provisioner->remove($website, $deleteFiles);
        $website->delete();

        Audit::log('website.deleted', 'website', (string) $website->id, [
            'domain' => $domain, 'files_deleted' => $deleteFiles,
        ]);

        return redirect()->route('websites.index')->with('success', "Website {$domain} deleted.");
    }

    public function toggle(Website $website): RedirectResponse
    {
        $website->update(['enabled' => ! $website->enabled]);

        $website->enabled
            ? $this->nginx->enable($website->domain)
            : $this->nginx->disable($website->domain);

        Audit::log('website.toggled', 'website', (string) $website->id, ['enabled' => $website->enabled]);

        return back()->with('success', $website->enabled ? 'Website enabled.' : 'Website disabled.');
    }

    public function suspend(Website $website): RedirectResponse
    {
        $suspended = ! $website->isSuspended();
        $website->update(['status' => $suspended ? 'suspended' : 'active']);

        Audit::log('website.suspended', 'website', (string) $website->id, ['suspended' => $suspended]);

        return back()->with('success', $suspended ? 'Website suspended.' : 'Website unsuspended.');
    }

    public function redeploy(Website $website): RedirectResponse
    {
        $result = $this->nginx->deploy($website);

        Audit::log('website.redeployed', 'website', (string) $website->id);

        return back()->with(
            $result->ok || $result->disabled ? 'success' : 'error',
            $result->ok || $result->disabled ? 'Nginx config redeployed.' : ('Deploy failed: ' . $result->combined())
        );
    }

    protected function validateWebsite(Request $request, ?Website $website = null): array
    {
        return $request->validate([
            'domain'          => [$website ? 'sometimes' : 'required', 'string', 'max:253',
                                  Rule::unique('websites', 'domain')->ignore($website?->id)],
            'user_id'         => ['nullable', 'exists:users,id'],
            'type'            => ['required', Rule::in(array_keys((array) config('librestack.site_types')))],
            'php_version'     => ['nullable', Rule::in((array) config('librestack.php_versions'))],
            'system_username' => [$website ? 'nullable' : 'required', 'string', 'max:32'],
            'upstream_url'    => ['nullable', 'url', 'max:255'],
            'aliases'         => ['nullable', 'string'],
        ]);
    }

    protected function syncAliases(Website $website, ?string $raw): void
    {
        $website->aliases()->delete();

        foreach (preg_split('/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) as $domain) {
            $domain = strtolower(trim($domain));
            if (Validators::isValidDomain($domain)) {
                WebsiteAlias::firstOrCreate(['website_id' => $website->id, 'domain' => $domain]);
            }
        }
    }
}
