<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupSchedule extends Model
{
    protected $fillable = [
        'website_id', 'type', 'frequency', 'retention', 'enabled', 'last_run_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
