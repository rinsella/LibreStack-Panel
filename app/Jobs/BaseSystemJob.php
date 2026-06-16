<?php

namespace App\Jobs;

use App\Models\SystemJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Base class for every long-running panel operation.
 *
 * A SystemJob record is created the moment the job is dispatched (in the web
 * request) so the Jobs UI shows it as "queued" immediately. The actual work
 * runs later on the queue worker via handle(). Progress, status and logs are
 * recorded on the SystemJob throughout. Failed jobs are retried and ultimately
 * marked failed via failed().
 */
abstract class BaseSystemJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 600;

    public int $systemJobId;

    public function __construct()
    {
        $job = SystemJob::create([
            'type'       => $this->type(),
            'status'     => 'queued',
            'payload'    => $this->payload(),
            'created_by' => Auth::id(),
        ]);

        $this->systemJobId = $job->id;
    }

    /** A short machine-readable job type, e.g. "website.create". */
    abstract protected function type(): string;

    /** Non-sensitive payload stored on the SystemJob for visibility. */
    abstract protected function payload(): array;

    /** Perform the work and return a success message. */
    abstract protected function execute(SystemJob $job): string;

    public function handle(): void
    {
        $job = SystemJob::find($this->systemJobId);
        if (! $job) {
            return;
        }

        $job->markRunning();
        $job->log("Started {$this->type()}");

        try {
            $message = $this->execute($job) ?: 'Completed';
            $job->markSuccess($message);
            $job->log($message, 'success');
        } catch (Throwable $e) {
            $job->markFailed($e->getMessage());
            $job->log($e->getMessage(), 'error');

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $job = SystemJob::find($this->systemJobId);
        if ($job && $job->status !== 'failed') {
            $job->markFailed($e->getMessage());
        }
    }
}
