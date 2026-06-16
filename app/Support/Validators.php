<?php

namespace App\Support;

/**
 * Strict validators for all values that may end up near system commands or the
 * filesystem. These intentionally reject anything that is not on a tight
 * allowlist of characters.
 */
class Validators
{
    public static function isValidDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        if ($domain === '' || strlen($domain) > 253) {
            return false;
        }

        // Labels: letters, digits, hyphen; not starting/ending with hyphen.
        return (bool) preg_match(
            '/^(?!-)(?:[a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/',
            $domain
        );
    }

    public static function isValidUsername(string $username): bool
    {
        // Linux-style username: lowercase, starts with letter, 3-32 chars.
        return (bool) preg_match('/^[a-z][a-z0-9_-]{2,31}$/', $username);
    }

    public static function isValidDatabaseName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]{1,63}$/', $name);
    }

    public static function isValidDatabaseUser(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]{1,32}$/', $name);
    }

    public static function isValidServiceName(string $name): bool
    {
        return in_array($name, (array) config('librestack.allowed_services'), true);
    }

    public static function isValidPort(int|string $port): bool
    {
        $port = (int) $port;

        return $port >= 1 && $port <= 65535;
    }

    public static function isValidEmail(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function isValidPhpVersion(string $version): bool
    {
        return in_array($version, (array) config('librestack.php_versions'), true);
    }

    /**
     * Validate a cron schedule expression (5 fields).
     */
    public static function isValidCronSchedule(string $schedule): bool
    {
        $parts = preg_split('/\s+/', trim($schedule));

        if (count($parts) !== 5) {
            return false;
        }

        foreach ($parts as $part) {
            if (! preg_match('/^[0-9*\/,\-]+$/', $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitise a domain into a safe slug for filenames/config names.
     */
    public static function domainSlug(string $domain): string
    {
        return preg_replace('/[^a-z0-9._-]/', '', strtolower($domain));
    }
}
