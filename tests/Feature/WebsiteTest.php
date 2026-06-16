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

    public function test_website_deletion_is_queued_and_removes_the_record(): void
    {
        $admin = $this->admin();

        $website = Website::create([
            'domain'          => 'del-test.com',
            'user_id'         => $admin->id,
            'type'            => 'php',
            'php_version'     => '8.3',
            'document_root'   => '/home/webuser/web/del-test.com/public_html',
            'system_username' => 'webuser',
            'status'          => 'active',
            'enabled'         => true,
        ]);

        $this->actingAs($admin)
            ->delete("/websites/{$website->id}")
            ->assertRedirect(route('websites.index'));

        // The teardown is dispatched to the queue (sync in tests) so the record
        // is removed by RemoveWebsiteJob, not synchronously in the request.
        $this->assertNull(Website::find($website->id));
        $this->assertDatabaseHas('system_jobs', ['type' => 'website.delete']);
    }

    public function test_redeploy_queues_a_provision_job(): void
    {
        $admin = $this->admin();

        $website = Website::create([
            'domain'          => 'redeploy-test.com',
            'user_id'         => $admin->id,
            'type'            => 'static',
            'document_root'   => '/home/webuser/web/redeploy-test.com/public_html',
            'system_username' => 'webuser',
            'status'          => 'active',
            'enabled'         => true,
        ]);

        $this->actingAs($admin)
            ->post("/websites/{$website->id}/redeploy")
            ->assertRedirect();

        $this->assertDatabaseHas('system_jobs', ['type' => 'website.create']);
    }
}
