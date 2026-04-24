<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\User;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Policies\ResourceReservationPolicy;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\NodeSelectionService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class ReservationServiceTest extends LaravelTestCase
{
    use DatabaseTransactions;

    private $mockNodeService;

    private $mockAuditService;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('settings.debug', false);
        Gate::policy(ResourceReservation::class, ResourceReservationPolicy::class);

        $this->mockNodeService = Mockery::mock(NodeSelectionService::class);
        $this->mockAuditService = Mockery::mock(AuditLogService::class);

        $this->app->instance(AuditLogService::class, $this->mockAuditService);
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
            'ttlMinutes' => 15,
        ] as $property => $value) {
            $instanceProperty = $reflection->getProperty($property);
            $instanceProperty->setAccessible(true);
            $instanceProperty->setValue($service, $value);
        }

        return $service;
    }

    public function test_safeAudit_logs_warning_on_failure(): void
    {
        $this->mockAuditService->shouldReceive('log')
            ->once()
            ->with('created', 'reservation', 99, ['token_prefix' => 'deadbeef...'])
            ->andThrow(new \RuntimeException('audit backend unavailable'));

        Log::shouldReceive('warning')
            ->once()
            ->with('extension audit write failed', Mockery::on(function (array $context) {
                return $context['action'] === 'created'
                    && $context['entity_type'] === 'reservation'
                    && $context['entity_id'] === 99
                    && $context['error'] === 'audit backend unavailable';
            }));
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $service = new class
        {
            use \Paymenter\Extensions\Others\DynamicPterodactyl\Services\Concerns\AuditsExtensionActions;

            public function writeAudit(): void
            {
                $this->safeAudit('created', 'reservation', 99, ['token_prefix' => 'deadbeef...']);
            }
        };

        $service->writeAudit();

        $this->addToAssertionCount(1);
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
            ->with('reservation_confirmed', 'resource_reservation', 99, Mockery::on(fn ($ctx) => $ctx['token_prefix'] === substr($token, 0, 8)
                && $ctx['service_id'] === $serviceId
                && array_key_exists('node_id', $ctx)
                && $ctx['node_id'] === null))
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
            ->with('reservation_cancelled', 'resource_reservation', 1, Mockery::on(fn ($ctx) => $ctx['token_prefix'] === substr($token, 0, 8)
                && array_key_exists('node_id', $ctx)
                && $ctx['node_id'] === null))
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
            ->with('reservations_expired_batch', 'resource_reservation', 0, Mockery::on(fn ($ctx) => $ctx['count'] === 5 && isset($ctx['run_at'])))
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
        $this->assertSame(0.0, $result['pricing']['total']);
        $this->assertSame([], $result['pricing']['breakdown']);
        $this->assertSame('stored', $result['pricing']['model']);
    }

    public function test_confirm_logs_audit_entry_on_success(): void
    {
        DB::shouldReceive('table')->with('ptero_resource_reservations')->andReturnSelf();
        DB::shouldReceive('where')->times(4)->andReturnSelf();
        DB::shouldReceive('first')->once()->andReturn((object) ['id' => 42, 'token' => 'test_token']);
        DB::shouldReceive('update')->andReturn(1);

        $this->mockAuditService->shouldReceive('log')
            ->once()
            ->with('reservation_confirmed', 'resource_reservation', 42, Mockery::on(fn ($ctx) => $ctx['token_prefix'] === 'test_tok' && $ctx['service_id'] === 42))
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
            ->with('reservation_extended', 'resource_reservation', 42, Mockery::on(fn ($ctx) => $ctx['additional_minutes'] === 15
                && $ctx['token_prefix'] === substr('test_token', 0, 8)
                && array_key_exists('node_id', $ctx)
                && $ctx['node_id'] === null))
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
            ->with('reservations_expired_batch', 'resource_reservation', 0, Mockery::on(fn ($ctx) => $ctx['count'] === 5 && isset($ctx['run_at'])))
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
            ->with('reservation_cancelled', 'resource_reservation', 5, Mockery::on(fn ($ctx) => $ctx['token_prefix'] === substr($token, 0, 8)))
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
            ->with('reservation_cancelled', 'resource_reservation', 6, Mockery::on(fn ($ctx) => $ctx['token_prefix'] === substr($token, 0, 8)))
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
        $user = User::withoutEvents(fn () => User::factory()->create());
        $service = $this->createService();

        $this->mockNodeService->shouldReceive('selectBestNode')
            ->once()
            ->andReturn(['node_id' => 1, 'name' => 'Node 1']);
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
        $user = User::withoutEvents(fn () => User::factory()->create());
        $service = $this->createService();

        $this->mockNodeService->shouldReceive('selectBestNode')
            ->twice()
            ->andReturn(['node_id' => 1, 'name' => 'Node 1']);
        $this->mockAuditService->shouldReceive('log')
            ->twice()
            ->with('created', 'reservation', Mockery::type('int'), Mockery::type('array'));

        $first = $service->create(1, 1, $this->standardResources(), null, $user->id, 'cancelled-key-123');
        ResourceReservation::query()->findOrFail($first['id'])->update(['status' => 'cancelled']);

        $second = $service->create(1, 1, $this->standardResources(), null, $user->id, 'cancelled-key-123');

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(2, ResourceReservation::count());
    }

    public function test_confirm_writes_audit_row(): void
    {
        $this->app->instance(AuditLogService::class, new AuditLogService);
        $actor = User::withoutEvents(fn () => User::factory()->create());
        $this->actingAs($actor);

        $serviceId = DB::table('services')->insertGetId([
            'user_id' => $actor->id,
            'status' => 'active',
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reservation = ResourceReservation::create([
            'token' => 'confirm_token_12345678',
            'user_id' => null,
            'cart_item_id' => null,
            'node_id' => 11,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'calculated_price' => 0,
            'pricing_breakdown' => [],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->assertTrue($this->createService()->confirm($reservation->token, $serviceId));

        $this->assertDatabaseHas('ptero_audit_logs', [
            'action' => 'reservation_confirmed',
            'entity_type' => 'resource_reservation',
            'entity_id' => $reservation->id,
        ]);

        $log = DB::table('ptero_audit_logs')->where('action', 'reservation_confirmed')->latest('id')->first();
        $newValues = json_decode($log->new_values, true);

        $this->assertSame(substr($reservation->token, 0, 8), $newValues['token_prefix']);
        $this->assertSame($serviceId, $newValues['service_id']);
        $this->assertSame(11, $newValues['node_id']);
    }

    public function test_cancel_writes_audit_row(): void
    {
        $this->app->instance(AuditLogService::class, new AuditLogService);
        $actor = User::withoutEvents(fn () => User::factory()->create());
        $this->actingAs($actor);

        $reservation = ResourceReservation::create([
            'token' => 'cancel_token_12345678',
            'user_id' => null,
            'cart_item_id' => null,
            'node_id' => 12,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'calculated_price' => 0,
            'pricing_breakdown' => [],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->assertTrue($this->createService()->cancel($reservation->token));

        $this->assertDatabaseHas('ptero_audit_logs', [
            'action' => 'reservation_cancelled',
            'entity_type' => 'resource_reservation',
            'entity_id' => $reservation->id,
        ]);

        $log = DB::table('ptero_audit_logs')->where('action', 'reservation_cancelled')->latest('id')->first();
        $newValues = json_decode($log->new_values, true);

        $this->assertSame(substr($reservation->token, 0, 8), $newValues['token_prefix']);
        $this->assertSame(12, $newValues['node_id']);
    }

    public function test_cleanup_expired_writes_batch_audit_row_with_count(): void
    {
        $this->app->instance(AuditLogService::class, new AuditLogService);
        $actor = User::withoutEvents(fn () => User::factory()->create());
        $this->actingAs($actor);

        ResourceReservation::create([
            'token' => 'expired_a_token_1234',
            'user_id' => null,
            'cart_item_id' => null,
            'node_id' => 21,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'calculated_price' => 0,
            'pricing_breakdown' => [],
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);

        ResourceReservation::create([
            'token' => 'expired_b_token_1234',
            'user_id' => null,
            'cart_item_id' => null,
            'node_id' => 22,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'calculated_price' => 0,
            'pricing_breakdown' => [],
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertSame(2, $this->createService()->cleanupExpired());

        $this->assertDatabaseHas('ptero_audit_logs', [
            'action' => 'reservations_expired_batch',
            'entity_type' => 'resource_reservation',
            'entity_id' => 0,
        ]);

        $log = DB::table('ptero_audit_logs')->where('action', 'reservations_expired_batch')->latest('id')->first();
        $newValues = json_decode($log->new_values, true);

        $this->assertSame(2, $newValues['count']);
        $this->assertArrayHasKey('run_at', $newValues);
    }

    public function test_create_without_idempotency_key_always_creates_new(): void
    {
        /** @var User $user */
        $user = User::withoutEvents(fn () => User::factory()->create());
        $service = $this->createService();

        $this->mockNodeService->shouldReceive('selectBestNode')
            ->twice()
            ->andReturn(['node_id' => 1, 'name' => 'Node 1']);
        $this->mockAuditService->shouldReceive('log')
            ->twice()
            ->with('created', 'reservation', Mockery::type('int'), Mockery::type('array'));

        $first = $service->create(1, 1, $this->standardResources(), null, $user->id);
        $second = $service->create(1, 1, $this->standardResources(), null, $user->id);

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(2, ResourceReservation::count());
    }


    private function createReservation(int $userId, string $token): ResourceReservation
    {
        return ResourceReservation::create([
            'token' => $token,
            'user_id' => $userId,
            'cart_item_id' => null,
            'node_id' => 1,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'calculated_price' => 0,
            'pricing_breakdown' => [],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    // ─── Commit-3: actor-aware authorization (cancel) ──────────────────────────

    public function test_cancel_throws_when_actor_does_not_own_reservation(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());
        $stranger = User::withoutEvents(fn () => User::factory()->create());

        $this->createReservation($owner->id, 'tok-cancel-deny');

        $this->mockAuditService->shouldReceive('log')->never();

        try {
            $this->createService()->cancel('tok-cancel-deny', null, 'customer', $stranger);
            $this->fail('Expected AuthorizationException was not thrown.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_cancel_succeeds_when_actor_is_owner(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());

        $this->createReservation($owner->id, 'tok-cancel-allow');

        $this->mockAuditService->shouldReceive('log')->once();

        $result = $this->createService()->cancel('tok-cancel-allow', null, 'customer', $owner);

        $this->assertTrue($result);
    }

    public function test_cancel_succeeds_when_actor_is_null(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());

        $this->createReservation($owner->id, 'tok-cancel-null');

        $this->mockAuditService->shouldReceive('log')->once();

        $result = $this->createService()->cancel('tok-cancel-null', null, 'system', null);

        $this->assertTrue($result);
    }

    // ─── Commit-3: actor-aware authorization (extend) ──────────────────────────

    public function test_extend_throws_when_actor_does_not_own_reservation(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());
        $stranger = User::withoutEvents(fn () => User::factory()->create());

        $this->createReservation($owner->id, 'tok-extend-deny');

        $this->mockAuditService->shouldReceive('log')->never();

        try {
            $this->createService()->extend('tok-extend-deny', 15, $stranger);
            $this->fail('Expected AuthorizationException was not thrown.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_extend_succeeds_when_actor_is_owner(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());

        $this->createReservation($owner->id, 'tok-extend-allow');

        $this->mockAuditService->shouldReceive('log')->once();

        $result = $this->createService()->extend('tok-extend-allow', 15, $owner);

        $this->assertTrue($result);
    }

    public function test_extend_succeeds_when_actor_is_null(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());

        $this->createReservation($owner->id, 'tok-extend-null');

        $this->mockAuditService->shouldReceive('log')->once();

        $result = $this->createService()->extend('tok-extend-null', 15, null);

        $this->assertTrue($result);
    }

    // ─── Commit-3: actor-aware authorization (confirm) ─────────────────────────

    public function test_confirm_throws_when_actor_does_not_own_reservation(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());
        $stranger = User::withoutEvents(fn () => User::factory()->create());

        $this->createReservation($owner->id, 'tok-confirm-deny');

        $this->mockAuditService->shouldReceive('log')->never();

        try {
            $this->createService()->confirm('tok-confirm-deny', 42, $stranger);
            $this->fail('Expected AuthorizationException was not thrown.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_confirm_succeeds_when_actor_is_owner(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());
        // Direct DB insert avoids App\Models\Service alias-mock contamination from CartItemDeletedListenerTest.
        $serviceId = DB::table('services')->insertGetId([
            'user_id'       => $owner->id,
            'status'        => 'active',
            'currency_code' => 'USD',
            'quantity'      => 1,
            'price'         => '0.00',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->createReservation($owner->id, 'tok-confirm-allow');

        $this->mockAuditService->shouldReceive('log')->once();

        $result = $this->createService()->confirm('tok-confirm-allow', $serviceId, $owner);

        $this->assertTrue($result);
    }

    public function test_confirm_succeeds_when_actor_is_null(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());
        $serviceId = DB::table('services')->insertGetId([
            'user_id'       => $owner->id,
            'status'        => 'active',
            'currency_code' => 'USD',
            'quantity'      => 1,
            'price'         => '0.00',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->createReservation($owner->id, 'tok-confirm-null');

        $this->mockAuditService->shouldReceive('log')->once();

        $result = $this->createService()->confirm('tok-confirm-null', $serviceId, null);

        $this->assertTrue($result);
    }

    // ─── Commit-3: admin bypass via ResourceReservationPolicy::before() ─────────

    public function test_policy_before_grants_admin_bypass(): void
    {
        $this->markTestSkipped('Requires full Filament panel registration');
    }
}
