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
}
