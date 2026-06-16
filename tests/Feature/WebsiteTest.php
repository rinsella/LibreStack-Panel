<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Setting::put('setup_completed', '1');

        $user = User::create([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => Hash::make('Sup3rSecret!'),
            'status'   => 'active',
        ]);
        $user->roles()->sync([Role::where('name', 'super_admin')->first()->id]);

        return $user;
    }

    public function test_website_creation_validates_domain(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/websites', [
            'domain'          => 'not a domain',
            'type'            => 'php',
            'system_username' => 'webuser',
        ])->assertSessionHasErrors('domain');

        $this->assertSame(0, Website::count());
    }

    public function test_website_creation_validates_username(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/websites', [
            'domain'          => 'example.com',
            'type'            => 'php',
            'system_username' => '1bad',
        ])->assertSessionHasErrors('system_username');
    }

    public function test_valid_website_is_created(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/websites', [
            'domain'          => 'example.com',
            'type'            => 'php',
            'system_username' => 'webuser',
            'www_alias'       => '1',
        ]);

        $website = Website::first();
        $this->assertNotNull($website);
        $this->assertSame('example.com', $website->domain);
        $this->assertStringContainsString('webuser', $website->document_root);
    }

    public function test_auditor_cannot_manage_websites(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Setting::put('setup_completed', '1');

        $auditor = User::create([
            'name' => 'Auditor', 'email' => 'a@example.com',
            'password' => Hash::make('Sup3rSecret!'), 'status' => 'active',
        ]);
        $auditor->roles()->sync([Role::where('name', 'auditor')->first()->id]);

        $this->actingAs($auditor)->get('/websites')->assertForbidden();
    }
}
