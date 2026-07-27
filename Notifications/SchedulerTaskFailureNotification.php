<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SchedulerTaskFailureNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(public array $context) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $task = (string) ($this->context['task'] ?? 'unknown');
        $lagging = ($this->context['kind'] ?? null) === 'scheduler_lag';

        return (new MailMessage)
            ->subject(
                $lagging
                    ? '[Paymenter] Dynamic Pterodactyl scheduler is lagging'
                    : '[Paymenter] Dynamic Pterodactyl scheduled task failed'
            )
            ->line('Task: '.$task)
            ->when(
                isset($this->context['entity_type']),
                fn (MailMessage $message) => $message->line(
                    'Record: '
                    .$this->context['entity_type']
                    .' #'
                    .($this->context['entity_id'] ?? 'unknown')
                )
            )
            ->when(
                isset($this->context['lag_seconds']),
                fn (MailMessage $message) => $message->line(
                    'Seconds since last successful run: '
                    .$this->context['lag_seconds']
                )
            )
            ->line(
                'Last error: '
                .($this->context['error'] ?? 'The scheduler missed its healthy-run threshold.')
            )
            ->line(
                'Inspect the scheduler heartbeat record and the application log before retrying or changing fulfillment state.'
            )
            ->action('Open admin panel', url('/admin'));
    }
}
