<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Listeners;

use App\Events\CartItem\Deleted;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class CartItemDeletedListener
{
    public function handle(Deleted $event): void
    {
        $cartItem = $event->cartItem;

        // Reservation token is stored in checkout_config by CartItemCreatedListener
        $checkoutConfig = $cartItem->checkout_config ?? [];

        // Check if this cart item had a reservation
        if (! isset($checkoutConfig['_reservation_token'])) {
            return;
        }

        try {
            $reservationService = app(ReservationService::class);
            $reservationService->cancel($checkoutConfig['_reservation_token']);

            Log::info('Cancelled reservation for deleted cart item', [
                'cart_item_id' => $cartItem->id,
                'reservation_token' => substr($checkoutConfig['_reservation_token'], 0, 8) . '...',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to cancel reservation', [
                'token' => substr($checkoutConfig['_reservation_token'], 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
