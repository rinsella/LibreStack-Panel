<?php

namespace App\Services\Database;

use App\Models\Setting;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use App\Support\Validators;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

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

        $defaults = $this->makeDefaultsFile();

        try {
            $args = ['--defaults-extra-file=' . $defaults, $name, '--result-file=' . $destination];

            return $this->runner->run('mysqldump', $args, 300);
        } finally {
            @unlink($defaults);
        }
    }

    public function import(string $name, string $sqlFilePath): CommandResult
    {
        $this->assertDbName($name);

        if (! is_readable($sqlFilePath)) {
            throw new InvalidArgumentException('SQL file is not readable.');
        }

        if (! $this->runner->isEnabled()) {
            return CommandResult::disabled();
        }

        $sql = (string) file_get_contents($sqlFilePath);

        return $this->runMysql([$name], $sql, 300);
    }

    protected function execSql(string $sql, array $extraArgs = []): CommandResult
    {
        // SQL is always piped through stdin, never via `-e` on the command line,
        // so statements containing generated passwords cannot appear in the
        // process list.
        return $this->runMysql($extraArgs, $sql, 60);
    }

    /**
     * Run the mysql client with admin credentials supplied through a 0600
     * defaults-extra-file (never as `-p<password>` on the command line). SQL is
     * piped over stdin. The credentials file is always removed afterwards.
     *
     * @param  array<int, string>  $extraArgs
     */
    protected function runMysql(array $extraArgs, ?string $sql, int $timeout): CommandResult
    {
        if (! $this->runner->isEnabled()) {
            return CommandResult::disabled();
        }

        $defaults = $this->makeDefaultsFile();

        try {
            $args = array_merge(['--defaults-extra-file=' . $defaults], $extraArgs);

            return $sql !== null
                ? $this->runner->runWithInput('mysql', $args, $sql, $timeout)
                : $this->runner->run('mysql', $args, $timeout);
        } finally {
            @unlink($defaults);
        }
    }

    /**
     * Write the admin credentials to a temporary 0600 my.cnf-style file so the
     * password is never exposed in the process list or in logs.
     */
    protected function makeDefaultsFile(): string
    {
        $user = (string) (Setting::get('db_admin_username', 'root') ?: 'root');
        $pass = (string) (Setting::get('db_admin_password') ?? '');

        $file = tempnam(sys_get_temp_dir(), 'lsdb_');
        if ($file === false) {
            throw new RuntimeException('Unable to create a temporary credentials file.');
        }

        // Restrict to owner read/write BEFORE writing the secret.
        @chmod($file, 0600);

        $contents = "[client]\nuser=\"" . $this->escapeMyCnf($user) . "\"\n";
        if ($pass !== '') {
            $contents .= 'password="' . $this->escapeMyCnf($pass) . "\"\n";
        }

        file_put_contents($file, $contents);
        @chmod($file, 0600);

        return $file;
    }

    /**
     * Escape a value for a double-quoted my.cnf option.
     */
    protected function escapeMyCnf(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
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
