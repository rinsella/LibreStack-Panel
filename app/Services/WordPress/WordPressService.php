<?php

namespace App\Services\WordPress;

use App\Models\Website;
use App\Services\Database\DatabaseService;
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

        // Provision database + user (tracked so we can roll back on failure).
        $dbName = 'wp_' . substr(md5($website->domain), 0, 8);
        $dbUser = 'wp_' . substr(md5($website->domain . 'user'), 0, 8);
        $dbPass = $this->databases->generatePassword();

        $this->databases->createDatabase($dbName);
        $this->databases->createUser($dbUser, $dbPass);
        $this->databases->grant($dbUser, $dbName);

        $db = ['name' => $dbName, 'user' => $dbUser, 'password' => $dbPass];

        if (! $this->runner->isEnabled()) {
            // Dev mode: just lay down a wp-config so the workflow is verifiable.
            $this->writeConfig($docroot, $dbName, $dbUser, $dbPass);

            return [
                'ok' => true,
                'message' => '[dev] WordPress scaffolded (download skipped).',
                'db' => $db,
            ];
        }

        $tmp = sys_get_temp_dir() . '/wp_' . Str::random(8) . '.tar.gz';

        $download = $this->runner->run('curl', [
            '-fsSL', '-o', $tmp, 'https://wordpress.org/latest.tar.gz',
        ], 300);

        // Verify the download actually produced a non-empty archive.
        if (! $download->ok || ! is_file($tmp) || filesize($tmp) < 1024) {
            @unlink($tmp);

            return $this->rollback($db, 'Failed to download WordPress.');
        }

        $this->runner->run('mkdir', ['-p', $docroot], 20);

        // Remove the panel's placeholder index.html so it cannot shadow index.php.
        $placeholder = rtrim($docroot, '/') . '/index.html';
        if (is_file($placeholder)) {
            @unlink($placeholder);
        }

        $extract = $this->runner->run('tar', [
            '-xzf', $tmp, '-C', $docroot, '--strip-components=1',
        ], 120);

        @unlink($tmp);

        // Verify extraction produced a real WordPress tree.
        if (! $extract->ok || ! is_file(rtrim($docroot, '/') . '/wp-settings.php')) {
            return $this->rollback($db, 'Failed to extract WordPress.');
        }

        $this->writeConfig($docroot, $dbName, $dbUser, $dbPass);
        $this->setPermissions($website, $docroot);

        return [
            'ok' => true,
            'message' => 'WordPress installed. Open the site to finish setup.',
            'db' => $db,
        ];
    }

    /**
     * Drop the provisioned database and user so a failed install leaves nothing
     * behind. The password is never included in the returned message.
     *
     * @param  array<string,string>  $db
     * @return array{ok: bool, message: string, db: array<string,string>}
     */
    protected function rollback(array $db, string $message): array
    {
        if (! empty($db['user'])) {
            $this->databases->dropUser($db['user']);
        }
        if (! empty($db['name'])) {
            $this->databases->dropDatabase($db['name']);
        }

        return ['ok' => false, 'message' => $message, 'db' => []];
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

        $wpConfig = rtrim($docroot, '/') . '/wp-config.php';
        @file_put_contents($wpConfig, $config);
        // wp-config.php holds the database password; keep it private (0640).
        @chmod($wpConfig, 0640);
    }

    /**
     * Apply least-privilege permissions: directories 0755, files 0644, and the
     * secret-bearing wp-config.php 0640. Nothing is chmod'd 0755 blindly.
     */
    protected function setPermissions(Website $website, string $docroot): void
    {
        $owner = $website->system_username;

        $this->runner->run('chown', ['-R', "{$owner}:{$owner}", $docroot], 120);
        $this->runner->run('find', [$docroot, '-type', 'd', '-exec', 'chmod', '755', '{}', '+'], 120);
        $this->runner->run('find', [$docroot, '-type', 'f', '-exec', 'chmod', '644', '{}', '+'], 120);
        $this->runner->run('chmod', ['640', rtrim($docroot, '/') . '/wp-config.php'], 30);
    }
}
