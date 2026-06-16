<?php

namespace App\Services\System;

use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use App\Support\Validators;
use InvalidArgumentException;

/**
 * Controls systemd services through an allowlist. Service names are validated
 * against config/librestack.php before any command is built.
 */
class ServiceManager
{
    public function __construct(protected CommandRunner $runner)
    {
    }

    /**
     * @return array<int, array{name:string, status:string}>
     */
    public function list(): array
    {
        $services = [];

        foreach ((array) config('librestack.allowed_services') as $name) {
            $services[] = [
                'name'   => $name,
                'status' => $this->status($name),
            ];
        }

        return $services;
    }

    public function status(string $service): string
    {
        $this->assertService($service);

        $result = $this->runner->run('systemctl', ['is-active', $service], 10);

        if ($result->disabled) {
            return 'unknown';
        }

        $value = trim($result->output) ?: trim($result->error);

        return $value !== '' ? $value : 'inactive';
    }

    public function start(string $service): CommandResult
    {
        return $this->action('start', $service);
    }

    public function stop(string $service): CommandResult
    {
        return $this->action('stop', $service);
    }

    public function restart(string $service): CommandResult
    {
        return $this->action('restart', $service);
    }

    public function reload(string $service): CommandResult
    {
        return $this->action('reload', $service);
    }

    /**
     * Return the latest journal lines for a service (bounded).
     */
    public function journal(string $service, int $lines = 100): string
    {
        $this->assertService($service);
        $lines = max(1, min($lines, 500));

        $result = $this->runner->run('journalctl', [
            '-u', $service,
            '-n', (string) $lines,
            '--no-pager',
        ], 20);

        if ($result->disabled) {
            return "[journal unavailable: system commands disabled]";
        }

        return $result->combined();
    }

    protected function action(string $action, string $service): CommandResult
    {
        $this->assertService($service);

        return $this->runner->run('systemctl', [$action, $service], 30);
    }

    protected function assertService(string $service): void
    {
        if (! Validators::isValidServiceName($service)) {
            throw new InvalidArgumentException("Service not allowed: {$service}");
        }
    }
}
