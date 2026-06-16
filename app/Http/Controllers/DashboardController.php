<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\PanelDatabase;
use App\Models\SystemJob;
use App\Models\Website;
use App\Services\Firewall\FirewallService;
use App\Services\System\ServerInfoService;
use App\Services\System\ServiceManager;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        ServerInfoService $server,
        ServiceManager $services,
    ) {
        $serviceStatuses = [];
        foreach (['nginx', 'php' . config('librestack.default_php') . '-fpm', 'mariadb', 'ufw'] as $name) {
            try {
                $serviceStatuses[$name] = in_array($name, (array) config('librestack.allowed_services'), true)
                    ? $services->status($name)
                    : 'unknown';
            } catch (\Throwable) {
                $serviceStatuses[$name] = 'unknown';
            }
        }

        return view('dashboard.index', [
            'info'      => $server->summary(),
            'services'  => $serviceStatuses,
            'counts'    => [
                'websites'  => Website::count(),
                'databases' => PanelDatabase::count(),
                'backups'   => Backup::count(),
            ],
            'recentJobs'  => SystemJob::latest()->take(5)->get(),
            'recentAudit' => AuditLog::with('user')->latest()->take(8)->get(),
        ]);
    }
}
