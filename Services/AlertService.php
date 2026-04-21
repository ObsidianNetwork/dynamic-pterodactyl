<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Notifications\ReservationShortfallNotification;

class AlertService
{
    private ResourceCalculationService $resourceService;

    public function __construct(ResourceCalculationService $resourceService)
    {
        $this->resourceService = $resourceService;
    }

    /**
     * Check all locations for capacity alerts
     */
    public function checkCapacityAlerts(): void
    {
        $alertConfigs = DB::table('ptero_alert_configs')
            ->where('is_active', true)
            ->get();

        foreach ($alertConfigs as $config) {
            $this->checkAlertConfig($config);
        }
    }

    private function checkAlertConfig(object $config): void
    {
        // Skip if in cooldown
        if ($config->last_notification_at &&
            now()->diffInMinutes($config->last_notification_at) < $config->cooldown_minutes) {
            return;
        }

        try {
            if ($config->location_id) {
                $locations = [$config->location_id];
            } else {
                $locations = collect($this->resourceService->getLocations())->pluck('id');
            }

            foreach ($locations as $locationId) {
                $availability = $this->resourceService->getLocationAvailability($locationId);
                $alerts = $this->checkThresholds($availability, $config);

                if (! empty($alerts)) {
                    $this->sendNotifications($config, $availability, $alerts);

                    DB::table('ptero_alert_configs')
                        ->where('id', $config->id)
                        ->update(['last_notification_at' => now()]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Alert check failed', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function checkThresholds(array $availability, object $config): array
    {
        $alerts = [];

        $memoryUtilization = $availability['total_capacity']['memory'] > 0
            ? ($availability['total_allocated']['memory'] / $availability['total_capacity']['memory']) * 100
            : 0;

        $diskUtilization = $availability['total_capacity']['disk'] > 0
            ? ($availability['total_allocated']['disk'] / $availability['total_capacity']['disk']) * 100
            : 0;

        if ($memoryUtilization >= $config->memory_critical_threshold) {
            $alerts[] = ['type' => 'critical', 'resource' => 'memory', 'utilization' => $memoryUtilization];
        } elseif ($memoryUtilization >= $config->memory_warning_threshold) {
            $alerts[] = ['type' => 'warning', 'resource' => 'memory', 'utilization' => $memoryUtilization];
        }

        if ($diskUtilization >= $config->disk_critical_threshold) {
            $alerts[] = ['type' => 'critical', 'resource' => 'disk', 'utilization' => $diskUtilization];
        } elseif ($diskUtilization >= $config->disk_warning_threshold) {
            $alerts[] = ['type' => 'warning', 'resource' => 'disk', 'utilization' => $diskUtilization];
        }

        return $alerts;
    }

    private function sendNotifications(object $config, array $availability, array $alerts): void
    {
        $locationName = $config->location_name ?? 'All Locations';

        if ($config->email_notifications && ! empty($config->notification_emails)) {
            $emails = json_decode($config->notification_emails, true);
            // TODO: Send email notification
            // Implementation depends on mail setup
            Log::info('Capacity alert email would be sent', [
                'emails' => $emails,
                'location' => $locationName,
                'alerts' => $alerts,
            ]);
        }

        if ($config->webhook_notifications && $config->webhook_url) {
            try {
                // Format for Discord webhook compatibility
                $alertColor = collect($alerts)->contains('type', 'critical') ? 16711680 : 16776960; // Red or Yellow

                Http::timeout(10)->post($config->webhook_url, [
                    'content' => "**Capacity Alert** - {$locationName}",
                    'embeds' => [[
                        'title' => 'Resource Usage Alert',
                        'color' => $alertColor,
                        'fields' => array_map(fn ($alert) => [
                            'name' => ucfirst($alert['type']) . ' - ' . ucfirst($alert['resource']),
                            'value' => round($alert['utilization'], 1) . '% utilized',
                            'inline' => true,
                        ], $alerts),
                        'footer' => [
                            'text' => 'DynamicPterodactyl Alert System',
                        ],
                        'timestamp' => now()->toIso8601String(),
                    ]],
                ]);
            } catch (\Exception $e) {
                Log::error('Webhook notification failed', [
                    'url' => $config->webhook_url,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send test notification
     */
    public function sendTestNotification(object $config): void
    {
        $testAlerts = [
            ['type' => 'test', 'resource' => 'memory', 'utilization' => 85],
        ];

        $testAvailability = [
            'location_id' => $config->location_id ?? 0,
            'total_capacity' => ['memory' => 65536, 'disk' => 512000],
            'total_allocated' => ['memory' => 55705, 'disk' => 409600],
        ];

        $this->sendNotifications($config, $testAvailability, $testAlerts);
    }

    /**
     * Notify all admins of a reservation shortfall or state drift after payment.
     */
    public function notifyShortfall(
        int $serviceId,
        int $invoiceId,
        array $snapshot,
        string $reason,
    ): void {
        $recipients = $this->getAdminRecipients();

        if ($recipients->isEmpty()) {
            Log::warning('No admin recipients configured for shortfall alert', [
                'service_id' => $serviceId,
                'invoice_id' => $invoiceId,
                'reason' => $reason,
            ]);
        }

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new ReservationShortfallNotification(
                    serviceId: $serviceId,
                    invoiceId: $invoiceId,
                    reservationSnapshot: $snapshot,
                    reason: $reason,
                ));
            } catch (\Throwable $e) {
                Log::error('Failed to notify admin recipient for shortfall alert', [
                    'service_id' => $serviceId,
                    'invoice_id' => $invoiceId,
                    'recipient_id' => $recipient->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get all admin users (non-null role_id = admin in Paymenter).
     */
    private function getAdminRecipients(): Collection
    {
        return User::whereNotNull('role_id')->get();
    }
}
