<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    /** Plain (non-secret) settings. */
    protected array $plainKeys = [
        'panel_name', 'panel_url', 'admin_email', 'ssl_email',
        'backup_path', 'default_php', 'db_admin_username', 'theme_mode',
    ];

    public function index()
    {
        $settings = [];
        foreach ($this->plainKeys as $key) {
            $settings[$key] = Setting::get($key, '');
        }

        return view('settings.index', [
            'settings'      => $settings,
            'hasDbPassword' => Setting::get('db_admin_password') !== null,
            'phpVersions'   => config('librestack.php_versions'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'panel_name'        => ['required', 'string', 'max:255'],
            'panel_url'         => ['nullable', 'url', 'max:255'],
            'admin_email'       => ['nullable', 'email', 'max:255'],
            'ssl_email'         => ['nullable', 'email', 'max:255'],
            'backup_path'       => ['nullable', 'string', 'max:255'],
            'default_php'       => ['nullable', Rule::in((array) config('librestack.php_versions'))],
            'db_admin_username' => ['nullable', 'string', 'max:64'],
            'theme_mode'        => ['required', 'in:light,dark'],
            'db_admin_password' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($this->plainKeys as $key) {
            if (array_key_exists($key, $data)) {
                Setting::put($key, $data[$key]);
            }
        }

        // Only overwrite the encrypted DB password when a new value is provided.
        if (! empty($data['db_admin_password'])) {
            Setting::put('db_admin_password', $data['db_admin_password'], encrypted: true);
        }

        Audit::log('settings.updated', null, null, ['keys' => array_keys($data)]);

        return back()->with('success', 'Settings saved.');
    }
}
