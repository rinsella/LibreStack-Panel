<?php

namespace App\Console\Commands;

use App\Services\Support\CommandRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Preflight diagnostics for a real VPS install. Reports the health of every
 * dependency the panel relies on. Run by the installer (--install) and any time
 * an operator wants to verify the environment.
 */
class DoctorCommand extends Command
{
    protected $signature = 'librestack:doctor {--install : Treat missing optional tools as warnings, not failures}';

    protected $description = 'Check that the server meets all LibreStack Panel requirements.';

    protected bool $hasFailure = false;

    public function handle(CommandRunner $runner): int
    {
        $this->line('LibreStack Panel — preflight diagnostics');
        $this->newLine();

        // PHP version
        $this->check(
            'PHP >= 8.3',
            PHP_VERSION_ID >= 80300,
            PHP_VERSION,
        );

        // Required PHP extensions
        foreach (['pdo', 'pdo_sqlite', 'mbstring', 'curl', 'openssl', 'zip', 'xml', 'bcmath'] as $ext) {
            $this->check("PHP ext: {$ext}", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing');
        }

        // Writable paths
        $this->check('storage/ writable', is_writable(storage_path()), storage_path());
        $this->check('bootstrap/cache writable', is_writable(base_path('bootstrap/cache')), base_path('bootstrap/cache'));

        // Database connectivity
        try {
            DB::connection()->getPdo();
            $this->check('Database connection', true, (string) config('database.default'));
        } catch (\Throwable $e) {
            $this->check('Database connection', false, $e->getMessage());
        }

        // SQLite file writable (when using sqlite)
        if (config('database.default') === 'sqlite') {
            $path = (string) config('database.connections.sqlite.database');
            $this->check('SQLite file writable', $path === ':memory:' || (is_file($path) && is_writable($path)), $path);
        }

        $optional = (bool) $this->option('install');

        // System tooling
        $this->checkBinary('sudo', true, $optional);
        $this->checkBinary('nginx', true, $optional);
        $this->checkBinary('php-fpm or phpX.Y-fpm', $this->fpmSocketExists(), $optional, $this->fpmSocketHint());
        $this->checkBinary('mysql/mariadb client', $this->binaryExists('mysql') || $this->binaryExists('mariadb'), $optional);
        $this->checkBinary('certbot', $this->binaryExists('certbot'), $optional);
        $this->checkBinary('ufw', $this->binaryExists('ufw'), $optional);
        $this->checkBinary('tar', $this->binaryExists('tar'), $optional);

        // safe-op helper present
        $script = (string) config('librestack.safe_op_script');
        $this->checkBinary('safe-op helper', is_file($script), $optional, $script);

        // nginx -t (only when system mode is on)
        if (config('librestack.system_enabled')) {
            $test = $runner->run('nginx', ['-t'], 15);
            $this->checkBinary('nginx -t', $test->ok || $test->disabled, true, $test->disabled ? 'system mode off' : trim($test->error));
        }

        $this->newLine();
        if ($this->hasFailure) {
            $this->error('One or more REQUIRED checks failed.');

            return self::FAILURE;
        }

        $this->info('All required checks passed.');

        return self::SUCCESS;
    }

    protected function check(string $label, bool $ok, string $detail = ''): void
    {
        $this->line(sprintf(' %s %s%s', $ok ? '<info>[ok]</info>' : '<error>[x]</error>', $label, $detail !== '' ? " — {$detail}" : ''));
        if (! $ok) {
            $this->hasFailure = true;
        }
    }

    /**
     * Optional tools warn (yellow) instead of failing when --install is set.
     */
    protected function checkBinary(string $label, bool $ok, bool $optional, string $detail = ''): void
    {
        if ($ok || ! $optional) {
            $this->check($label, $ok, $detail);

            return;
        }

        $this->line(sprintf(' <comment>[!]</comment> %s%s', $label, $detail !== '' ? " — {$detail}" : ' — not found (optional)'));
    }

    protected function binaryExists(string $bin): bool
    {
        $result = \Illuminate\Support\Facades\Process::run(['command', '-v', $bin]);

        // `command -v` is a shell builtin; fall back to checking common paths.
        if ($result->successful()) {
            return true;
        }

        foreach (['/usr/bin/', '/usr/sbin/', '/bin/', '/sbin/'] as $dir) {
            if (is_executable($dir . $bin)) {
                return true;
            }
        }

        return false;
    }

    protected function fpmSocketExists(): bool
    {
        return $this->fpmSocketHint() !== '';
    }

    protected function fpmSocketHint(): string
    {
        $found = glob('/run/php/php*-fpm.sock') ?: [];

        return $found[0] ?? '';
    }
}
