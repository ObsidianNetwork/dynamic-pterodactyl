<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Events\CartItem\Deleted;
use App\Models\CartItem;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\DynamicPterodactyl\Listeners\CartItemDeletedListener;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class CartItemDeletedListenerTest extends LaravelTestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }

    public function test_no_token_returns_early_without_cancelling(): void
    {
        $reservationService = \Mockery::mock(ReservationService::class);
        $reservationService->shouldNotReceive('cancel');
        $this->app->instance(ReservationService::class, $reservationService);

        $listener = new CartItemDeletedListener;
        $listener->handle(new Deleted($this->makeCartItem()));

        $this->addToAssertionCount(1);
    }

    public function test_skip_checkout_path_does_not_cancel_and_logs_debug(): void
    {
        $token = 'checkout_token_123';
        $propertyQuery = \Mockery::mock();
        $propertyQuery->shouldReceive('where')->once()->with('value', $token)->andReturnSelf();

        $serviceQuery = \Mockery::mock();
        $serviceQuery->shouldReceive('exists')->once()->andReturn(true);

        $serviceModel = \Mockery::mock('alias:App\\Models\\Service');
        $serviceModel->shouldReceive('whereHas')
            ->once()
            ->with('properties', \Mockery::on(function ($closure) use ($propertyQuery) {
                $propertyQuery->shouldReceive('where')->once()->with('key', '_reservation_token')->andReturn($propertyQuery);
                $closure($propertyQuery);

                return true;
            }))
            ->andReturn($serviceQuery);

        $reservationService = \Mockery::mock(ReservationService::class);
        $reservationService->shouldNotReceive('cancel');
        $this->app->instance(ReservationService::class, $reservationService);

        Log::shouldReceive('debug')
            ->once()
            ->with('Skipping reservation cancel: cart item consumed by checkout', \Mockery::on(function ($context) use ($token) {
                return $context['cart_item_id'] === 1
                    && $context['reservation_token'] === substr($token, 0, 8) . '...';
            }));

        $listener = new CartItemDeletedListener;
        $listener->handle(new Deleted($this->makeCartItem($token)));

        $this->addToAssertionCount(1);
    }

    public function test_abandonment_path_cancels_reservation(): void
    {
        $token = 'abandon_token_123';

        $serviceQuery = \Mockery::mock();
        $serviceQuery->shouldReceive('exists')->once()->andReturn(false);

        $serviceModel = \Mockery::mock('alias:App\\Models\\Service');
        $serviceModel->shouldReceive('whereHas')->once()->andReturn($serviceQuery);

        $reservationService = \Mockery::mock(ReservationService::class);
        $reservationService->shouldReceive('cancel')->once()->with($token, null, 'cart_deleted');
        $this->app->instance(ReservationService::class, $reservationService);

        Log::shouldReceive('info')
            ->once()
            ->with('Cancelled reservation for deleted cart item', \Mockery::on(function ($context) use ($token) {
                return $context['cart_item_id'] === 1
                    && $context['reservation_token'] === substr($token, 0, 8) . '...';
            }));

        $listener = new CartItemDeletedListener;
        $listener->handle(new Deleted($this->makeCartItem($token)));

        $this->addToAssertionCount(1);
    }

    public function test_exception_path_logs_error_without_rethrowing(): void
    {
        $token = 'exception_token_123';

        $serviceQuery = \Mockery::mock();
        $serviceQuery->shouldReceive('exists')->once()->andReturn(false);

        $serviceModel = \Mockery::mock('alias:App\\Models\\Service');
        $serviceModel->shouldReceive('whereHas')->once()->andReturn($serviceQuery);

        $reservationService = \Mockery::mock(ReservationService::class);
        $reservationService->shouldReceive('cancel')
            ->once()
            ->with($token, null, 'cart_deleted')
            ->andThrow(new \RuntimeException('boom'));
        $this->app->instance(ReservationService::class, $reservationService);

        Log::shouldReceive('error')
            ->once()
            ->with('Failed to cancel reservation', \Mockery::on(function ($context) use ($token) {
                return $context['token'] === substr($token, 0, 8) . '...'
                    && $context['error'] === 'boom';
            }));

        $listener = new CartItemDeletedListener;
        $listener->handle(new Deleted($this->makeCartItem($token)));

        $this->addToAssertionCount(1);
    }

    private function makeCartItem(?string $token = null): CartItem
    {
        $cartItem = new CartItem;
        $cartItem->id = 1;
        $cartItem->checkout_config = $token ? ['_reservation_token' => $token] : [];

        return $cartItem;
    }
}
