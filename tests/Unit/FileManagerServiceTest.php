<?php

namespace Tests\Unit;

use App\Services\FileManager\FileManagerService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FileManagerServiceTest extends TestCase
{
    protected string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/ls_fm_' . uniqid();
        @mkdir($this->base . '/sub', 0755, true);
        file_put_contents($this->base . '/hello.txt', 'hi');
    }

    protected function tearDown(): void
    {
        @unlink($this->base . '/hello.txt');
        @rmdir($this->base . '/sub');
        @rmdir($this->base);
        parent::tearDown();
    }

    public function test_resolves_paths_inside_base(): void
    {
        $service = new FileManagerService();
        $resolved = $service->resolve($this->base, 'hello.txt');

        $this->assertStringStartsWith($this->base, $resolved);
    }

    public function test_rejects_path_traversal(): void
    {
        $service = new FileManagerService();

        $this->expectException(RuntimeException::class);
        $service->resolve($this->base, '../../../../etc/passwd');
    }

    public function test_rejects_null_byte(): void
    {
        $service = new FileManagerService();

        $this->expectException(\InvalidArgumentException::class);
        $service->resolve($this->base, "evil\0.txt");
    }

    public function test_lists_directory_contents(): void
    {
        $service = new FileManagerService();
        $items = $service->list($this->base);

        $names = array_column($items, 'name');
        $this->assertContains('hello.txt', $names);
        $this->assertContains('sub', $names);
    }

    public function test_rejects_binary_file_edit(): void
    {
        $binary = $this->base . '/image.bin';
        file_put_contents($binary, "PK\x03\x04\0\0\0binarydata\xff\xfe");

        $service = new FileManagerService();

        $this->expectException(RuntimeException::class);
        $service->read($this->base, 'image.bin');

        @unlink($binary);
    }

    public function test_delete_refuses_symlink_escaping_base(): void
    {
        // A symlink inside the base pointing outside it must not let delete
        // follow it to the external target.
        $outside = sys_get_temp_dir() . '/ls_fm_outside_' . uniqid();
        @mkdir($outside, 0755, true);
        file_put_contents($outside . '/secret.txt', 'secret');

        $link = $this->base . '/escape';
        @symlink($outside, $link);

        $service = new FileManagerService();

        // Resolving a symlink that points outside the base is refused outright.
        try {
            $service->delete($this->base, 'escape');
            $this->fail('Expected the escaping symlink delete to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outside', $e->getMessage());
        }

        // The external target survives regardless.
        $this->assertFileExists($outside . '/secret.txt');

        @unlink($link);
        @unlink($outside . '/secret.txt');
        @rmdir($outside);
    }
}
