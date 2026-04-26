<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Events\AlertDeliveryFailed;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertDeliveryLog;
use Paymenter\Extensions\Others\DynamicPterodactyl\Notifications\CapacityAlertNotification;
use Paymenter\Extensions\Others\DynamicPterodactyl\Notifications\ReservationShortfallNotification;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\Concerns\AuditsExtensionActions;

class AlertService
{
    use AuditsExtensionActions;

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

                if (! empty($alerts) && $this->sendNotifications($config, $availability, $alerts)) {
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
            $alerts[] = [
                'type' => 'critical',
                'resource' => 'memory',
                'utilization' => $memoryUtilization,
                'usage_percent' => round($memoryUtilization, 1),
                'threshold' => (int) $config->memory_critical_threshold,
            ];
        } elseif ($memoryUtilization >= $config->memory_warning_threshold) {
            $alerts[] = [
                'type' => 'warning',
                'resource' => 'memory',
                'utilization' => $memoryUtilization,
                'usage_percent' => round($memoryUtilization, 1),
                'threshold' => (int) $config->memory_warning_threshold,
            ];
        }

        if ($diskUtilization >= $config->disk_critical_threshold) {
            $alerts[] = [
                'type' => 'critical',
                'resource' => 'disk',
                'utilization' => $diskUtilization,
                'usage_percent' => round($diskUtilization, 1),
                'threshold' => (int) $config->disk_critical_threshold,
            ];
        } elseif ($diskUtilization >= $config->disk_warning_threshold) {
            $alerts[] = [
                'type' => 'warning',
                'resource' => 'disk',
                'utilization' => $diskUtilization,
                'usage_percent' => round($diskUtilization, 1),
                'threshold' => (int) $config->disk_warning_threshold,
            ];
        }

        return $alerts;
    }

    private function sendNotifications(object $config, array $availability, array $alerts): bool
    {
        $locationScope = $availability['location_id'] ?? $config->location_id ?? null;
        $locationName = $availability['location_name']
            ?? $config->location_name
            ?? ($locationScope !== null ? 'Location #' . $locationScope : 'All Locations');
        $alertConfig = $this->hydrateAlertConfig((object) array_merge((array) $config, [
            'location_id' => $locationScope,
            'location_name' => $locationName,
        ]));
        $channelsTried = [];
        $channelsOk = [];
        $channelsFailed = [];
        $lastError = null;

        if ($config->email_notifications) {
            $channelsTried[] = 'email';
            $recipients = $this->getAdminRecipients();

            if ($recipients->isEmpty()) {
                Log::warning('No admin recipients configured for capacity alert', [
                    'alert_config_id' => $config->id,
                ]);
                $channelsFailed[] = 'email';
                $lastError = 'No admin recipients configured';
            } else {
                $emailDelivered = false;

                foreach ($recipients as $admin) {
                    try {
                        $admin->notify(new CapacityAlertNotification(
                            $alertConfig,
                            $alerts,
                        ));
                        $emailDelivered = true;
                    } catch (\Throwable $e) {
                        Log::warning('Failed to send capacity alert email', [
                            'alert_config_id' => $config->id,
                            'recipient_id' => $admin->id ?? null,
                            'error' => $e->getMessage(),
                        ]);
                        $lastError = $e->getMessage();
                        $this->reportThrowable($e);
                    }
                }

                if ($emailDelivered) {
                    $channelsOk[] = 'email';
                } else {
                    $channelsFailed[] = 'email';
                }
            }
        }

        if ($config->webhook_notifications && $config->webhook_url) {
            $channelsTried[] = 'webhook';

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
                ])->throw();

                $channelsOk[] = 'webhook';
            } catch (\Throwable $e) {
                Log::error('Webhook notification failed', [
                    'alert_config_id' => $config->id,
                    'webhook_host' => parse_url($config->webhook_url, PHP_URL_HOST),
                    'error' => $e->getMessage(),
                ]);
                $channelsFailed[] = 'webhook';
                $lastError = $e->getMessage();
            }
        }

        $deliveryLog = $this->safeWriteDeliveryLog(
            (int) $config->id,
            $channelsTried !== [] && $channelsOk === [] ? 'check_failure' : 'capacity_breach',
            $channelsTried,
            $channelsOk,
            $channelsFailed,
            $lastError,
        );

        if ($channelsTried !== [] && $channelsOk === []) {
            Event::dispatch(new AlertDeliveryFailed($deliveryLog ?? $this->makeTransientDeliveryLog(
                (int) $config->id,
                'check_failure',
                $channelsTried,
                $channelsOk,
                $channelsFailed,
                $lastError,
            )));
        }

        if ($channelsOk !== []) {
            $this->safeAudit('capacity_alert_sent', 'alert_config', (int) $config->id, [
                'channels' => $channelsOk,
                'severity' => collect($alerts)->contains('type', 'critical') ? 'critical' : 'warning',
                'breached' => array_column($alerts, 'resource'),
                'location_scope' => $locationScope,
            ]);
        }

        return $channelsOk !== [];
    }

    private function safeWriteDeliveryLog(
        int $configId,
        string $triggerType,
        array $channelsTried,
        array $channelsOk,
        array $channelsFailed,
        ?string $lastError,
    ): ?AlertDeliveryLog {
        try {
            return AlertDeliveryLog::create([
                'alert_config_id' => $configId,
                'trigger_type' => $triggerType,
                'attempted_at' => now(),
                'channels_tried' => $channelsTried,
                'channels_ok' => $channelsOk,
                'channels_failed' => $channelsFailed,
                'last_error' => $lastError,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AlertService: delivery-log write failed', [
                'alert_config_id' => $configId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function makeTransientDeliveryLog(
        int $configId,
        string $triggerType,
        array $channelsTried,
        array $channelsOk,
        array $channelsFailed,
        ?string $lastError,
    ): AlertDeliveryLog {
        $deliveryLog = new AlertDeliveryLog;
        $deliveryLog->exists = false;
        $deliveryLog->setRawAttributes([
            'alert_config_id' => $configId,
            'trigger_type' => $triggerType,
            'attempted_at' => now()->toDateTimeString(),
            'channels_tried' => $channelsTried,
            'channels_ok' => $channelsOk,
            'channels_failed' => $channelsFailed,
            'last_error' => $lastError,
        ], true);

        return $deliveryLog;
    }

    /**
     * Send test notification
     */
    public function sendTestNotification(object $config): void
    {
        $testAlerts = [
            [
                'type' => 'test',
                'resource' => 'memory',
                'utilization' => 85,
                'usage_percent' => 85.0,
                'threshold' => (int) ($config->memory_warning_threshold ?? 80),
            ],
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

    private function hydrateAlertConfig(object $config): AlertConfig
    {
        if ($config instanceof AlertConfig) {
            return $config;
        }

        $alertConfig = new AlertConfig;
        $alertConfig->forceFill((array) $config);
        $alertConfig->exists = isset($config->id);

        return $alertConfig;
    }

    private function reportThrowable(\Throwable $throwable): void
    {
        try {
            app(ExceptionHandler::class)->report($throwable);
        } catch (\Throwable) {
            // Plain unit tests do not boot the Laravel exception handler.
        }
    }
}
