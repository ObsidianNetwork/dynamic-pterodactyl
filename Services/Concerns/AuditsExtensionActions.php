<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services\Concerns;

use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;

trait AuditsExtensionActions
{
    protected function safeAudit(string $action, string $entityType, int $entityId, ?array $newValues = null): void
    {
        try {
            app(AuditLogService::class)->log($action, $entityType, $entityId, $newValues);
        } catch (\Throwable $e) {
            Log::warning('extension audit write failed', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
            report($e);
        }
    }
}
