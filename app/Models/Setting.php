<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'is_encrypted'];

    protected $casts = [
        'is_encrypted' => 'boolean',
    ];

    /**
     * Read a setting value, decrypting if necessary.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        if ($setting->is_encrypted && $setting->value !== null) {
            try {
                return Crypt::decryptString($setting->value);
            } catch (\Throwable) {
                return $default;
            }
        }

        return $setting->value ?? $default;
    }

    /**
     * Store a setting value, encrypting if requested.
     */
    public static function put(string $key, mixed $value, bool $encrypted = false): void
    {
        $stored = $value;

        if ($encrypted && $value !== null && $value !== '') {
            $stored = Crypt::encryptString((string) $value);
        }

        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'is_encrypted' => $encrypted]
        );
    }

    public static function all_settings(): array
    {
        return static::query()->get()->mapWithKeys(function (Setting $s) {
            return [$s->key => $s->is_encrypted ? '********' : $s->value];
        })->all();
    }
}
