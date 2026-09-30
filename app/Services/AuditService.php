<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

final class AuditService
{
    public static function log(string $action, string $entityType, int|string|null $entityId = null, array $old = [], array $new = []): void
    {
        Database::get()->insert('audit_logs', [
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => mb_substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45),
            'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
