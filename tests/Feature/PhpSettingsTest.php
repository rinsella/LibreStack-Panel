<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Nginx\NginxService;
use App\Services\System\PhpFpmService;
use App\Services\System\PhpSettings;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Per-site PHP settings manager: validation, pool injection, nginx body size,
 * and the edit/update HTTP flow.
 */
class PhpSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Setting::put('setup_completed', '1');

        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com',
            'password' => Hash::make('Sup3rSecret!'), 'status' => 'active',
        ]);
        $user->roles()->sync([Role::where('name', 'super_admin')->first()->id]);

        return $user;
    }

    private function website(array $overrides = []): Website
    {
        return Website::create(array_merge([
            'domain'          => 'php-settings.com',
            'type'            => 'wordpress',
            'php_version'     => '8.3',
            'document_root'   => '/home/webuser/web/php-settings.com/public_html',
            'system_username' => 'webuser',
            'status'          => 'active',
            'enabled'         => true,
        ], $overrides));
    }

    public function test_size_and_int_validation(): void
    {
        $sizeDef = ['min' => 1048576, 'max' => 2147483648];
        $this->assertTrue(PhpSettings::isValidValue('size', '64M', $sizeDef));
        $this->assertTrue(PhpSettings::isValidValue('size', '1G', $sizeDef));
        $this->assertFalse(PhpSettings::isValidValue('size', '64MB', $sizeDef));   // bad suffix
        $this->assertFalse(PhpSettings::isValidValue('size', '64; rm', $sizeDef)); // injection attempt
        $this->assertFalse(PhpSettings::isValidValue('size', '1K', $sizeDef));     // below min

        $intDef = ['min' => 5, 'max' => 3600];
        $this->assertTrue(PhpSettings::isValidValue('int', '30', $intDef));
        $this->assertFalse(PhpSettings::isValidValue('int', '3', $intDef));        // below min
        $this->assertFalse(PhpSettings::isValidValue('int', '30s', $intDef));      // not an integer
    }

    public function test_sanitize_drops_unknown_and_invalid_keys(): void
    {
        $clean = PhpSettings::sanitize([
            'upload_max_filesize' => '64M',
            'evil_directive'      => 'value',           // unknown key dropped
            'memory_limit'        => "256M\nbad = x",   // newline injection dropped
        ]);

        $this->assertSame(['upload_max_filesize' => '64M'], $clean);
    }

    public function test_to_bytes_parses_shorthand(): void
    {
        $this->assertSame(64 * 1024 * 1024, PhpSettings::toBytes('64M'));
        $this->assertSame(1024 * 1024 * 1024, PhpSettings::toBytes('1G'));
        $this->assertSame(0, PhpSettings::toBytes('-1'));
    }

    public function test_pool_config_injects_validated_settings_only(): void
    {
        config(['librestack.paths.web_root' => '/home']);

        $pool = app(PhpFpmService::class)->poolConfig('webuser', '8.3', [
            'upload_max_filesize' => '64M',
            'memory_limit'        => '512M',
            'evil'                => "x\npm = static", // must never appear
        ]);

        $this->assertStringContainsString('php_admin_value[upload_max_filesize] = 64M', $pool);
        $this->assertStringContainsString('php_admin_value[memory_limit] = 512M', $pool);
        $this->assertStringNotContainsString('evil', $pool);
        $this->assertStringNotContainsString('pm = static', $pool);
    }

    public function test_nginx_body_size_tracks_upload_limit(): void
    {
        $website = $this->website();
        $website->update(['meta' => ['php_settings' => ['upload_max_filesize' => '64M', 'post_max_size' => '64M']]]);

        $config = app(NginxService::class)->generateConfig($website->fresh());

        $this->assertStringContainsString('client_max_body_size 64m;', $config);
    }

    public function test_default_nginx_body_size_is_at_least_post_max(): void
    {
        // With no overrides the default post_max_size (8M) should drive a body
        // size big enough that stock WordPress uploads are not blocked by nginx.
        $config = app(NginxService::class)->generateConfig($this->website());

        $this->assertStringContainsString('client_max_body_size 8m;', $config);
    }

    public function test_update_saves_settings_and_queues_apply(): void
    {
        $admin = $this->admin();
        $website = $this->website(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->put(route('php-settings.update', $website), [
                'upload_max_filesize' => '128M',
                'post_max_size'       => '128M',
                'memory_limit'        => '512M',
                'max_execution_time'  => '120',
                'max_input_time'      => '120',
                'max_input_vars'      => '5000',
            ])
            ->assertRedirect(route('php-settings.edit', $website));

        $website->refresh();
        $this->assertSame('128M', $website->phpSettingOverrides()['upload_max_filesize']);
        $this->assertDatabaseHas('system_jobs', ['type' => 'website.create']);
    }

    public function test_update_rejects_post_smaller_than_upload(): void
    {
        $admin = $this->admin();
        $website = $this->website(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->put(route('php-settings.update', $website), [
                'upload_max_filesize' => '128M',
                'post_max_size'       => '8M', // smaller than upload → error
                'memory_limit'        => '256M',
                'max_execution_time'  => '30',
                'max_input_time'      => '60',
                'max_input_vars'      => '1000',
            ])
            ->assertSessionHasErrors('post_max_size');

        $this->assertSame([], $website->fresh()->phpSettingOverrides());
    }

    public function test_static_site_has_no_php_settings_page(): void
    {
        $admin = $this->admin();
        $website = $this->website(['type' => 'static', 'domain' => 'static-site.com', 'user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('php-settings.edit', $website))
            ->assertRedirect(route('websites.show', $website));
    }
}
