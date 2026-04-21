<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models\Observers;

use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;

class AlertConfigObserver
{
    public function __construct(private AuditLogService $audit) {}

    public function created(AlertConfig $config): void
    {
        try {
            $attrs = $config->getAttributes();
            unset($attrs['id'], $attrs['created_at'], $attrs['updated_at']);
            $this->redactWebhook($attrs);
            $this->audit->log('created', 'alert_config', $config->id, $attrs);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function updated(AlertConfig $config): void
    {
        try {
            $changes = $config->getChanges();
            $original = array_intersect_key(
                $config->getOriginal(),
                array_flip(array_keys($changes))
            );

            $this->redactWebhook($changes);
            $this->redactWebhook($original);

            $this->audit->log(
                'updated',
                'alert_config',
                $config->id,
                $changes,
                $original
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function deleted(AlertConfig $config): void
    {
        try {
            $attrs = $config->getAttributes();
            unset($attrs['id'], $attrs['created_at'], $attrs['updated_at']);
            $this->redactWebhook($attrs);

            $this->audit->log('deleted', 'alert_config', $config->id, $attrs);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function redactWebhook(array &$attrs): void
    {
        if (array_key_exists('webhook_url', $attrs)
            && $attrs['webhook_url'] !== null
            && $attrs['webhook_url'] !== '') {
            $attrs['webhook_url'] = '[REDACTED]';
        }
    }
}
