<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit;
use App\Support\PanelState;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * First-run setup wizard. Creates the first super admin account, seeds roles,
 * permissions and default settings, and marks setup as complete. No default
 * admin account is ever created automatically.
 */
class SetupController extends Controller
{
    public function index()
    {
        if (PanelState::isSetupComplete()) {
            return redirect()->route('dashboard');
        }

        return view('setup.index', [
            'requirements' => $this->requirements(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (PanelState::isSetupComplete()) {
            return redirect()->route('dashboard');
        }

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'email'      => ['required', 'email', 'max:255'],
            'password'   => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()],
            'panel_name' => ['nullable', 'string', 'max:255'],
        ]);

        // Ensure roles, permissions and default settings exist.
        (new RolePermissionSeeder())->run();
        (new SettingsSeeder())->run();

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name'              => $data['name'],
                'email'             => $data['email'],
                'password'          => Hash::make($data['password']),
                'status'            => 'active',
                'email_verified_at' => now(),
            ]);

            $superAdmin = Role::where('name', 'super_admin')->first();
            if ($superAdmin) {
                $user->roles()->sync([$superAdmin->id]);
            }

            return $user;
        });

        if (! empty($data['panel_name'])) {
            Setting::put('panel_name', $data['panel_name']);
        }
        Setting::put('admin_email', $data['email']);

        PanelState::markComplete();

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        Audit::log('setup.completed', 'user', (string) $user->id, ['email' => $user->email]);

        return redirect()->route('dashboard')->with('success', 'Welcome to LibreStack Panel!');
    }

    /**
     * @return array<int, array{label:string, ok:bool}>
     */
    protected function requirements(): array
    {
        return [
            ['label' => 'PHP ' . PHP_VERSION, 'ok' => version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['label' => 'PDO SQLite extension', 'ok' => extension_loaded('pdo_sqlite')],
            ['label' => 'OpenSSL extension', 'ok' => extension_loaded('openssl')],
            ['label' => 'Mbstring extension', 'ok' => extension_loaded('mbstring')],
            ['label' => 'cURL extension', 'ok' => extension_loaded('curl')],
            ['label' => 'Zip extension', 'ok' => extension_loaded('zip')],
            ['label' => 'storage/ writable', 'ok' => is_writable(storage_path())],
        ];
    }
}
