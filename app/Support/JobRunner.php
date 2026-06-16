<?php

namespace App\Support;

use App\Models\SystemJob;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Runs a unit of work as a tracked SystemJob, recording status and logs.
 *
 * Work runs synchronously so the panel functions correctly even when no queue
 * worker is running. The SystemJob + JobLog records give the Jobs UI a full,
 * real audit of every long-running operation.
 */
class JobRunner
{
    /**
     * @param  callable(SystemJob):string  $work  returns a success message
     */
    public static function run(string $type, array $payload, callable $work): SystemJob
    {
        $job = SystemJob::create([
            'type'       => $type,
            'status'     => 'queued',
            'payload'    => $payload,
            'created_by' => Auth::id(),
        ]);

        $job->markRunning();
        $job->log("Started {$type}");

        try {
            $message = $work($job) ?: 'Completed';
            $job->markSuccess($message);
            $job->log($message, 'success');
        } catch (Throwable $e) {
            $job->markFailed($e->getMessage());
            $job->log($e->getMessage(), 'error');
        }

        return $job->fresh();
    }
}
