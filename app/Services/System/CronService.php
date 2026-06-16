<?php

namespace App\Services\System;

use App\Models\CronJob;
use App\Services\Support\CommandResult;
use App\Services\Support\CommandRunner;
use App\Support\Validators;
use InvalidArgumentException;

/**
 * Synchronises panel-managed cron jobs into the system crontab.
 *
 * The whole panel-managed block is rewritten atomically between marker
 * comments, so user-authored crontab entries outside the block are preserved.
 * Commands and schedules are validated before being written.
 */
class CronService
{
    protected const BEGIN = '# >>> LibreStack Panel managed block >>>';
    protected const END = '# <<< LibreStack Panel managed block <<<';

    public function __construct(protected CommandRunner $runner)
    {
    }

    /**
     * Render the crontab lines for the enabled, valid jobs.
     */
    public function render(): string
    {
        $lines = [self::BEGIN];

        foreach (CronJob::where('enabled', true)->get() as $job) {
            if (! Validators::isValidCronSchedule($job->schedule)) {
                continue;
            }
            // Strip newlines from the command to keep each entry on one line.
            $command = trim(preg_replace('/[\r\n]+/', ' ', (string) $job->command));
            $lines[] = "{$job->schedule} {$command} # librestack:{$job->id}";
        }

        $lines[] = self::END;

        return implode("\n", $lines) . "\n";
    }

    /**
     * Write the managed block into the user's crontab.
     */
    public function sync(): CommandResult
    {
        if (! $this->runner->isEnabled()) {
            return new CommandResult(true, 0, '[dev] crontab not written (system disabled)', '');
        }

        $current = $this->runner->run('crontab', ['-l'], 15);
        $existing = $current->ok ? $current->output : '';

        // Remove any previous managed block.
        $existing = preg_replace(
            '/' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '\n?/s',
            '',
            $existing
        );

        $new = rtrim((string) $existing) . "\n\n" . $this->render();

        return $this->runner->runWithInput('crontab', ['-'], $new, 15);
    }

    public function assertValid(string $schedule, string $command): void
    {
        if (! Validators::isValidCronSchedule($schedule)) {
            throw new InvalidArgumentException('Invalid cron schedule.');
        }
        if (trim($command) === '' || str_contains($command, "\0")) {
            throw new InvalidArgumentException('Invalid command.');
        }
    }

    /**
     * Heuristic flag for obviously dangerous commands (shown as a UI warning).
     */
    public static function isDangerous(string $command): bool
    {
        $patterns = ['rm -rf', 'mkfs', ':(){', 'dd if=', '> /dev/sd', 'shutdown', 'reboot', 'chmod -R 777 /'];
        $lower = strtolower($command);

        foreach ($patterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
