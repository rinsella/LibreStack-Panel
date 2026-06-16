<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * The ONLY place in the application allowed to execute system commands.
 *
 * Hard rules enforced here:
 *  - The binary MUST be present on the allowlist in config/librestack.php.
 *  - Arguments are ALWAYS passed as an array to Symfony Process — user input is
 *    never concatenated into a shell string, so there is no shell interpolation.
 *  - When the panel runs in "non-system" mode (local/dev) commands are not
 *    executed; a disabled CommandResult is returned instead.
 */
class CommandRunner
{
    /**
     * @param  array<int, string>  $args
     */
    public function run(string $binary, array $args = [], ?int $timeout = 60, ?string $input = null): CommandResult
    {
        $this->assertAllowed($binary);
        $this->assertArgs($args);

        if (! config('librestack.system_enabled')) {
            return CommandResult::disabled();
        }

        $command = array_merge([$binary], array_values($args));

        $pending = Process::timeout($timeout ?? 60);

        if ($input !== null) {
            $pending = $pending->input($input);
        }

        $result = $pending->run($command);

        return new CommandResult(
            ok: $result->successful(),
            exitCode: $result->exitCode() ?? -1,
            output: $result->output(),
            error: $result->errorOutput(),
        );
    }

    /**
     * Run a command and pipe input through stdin (e.g. SQL into mysql).
     */
    public function runWithInput(string $binary, array $args, string $input, ?int $timeout = 120): CommandResult
    {
        return $this->run($binary, $args, $timeout, $input);
    }

    public function isEnabled(): bool
    {
        return (bool) config('librestack.system_enabled');
    }

    protected function assertAllowed(string $binary): void
    {
        $allowed = (array) config('librestack.allowed_binaries');

        // Allow absolute paths whose basename is allowlisted (e.g. /usr/bin/nginx).
        $name = basename($binary);

        if (! in_array($name, $allowed, true)) {
            throw new InvalidArgumentException("Binary not allowed: {$binary}");
        }
    }

    /**
     * Reject argument arrays that are not a flat list of scalars. This stops
     * accidental nested structures or null bytes reaching Process.
     *
     * @param  array<int, mixed>  $args
     */
    protected function assertArgs(array $args): void
    {
        foreach ($args as $arg) {
            if (! is_string($arg) && ! is_int($arg)) {
                throw new InvalidArgumentException('Command arguments must be strings or integers.');
            }

            if (is_string($arg) && str_contains($arg, "\0")) {
                throw new InvalidArgumentException('Command arguments may not contain null bytes.');
            }
        }
    }
}
