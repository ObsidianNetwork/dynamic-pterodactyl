<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Listeners;

use App\Events\CartItem\Created;
use App\Events\CartItem\Updated;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationConfigurationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class CartItemCreatedListener
{
    public function __construct(
        private readonly ReservationService $reservationService,
        private readonly ReservationConfigurationService $configurationService
    ) {}

    public function handle(Created|Updated $event): void
    {
        $cartItem = $event->cartItem;

        if (! $this->configurationService->requiresReservation($cartItem->product_id)) {
            return;
        }

        // Exceptions intentionally bubble into the cart transaction. A dynamic
        // product must never be added or updated without an authoritative hold.
        $reservation = $this->reservationService->reserveForCartItem($cartItem);

        Log::info('Capacity reserved for cart item', [
            'cart_item_id' => $cartItem->id,
            'reservation_id' => $reservation['id'],
            'node_id' => $reservation['node_id'],
            'expires_at' => $reservation['expires_at'],
        ]);
    }
}
