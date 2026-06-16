<?php

namespace App\Services\WordPress;

use App\Models\Website;
use App\Services\Database\DatabaseService;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use Illuminate\Support\Str;

/**
 * One-click WordPress installer. Downloads core from wordpress.org, extracts it
 * into the site's document root, provisions a database + user, and writes a
 * hardened wp-config.php with unique salts.
 */
class WordPressService
{
    public function __construct(
        protected CommandRunner $runner,
        protected DatabaseService $databases,
    ) {
    }

    /**
     * @return array{ok: bool, message: string, db: array<string,string>}
     */
    public function install(Website $website): array
    {
        $docroot = $website->document_root;

        // Provision database + user
        $dbName = 'wp_' . substr(md5($website->domain), 0, 8);
        $dbUser = 'wp_' . substr(md5($website->domain . 'user'), 0, 8);
        $dbPass = $this->databases->generatePassword();

        $this->databases->createDatabase($dbName);
        $this->databases->createUser($dbUser, $dbPass);
        $this->databases->grant($dbUser, $dbName);

        if (! $this->runner->isEnabled()) {
            // Dev mode: just lay down a wp-config so the workflow is verifiable.
            $this->writeConfig($docroot, $dbName, $dbUser, $dbPass);

            return [
                'ok' => true,
                'message' => '[dev] WordPress scaffolded (download skipped).',
                'db' => ['name' => $dbName, 'user' => $dbUser, 'password' => $dbPass],
            ];
        }

        $tmp = sys_get_temp_dir() . '/wp_' . Str::random(8) . '.tar.gz';

        $download = $this->runner->run('curl', [
            '-fsSL', '-o', $tmp, 'https://wordpress.org/latest.tar.gz',
        ], 300);

        if (! $download->ok) {
            return ['ok' => false, 'message' => 'Failed to download WordPress.', 'db' => []];
        }

        // Extract (wordpress.tar.gz contains a top-level "wordpress/" folder).
        $this->runner->run('mkdir', ['-p', $docroot], 20);
        $extract = $this->runner->run('tar', [
            '-xzf', $tmp, '-C', $docroot, '--strip-components=1',
        ], 120);

        @unlink($tmp);

        if (! $extract->ok) {
            return ['ok' => false, 'message' => 'Failed to extract WordPress.', 'db' => []];
        }

        $this->writeConfig($docroot, $dbName, $dbUser, $dbPass);
        $this->setPermissions($website, $docroot);

        return [
            'ok' => true,
            'message' => 'WordPress installed. Open the site to finish setup.',
            'db' => ['name' => $dbName, 'user' => $dbUser, 'password' => $dbPass],
        ];
    }

    public function detectVersion(Website $website): ?string
    {
        $file = rtrim($website->document_root, '/') . '/wp-includes/version.php';
        if (! is_readable($file)) {
            return null;
        }
        $content = (string) file_get_contents($file);
        if (preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    protected function writeConfig(string $docroot, string $db, string $user, string $pass): void
    {
        if (! is_dir($docroot)) {
            @mkdir($docroot, 0755, true);
        }

        $salts = '';
        foreach ([
            'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
            'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
        ] as $key) {
            $salts .= "define('{$key}', '" . Str::random(64) . "');\n";
        }

        $config = <<<PHP
<?php
define('DB_NAME', '{$db}');
define('DB_USER', '{$user}');
define('DB_PASSWORD', '{$pass}');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

{$salts}
\$table_prefix = 'wp_';

define('WP_DEBUG', false);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
PHP;

        @file_put_contents(rtrim($docroot, '/') . '/wp-config.php', $config);
    }

    protected function setPermissions(Website $website, string $docroot): CommandResult
    {
        $this->runner->run('chown', ['-R', "{$website->system_username}:{$website->system_username}", $docroot], 60);

        return $this->runner->run('chmod', ['-R', '755', $docroot], 60);
    }
}
