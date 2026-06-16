<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function completeSetup(): User
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

    public function test_login_page_loads_after_setup(): void
    {
        $this->completeSetup();

        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->completeSetup();

        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_user_can_login_and_reach_dashboard(): void
    {
        $user = $this->completeSetup();

        $this->post('/login', [
            'email'    => 'admin@example.com',
            'password' => 'Sup3rSecret!',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->completeSetup();

        $this->post('/login', [
            'email'    => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
