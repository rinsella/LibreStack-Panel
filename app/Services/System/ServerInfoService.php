<?php

namespace App\Services\System;

use App\Services\Support\CommandRunner;

/**
 * Gathers real server information using safe, read-only sources:
 *  - direct reads of /proc (no shell)
 *  - allowlisted commands via CommandRunner (hostnamectl, df, free, ...)
 *
 * Every method degrades gracefully so the dashboard never crashes on a host
 * that is missing a particular tool.
 */
class ServerInfoService
{
    public function __construct(protected CommandRunner $runner)
    {
    }

    public function summary(): array
    {
        return [
            'hostname'    => $this->hostname(),
            'os'          => $this->osName(),
            'kernel'      => $this->kernel(),
            'uptime'      => $this->uptime(),
            'cpu'         => $this->cpuUsage(),
            'memory'      => $this->memory(),
            'disk'        => $this->disk(),
            'load'        => $this->loadAverage(),
            'public_ip'   => $this->publicIp(),
        ];
    }

    public function hostname(): string
    {
        return trim((string) @gethostname()) ?: 'unknown';
    }

    public function osName(): string
    {
        $file = '/etc/os-release';
        if (is_readable($file)) {
            $content = (string) @file_get_contents($file);
            if (preg_match('/PRETTY_NAME="?([^"\n]+)"?/', $content, $m)) {
                return $m[1];
            }
        }

        return php_uname('s') . ' ' . php_uname('r');
    }

    public function kernel(): string
    {
        return php_uname('r');
    }

    public function uptime(): string
    {
        $file = '/proc/uptime';
        if (is_readable($file)) {
            $parts = explode(' ', (string) @file_get_contents($file));
            $seconds = (int) ($parts[0] ?? 0);

            return $this->humanizeSeconds($seconds);
        }

        return 'n/a';
    }

    public function loadAverage(): array
    {
        $file = '/proc/loadavg';
        if (is_readable($file)) {
            $parts = explode(' ', (string) @file_get_contents($file));

            return [
                '1m'  => (float) ($parts[0] ?? 0),
                '5m'  => (float) ($parts[1] ?? 0),
                '15m' => (float) ($parts[2] ?? 0),
            ];
        }

        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];

        return ['1m' => $load[0] ?? 0, '5m' => $load[1] ?? 0, '15m' => $load[2] ?? 0];
    }

    /**
     * Approximate CPU usage by sampling /proc/stat twice.
     */
    public function cpuUsage(): float
    {
        $file = '/proc/stat';
        if (! is_readable($file)) {
            return 0.0;
        }

        $sample = function () use ($file): array {
            foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
                if (str_starts_with($line, 'cpu ')) {
                    $cols = preg_split('/\s+/', trim($line));
                    array_shift($cols);
                    $cols = array_map('intval', $cols);
                    $idle = ($cols[3] ?? 0) + ($cols[4] ?? 0);
                    $total = array_sum($cols);

                    return ['idle' => $idle, 'total' => $total];
                }
            }

            return ['idle' => 0, 'total' => 0];
        };

        $a = $sample();
        usleep(120000);
        $b = $sample();

        $totalDiff = $b['total'] - $a['total'];
        $idleDiff = $b['idle'] - $a['idle'];

        if ($totalDiff <= 0) {
            return 0.0;
        }

        return round((1 - $idleDiff / $totalDiff) * 100, 1);
    }

    public function memory(): array
    {
        $file = '/proc/meminfo';
        $info = ['total' => 0, 'used' => 0, 'free' => 0, 'percent' => 0];

        if (is_readable($file)) {
            $data = [];
            foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
                if (preg_match('/^(\w+):\s+(\d+)\skB/', $line, $m)) {
                    $data[$m[1]] = (int) $m[2] * 1024;
                }
            }

            $total = $data['MemTotal'] ?? 0;
            $available = $data['MemAvailable'] ?? ($data['MemFree'] ?? 0);
            $used = max(0, $total - $available);

            $info = [
                'total'   => $total,
                'used'    => $used,
                'free'    => $available,
                'percent' => $total > 0 ? round($used / $total * 100, 1) : 0,
            ];
        }

        return $info;
    }

    public function disk(string $path = '/'): array
    {
        $total = @disk_total_space($path) ?: 0;
        $free = @disk_free_space($path) ?: 0;
        $used = max(0, $total - $free);

        return [
            'total'   => (int) $total,
            'used'    => (int) $used,
            'free'    => (int) $free,
            'percent' => $total > 0 ? round($used / $total * 100, 1) : 0,
        ];
    }

    public function publicIp(): ?string
    {
        // Prefer locally bound address; avoid outbound calls by default.
        $ip = trim((string) @file_get_contents('/proc/sys/kernel/hostname'));

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        $local = gethostbyname(gethostname() ?: '');

        return filter_var($local, FILTER_VALIDATE_IP) ? $local : null;
    }

    protected function humanizeSeconds(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days) {
            $parts[] = "{$days}d";
        }
        if ($hours) {
            $parts[] = "{$hours}h";
        }
        $parts[] = "{$minutes}m";

        return implode(' ', $parts);
    }
}
