<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Services\FileManager\FileManagerService;
use App\Services\Support\CommandResult;
use App\Services\System\PrivilegedFs;
use App\Services\System\SafeOps;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * File manager hardening: every write goes through the privileged safe-op layer
 * and unsafe inputs (traversal, absolute paths, symlink escapes, bad chmod
 * modes, zip-slip/zip-bomb) are rejected.
 */
class FileManagerSafeOpTest extends TestCase
{
    protected string $webRoot;
    protected string $username = 'webuser';
    protected string $domain = 'fm-test.com';
    protected string $siteBase;
    protected string $docroot;
    protected Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webRoot = sys_get_temp_dir() . '/ls_webroot_' . uniqid();
        $this->siteBase = "{$this->webRoot}/{$this->username}/web/{$this->domain}";
        $this->docroot = "{$this->siteBase}/public_html";
        mkdir($this->docroot, 0755, true);

        config([
            'librestack.paths.web_root' => $this->webRoot,
            'librestack.system_enabled' => false,
            'librestack.use_sudo'       => false,
        ]);

        $this->website = new Website([
            'domain'          => $this->domain,
            'type'            => 'php',
            'document_root'   => $this->docroot,
            'system_username' => $this->username,
        ]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->webRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) && ! is_link($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function service(): FileManagerService
    {
        return app(FileManagerService::class);
    }

    public function test_write_creates_file_through_safe_op(): void
    {
        $this->service()->write($this->website, 'public_html/index.php', '<?php echo "hi";');

        $this->assertFileExists($this->docroot . '/index.php');
        $this->assertSame('<?php echo "hi";', file_get_contents($this->docroot . '/index.php'));
    }

    public function test_write_uses_privileged_fs(): void
    {
        $spy = new class(app(\App\Services\Support\CommandRunner::class), app(SafeOps::class)) extends PrivilegedFs {
            public array $calls = [];

            public function fileWrite(string $username, string $domain, string $relative, string $content): CommandResult
            {
                $this->calls[] = 'fileWrite';

                return parent::fileWrite($username, $domain, $relative, $content);
            }
        };
        $this->app->instance(PrivilegedFs::class, $spy);

        app(FileManagerService::class)->write($this->website, 'public_html/a.txt', 'x');

        $this->assertContains('fileWrite', $spy->calls);
    }

    public function test_delete_uses_privileged_fs(): void
    {
        file_put_contents($this->docroot . '/del.txt', 'bye');

        $spy = new class(app(\App\Services\Support\CommandRunner::class), app(SafeOps::class)) extends PrivilegedFs {
            public array $calls = [];

            public function fileDelete(string $username, string $domain, string $relative): CommandResult
            {
                $this->calls[] = 'fileDelete';

                return parent::fileDelete($username, $domain, $relative);
            }
        };
        $this->app->instance(PrivilegedFs::class, $spy);

        app(FileManagerService::class)->delete($this->website, 'public_html/del.txt');

        $this->assertContains('fileDelete', $spy->calls);
        $this->assertFileDoesNotExist($this->docroot . '/del.txt');
    }

    public function test_upload_uses_privileged_fs(): void
    {
        $tmp = sys_get_temp_dir() . '/ls_upload_' . uniqid();
        file_put_contents($tmp, 'uploaded');

        $spy = new class(app(\App\Services\Support\CommandRunner::class), app(SafeOps::class)) extends PrivilegedFs {
            public array $calls = [];

            public function fileUpload(string $username, string $domain, string $relative, string $sourceTmp): CommandResult
            {
                $this->calls[] = 'fileUpload';

                return parent::fileUpload($username, $domain, $relative, $sourceTmp);
            }
        };
        $this->app->instance(PrivilegedFs::class, $spy);

        app(FileManagerService::class)->upload($this->website, 'public_html/up.txt', $tmp);

        $this->assertContains('fileUpload', $spy->calls);
        $this->assertFileExists($this->docroot . '/up.txt');

        @unlink($tmp);
    }

    public function test_edit_then_delete_when_file_exists(): void
    {
        $this->service()->createFile($this->website, 'public_html/note.txt');
        $this->assertFileExists($this->docroot . '/note.txt');

        $this->service()->write($this->website, 'public_html/note.txt', 'edited');
        $this->assertSame('edited', file_get_contents($this->docroot . '/note.txt'));

        $this->service()->delete($this->website, 'public_html/note.txt');
        $this->assertFileDoesNotExist($this->docroot . '/note.txt');
    }

    public function test_traversal_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->write($this->website, '../../../../etc/evil', 'x');
    }

    public function test_absolute_path_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->write($this->website, '/etc/passwd', 'x');
    }

    public function test_windows_drive_path_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->write($this->website, 'C:\\evil.txt', 'x');
    }

    public function test_unsafe_chmod_mode_is_rejected(): void
    {
        file_put_contents($this->docroot . '/c.txt', 'x');

        $this->expectException(RuntimeException::class);
        $this->service()->chmod($this->website, 'public_html/c.txt', '0777');
    }

    public function test_safe_chmod_mode_is_allowed(): void
    {
        file_put_contents($this->docroot . '/c.txt', 'x');

        $this->service()->chmod($this->website, 'public_html/c.txt', '0640');

        $this->assertSame('0640', substr(sprintf('%o', fileperms($this->docroot . '/c.txt')), -4));
    }

    public function test_symlink_escape_is_rejected_on_delete(): void
    {
        $outside = sys_get_temp_dir() . '/ls_outside_' . uniqid();
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/secret.txt', 'secret');

        symlink($outside, $this->docroot . '/escape');

        try {
            $this->expectException(RuntimeException::class);
            $this->service()->delete($this->website, 'public_html/escape');
        } finally {
            // The external target must survive.
            $this->assertFileExists($outside . '/secret.txt');
            @unlink($this->docroot . '/escape');
            @unlink($outside . '/secret.txt');
            @rmdir($outside);
        }
    }

    public function test_binary_file_edit_is_rejected(): void
    {
        file_put_contents($this->docroot . '/image.bin', "PK\x03\x04\0\0\0binary\xff\xfe");

        $this->expectException(RuntimeException::class);
        $this->service()->read($this->siteBase, 'public_html/image.bin');
    }

    /**
     * Zip-slip entry validation lives in SafeOps and is covered without needing
     * the zip extension by exercising the validator directly.
     *
     * @dataProvider maliciousZipEntries
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('maliciousZipEntries')]
    public function test_zip_entry_validator_rejects_malicious_entries(string $entry): void
    {
        $ops = app(SafeOps::class);
        $method = new ReflectionMethod($ops, 'assertSafeZipEntry');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $method->invoke($ops, $entry, $this->docroot);
    }

    public static function maliciousZipEntries(): array
    {
        return [
            'parent traversal' => ['../../../../tmp/pwned'],
            'absolute posix'   => ['/etc/passwd'],
            'windows drive'    => ['C:\\Windows\\evil.dll'],
            'null byte'        => ["evil\0.txt"],
        ];
    }
}
