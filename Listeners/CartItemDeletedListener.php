<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Listeners;

use App\Events\CartItem\Deleting;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class CartItemDeletedListener
{
    public function __construct(private readonly ReservationService $reservationService) {}

    public function handle(Deleting $event): void
    {
        if ($this->reservationService->cancelForCartItem($event->cartItem->id)) {
            Log::info('Cancelled capacity reservation for removed cart item', [
                'cart_item_id' => $event->cartItem->id,
            ]);
        }
    }
}
