<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\NodeSelectionService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\PricingCalculatorService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;
use PHPUnit\Framework\Assert;

class ReservationServiceTest extends LaravelTestCase
{
    private $mockNodeService;

    private $mockPricingService;

    private $mockAuditService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockNodeService = \Mockery::mock(NodeSelectionService::class);
        $this->mockPricingService = \Mockery::mock(PricingCalculatorService::class);
        $this->mockAuditService = \Mockery::mock(AuditLogService::class);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Create ReservationService with mocked dependencies.
     * Uses reflection to bypass constructor ExtensionHelper call.
     */
    private function createService(): ReservationService
    {
        $service = new class($this->mockNodeService, $this->mockPricingService, $this->mockAuditService) extends ReservationService
        {
            private int $testTtl = 15;

            public function __construct($nodeService, $pricingService, $auditService)
            {
                // Skip parent constructor to avoid ExtensionHelper call
                $this->nodeService = $nodeService;
                $this->pricingService = $pricingService;
                $this->auditService = $auditService;
                $this->ttlMinutes = $this->testTtl;
            }

            // Access to protected properties
            private NodeSelectionService $nodeService;

            private PricingCalculatorService $pricingService;

            private AuditLogService $auditService;

            private int $ttlMinutes;
        };

        return $service;
    }

    /**
     * Test that confirm updates status to confirmed.
     */
    public function test_confirm_updates_status(): void
    {
        $token = 'test_token_123';
        $serviceId = 42;

        DB::shouldReceive('table')
            ->with('ptero_resource_reservations')
            ->andReturnSelf();
        DB::shouldReceive('where')
            ->with('token', $token)
            ->andReturnSelf();
        DB::shouldReceive('where')
            ->with('status', 'pending')
            ->andReturnSelf();
        DB::shouldReceive('where')
            ->with('expires_at', '>', \Mockery::any())
            ->andReturnSelf();
        DB::shouldReceive('update')
            ->with(\Mockery::on(function ($data) use ($serviceId) {
                return $data['status'] === 'confirmed'
                    && $data['service_id'] === $serviceId
                    && isset($data['updated_at']);
            }))
            ->andReturn(1);

        $service = $this->createService();
        $result = $service->confirm($token, $serviceId);

        Assert::assertTrue($result);
    }

    /**
     * Test that confirm returns false for non-existent token.
     */
    public function test_confirm_returns_false_for_nonexistent_token(): void
    {
        DB::shouldReceive('table')
            ->with('ptero_resource_reservations')
            ->andReturnSelf();
        DB::shouldReceive('where')->times(3)->andReturnSelf();
        DB::shouldReceive('update')->andReturn(0);

        $service = $this->createService();
        $result = $service->confirm('nonexistent', 1);

        Assert::assertFalse($result);
    }

    /**
     * Test that expired reservations cannot be confirmed.
     */
    public function test_expired_reservation_cannot_be_confirmed(): void
    {
        $token = 'expired_token_123';

        DB::shouldReceive('table')
            ->with('ptero_resource_reservations')
            ->andReturnSelf();
        DB::shouldReceive('where')
            ->with('token', $token)
            ->andReturnSelf();
        DB::shouldReceive('where')
            ->with('status', 'pending')
            ->andReturnSelf();
        DB::shouldReceive('where')
            ->with('expires_at', '>', \Mockery::any())
            ->andReturnSelf();
        DB::shouldReceive('update')->andReturn(0);

        $service = $this->createService();
        $result = $service->confirm($token, 1);

        Assert::assertFalse($result);
    }

    /**
     * Test that cancel updates status to cancelled.
     */
    public function test_cancel_updates_status(): void
    {
        $token = 'test_token_123';

        $mockReservation = (object) [
            'id' => 1,
            'token' => $token,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'status' => 'pending',
        ];

        // First call for getByToken
        DB::shouldReceive('table')
            ->with('ptero_resource_reservations')
            ->andReturnSelf();
        DB::shouldReceive('where')
            ->with('token', $token)
            ->andReturnSelf();
        DB::shouldReceive('first')
            ->once()
            ->andReturn($mockReservation);

        // Second call for update
        DB::shouldReceive('where')
            ->with('status', 'pending')
            ->andReturnSelf();
        DB::shouldReceive('update')
            ->with(\Mockery::on(function ($data) {
                return $data['status'] === 'cancelled'
                    && isset($data['updated_at']);
            }))
            ->andReturn(1);

        $service = $this->createService();
        $result = $service->cancel($token);

        Assert::assertTrue($result);
    }

    /**
     * Test that cancel returns false for non-existent token.
     */
    public function test_cancel_returns_false_for_nonexistent_token(): void
    {
        DB::shouldReceive('table')
            ->with('ptero_resource_reservations')
            ->andReturnSelf();
        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('first')->andReturn(null);

        $service = $this->createService();
        $result = $service->cancel('nonexistent');

        Assert::assertFalse($result);
    }

    /**
     * Test that cleanup expired marks pending reservations as expired.
     */
    public function test_cleanup_expired_marks_pending_as_expired(): void
    {
        DB::shouldReceive('table')
            ->with('ptero_resource_reservations')
            ->andReturnSelf();
        DB::shouldReceive('where')
            ->with('status', 'pending')
            ->andReturnSelf();
        DB::shouldReceive('where')
            ->with('expires_at', '<', \Mockery::any())
            ->andReturnSelf();
        DB::shouldReceive('update')
            ->with(\Mockery::on(function ($data) {
                return $data['status'] === 'expired'
                    && isset($data['updated_at']);
            }))
            ->andReturn(5);

        $service = $this->createService();
        $result = $service->cleanupExpired();

        Assert::assertEquals(5, $result);
    }

    /**
     * Test getByToken returns reservation.
     */
    public function test_get_by_token_returns_reservation(): void
    {
        $token = 'test_token_123';
        $expected = (object) [
            'id' => 1,
            'token' => $token,
            'status' => 'pending',
        ];

        DB::shouldReceive('table')
            ->with('ptero_resource_reservations')
            ->andReturnSelf();
        DB::shouldReceive('where')
            ->with('token', $token)
            ->andReturnSelf();
        DB::shouldReceive('first')
            ->andReturn($expected);

        $service = $this->createService();
        $result = $service->getByToken($token);

        Assert::assertEquals($expected, $result);
    }

    /**
     * Test getByCartItem returns pending reservation.
     */
    public function test_get_by_cart_item_returns_pending_reservation(): void
    {
        $cartItemId = 42;
        $expected = (object) [
            'id' => 1,
            'cart_item_id' => $cartItemId,
            'status' => 'pending',
        ];

        DB::shouldReceive('table')
            ->with('ptero_resource_reservations')
            ->andReturnSelf();
        DB::shouldReceive('where')
            ->with('cart_item_id', $cartItemId)
            ->andReturnSelf();
        DB::shouldReceive('where')
            ->with('status', 'pending')
            ->andReturnSelf();
        DB::shouldReceive('first')
            ->andReturn($expected);

        $service = $this->createService();
        $result = $service->getByCartItem($cartItemId);

        Assert::assertEquals($expected, $result);
    }
}
