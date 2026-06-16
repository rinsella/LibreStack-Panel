<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PanelDatabase extends Model
{
    protected $table = 'panel_databases';

    protected $fillable = ['name', 'driver', 'website_id', 'user_id', 'size_bytes'];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(DatabaseUser::class);
    }
}
