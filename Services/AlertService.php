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
use Paymenter\Extensions\Others\DynamicPterodactyl\Notifications\PaymentAttentionNotification;
use Paymenter\Extensions\Others\DynamicPterodactyl\Notifications\ProvisioningFailedNotification;
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

        foreach (['memory', 'cpu', 'disk'] as $resource) {
            $capacity = (int) data_get(
                $availability,
                "total_capacity.{$resource}",
                0
            );
            $available = data_get(
                $availability,
                "total_available.{$resource}"
            );
            $used = $available !== null
                ? max(0, $capacity - (int) $available)
                : (int) data_get(
                    $availability,
                    "total_allocated.{$resource}",
                    0
                );
            $utilization = $capacity > 0
                ? ($used / $capacity) * 100
                : 0.0;
            $warning = (int) (
                $config->{"{$resource}_warning_threshold"} ?? 80
            );
            $critical = (int) (
                $config->{"{$resource}_critical_threshold"} ?? 95
            );

            if ($utilization >= $critical) {
                $alerts[] = [
                    'type' => 'critical',
                    'resource' => $resource,
                    'utilization' => $utilization,
                    'usage_percent' => round($utilization, 1),
                    'threshold' => $critical,
                ];
            } elseif ($utilization >= $warning) {
                $alerts[] = [
                    'type' => 'warning',
                    'resource' => $resource,
                    'utilization' => $utilization,
                    'usage_percent' => round($utilization, 1),
                    'threshold' => $warning,
                ];
            }
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
     * Notify operators after the immediate queue retry series is exhausted.
     * ReservationService persists a deduplication timestamp before calling this.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function notifyProvisioningFailure(array $snapshot): void
    {
        $operation = ($snapshot['operation'] ?? 'provisioning') === 'cancellation'
            ? 'cancellation'
            : 'provisioning';
        $recipients = $this->getAdminRecipients();
        if ($recipients->isEmpty()) {
            Log::critical("Dynamic server {$operation} requires attention", $snapshot);

            return;
        }

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new ProvisioningFailedNotification($snapshot));
            } catch (\Throwable $exception) {
                Log::error("Failed to notify operator about {$operation} failure", [
                    'service_id' => $snapshot['service_id'] ?? null,
                    'recipient_id' => $recipient->id ?? null,
                    'error' => $exception->getMessage(),
                ]);
                $this->reportThrowable($exception);
            }
        }

        $this->safeAudit(
            $operation . '_failure_alerted',
            'resource_reservation',
            (int) ($snapshot['reservation_id'] ?? 0),
            [
                'service_id' => $snapshot['service_id'] ?? null,
                'invoice_id' => $snapshot['invoice_id'] ?? null,
                'attempts' => $snapshot['attempts'] ?? null,
            ]
        );
    }

    /**
     * Notify operators when a paid resource upgrade exhausts automatic
     * retries. ServiceUpgradeService persists its deduplication timestamp
     * before this method is called.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function notifyUpgradeFailure(array $snapshot): void
    {
        $recipients = $this->getAdminRecipients();
        if ($recipients->isEmpty()) {
            Log::critical(
                'Dynamic resource upgrade requires attention',
                $snapshot
            );
        }

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(
                    new ProvisioningFailedNotification($snapshot)
                );
            } catch (\Throwable $exception) {
                Log::error(
                    'Failed to notify operator about dynamic upgrade failure',
                    [
                        'upgrade_id' => $snapshot['upgrade_id'] ?? null,
                        'service_id' => $snapshot['service_id'] ?? null,
                        'recipient_id' => $recipient->id ?? null,
                        'error' => $exception->getMessage(),
                    ]
                );
                $this->reportThrowable($exception);
            }
        }

        $reservationId = (int) ($snapshot['reservation_id'] ?? 0);
        $this->safeAudit(
            'upgrade_failure_alerted',
            $reservationId > 0 ? 'resource_reservation' : 'service_upgrade',
            $reservationId > 0
                ? $reservationId
                : (int) ($snapshot['upgrade_id'] ?? 0),
            [
                'upgrade_id' => $snapshot['upgrade_id'] ?? null,
                'service_id' => $snapshot['service_id'] ?? null,
                'invoice_id' => $snapshot['invoice_id'] ?? null,
                'reservation_id' => $snapshot['reservation_id'] ?? null,
                'attempts' => $snapshot['attempts'] ?? null,
                'error' => $snapshot['error'] ?? null,
            ]
        );
    }

    /**
     * Notify operators that an external/partial payment exists but its
     * capacity guarantee may no longer be consumed.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function notifyPaymentAttention(array $snapshot): void
    {
        $recipients = $this->getAdminRecipients();
        if ($recipients->isEmpty()) {
            Log::critical('Capacity invoice payment requires refund review', $snapshot);

            return;
        }

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new PaymentAttentionNotification($snapshot));
            } catch (\Throwable $exception) {
                Log::error('Failed to notify operator about a late capacity payment', [
                    'invoice_id' => $snapshot['invoice_id'] ?? null,
                    'recipient_id' => $recipient->id ?? null,
                    'error' => $exception->getMessage(),
                ]);
                $this->reportThrowable($exception);
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
