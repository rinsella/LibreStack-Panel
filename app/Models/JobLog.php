<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobLog extends Model
{
    protected $fillable = ['system_job_id', 'level', 'line'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(SystemJob::class, 'system_job_id');
    }
}
