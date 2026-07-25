<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProvisioningFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(public array $snapshot)
    {
        $this->afterCommit = true;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $serviceId = (int) ($this->snapshot['service_id'] ?? 0);
        $operation = $this->snapshot['operation'] ?? 'provisioning';
        $cancellation = $operation === 'cancellation';
        $upgrade = $operation === 'upgrade';

        return (new MailMessage)
            ->subject(match (true) {
                $cancellation => '[Paymenter] Dynamic server cancellation requires attention',
                $upgrade => '[Paymenter] Dynamic resource upgrade requires attention',
                default => '[Paymenter] Dynamic server provisioning requires attention',
            })
            ->line(match (true) {
                $cancellation => 'A dynamic server cancellation exhausted its immediate termination retries.',
                $upgrade => 'A paid dynamic resource upgrade exhausted its automatic provisioning retries.',
                default => 'A paid dynamic-capacity order exhausted its immediate provisioning retries.',
            })
            ->when(
                $upgrade,
                fn (MailMessage $message) => $message->line(
                    'Upgrade ID: ' . ($this->snapshot['upgrade_id'] ?? 'unknown')
                )
            )
            ->line('Service ID: ' . $serviceId)
            ->line('Invoice ID: ' . ($this->snapshot['invoice_id'] ?? 'free service'))
            ->line('Reservation ID: ' . ($this->snapshot['reservation_id'] ?? 'unknown'))
            ->line('Node ID: ' . ($this->snapshot['node_id'] ?? 'unknown'))
            ->line('Attempts: ' . ($this->snapshot['attempts'] ?? 0))
            ->line('Last error: ' . ($this->snapshot['error'] ?? 'unknown'))
            ->line(match (true) {
                $cancellation => 'The cancellation tombstone remains active. Confirm the external server is deleted before resolving it.',
                $upgrade => 'The paid capacity delta remains committed. Reconcile the panel state before manually resolving the upgrade.',
                default => 'The paid capacity remains committed and automatic reconciliation will continue.',
            })
            ->action('View service', url('/admin/services/' . $serviceId . '/edit'));
    }
}
