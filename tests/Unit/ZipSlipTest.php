<?php

namespace Tests\Unit;

use App\Services\FileManager\FileManagerService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * ZIP-slip (path traversal during extraction) defence tests.
 */
class ZipSlipTest extends TestCase
{
    protected string $base = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is not installed in this environment.');
        }

        $this->base = sys_get_temp_dir() . '/ls_zip_' . uniqid();
        @mkdir($this->base, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->base !== '') {
            $this->rrmdir($this->base);
        }
        // Clean any file a successful slip might have created.
        @unlink(sys_get_temp_dir() . '/ls_zipslip_pwned');
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
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function makeZip(string $entryName, string $content = 'pwned'): string
    {
        $path = $this->base . '/evil.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($entryName, $content);
        $zip->close();

        return $path;
    }

    public function test_rejects_parent_traversal_entry(): void
    {
        $this->makeZip('../../../../tmp/ls_zipslip_pwned');

        $this->expectException(RuntimeException::class);
        (new FileManagerService())->unzip($this->base, 'evil.zip', '');

        $this->assertFileDoesNotExist(sys_get_temp_dir() . '/ls_zipslip_pwned');
    }

    public function test_rejects_absolute_path_entry(): void
    {
        $this->makeZip('/tmp/ls_zipslip_pwned');

        $this->expectException(RuntimeException::class);
        (new FileManagerService())->unzip($this->base, 'evil.zip', '');
    }

    public function test_rejects_windows_drive_path_entry(): void
    {
        $this->makeZip('C:\\Windows\\system32\\evil.dll');

        $this->expectException(RuntimeException::class);
        (new FileManagerService())->unzip($this->base, 'evil.zip', '');
    }

    public function test_extracts_safe_archive(): void
    {
        $this->makeZip('safe/hello.txt', 'hi');

        (new FileManagerService())->unzip($this->base, 'evil.zip', '');

        $this->assertFileExists($this->base . '/safe/hello.txt');
        $this->assertSame('hi', file_get_contents($this->base . '/safe/hello.txt'));
    }
}
