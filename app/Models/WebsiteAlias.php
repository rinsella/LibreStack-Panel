<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteAlias extends Model
{
    protected $fillable = ['website_id', 'domain'];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
