<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Website;
use App\Services\Nginx\NginxService;
use App\Services\Website\WebsiteProvisioner;
use App\Support\Audit;
use App\Support\Validators;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reverse proxy websites are regular Website records of type reverse_proxy.
 * This controller offers a focused workflow for creating/managing them.
 */
class ReverseProxyController extends Controller
{
    public function __construct(
        protected WebsiteProvisioner $provisioner,
        protected NginxService $nginx,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $sites = Website::whereIn('type', ['reverse_proxy', 'node_proxy'])
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->paginate(15);

        return view('reverse-proxy.index', compact('sites'));
    }

    public function create(Request $request)
    {
        // Only admins may assign an owner; everyone else owns what they create.
        $owners = $request->user()->isAdmin()
            ? User::orderBy('name')->get()
            : collect();

        return view('reverse-proxy.create', [
            'owners' => $owners,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Website::class);

        $data = $request->validate([
            'domain'          => ['required', 'string', 'max:253', 'unique:websites,domain'],
            'system_username' => ['required', 'string', 'max:32'],
            'upstream_url'    => ['required', 'url', 'max:255'],
            'user_id'         => ['nullable', 'exists:users,id'],
            'type'            => ['required', Rule::in(['reverse_proxy', 'node_proxy'])],
        ]);

        if (! Validators::isValidDomain($data['domain'])) {
            return back()->withInput()->withErrors(['domain' => 'Invalid domain.']);
        }
        if (! Validators::isValidUsername($data['system_username'])) {
            return back()->withInput()->withErrors(['system_username' => 'Invalid system username.']);
        }

        // Non-admins always own what they create and may not set user_id.
        $ownerId = $request->user()->isAdmin() ? ($data['user_id'] ?? null) : $request->user()->id;

        $website = Website::create([
            'domain'          => $data['domain'],
            'user_id'         => $ownerId,
            'type'            => $data['type'],
            'document_root'   => $this->provisioner->documentRoot($data['system_username'], $data['domain']),
            'system_username' => $data['system_username'],
            'www_alias'       => $request->boolean('www_alias'),
            'upstream_url'    => $data['upstream_url'],
            'websocket'       => $request->boolean('websocket'),
            'force_https'     => $request->boolean('force_https'),
            'status'          => 'active',
            'enabled'         => true,
        ]);

        $this->nginx->deploy($website);

        Audit::log('reverse_proxy.created', 'website', (string) $website->id, [
            'domain' => $website->domain, 'upstream' => $website->upstream_url,
        ]);

        return redirect()->route('reverse-proxy.index')->with('success', "Reverse proxy for {$website->domain} created.");
    }

    public function destroy(Website $website): RedirectResponse
    {
        $this->authorize('delete', $website);

        if (! in_array($website->type, ['reverse_proxy', 'node_proxy'], true)) {
            return back()->with('error', 'This website is not a reverse proxy.');
        }

        $domain = $website->domain;
        $this->provisioner->remove($website, false);
        $website->delete();

        Audit::log('reverse_proxy.deleted', 'website', (string) $website->id, ['domain' => $domain]);

        return back()->with('success', "Reverse proxy {$domain} deleted.");
    }
}
