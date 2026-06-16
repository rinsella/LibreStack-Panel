<?php

namespace App\Services\Support;

/**
 * Immutable result of a system command executed through the CommandRunner.
 */
class CommandResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $error,
        public readonly bool $disabled = false,
    ) {
    }

    public static function disabled(string $reason = 'System commands are disabled (LIBRESTACK_SYSTEM_ENABLED=false).'): self
    {
        return new self(false, -1, '', $reason, true);
    }

    public function combined(): string
    {
        return trim($this->output . ($this->error ? "\n" . $this->error : ''));
    }
}
