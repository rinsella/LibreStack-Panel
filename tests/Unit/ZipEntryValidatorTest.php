<?php

namespace Tests\Unit;

use App\Services\FileManager\FileManagerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Exercises the ZIP entry validator directly (no ext-zip required) so the
 * malicious-entry rejection logic is always covered, even where the zip
 * extension is unavailable.
 */
class ZipEntryValidatorTest extends TestCase
{
    private function dest(): string
    {
        $dest = sys_get_temp_dir() . '/ls_zipval_' . uniqid();
        @mkdir($dest, 0755, true);

        return (string) realpath($dest);
    }

    private function validate(string $entry, string $dest): void
    {
        $service = new FileManagerService();
        $method = new ReflectionMethod($service, 'assertSafeZipEntry');
        $method->setAccessible(true);
        $method->invoke($service, $entry, $dest);
    }

    /**
     * @dataProvider maliciousEntries
     */
    #[DataProvider('maliciousEntries')]
    public function test_rejects_malicious_entries(string $entry): void
    {
        $dest = $this->dest();

        try {
            $this->expectException(RuntimeException::class);
            $this->validate($entry, $dest);
        } finally {
            @rmdir($dest);
        }
    }

    public static function maliciousEntries(): array
    {
        return [
            'parent traversal'  => ['../../../../tmp/pwned'],
            'nested traversal'  => ['a/b/../../../../../etc/passwd'],
            'absolute posix'    => ['/etc/passwd'],
            'windows drive'     => ['C:\\Windows\\evil.dll'],
            'windows backslash' => ['..\\..\\evil'],
            'null byte'         => ["evil\0.txt"],
        ];
    }

    public function test_accepts_safe_nested_entry(): void
    {
        $dest = $this->dest();

        $this->validate('safe/nested/file.txt', $dest);
        @rmdir($dest);

        $this->assertTrue(true);
    }
}
