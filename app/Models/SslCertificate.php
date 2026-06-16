<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SslCertificate extends Model
{
    protected $fillable = [
        'website_id', 'domain', 'issuer', 'status',
        'issued_at', 'expires_at', 'auto_renew', 'meta',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'auto_renew' => 'boolean',
        'meta' => 'array',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
