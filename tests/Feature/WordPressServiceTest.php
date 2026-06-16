<?php

namespace Tests\Feature;

use App\Models\DatabaseUser;
use App\Models\PanelDatabase;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Database\DatabaseService;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use App\Services\System\PrivilegedFs;
use App\Services\System\SafeOps;
use App\Services\WordPress\WordPressService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WordPressServiceTest extends TestCase
{
    use RefreshDatabase;

    private function website(): Website
    {
        return Website::create([
            'domain'          => 'wp-test.com',
            'type'            => 'php',
            'document_root'   => sys_get_temp_dir() . '/ls_wp_' . uniqid(),
            'system_username' => 'webuser',
        ]);
    }

    public function test_install_creates_panel_database_and_user_records(): void
    {
        config(['librestack.system_enabled' => false]);

        $website = $this->website();
        $result = app(WordPressService::class)->install($website);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('panel_databases', [
            'website_id' => $website->id,
            'name'       => $result['db']['name'],
        ]);
        $this->assertDatabaseHas('database_users', [
            'username' => $result['db']['user'],
        ]);
    }

    public function test_failed_install_rolls_back_database_records(): void
    {
        config(['librestack.system_enabled' => true]);

        $website = $this->website();

        // Runner where every command (curl download) fails.
        $failingRunner = new class extends CommandRunner {
            public function run(string $binary, array $args = [], ?int $timeout = 60, ?string $input = null): CommandResult
            {
                return new CommandResult(false, 1, '', 'boom');
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };

        // DatabaseService whose drops are harmless no-ops (disabled runner).
        $databases = new DatabaseService(new CommandRunner());
        $fs = new PrivilegedFs($failingRunner, app(SafeOps::class));

        $service = new WordPressService($failingRunner, $databases, $fs);
        $result = $service->install($website);

        $this->assertFalse($result['ok']);
        // Records created during the attempt must be rolled back.
        $this->assertSame(0, PanelDatabase::count());
        $this->assertSame(0, DatabaseUser::count());
    }

    public function test_install_refuses_non_empty_docroot_without_confirmation(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Setting::put('setup_completed', '1');

        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com',
            'password' => Hash::make('Sup3rSecret!'), 'status' => 'active',
        ]);
        $admin->roles()->sync([Role::where('name', 'super_admin')->first()->id]);

        // A docroot that already contains a real file.
        $docroot = sys_get_temp_dir() . '/ls_wp_full_' . uniqid();
        @mkdir($docroot, 0755, true);
        file_put_contents($docroot . '/existing.php', '<?php');

        $website = Website::create([
            'domain'          => 'wp-full.com',
            'type'            => 'php',
            'document_root'   => $docroot,
            'system_username' => 'webuser',
            'user_id'         => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post('/wordpress/install', ['website_id' => $website->id])
            ->assertSessionHas('error');

        // No database should have been provisioned.
        $this->assertSame(0, PanelDatabase::count());

        @unlink($docroot . '/existing.php');
        @rmdir($docroot);
    }
}
