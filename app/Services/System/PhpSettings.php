<?php

namespace App\Services\System;

/**
 * Validates and normalises the per-site PHP settings managed by the panel.
 *
 * These values are written into a PHP-FPM pool file, so every value is strictly
 * validated against the allowlist + bounds in config('librestack.php_settings').
 * Anything not on the allowlist, or any value that is not a plain size/integer
 * within range, is rejected — there is no way to inject arbitrary pool
 * directives (no newlines, brackets or '=' ever reach the file).
 */
class PhpSettings
{
    /**
     * The raw definitions from config (label, type, default, min, max…).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return (array) config('librestack.php_settings', []);
    }

    /**
     * Stock default for every managed setting (key => value string).
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::definitions() as $key => $def) {
            $out[$key] = (string) ($def['default'] ?? '');
        }

        return $out;
    }

    /**
     * Merge stored overrides over the defaults, keeping only valid, allowlisted
     * values. The result always contains exactly the managed keys.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, string>
     */
    public static function effective(array $overrides): array
    {
        $defaults = self::defaults();
        $clean = self::sanitize($overrides);

        return array_merge($defaults, $clean);
    }

    /**
     * Return only the values from $input that are managed AND valid. Invalid or
     * unknown entries are dropped (not throwing keeps the UI forgiving; the
     * effective value simply falls back to the default).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function sanitize(array $input): array
    {
        $out = [];
        foreach (self::definitions() as $key => $def) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = is_string($input[$key]) || is_int($input[$key]) ? trim((string) $input[$key]) : '';
            if ($value === '') {
                continue;
            }
            if (self::isValidValue((string) ($def['type'] ?? 'size'), $value, $def)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Whether a single value is a well-formed size/int within the configured
     * bounds. memory_limit additionally accepts "-1" (unlimited).
     *
     * @param  array<string, mixed>  $def
     */
    public static function isValidValue(string $type, string $value, array $def): bool
    {
        if ($type === 'int') {
            if (! preg_match('/^\d+$/', $value)) {
                return false;
            }
            $n = (int) $value;

            return $n >= (int) ($def['min'] ?? 0) && $n <= (int) ($def['max'] ?? PHP_INT_MAX);
        }

        // size
        if ($value === '-1') {
            return true; // unlimited (e.g. memory_limit)
        }
        if (! preg_match('/^\d+[KMG]?$/i', $value)) {
            return false;
        }
        $bytes = self::toBytes($value);

        return $bytes >= (int) ($def['min'] ?? 0) && $bytes <= (int) ($def['max'] ?? PHP_INT_MAX);
    }

    /**
     * Convert a php.ini shorthand size ("64M", "1G", "512K", "1048576") to
     * bytes. Returns 0 for unparseable / unlimited input.
     */
    public static function toBytes(string $value): int
    {
        $value = trim($value);
        if (! preg_match('/^(\d+)([KMG]?)$/i', $value, $m)) {
            return 0;
        }
        $n = (int) $m[1];

        return match (strtoupper($m[2])) {
            'K' => $n * 1024,
            'M' => $n * 1024 * 1024,
            'G' => $n * 1024 * 1024 * 1024,
            default => $n,
        };
    }

    /**
     * The nginx client_max_body_size for a site, derived from the largest
     * upload-related directive so nginx never rejects a request that PHP would
     * accept. Rounded up to whole megabytes (minimum 1m).
     *
     * @param  array<string, string>  $effective
     */
    public static function nginxBodySize(array $effective): string
    {
        $maxBytes = 0;
        foreach (self::definitions() as $key => $def) {
            if (empty($def['nginx_body'])) {
                continue;
            }
            $maxBytes = max($maxBytes, self::toBytes($effective[$key] ?? (string) ($def['default'] ?? '0')));
        }

        $mb = (int) max(1, (int) ceil($maxBytes / (1024 * 1024)));

        return "{$mb}m";
    }
}
