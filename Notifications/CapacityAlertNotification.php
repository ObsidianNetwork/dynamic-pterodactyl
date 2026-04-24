<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig;

class CapacityAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public AlertConfig $alertConfig,
        public array $breachedThresholds,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $severity = collect($this->breachedThresholds)->contains('type', 'critical') ? 'CRITICAL' : 'WARNING';
        $scope = $this->alertConfig->location_name
            ?? ($this->alertConfig->location_id ? 'Location #' . $this->alertConfig->location_id : 'All Locations');

        $message = (new MailMessage)
            ->subject("[{$severity}] Pterodactyl capacity alert: {$scope}")
            ->greeting("Capacity alert: {$scope}");

        foreach ($this->breachedThresholds as $breach) {
            $message->line(sprintf(
                '%s at %.1f%% (threshold %d%%, %s)',
                ucfirst($breach['resource']),
                $breach['usage_percent'] ?? $breach['utilization'] ?? 0,
                $breach['threshold'] ?? 0,
                strtoupper($breach['type'] ?? 'warning'),
            ));
        }

        return $message
            ->line('Location scope: ' . $scope)
            ->action('View alert config', url("/admin/alert-configs/{$this->alertConfig->id}/edit"));
    }
}
