<?php

namespace App\Services\WordPress;

use App\Models\DatabaseUser;
use App\Models\PanelDatabase;
use App\Models\Website;
use App\Services\Database\DatabaseService;
use App\Services\Support\CommandRunner;
use App\Services\System\PrivilegedFs;
use Illuminate\Support\Str;
use Throwable;

/**
 * One-click WordPress installer. Downloads core from wordpress.org, extracts it
 * into the site's document root via the privileged safe-op layer, provisions a
 * database + user (tracked in panel_databases / database_users), and writes a
 * hardened wp-config.php with unique salts. A failed install rolls back the
 * database, the panel records and any files it created.
 */
class WordPressService
{
    public function __construct(
        protected CommandRunner $runner,
        protected DatabaseService $databases,
        protected PrivilegedFs $fs,
    ) {
    }

    /**
     * @return array{ok: bool, message: string, db: array<string,string>}
     */
    public function install(Website $website): array
    {
        $docroot = $website->document_root;

        $dbName = 'wp_' . substr(md5($website->domain), 0, 8);
        $dbUser = 'wp_' . substr(md5($website->domain . 'user'), 0, 8);
        $dbPass = $this->databases->generatePassword();

        // Provision the real MySQL database/user (no-op in dev mode).
        $this->databases->createDatabase($dbName);
        $this->databases->createUser($dbUser, $dbPass);
        $this->databases->grant($dbUser, $dbName);

        // Track the database in the panel so DB Manager + backups see it.
        [$panelDb, $userRecord] = $this->trackDatabase($website, $dbName, $dbUser);

        $db = ['name' => $dbName, 'user' => $dbUser, 'password' => $dbPass];

        if (! $this->runner->isEnabled()) {
            // Dev mode: records exist; skip filesystem work that needs root.
            return [
                'ok' => true,
                'message' => '[dev] WordPress scaffolded (download skipped).',
                'db' => $db,
            ];
        }

        // Was the docroot empty before we touched it? Controls file rollback.
        $docrootWasEmpty = $this->docrootIsEmpty($docroot);

        $work = sys_get_temp_dir() . '/wp_' . Str::random(8);
        $tmp = $work . '.tar.gz';

        try {
            $download = $this->runner->run('curl', ['-fsSL', '-o', $tmp, 'https://wordpress.org/latest.tar.gz'], 300);
            if (! $download->ok || ! is_file($tmp) || filesize($tmp) < 1024) {
                throw new \RuntimeException('Failed to download WordPress.');
            }

            // Extract into a www-data-writable staging dir first.
            if (! is_dir($work) && ! mkdir($work, 0755, true) && ! is_dir($work)) {
                throw new \RuntimeException('Failed to create staging directory.');
            }
            $extract = $this->runner->run('tar', ['-xzf', $tmp, '-C', $work, '--strip-components=1'], 120);
            if (! $extract->ok || ! is_file($work . '/wp-settings.php')) {
                throw new \RuntimeException('Failed to extract WordPress.');
            }

            // Write wp-config.php into the staging tree (still www-data).
            $this->writeStagedConfig($work, $dbName, $dbUser, $dbPass);

            // Remove the placeholder index then copy the tree into the docroot
            // and lock down permissions — all through the privileged safe-op.
            $this->fs->removeFile(rtrim($docroot, '/') . '/index.html');

            $copy = $this->fs->copyTree($work, $docroot);
            if (! $copy->ok && ! $copy->disabled) {
                throw new \RuntimeException('Failed to copy WordPress into the document root: ' . $copy->error);
            }

            $perms = $this->fs->setWebPermissions($docroot, $website->system_username);
            if (! $perms->ok && ! $perms->disabled) {
                throw new \RuntimeException('Failed to set permissions: ' . $perms->error);
            }

            return [
                'ok' => true,
                'message' => 'WordPress installed. Open the site to finish setup.',
                'db' => $db,
            ];
        } catch (Throwable $e) {
            return $this->rollback($website, $panelDb, $userRecord, $docroot, $docrootWasEmpty, $e->getMessage());
        } finally {
            $this->cleanupStaging($work, $tmp);
        }
    }

    /**
     * Create panel_databases + database_users records for the WordPress DB.
     *
     * @return array{0: PanelDatabase, 1: DatabaseUser}
     */
    protected function trackDatabase(Website $website, string $dbName, string $dbUser): array
    {
        $panelDb = PanelDatabase::firstOrCreate(
            ['name' => $dbName],
            ['driver' => 'mysql', 'website_id' => $website->id, 'user_id' => $website->user_id],
        );

        $userRecord = DatabaseUser::firstOrCreate(
            ['username' => $dbUser],
            ['host' => 'localhost', 'panel_database_id' => $panelDb->id],
        );

        return [$panelDb, $userRecord];
    }

    /**
     * Drop the provisioned database/user and remove panel records. Removes
     * partially-installed files only when this install populated an empty
     * docroot. The DB password is never included in the returned message.
     *
     * @return array{ok: bool, message: string, db: array<string,string>}
     */
    protected function rollback(
        Website $website,
        ?PanelDatabase $panelDb,
        ?DatabaseUser $userRecord,
        string $docroot,
        bool $docrootWasEmpty,
        string $message,
    ): array {
        if ($userRecord) {
            $this->databases->dropUser($userRecord->username);
            $userRecord->delete();
        }
        if ($panelDb) {
            $this->databases->dropDatabase($panelDb->name);
            $panelDb->delete();
        }

        // Only remove files if we created them in a previously-empty docroot.
        if ($docrootWasEmpty && $this->runner->isEnabled()) {
            $this->fs->purgeDocroot($website->system_username, $website->domain, $docroot);
        }

        return ['ok' => false, 'message' => $message, 'db' => []];
    }

    protected function docrootIsEmpty(string $docroot): bool
    {
        if (! is_dir($docroot)) {
            return true;
        }
        $entries = array_diff(scandir($docroot) ?: [], ['.', '..', 'index.html']);

        return count($entries) === 0;
    }

    protected function cleanupStaging(string $work, string $tmp): void
    {
        if (is_file($tmp)) {
            unlink($tmp);
        }
        if (is_dir($work)) {
            $this->runner->run('rm', ['-rf', $work], 30);
        }
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

    /**
     * Build and write wp-config.php into the (www-data-writable) staging tree.
     */
    protected function writeStagedConfig(string $stagingDir, string $db, string $user, string $pass): void
    {
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

        $wpConfig = rtrim($stagingDir, '/') . '/wp-config.php';
        if (file_put_contents($wpConfig, $config) === false) {
            throw new \RuntimeException('Failed to write wp-config.php.');
        }
        chmod($wpConfig, 0640);
    }
}
