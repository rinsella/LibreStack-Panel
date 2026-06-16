<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\PanelDatabase;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Proves ownership-based authorization: a site_owner can only manage their own
 * resources and is forbidden from touching another user's website, database,
 * backup or files.
 */
class OwnershipPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Setting::put('setup_completed', '1');
    }

    private function makeUser(string $role, string $email): User
    {
        $user = User::create([
            'name'            => ucfirst($role),
            'email'           => $email,
            'password'        => Hash::make('Sup3rSecret!'),
            'status'          => 'active',
            'system_username' => 'u' . substr(md5($email), 0, 6),
        ]);
        $user->roles()->sync([Role::where('name', $role)->first()->id]);

        return $user;
    }

    private function website(User $owner): Website
    {
        return Website::create([
            'domain'          => 'site-' . substr(md5($owner->email), 0, 6) . '.com',
            'user_id'         => $owner->id,
            'type'            => 'php',
            'document_root'   => '/home/' . $owner->system_username . '/web/x/public_html',
            'system_username' => $owner->system_username,
        ]);
    }

    public function test_site_owner_can_view_own_website(): void
    {
        $owner = $this->makeUser('site_owner', 'owner@example.com');
        $site = $this->website($owner);

        $this->actingAs($owner)->get("/websites/{$site->id}")->assertOk();
    }

    public function test_site_owner_cannot_view_another_users_website(): void
    {
        $owner = $this->makeUser('site_owner', 'owner@example.com');
        $other = $this->makeUser('site_owner', 'other@example.com');
        $site = $this->website($other);

        $this->actingAs($owner)->get("/websites/{$site->id}")->assertForbidden();
    }

    public function test_site_owner_cannot_update_another_users_website(): void
    {
        $owner = $this->makeUser('site_owner', 'owner@example.com');
        $other = $this->makeUser('site_owner', 'other@example.com');
        $site = $this->website($other);

        $this->actingAs($owner)
            ->put("/websites/{$site->id}", ['type' => 'static'])
            ->assertForbidden();
    }

    public function test_site_owner_cannot_delete_another_users_website(): void
    {
        $owner = $this->makeUser('site_owner', 'owner@example.com');
        $other = $this->makeUser('site_owner', 'other@example.com');
        $site = $this->website($other);

        $this->actingAs($owner)->delete("/websites/{$site->id}")->assertForbidden();
        $this->assertDatabaseHas('websites', ['id' => $site->id]);
    }

    public function test_site_owner_cannot_access_another_users_files(): void
    {
        $owner = $this->makeUser('site_owner', 'owner@example.com');
        $other = $this->makeUser('site_owner', 'other@example.com');
        $site = $this->website($other);

        $this->actingAs($owner)
            ->get('/file-manager?website=' . $site->id)
            ->assertForbidden();
    }

    public function test_site_owner_cannot_delete_another_users_database(): void
    {
        $owner = $this->makeUser('site_owner', 'owner@example.com');
        $other = $this->makeUser('site_owner', 'other@example.com');

        $db = PanelDatabase::create(['name' => 'otherdb', 'user_id' => $other->id]);

        $this->actingAs($owner)->delete("/databases/{$db->id}")->assertForbidden();
        $this->assertDatabaseHas('panel_databases', ['id' => $db->id]);
    }

    public function test_site_owner_cannot_restore_another_users_backup(): void
    {
        $owner = $this->makeUser('site_owner', 'owner@example.com');
        $other = $this->makeUser('site_owner', 'other@example.com');
        $site = $this->website($other);

        $backup = Backup::create([
            'website_id' => $site->id,
            'domain'     => $site->domain,
            'type'       => 'full',
            'status'     => 'success',
            'created_by' => $other->id,
        ]);

        $this->actingAs($owner)
            ->post("/backups/{$backup->id}/restore")
            ->assertForbidden();
    }

    public function test_admin_can_view_any_website(): void
    {
        $admin = $this->makeUser('admin', 'admin@example.com');
        $owner = $this->makeUser('site_owner', 'owner@example.com');
        $site = $this->website($owner);

        $this->actingAs($admin)->get("/websites/{$site->id}")->assertOk();
    }
}
