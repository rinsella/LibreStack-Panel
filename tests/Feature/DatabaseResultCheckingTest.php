<?php

namespace Tests\Feature;

use App\Models\DatabaseUser;
use App\Models\PanelDatabase;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Database\DatabaseService;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The database UI must never persist panel metadata when the real MySQL command
 * failed, and failed steps must roll back the partially-created database/user.
 */
class DatabaseResultCheckingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Setting::put('setup_completed', '1');
        // Force "system mode" so the fake DatabaseService results are honoured
        // (otherwise commands report "disabled" and are treated as success).
        config(['librestack.system_enabled' => true]);
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com',
            'password' => Hash::make('Sup3rSecret!'), 'status' => 'active',
        ]);
        $user->roles()->sync([Role::where('name', 'super_admin')->first()->id]);

        return $user;
    }

    /**
     * Bind a fake DatabaseService that fails at a chosen step and records the
     * drop calls used for rollback.
     */
    private function fakeDb(string $failAt, array &$dropped): void
    {
        $service = new class($failAt, $dropped) extends DatabaseService {
            public function __construct(public string $failAt, public array &$dropped)
            {
                parent::__construct(app(CommandRunner::class));
            }

            private function ok(): CommandResult
            {
                return new CommandResult(true, 0, 'ok', '');
            }

            private function fail(): CommandResult
            {
                return new CommandResult(false, 1, '', 'mysql error');
            }

            public function createDatabase(string $name): CommandResult
            {
                return $this->failAt === 'database' ? $this->fail() : $this->ok();
            }

            public function createUser(string $user, string $password, string $host = 'localhost'): CommandResult
            {
                return $this->failAt === 'user' ? $this->fail() : $this->ok();
            }

            public function grant(string $user, string $database, string $host = 'localhost'): CommandResult
            {
                return $this->failAt === 'grant' ? $this->fail() : $this->ok();
            }

            public function dropDatabase(string $name): CommandResult
            {
                $this->dropped[] = "db:{$name}";

                return $this->ok();
            }

            public function dropUser(string $user, string $host = 'localhost'): CommandResult
            {
                $this->dropped[] = "user:{$user}";

                return $this->ok();
            }

            public function size(string $name): ?int
            {
                return 0;
            }
        };

        $this->app->instance(DatabaseService::class, $service);
    }

    private function storeDatabase(User $admin): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($admin)->post('/databases', [
            'name'     => 'app_db',
            'username' => 'app_user',
            'password' => 'Sup3rSecret!',
        ]);
    }

    public function test_database_create_failure_creates_no_records(): void
    {
        $dropped = [];
        $this->fakeDb('database', $dropped);

        $this->storeDatabase($this->admin())->assertSessionHas('error');

        $this->assertSame(0, PanelDatabase::count());
        $this->assertSame(0, DatabaseUser::count());
    }

    public function test_user_create_failure_drops_database_and_creates_no_records(): void
    {
        $dropped = [];
        $this->fakeDb('user', $dropped);

        $this->storeDatabase($this->admin())->assertSessionHas('error');

        $this->assertContains('db:app_db', $dropped);
        $this->assertSame(0, PanelDatabase::count());
        $this->assertSame(0, DatabaseUser::count());
    }

    public function test_grant_failure_drops_user_and_database_and_creates_no_records(): void
    {
        $dropped = [];
        $this->fakeDb('grant', $dropped);

        $this->storeDatabase($this->admin())->assertSessionHas('error');

        $this->assertContains('user:app_user', $dropped);
        $this->assertContains('db:app_db', $dropped);
        $this->assertSame(0, PanelDatabase::count());
    }

    public function test_all_success_creates_records(): void
    {
        $dropped = [];
        $this->fakeDb('none', $dropped);

        $this->storeDatabase($this->admin())->assertSessionHas('success');

        $this->assertDatabaseHas('panel_databases', ['name' => 'app_db']);
        $this->assertDatabaseHas('database_users', ['username' => 'app_user']);
    }

    public function test_destroy_failure_keeps_metadata(): void
    {
        // Fake whose drops fail.
        $service = new class extends DatabaseService {
            public function __construct()
            {
            }

            public function dropDatabase(string $name): CommandResult
            {
                return new CommandResult(false, 1, '', 'mysql drop failed');
            }

            public function dropUser(string $user, string $host = 'localhost'): CommandResult
            {
                return new CommandResult(false, 1, '', 'mysql drop failed');
            }
        };
        $this->app->instance(DatabaseService::class, $service);

        $database = PanelDatabase::create(['name' => 'keep_db', 'user_id' => null]);
        DatabaseUser::create(['username' => 'keep_user', 'host' => 'localhost', 'panel_database_id' => $database->id]);

        $this->actingAs($this->admin())
            ->delete("/databases/{$database->id}")
            ->assertSessionHas('error');

        // Metadata is preserved because the real drop failed.
        $this->assertDatabaseHas('panel_databases', ['id' => $database->id]);
    }

    public function test_password_is_not_logged_in_audit(): void
    {
        $dropped = [];
        $this->fakeDb('none', $dropped);

        $this->storeDatabase($this->admin())->assertSessionHas('success');

        // No audit log row may contain the raw password.
        foreach (\App\Models\AuditLog::all() as $log) {
            $this->assertStringNotContainsString('Sup3rSecret!', json_encode($log->getAttributes()));
        }
    }
}
