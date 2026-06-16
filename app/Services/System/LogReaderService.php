<?php

namespace App\Services\System;

use App\Services\Support\CommandRunner;

/**
 * Reads log files in a memory-safe way. Only an allowlisted set of log sources
 * may be read; arbitrary paths are rejected. Output is always bounded.
 */
class LogReaderService
{
    public function __construct(protected CommandRunner $runner)
    {
    }

    /**
     * @return array<string, array{label:string, type:string, path?:string, unit?:string}>
     */
    public function sources(): array
    {
        return [
            'panel' => [
                'label' => 'Panel log (Laravel)',
                'type'  => 'file',
                'path'  => storage_path('logs/laravel.log'),
            ],
            'nginx_access' => [
                'label' => 'Nginx access log',
                'type'  => 'file',
                'path'  => '/var/log/nginx/access.log',
            ],
            'nginx_error' => [
                'label' => 'Nginx error log',
                'type'  => 'file',
                'path'  => '/var/log/nginx/error.log',
            ],
            'php_fpm' => [
                'label' => 'PHP-FPM log',
                'type'  => 'file',
                'path'  => '/var/log/php' . config('librestack.default_php') . '-fpm.log',
            ],
            'nginx_service' => [
                'label' => 'Nginx service (journal)',
                'type'  => 'journal',
                'unit'  => 'nginx',
            ],
            'mariadb_service' => [
                'label' => 'MariaDB service (journal)',
                'type'  => 'journal',
                'unit'  => 'mariadb',
            ],
        ];
    }

    /**
     * Read the tail of a log source, bounded to a safe number of lines.
     */
    public function read(string $key, int $lines = 200, string $search = ''): string
    {
        $sources = $this->sources();

        if (! isset($sources[$key])) {
            return '[unknown log source]';
        }

        $lines = max(10, min($lines, 1000));
        $source = $sources[$key];

        $content = $source['type'] === 'journal'
            ? $this->readJournal($source['unit'], $lines)
            : $this->readFile($source['path'] ?? '', $lines);

        if ($search !== '') {
            $needle = strtolower($search);
            $content = implode("\n", array_filter(
                explode("\n", $content),
                fn ($line) => str_contains(strtolower($line), $needle)
            ));
        }

        return $content !== '' ? $content : '[no log entries]';
    }

    public function path(string $key): ?string
    {
        $source = $this->sources()[$key] ?? null;

        return ($source && ($source['type'] ?? '') === 'file') ? ($source['path'] ?? null) : null;
    }

    protected function readFile(string $path, int $lines): string
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return "[log file unavailable: {$path}]";
        }

        // Read only the tail to avoid loading huge files into memory.
        $buffer = '';
        $chunkSize = 4096;
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return '[unable to open log file]';
        }

        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);
        $lineCount = 0;

        while ($pos > 0 && $lineCount <= $lines) {
            $read = (int) min($chunkSize, $pos);
            $pos -= $read;
            fseek($fp, $pos);
            $chunk = (string) fread($fp, $read);
            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }
        fclose($fp);

        $all = explode("\n", $buffer);

        return implode("\n", array_slice($all, -$lines));
    }

    protected function readJournal(string $unit, int $lines): string
    {
        $result = $this->runner->run('journalctl', [
            '-u', $unit, '-n', (string) $lines, '--no-pager',
        ], 20);

        if ($result->disabled) {
            return '[journal unavailable: system commands disabled]';
        }

        return $result->combined();
    }
}
