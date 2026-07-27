<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Events\CartItem\Created;
use App\Events\CartItem\Updated;
use App\Models\CartItem;
use Illuminate\Support\Facades\Log;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Listeners\CartItemCreatedListener;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationConfigurationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class CartItemCreatedListenerTest extends LaravelTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_created_dynamic_cart_item_receives_an_authoritative_hold(): void
    {
        $cartItem = $this->cartItem();
        $listener = $this->listenerExpectingReservation($cartItem);

        $listener->handle(new Created($cartItem));

        $this->addToAssertionCount(1);
    }

    public function test_updated_dynamic_cart_item_replaces_or_refreshes_its_hold(): void
    {
        $cartItem = $this->cartItem();
        $listener = $this->listenerExpectingReservation($cartItem);

        $listener->handle(new Updated($cartItem));

        $this->addToAssertionCount(1);
    }

    public function test_product_without_dynamic_resources_does_not_reserve_or_log(): void
    {
        $cartItem = $this->cartItem();
        $reservations = Mockery::mock(ReservationService::class);
        $reservations->shouldReceive('reserveForCartItem')->never();
        $configuration = Mockery::mock(
            ReservationConfigurationService::class
        );
        $configuration->shouldReceive('requiresReservation')
            ->once()
            ->with(91)
            ->andReturn(false);
        Log::shouldReceive('info')->never();

        (new CartItemCreatedListener($reservations, $configuration))
            ->handle(new Created($cartItem));

        $this->addToAssertionCount(1);
    }

    private function listenerExpectingReservation(
        CartItem $cartItem
    ): CartItemCreatedListener {
        $configuration = Mockery::mock(
            ReservationConfigurationService::class
        );
        $configuration->shouldReceive('requiresReservation')
            ->once()
            ->with(91)
            ->andReturn(true);
        $reservations = Mockery::mock(ReservationService::class);
        $reservations->shouldReceive('reserveForCartItem')
            ->once()
            ->with($cartItem)
            ->andReturn([
                'id' => 73,
                'node_id' => 7,
                'expires_at' => '2026-07-27T04:30:00+00:00',
                'status' => 'pending',
            ]);
        Log::shouldReceive('info')
            ->once()
            ->with('Capacity reserved for cart item', [
                'cart_item_id' => 42,
                'reservation_id' => 73,
                'node_id' => 7,
                'expires_at' => '2026-07-27T04:30:00+00:00',
            ]);

        return new CartItemCreatedListener(
            $reservations,
            $configuration
        );
    }

    private function cartItem(): CartItem
    {
        $cartItem = new CartItem;
        $cartItem->id = 42;
        $cartItem->product_id = 91;

        return $cartItem;
    }
}
