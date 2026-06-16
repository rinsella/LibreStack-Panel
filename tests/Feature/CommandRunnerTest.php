<?php

namespace Tests\Feature;

use App\Services\Support\CommandRunner;
use InvalidArgumentException;
use Tests\TestCase;

class CommandRunnerTest extends TestCase
{
    public function test_unknown_binary_is_rejected(): void
    {
        $runner = new CommandRunner();

        $this->expectException(InvalidArgumentException::class);
        $runner->run('definitely-not-allowed', []);
    }

    public function test_null_byte_arguments_are_rejected(): void
    {
        $runner = new CommandRunner();

        $this->expectException(InvalidArgumentException::class);
        // 'uname' is allowlisted, but the argument contains a null byte.
        $runner->run('uname', ["a\0b"]);
    }

    public function test_disabled_in_non_system_mode(): void
    {
        config(['librestack.system_enabled' => false]);

        $result = (new CommandRunner())->run('uname', ['-a']);

        $this->assertTrue($result->disabled);
        $this->assertFalse($result->ok);
    }
}
