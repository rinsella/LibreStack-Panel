<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'panel_name'        => 'LibreStack Panel',
            'panel_url'         => config('app.url'),
            'admin_email'       => '',
            'ssl_email'         => '',
            'backup_path'       => config('librestack.paths.backups'),
            'default_php'       => config('librestack.default_php'),
            'db_admin_username' => 'root',
            'theme_mode'        => 'dark',
        ];

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value, 'is_encrypted' => false]);
        }
    }
}
