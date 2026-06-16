<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class RunScheduledBackups extends Command
{
    protected $signature = 'librestack:run-backups {--frequency=daily}';

    protected $description = 'Run enabled backup schedules for the given frequency and prune old backups.';

    public function handle(BackupService $backups): int
    {
        $frequency = (string) $this->option('frequency');

        $schedules = BackupSchedule::with('website')
            ->where('enabled', true)
            ->where('frequency', $frequency)
            ->get();

        foreach ($schedules as $schedule) {
            if (! $schedule->website) {
                continue;
            }

            $this->info("Backing up {$schedule->website->domain} ({$schedule->type})");
            $backups->create($schedule->website, $schedule->type);
            $schedule->update(['last_run_at' => now()]);

            $this->prune($schedule);
        }

        $this->info("Completed {$schedules->count()} scheduled backup(s).");

        return self::SUCCESS;
    }

    protected function prune(BackupSchedule $schedule): void
    {
        $keep = max(1, (int) $schedule->retention);

        $old = Backup::where('website_id', $schedule->website_id)
            ->where('type', $schedule->type)
            ->orderByDesc('created_at')
            ->skip($keep)
            ->take(1000)
            ->get();

        foreach ($old as $backup) {
            if ($backup->path && is_file($backup->path)) {
                @unlink($backup->path);
            }
            $backup->delete();
        }
    }
}
