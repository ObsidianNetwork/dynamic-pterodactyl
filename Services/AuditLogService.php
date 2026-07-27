<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    /**
     * Log an action
     */
    public function log(
        string $action,
        string $entityType,
        int $entityId,
        ?array $newValues = null,
        ?array $oldValues = null,
        ?string $description = null,
        ?string $entityName = null
    ): int {
        $user = Auth::user();

        return DB::table('ptero_audit_logs')->insertGetId([
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'System',
            'user_email' => $user?->email ?? 'system@localhost',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'old_values' => $oldValues ? json_encode($oldValues) : null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'description' => $description,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Get audit logs with filters
     */
    public function getLogs(array $filters = [], int $limit = 50): Collection
    {
        $query = DB::table('ptero_audit_logs');

        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }
        if (! empty($filters['entity_id'])) {
            $query->where('entity_id', $filters['entity_id']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->limit($limit)->get();
    }

    /**
     * Get logs for a specific entity
     */
    public function getEntityHistory(string $entityType, int $entityId): Collection
    {
        return $this->getLogs([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ], 100);
    }
}
