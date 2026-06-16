<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CronJob extends Model
{
    protected $fillable = [
        'user_id', 'system_username', 'name', 'schedule', 'command', 'enabled', 'last_run_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
