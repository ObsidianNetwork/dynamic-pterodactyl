<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentAttentionNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        return (new MailMessage)
            ->subject('[Paymenter] Capacity invoice payment needs refund review')
            ->line('Payment activity exists after a dynamic-capacity guarantee expired.')
            ->line('Invoice ID: ' . ($this->snapshot['invoice_id'] ?? 'unknown'))
            ->line('Service ID: ' . ($this->snapshot['service_id'] ?? 'unknown'))
            ->line('Reason: ' . ($this->snapshot['error'] ?? 'unknown'))
            ->line('Capacity was not consumed. Review the gateway transaction and issue a refund or account credit.')
            ->action('View invoice', url('/admin/invoices/' . ($this->snapshot['invoice_id'] ?? 0) . '/edit'));
    }
}
