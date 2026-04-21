<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationShortfallNotification extends Notification implements ShouldQueue
{
    use Queueable;
    public function __construct(
        public int $serviceId,
        public int $invoiceId,
        public array $reservationSnapshot,
        public string $reason,
    ) {
        $this->afterCommit = true;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Paymenter] Reservation shortfall — service ' . $this->serviceId)
            ->line('A paid invoice could not reconcile with its reservation.')
            ->line('Service ID: ' . $this->serviceId)
            ->line('Invoice ID: ' . $this->invoiceId)
            ->line('Reason: ' . $this->reason)
            ->line('Snapshot: memory=' . ($this->reservationSnapshot['memory'] ?? 'n/a')
                . ' cpu=' . ($this->reservationSnapshot['cpu'] ?? 'n/a')
                . ' disk=' . ($this->reservationSnapshot['disk'] ?? 'n/a'))
            ->line('Action required: verify the provisioned server has correct resources or manually migrate.')
            ->action('View service', url('/admin/services/' . $this->serviceId . '/edit'));
    }
}
