<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Website extends Model
{
    protected $fillable = [
        'domain', 'user_id', 'type', 'php_version', 'document_root',
        'system_username', 'www_alias', 'upstream_url', 'websocket',
        'force_https', 'status', 'enabled', 'ssl_enabled', 'meta',
    ];

    protected $casts = [
        'www_alias' => 'boolean',
        'websocket' => 'boolean',
        'force_https' => 'boolean',
        'enabled' => 'boolean',
        'ssl_enabled' => 'boolean',
        'meta' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(WebsiteAlias::class);
    }

    public function sslCertificate(): HasMany
    {
        return $this->hasMany(SslCertificate::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isDeleting(): bool
    {
        return $this->status === 'deleting';
    }

    /**
     * Stored per-site PHP setting overrides (raw, as the operator saved them).
     *
     * @return array<string, string>
     */
    public function phpSettingOverrides(): array
    {
        return (array) ($this->meta['php_settings'] ?? []);
    }

    /**
     * Effective per-site PHP settings: stock defaults merged with valid stored
     * overrides. Always contains every managed key.
     *
     * @return array<string, string>
     */
    public function phpSettings(): array
    {
        return \App\Services\System\PhpSettings::effective($this->phpSettingOverrides());
    }
}
