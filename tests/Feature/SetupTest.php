<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_page_loads_when_no_admin_exists(): void
    {
        $this->get('/setup')->assertOk()->assertSee('Create your admin account');
    }

    public function test_all_routes_redirect_to_setup_before_completion(): void
    {
        $this->get('/dashboard')->assertRedirect(route('setup.index'));
        $this->get('/login')->assertRedirect(route('setup.index'));
    }

    public function test_setup_creates_first_super_admin_and_completes(): void
    {
        $response = $this->post('/setup', [
            'name'                  => 'Admin',
            'email'                 => 'admin@example.com',
            'password'              => 'Sup3rSecret!',
            'password_confirmation' => 'Sup3rSecret!',
            'panel_name'            => 'My Panel',
        ]);

        $response->assertRedirect(route('dashboard'));

        $user = User::first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_setup_validation_rejects_weak_password(): void
    {
        $this->post('/setup', [
            'name'                  => 'Admin',
            'email'                 => 'admin@example.com',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }
}
