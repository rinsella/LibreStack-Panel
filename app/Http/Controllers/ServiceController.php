<?php

namespace App\Http\Controllers;

use App\Services\System\ServiceManager;
use App\Support\Audit;
use App\Support\Validators;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function __construct(protected ServiceManager $services)
    {
    }

    public function index()
    {
        return view('services.index', [
            'services' => $this->services->list(),
        ]);
    }

    public function action(Request $request, string $service): RedirectResponse
    {
        if (! Validators::isValidServiceName($service)) {
            abort(404);
        }

        $action = (string) $request->input('action');
        if (! in_array($action, ['start', 'stop', 'restart', 'reload'], true)) {
            return back()->with('error', 'Unknown action.');
        }

        $result = $this->services->{$action}($service);

        Audit::log('service.' . $action, 'service', null, ['service' => $service]);

        return back()->with(
            $result->ok || $result->disabled ? 'success' : 'error',
            $result->disabled
                ? 'System commands are disabled in dev mode.'
                : ($result->ok ? "Service {$service} {$action} ok." : "Failed: " . $result->combined())
        );
    }

    public function logs(string $service)
    {
        if (! Validators::isValidServiceName($service)) {
            abort(404);
        }

        return view('services.logs', [
            'service' => $service,
            'logs'    => $this->services->journal($service, 200),
        ]);
    }
}
