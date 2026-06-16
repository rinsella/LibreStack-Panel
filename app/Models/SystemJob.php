<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemJob extends Model
{
    protected $fillable = [
        'type', 'status', 'progress', 'message', 'payload',
        'created_by', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(JobLog::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function log(string $line, string $level = 'info'): void
    {
        $this->logs()->create(['line' => $line, 'level' => $level]);
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markSuccess(string $message = 'Completed'): void
    {
        $this->update([
            'status' => 'success',
            'progress' => 100,
            'message' => $message,
            'finished_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => 'failed',
            'message' => $message,
            'finished_at' => now(),
        ]);
    }
}
