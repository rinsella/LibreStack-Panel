<?php

namespace App\Http\Controllers;

use App\Services\Firewall\FirewallService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FirewallController extends Controller
{
    public function __construct(protected FirewallService $firewall)
    {
    }

    public function index()
    {
        return view('firewall.index', [
            'status'  => $this->firewall->status(),
            'rules'   => $this->firewall->rules(),
            'presets' => config('librestack.firewall_presets'),
        ]);
    }

    public function toggle(Request $request): RedirectResponse
    {
        $enable = $request->boolean('enable');

        $result = $enable ? $this->firewall->enable() : $this->firewall->disable();

        Audit::log('firewall.' . ($enable ? 'enabled' : 'disabled'));

        return back()->with(
            $result->ok || $result->disabled ? 'success' : 'error',
            $result->disabled ? 'System commands disabled in dev mode.' : ($enable ? 'Firewall enabled.' : 'Firewall disabled.')
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'port'   => ['required', 'integer', 'min:1', 'max:65535'],
            'proto'  => ['required', 'in:tcp,udp'],
            'policy' => ['required', 'in:allow,deny'],
        ]);

        $result = $data['policy'] === 'allow'
            ? $this->firewall->allow((int) $data['port'], $data['proto'])
            : $this->firewall->deny((int) $data['port'], $data['proto']);

        Audit::log('firewall.rule_added', null, null, $data);

        return back()->with(
            $result->ok || $result->disabled ? 'success' : 'error',
            $result->disabled ? 'System commands disabled in dev mode.' : 'Firewall rule applied.'
        );
    }

    public function destroy(int $number): RedirectResponse
    {
        $result = $this->firewall->deleteRule($number);

        Audit::log('firewall.rule_deleted', null, null, ['number' => $number]);

        return back()->with(
            $result->ok || $result->disabled ? 'success' : 'error',
            $result->disabled ? 'System commands disabled in dev mode.' : 'Firewall rule deleted.'
        );
    }
}
