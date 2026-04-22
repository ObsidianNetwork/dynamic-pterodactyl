<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\NodeSelectionService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\PricingCalculatorService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class ReservationServiceTest extends LaravelTestCase
{
    use DatabaseTransactions;

    private $mockNodeService;

    private $mockPricingService;

    private $mockAuditService;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('settings.debug', false);

        $this->mockNodeService = Mockery::mock(NodeSelectionService::class);
        $this->mockPricingService = Mockery::mock(PricingCalculatorService::class);
        $this->mockAuditService = Mockery::mock(AuditLogService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create ReservationService with mocked dependencies.
     * Uses reflection to bypass constructor ExtensionHelper call.
     */
    private function createService(): ReservationService
    {
        $reflection = new \ReflectionClass(ReservationService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'nodeService' => $this->mockNodeService,
            'pricingService' => $this->mockPricingService,
            'auditService' => $this->mockAuditService,
            'ttlMinutes' => 15,
        ] as $property => $value) {
            $instanceProperty = $reflection->getProperty($property);
            $instanceProperty->setAccessible(true);
            $instanceProperty->setValue($service, $value);
        }

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
            ->with('expires_at', '>', Mockery::any())
            ->andReturnSelf();
        DB::shouldReceive('first')->once()->andReturn((object) ['id' => 99, 'token' => $token]);
        DB::shouldReceive('update')
            ->with(Mockery::on(function ($data) use ($serviceId) {
                return $data['status'] === 'confirmed'
                    && $data['service_id'] === $serviceId
                    && isset($data['updated_at']);
            }))
            ->andReturn(1);
        $this->mockAuditService->shouldReceive('log')
            ->once()
            ->with('confirmed', 'reservation', 99, Mockery::type('array'))
            ->andReturn(1);

        $service = $this->createService();
        $result = $service->confirm($token, $serviceId);

        $this->assertTrue($result);
    }

    /**
     * Test that confirm returns false for non-existent token.
     */
    public function test_confirm_returns_false_for_nonexistent_token(): void
    {
        DB::shouldReceive('table')
            ->with('ptero_resource_reservations')
            ->andReturnSelf();
        DB::shouldReceive('where')->times(4)->andReturnSelf();
        DB::shouldReceive('first')->once()->andReturn(null);
        DB::shouldReceive('update')->andReturn(0);

        $service = $this->createService();
        $result = $service->confirm('nonexistent', 1);

        $this->assertFalse($result);
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
            ->with('expires_at', '>', Mockery::any())
            ->andReturnSelf();
        DB::shouldReceive('first')->once()->andReturn((object) ['id' => 5, 'token' => $token]);
        DB::shouldReceive('update')->andReturn(0);

        $service = $this->createService();
        $result = $service->confirm($token, 1);

        $this->assertFalse($result);
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
            ->with(Mockery::on(function ($data) {
                return $data['status'] === 'cancelled'
                    && isset($data['updated_at']);
            }))
            ->andReturn(1);
        $this->mockAuditService->shouldReceive('log')
            ->once()
            ->with('cancelled', 'reservation', 1, Mockery::type('array'))
            ->andReturn(1);

        $service = $this->createService();
        $result = $service->cancel($token);

        $this->assertTrue($result);
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

        $this->assertFalse($result);
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
            ->with('expires_at', '<', Mockery::any())
            ->andReturnSelf();
        DB::shouldReceive('update')
            ->with(Mockery::on(function ($data) {
                return $data['status'] === 'expired'
                    && isset($data['updated_at']);
            }))
            ->andReturn(5);
        $this->mockAuditService->shouldReceive('log')
            ->once()
            ->with('batch_expired', 'reservation', 0, Mockery::on(fn ($ctx) => $ctx['count'] === 5))
            ->andReturn(1);

        $service = $this->createService();
        $result = $service->cleanupExpired();

        $this->assertEquals(5, $result);
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

        $this->assertEquals($expected, $result);
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

        $this->assertEquals($expected, $result);
    }

    public function test_create_logs_audit_entry(): void
    {
        $this->mockNodeService->shouldReceive('selectBestNode')
            ->once()
            ->andReturn(['node_id' => 1, 'name' => 'Node 1']);

        $this->mockPricingService->shouldReceive('calculate')
            ->once()
            ->andReturn(['total' => 9.99, 'breakdown' => []]);

        DB::shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn ($callback) => $callback());

        DB::shouldReceive('table')
            ->with('ptero_resource_reservations')
            ->andReturnSelf();
        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('lockForUpdate')->andReturnSelf();
        DB::shouldReceive('get')->andReturn(collect([]));
        DB::shouldReceive('insertGetId')->andReturn(42);

        $this->mockAuditService->shouldReceive('log')
            ->once()
            ->with('created', 'reservation', 42, Mockery::type('array'))
            ->andReturn(1);

        $service = $this->createService();
        $result = $service->create(1, 1, ['memory' => 4096, 'cpu' => 200, 'disk' => 51200], 10, 5);

        $this->assertEquals(42, $result['id']);
    }

    public function test_confirm_logs_audit_entry_on_success(): void
    {
        DB::shouldReceive('table')->with('ptero_resource_reservations')->andReturnSelf();
        DB::shouldReceive('where')->times(4)->andReturnSelf();
        DB::shouldReceive('first')->once()->andReturn((object) ['id' => 42, 'token' => 'test_token']);
        DB::shouldReceive('update')->andReturn(1);

        $this->mockAuditService->shouldReceive('log')
            ->once()
            ->with('confirmed', 'reservation', 42, Mockery::type('array'))
            ->andReturn(1);

        $service = $this->createService();
        $result = $service->confirm('test_token', 42);

        $this->assertTrue($result);
    }

    public function test_confirm_skips_audit_on_state_drift(): void
    {
        DB::shouldReceive('table')->with('ptero_resource_reservations')->andReturnSelf();
        DB::shouldReceive('where')->times(4)->andReturnSelf();
        DB::shouldReceive('first')->once()->andReturn(null);
        DB::shouldReceive('update')->andReturn(0);

        $this->mockAuditService->shouldReceive('log')->never();

        $service = $this->createService();
        $result = $service->confirm('expired_token', 42);

        $this->assertFalse($result);
    }

    public function test_extend_logs_audit_entry_on_success(): void
    {
        DB::shouldReceive('table')->with('ptero_resource_reservations')->andReturnSelf();
        DB::shouldReceive('where')->times(3)->andReturnSelf();
        DB::shouldReceive('first')->once()->andReturn((object) ['id' => 42, 'token' => 'test_token']);
        DB::shouldReceive('raw')->once()->andReturn('DATE_ADD_SQL');
        DB::shouldReceive('update')->andReturn(1);

        $this->mockAuditService->shouldReceive('log')
            ->once()
            ->with('extended', 'reservation', 42, Mockery::on(fn ($ctx) => $ctx['additional_minutes'] === 15))
            ->andReturn(1);

        $service = $this->createService();
        $result = $service->extend('test_token', 15);

        $this->assertTrue($result);
    }

    public function test_cleanup_expired_logs_batch_count(): void
    {
        DB::shouldReceive('table')->with('ptero_resource_reservations')->andReturnSelf();
        DB::shouldReceive('where')->times(2)->andReturnSelf();
        DB::shouldReceive('update')->andReturn(5);

        $this->mockAuditService->shouldReceive('log')
            ->once()
            ->with('batch_expired', 'reservation', 0, Mockery::on(fn ($ctx) => $ctx['count'] === 5))
            ->andReturn(1);

        $service = $this->createService();
        $result = $service->cleanupExpired();

        $this->assertEquals(5, $result);
    }

    public function test_cancel_audits_with_source_admin(): void
    {
        $token = 'admin_cancel_token';
        $mockReservation = (object) ['id' => 5, 'token' => $token, 'memory' => 4096, 'cpu' => 200, 'disk' => 51200, 'status' => 'pending'];

        DB::shouldReceive('table')->with('ptero_resource_reservations')->andReturnSelf();
        DB::shouldReceive('where')->with('token', $token)->andReturnSelf();
        DB::shouldReceive('first')->once()->andReturn($mockReservation);
        DB::shouldReceive('where')->with('status', 'pending')->andReturnSelf();
        DB::shouldReceive('update')->andReturn(1);

        $this->mockAuditService->shouldReceive('log')
            ->once()
            ->with('cancelled', 'reservation', 5, Mockery::on(fn ($ctx) => $ctx['source'] === 'admin'))
            ->andReturn(1);

        $service = $this->createService();
        $service->cancel($token, 'admin override', 'admin');

        $this->addToAssertionCount(1);
    }

    public function test_cancel_audits_with_source_customer(): void
    {
        $token = 'system_cancel_token';
        $mockReservation = (object) ['id' => 6, 'token' => $token, 'memory' => 4096, 'cpu' => 200, 'disk' => 51200, 'status' => 'pending'];

        DB::shouldReceive('table')->with('ptero_resource_reservations')->andReturnSelf();
        DB::shouldReceive('where')->with('token', $token)->andReturnSelf();
        DB::shouldReceive('first')->once()->andReturn($mockReservation);
        DB::shouldReceive('where')->with('status', 'pending')->andReturnSelf();
        DB::shouldReceive('update')->andReturn(1);

        $this->mockAuditService->shouldReceive('log')
            ->once()
            ->with('cancelled', 'reservation', 6, Mockery::on(fn ($ctx) => $ctx['source'] === 'customer'))
            ->andReturn(1);

        $service = $this->createService();
        $service->cancel($token, null, 'customer');

        $this->addToAssertionCount(1);
    }
    public function test_audit_failure_does_not_break_confirm(): void
    {
        $token = 'audit_fail_token';
        $mockReservation = (object) ['id' => 77, 'token' => $token];

        DB::shouldReceive('table')->with('ptero_resource_reservations')->andReturnSelf();
        DB::shouldReceive('where')->andReturnSelf();
        DB::shouldReceive('first')->once()->andReturn($mockReservation);
        DB::shouldReceive('update')->once()->andReturn(1);

        $this->mockAuditService->shouldReceive('log')->once()
            ->andThrow(new \RuntimeException('audit db down'));

        $service = $this->createService();

        $this->assertTrue($service->confirm($token, 42));
    }

    public function test_create_with_idempotency_key_returns_existing_on_duplicate(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $service = $this->createService();

        $this->mockNodeService->shouldReceive('selectBestNode')
            ->once()
            ->andReturn(['node_id' => 1, 'name' => 'Node 1']);
        $this->mockPricingService->shouldReceive('calculate')
            ->once()
            ->andReturn(['total' => 9.99, 'breakdown' => [], 'model' => 'linear']);
        $this->mockAuditService->shouldReceive('log')
            ->once()
            ->with('created', 'reservation', Mockery::type('int'), Mockery::type('array'));

        $first = $service->create(1, 1, $this->standardResources(), null, $user->id, 'dup-key-123');
        $second = $service->create(1, 1, $this->standardResources(), null, $user->id, 'dup-key-123');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['token'], $second['token']);
        $this->assertSame(1, ResourceReservation::count());
    }

    public function test_create_with_idempotency_key_creates_new_after_original_cancelled(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $service = $this->createService();

        $this->mockNodeService->shouldReceive('selectBestNode')
            ->twice()
            ->andReturn(['node_id' => 1, 'name' => 'Node 1']);
        $this->mockPricingService->shouldReceive('calculate')
            ->twice()
            ->andReturn(['total' => 9.99, 'breakdown' => [], 'model' => 'linear']);
        $this->mockAuditService->shouldReceive('log')
            ->twice()
            ->with('created', 'reservation', Mockery::type('int'), Mockery::type('array'));

        $first = $service->create(1, 1, $this->standardResources(), null, $user->id, 'cancelled-key-123');
        ResourceReservation::query()->findOrFail($first['id'])->update(['status' => 'cancelled']);

        $second = $service->create(1, 1, $this->standardResources(), null, $user->id, 'cancelled-key-123');

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(2, ResourceReservation::count());
    }

    public function test_create_without_idempotency_key_always_creates_new(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $service = $this->createService();

        $this->mockNodeService->shouldReceive('selectBestNode')
            ->twice()
            ->andReturn(['node_id' => 1, 'name' => 'Node 1']);
        $this->mockPricingService->shouldReceive('calculate')
            ->twice()
            ->andReturn(['total' => 9.99, 'breakdown' => [], 'model' => 'linear']);
        $this->mockAuditService->shouldReceive('log')
            ->twice()
            ->with('created', 'reservation', Mockery::type('int'), Mockery::type('array'));

        $first = $service->create(1, 1, $this->standardResources(), null, $user->id);
        $second = $service->create(1, 1, $this->standardResources(), null, $user->id);

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(2, ResourceReservation::count());
    }

}
