<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Listeners;

use App\Events\Invoice\Paid;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AlertService;

class InvoicePaidListener
{
    public function handle(Paid $event): void
    {
        $invoice = $event->invoice;

        foreach ($invoice->items as $item) {
            if ($item->reference_type !== Service::class) {
                continue;
            }

            $service = $item->reference;
            if (!$service) {
                continue;
            }

            $reservationToken = $service->properties()
                ->where('key', '_reservation_token')
                ->value('value');

            if (!$reservationToken) {
                continue;
            }

            try {
                $reservationService = app(ReservationService::class);
                $resourceService = app(ResourceCalculationService::class);
                $alertService = app(AlertService::class);

                $reservation = $reservationService->getByToken($reservationToken);

                if (!$reservation) {
                    Log::warning('Reservation not found for paid invoice', [
                        'service_id' => $service->id,
                        'invoice_id' => $invoice->id,
                    ]);

                    continue;
                }

                $snapshot = [
                    'memory' => $reservation->memory,
                    'cpu' => $reservation->cpu,
                    'disk' => $reservation->disk,
                ];

                // CRITICAL: Final availability verification
                $available = $resourceService->verifyAvailability(
                    $reservation->node_id,
                    $snapshot,
                );

                if (!$available) {
                    Log::error('Resources no longer available for paid service', [
                        'service_id' => $service->id,
                        'node_id' => $reservation->node_id,
                        'memory' => $reservation->memory,
                        'cpu' => $reservation->cpu,
                        'disk' => $reservation->disk,
                    ]);

                    try {
                        $alertService->notifyShortfall(
                            serviceId: $service->id,
                            invoiceId: $invoice->id,
                            snapshot: $snapshot,
                            reason: 'insufficient_resources',
                        );
                    } catch (\Throwable $e) {
                        Log::error('Shortfall notification delivery failed', [
                            'service_id' => $service->id,
                            'reason' => 'insufficient_resources',
                            'error' => $e->getMessage(),
                        ]);
                    }

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

                    try {
                        $alertService->notifyShortfall(
                            serviceId: $service->id,
                            invoiceId: $invoice->id,
                            snapshot: $snapshot,
                            reason: 'state_drift:' . ($current?->status ?? 'unknown'),
                        );
                    } catch (\Throwable $e) {
                        Log::error('Shortfall notification delivery failed', [
                            'service_id' => $service->id,
                            'reason' => 'state_drift',
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

            } catch (\Exception $e) {
                Log::error('Invoice reservation processing failed', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
