<?php

namespace App\Services\Database;

use App\Models\Setting;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use App\Support\Validators;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Manages MariaDB/MySQL databases and users.
 *
 * All identifiers are validated against a tight allowlist before being placed
 * into SQL, and SQL is piped to the mysql client over stdin (never via the
 * shell). Admin credentials are read from encrypted settings.
 */
class DatabaseService
{
    public function __construct(protected CommandRunner $runner)
    {
    }

    public function generatePassword(int $length = 24): string
    {
        return Str::password($length, symbols: false);
    }

    public function createDatabase(string $name): CommandResult
    {
        $this->assertDbName($name);

        return $this->execSql("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    }

    public function dropDatabase(string $name): CommandResult
    {
        $this->assertDbName($name);

        return $this->execSql("DROP DATABASE IF EXISTS `{$name}`;");
    }

    public function createUser(string $user, string $password, string $host = 'localhost'): CommandResult
    {
        $this->assertDbUser($user);
        // Escape only single quotes in the password for the SQL literal.
        $safePass = str_replace("'", "''", $password);

        return $this->execSql(
            "CREATE USER IF NOT EXISTS '{$user}'@'{$host}' IDENTIFIED BY '{$safePass}';"
        );
    }

    public function grant(string $user, string $database, string $host = 'localhost'): CommandResult
    {
        $this->assertDbUser($user);
        $this->assertDbName($database);

        return $this->execSql(
            "GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$user}'@'{$host}'; FLUSH PRIVILEGES;"
        );
    }

    public function dropUser(string $user, string $host = 'localhost'): CommandResult
    {
        $this->assertDbUser($user);

        return $this->execSql("DROP USER IF EXISTS '{$user}'@'{$host}';");
    }

    public function size(string $name): ?int
    {
        $this->assertDbName($name);

        $sql = "SELECT IFNULL(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = '{$name}';";
        $result = $this->execSql($sql, extraArgs: ['-N', '-B']);

        if (! $result->ok) {
            return null;
        }

        return (int) trim($result->output);
    }

    public function export(string $name, string $destination): CommandResult
    {
        $this->assertDbName($name);

        if (! $this->runner->isEnabled()) {
            return CommandResult::disabled();
        }

        $args = array_merge($this->authArgs(), [$name, '--result-file=' . $destination]);

        return $this->runner->run('mysqldump', $args, 300);
    }

    public function import(string $name, string $sqlFilePath): CommandResult
    {
        $this->assertDbName($name);

        if (! is_readable($sqlFilePath)) {
            throw new InvalidArgumentException('SQL file is not readable.');
        }

        $sql = (string) file_get_contents($sqlFilePath);
        $args = array_merge($this->authArgs(), [$name]);

        return $this->runner->runWithInput('mysql', $args, $sql, 300);
    }

    protected function execSql(string $sql, array $extraArgs = []): CommandResult
    {
        $args = array_merge($this->authArgs(), $extraArgs, ['-e', $sql]);

        return $this->runner->run('mysql', $args, 60);
    }

    /**
     * Build authentication arguments from stored admin credentials.
     *
     * @return array<int, string>
     */
    protected function authArgs(): array
    {
        $user = Setting::get('db_admin_username', 'root');
        $pass = Setting::get('db_admin_password');

        $args = ['-u', (string) $user];

        if ($pass) {
            // mysql accepts -p<password> with no space; passed as a single arg
            // so it is never split by a shell.
            $args[] = '-p' . $pass;
        }

        return $args;
    }

    protected function assertDbName(string $name): void
    {
        if (! Validators::isValidDatabaseName($name)) {
            throw new InvalidArgumentException("Invalid database name: {$name}");
        }
    }

    protected function assertDbUser(string $name): void
    {
        if (! Validators::isValidDatabaseUser($name)) {
            throw new InvalidArgumentException("Invalid database user: {$name}");
        }
    }
}
