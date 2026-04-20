<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Listeners;

use App\Events\CartItem\Deleted;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class CartItemDeletedListener
{
    public function handle(Deleted $event): void
    {
        $cartItem = $event->cartItem;

        // Reservation token is stored in checkout_config by CartItemCreatedListener
        $checkoutConfig = $cartItem->checkout_config ?? [];
        $token = $checkoutConfig['_reservation_token'] ?? null;

        if (!$token) {
            return;
        }

        // If a Service already carries this reservation token, the cart item was
        // deleted as part of a successful checkout. Paymenter core (Cart::checkout)
        // copies checkout_config into Service::properties, commits, then clears the
        // cart — all BEFORE Invoice\Paid fires. Cancelling here would race-cancel a
        // reservation that InvoicePaidListener is about to confirm. Leave it pending.
        $serviceExists = Service::whereHas('properties', function ($q) use ($token) {
            $q->where('key', '_reservation_token')->where('value', $token);
        })->exists();

        if ($serviceExists) {
            Log::debug('Skipping reservation cancel: cart item consumed by checkout', [
                'cart_item_id' => $cartItem->id,
                'reservation_token' => substr($token, 0, 8) . '...',
            ]);

            return;
        }

        try {
            $reservationService = app(ReservationService::class);
            $reservationService->cancel($token);

            Log::info('Cancelled reservation for deleted cart item', [
                'cart_item_id' => $cartItem->id,
                'reservation_token' => substr($token, 0, 8) . '...',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cancel reservation', [
                'token' => substr($token, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
