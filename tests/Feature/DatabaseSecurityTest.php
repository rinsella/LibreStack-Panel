<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Database\DatabaseService;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ensures database admin/user passwords never reach the command line (process
 * list) and that SQL is piped through stdin rather than `-e`.
 */
class DatabaseSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_password_is_not_passed_on_the_command_line(): void
    {
        Setting::put('db_admin_username', 'root');
        Setting::put('db_admin_password', 'sup3r-secret-pw', true);
        config(['librestack.system_enabled' => true]);

        $captured = ['args' => [], 'input' => null, 'defaults' => null];

        $runner = new class($captured) extends CommandRunner {
            public function __construct(public array &$captured)
            {
            }

            public function run(string $binary, array $args = [], ?int $timeout = 60, ?string $input = null): CommandResult
            {
                $this->captured['args'] = $args;
                $this->captured['input'] = $input;
                foreach ($args as $a) {
                    if (str_starts_with((string) $a, '--defaults-extra-file=')) {
                        // Capture the file contents before it is unlinked.
                        $this->captured['defaults'] = @file_get_contents(substr($a, strlen('--defaults-extra-file=')));
                    }
                }

                return new CommandResult(true, 0, '', '');
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };

        (new DatabaseService($runner))->createDatabase('mydb');

        // No argument may contain the password or a -p<password> flag.
        foreach ($captured['args'] as $arg) {
            $this->assertStringNotContainsString('sup3r-secret-pw', (string) $arg);
            $this->assertDoesNotMatchRegularExpression('/^-p\S/', (string) $arg);
        }

        // The credentials travelled via a defaults-extra-file instead.
        $this->assertNotNull($captured['defaults']);
        $this->assertStringContainsString('sup3r-secret-pw', $captured['defaults']);

        // The SQL statement is piped over stdin, not via -e on the command line.
        $this->assertNotContains('-e', $captured['args']);
        $this->assertStringContainsString('CREATE DATABASE', (string) $captured['input']);
    }

    public function test_user_creation_sql_is_sent_over_stdin(): void
    {
        config(['librestack.system_enabled' => true]);

        $captured = ['args' => [], 'input' => null];

        $runner = new class($captured) extends CommandRunner {
            public function __construct(public array &$captured)
            {
            }

            public function run(string $binary, array $args = [], ?int $timeout = 60, ?string $input = null): CommandResult
            {
                $this->captured['args'] = $args;
                $this->captured['input'] = $input;

                return new CommandResult(true, 0, '', '');
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };

        (new DatabaseService($runner))->createUser('appuser', 'gener4ted-pass');

        foreach ($captured['args'] as $arg) {
            $this->assertStringNotContainsString('gener4ted-pass', (string) $arg);
        }
        $this->assertStringContainsString('gener4ted-pass', (string) $captured['input']);
    }
}
