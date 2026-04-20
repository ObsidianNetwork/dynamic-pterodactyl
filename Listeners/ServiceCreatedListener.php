<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Listeners;

use App\Events\Service\Created;
use Illuminate\Support\Facades\Log;

class ServiceCreatedListener
{
    public function handle(Created $event): void
    {
        $service = $event->service;

        // Get reservation token from service properties (morphMany relationship)
        $reservationToken = $service->properties()
            ->where('key', '_reservation_token')
            ->value('value');

        if (! $reservationToken) {
            return;
        }

        Log::info('Service created with reservation', [
            'service_id' => $service->id,
            'reservation_token' => substr($reservationToken, 0, 8) . '...',
            'product_id' => $service->product_id,
        ]);

        // The reservation should already be confirmed by InvoicePaidListener
        // This is just for logging/tracking purposes
    }
}
