<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class PanelState
{
    /**
     * The panel is considered set up when the database is migrated, at least
     * one user exists, and the setup_completed flag is stored.
     */
    public static function isSetupComplete(): bool
    {
        try {
            if (! Schema::hasTable('users') || ! Schema::hasTable('settings')) {
                return false;
            }

            if (User::query()->count() === 0) {
                return false;
            }

            return Setting::get('setup_completed') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    public static function markComplete(): void
    {
        Setting::put('setup_completed', '1');
    }
}
