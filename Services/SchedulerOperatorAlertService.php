<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Notifications\SchedulerTaskFailureNotification;

class SchedulerOperatorAlertService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function notify(array $context): void
    {
        try {
            $recipients = User::whereNotNull('role_id')->get();
        } catch (\Throwable $exception) {
            $this->safeLog(
                'critical',
                'Scheduler failure recipients could not be loaded',
                $context + ['notification_error' => $exception->getMessage()]
            );
            $this->reportThrowable($exception);

            return;
        }

        if ($recipients->isEmpty()) {
            $this->safeLog(
                'critical',
                'Dynamic Pterodactyl scheduler requires attention',
                $context
            );

            return;
        }

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(
                    new SchedulerTaskFailureNotification($context)
                );
            } catch (\Throwable $exception) {
                $this->safeLog(
                    'error',
                    'Failed to notify operator about scheduler health',
                    [
                        'task' => $context['task'] ?? null,
                        'entity_type' => $context['entity_type'] ?? null,
                        'entity_id' => $context['entity_id'] ?? null,
                        'recipient_id' => $recipient->id ?? null,
                        'error' => $exception->getMessage(),
                    ]
                );
                $this->reportThrowable($exception);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function safeLog(
        string $level,
        string $message,
        array $context,
    ): void {
        try {
            Log::{$level}($message, $context);
        } catch (\Throwable) {
            // Scheduler alerting must not interrupt later lifecycle rows.
        }
    }

    private function reportThrowable(\Throwable $throwable): void
    {
        try {
            app(ExceptionHandler::class)->report($throwable);
        } catch (\Throwable) {
            // The application exception handler may itself be unavailable.
        }
    }
}
