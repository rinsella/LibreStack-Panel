<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class Audit
{
    /**
     * Record an audit log entry for an important action.
     */
    public static function log(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
        ?int $userId = null,
    ): void {
        AuditLog::create([
            'user_id'     => $userId ?? Auth::id(),
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'ip_address'  => Request::ip(),
            'user_agent'  => substr((string) Request::userAgent(), 0, 255),
            'metadata'    => self::redact($metadata),
            'created_at'  => now(),
        ]);
    }

    /**
     * Redact sensitive keys from metadata before storing.
     */
    protected static function redact(array $data): array
    {
        $sensitive = ['password', 'pass', 'secret', 'token', 'db_password', 'key'];

        foreach ($data as $key => $value) {
            foreach ($sensitive as $needle) {
                if (str_contains(strtolower((string) $key), $needle)) {
                    $data[$key] = '********';
                }
            }
        }

        return $data;
    }
}
