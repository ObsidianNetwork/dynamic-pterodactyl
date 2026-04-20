<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Listeners;

use App\Events\Invoice\Paid;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;

class InvoicePaidListener
{
    public function handle(Paid $event): void
    {
        $invoice = $event->invoice;

        foreach ($invoice->items as $item) {
            // Skip items that don't reference a service
            if ($item->reference_type !== Service::class) {
                continue;
            }

            $service = $item->reference;
            if (!$service) {
                continue;
            }

            // Get reservation token from service properties (morphMany relationship)
            $reservationToken = $service->properties()
                ->where('key', '_reservation_token')
                ->value('value');

            // Check if this service has a reservation
            if (!$reservationToken) {
                continue;
            }

            try {
                $reservationService = app(ReservationService::class);
                $resourceService = app(ResourceCalculationService::class);

                $reservation = $reservationService->getByToken($reservationToken);

                if (!$reservation) {
                    Log::warning('Reservation not found for paid invoice', [
                        'service_id' => $service->id,
                        'invoice_id' => $invoice->id,
                    ]);

                    continue;
                }

                // CRITICAL: Final availability verification
                $available = $resourceService->verifyAvailability(
                    $reservation->node_id,
                    [
                        'memory' => $reservation->memory,
                        'cpu' => $reservation->cpu,
                        'disk' => $reservation->disk,
                    ]
                );

                if (!$available) {
                    // This should rarely happen, but we need to handle it
                    Log::error('Resources no longer available for paid service', [
                        'service_id' => $service->id,
                        'node_id' => $reservation->node_id,
                        'memory' => $reservation->memory,
                        'cpu' => $reservation->cpu,
                        'disk' => $reservation->disk,
                    ]);

                    // TODO: Notify admin for manual intervention
                    // The server will still be created by Pterodactyl extension,
                    // but may fail due to insufficient resources
                    continue;
                }

                // Confirm the reservation. Returns false if no pending row matched —
                // meaning the reservation was already cancelled or expired between
                // verifyAvailability() and this call (state drift).
                $confirmed = $reservationService->confirm($reservationToken, $service->id);

                if ($confirmed) {
                    Log::info('Confirmed reservation for paid service', [
                        'service_id' => $service->id,
                        'node_id' => $reservation->node_id,
                    ]);
                } else {
                    $current = $reservationService->getByToken($reservationToken);
                    Log::warning('Reservation could not be confirmed (state drift)', [
                        'service_id' => $service->id,
                        'reservation_id' => $reservation->id,
                        'current_status' => $current?->status,
                    ]);
                    // TODO: notify admin — server still provisions via Pterodactyl
                    // extension, but reservation bookkeeping/linkage is now broken.
                }

            } catch (\Exception $e) {
                Log::error('Failed to confirm reservation', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
