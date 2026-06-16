<?php

namespace App\Services\Firewall;

use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use App\Support\Validators;
use InvalidArgumentException;

/**
 * UFW firewall management with port/protocol validation.
 */
class FirewallService
{
    public function __construct(protected CommandRunner $runner)
    {
    }

    public function status(): string
    {
        $result = $this->runner->run('ufw', ['status', 'verbose'], 15);

        if ($result->disabled) {
            return "[firewall status unavailable: system commands disabled]";
        }

        return $result->combined();
    }

    /**
     * @return array<int, array{number:string, rule:string}>
     */
    public function rules(): array
    {
        $result = $this->runner->run('ufw', ['status', 'numbered'], 15);
        if (! $result->ok) {
            return [];
        }

        $rules = [];
        foreach (explode("\n", $result->output) as $line) {
            if (preg_match('/^\[\s*(\d+)\]\s+(.*)$/', trim($line), $m)) {
                $rules[] = ['number' => $m[1], 'rule' => trim($m[2])];
            }
        }

        return $rules;
    }

    public function enable(): CommandResult
    {
        return $this->runner->run('ufw', ['--force', 'enable'], 20);
    }

    public function disable(): CommandResult
    {
        return $this->runner->run('ufw', ['disable'], 20);
    }

    public function allow(int $port, string $proto = 'tcp'): CommandResult
    {
        $this->assertPort($port);
        $this->assertProto($proto);

        return $this->runner->run('ufw', ['allow', "{$port}/{$proto}"], 20);
    }

    public function deny(int $port, string $proto = 'tcp'): CommandResult
    {
        $this->assertPort($port);
        $this->assertProto($proto);

        return $this->runner->run('ufw', ['deny', "{$port}/{$proto}"], 20);
    }

    public function deleteRule(int $number): CommandResult
    {
        if ($number < 1) {
            throw new InvalidArgumentException('Invalid rule number.');
        }

        return $this->runner->run('ufw', ['--force', 'delete', (string) $number], 20);
    }

    protected function assertPort(int $port): void
    {
        if (! Validators::isValidPort($port)) {
            throw new InvalidArgumentException('Invalid port.');
        }
    }

    protected function assertProto(string $proto): void
    {
        if (! in_array($proto, ['tcp', 'udp'], true)) {
            throw new InvalidArgumentException('Invalid protocol.');
        }
    }
}
