<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseUser extends Model
{
    protected $fillable = ['username', 'host', 'panel_database_id'];

    public function database(): BelongsTo
    {
        return $this->belongsTo(PanelDatabase::class, 'panel_database_id');
    }
}
