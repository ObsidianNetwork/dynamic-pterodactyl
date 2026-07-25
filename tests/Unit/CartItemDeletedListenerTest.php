<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Events\CartItem\Deleting;
use App\Models\CartItem;
use Illuminate\Support\Facades\Log;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Listeners\CartItemDeletedListener;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class CartItemDeletedListenerTest extends LaravelTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_cart_item_deletion_cancels_by_relationship_without_a_token(): void
    {
        $reservationService = Mockery::mock(ReservationService::class);
        $reservationService->shouldReceive('cancelForCartItem')
            ->once()
            ->with(42)
            ->andReturn(true);

        Log::shouldReceive('info')
            ->once()
            ->with('Cancelled capacity reservation for removed cart item', [
                'cart_item_id' => 42,
            ]);

        $cartItem = new CartItem;
        $cartItem->id = 42;

        (new CartItemDeletedListener($reservationService))
            ->handle(new Deleting($cartItem));
    }

    public function test_bound_or_missing_hold_needs_no_cancellation_log(): void
    {
        $reservationService = Mockery::mock(ReservationService::class);
        $reservationService->shouldReceive('cancelForCartItem')
            ->once()
            ->with(42)
            ->andReturn(false);

        Log::shouldReceive('info')->never();

        $cartItem = new CartItem;
        $cartItem->id = 42;

        (new CartItemDeletedListener($reservationService))
            ->handle(new Deleting($cartItem));
    }
}
