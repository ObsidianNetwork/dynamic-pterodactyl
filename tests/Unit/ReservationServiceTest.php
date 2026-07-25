<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\NodeSelectionService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationConfigurationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class ReservationServiceTest extends LaravelTestCase
{
    use DatabaseTransactions;

    private NodeSelectionService $nodeService;

    private ReservationConfigurationService $configurationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nodeService = Mockery::mock(NodeSelectionService::class);
        $this->configurationService = Mockery::mock(ReservationConfigurationService::class)
            ->makePartial();

        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('log')->zeroOrMoreTimes();
        $this->app->instance(AuditLogService::class, $audit);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_guest_holds_transfer_with_cart_ownership(): void
    {
        $user = User::withoutEvents(fn () => User::factory()->create());
        $this->insertReservation(cartId: 71, userId: null);

        $updated = $this->service()->transferCartOwnership(71, $user->id);

        $this->assertSame(1, $updated);
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'cart_id' => 71,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
        $reservation = DB::table('ptero_resource_reservations')->where('cart_id', 71)->first();
        $payload = json_decode($reservation->configuration_payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($user->id, $payload['customer_id']);
        $this->assertSame(
            $this->configurationService->fingerprint($payload),
            $reservation->configuration_fingerprint
        );
    }

    public function test_begin_keeps_hold_pending_until_complete_consumes_it(): void
    {
        $service = $this->makeService();
        $reservationId = $this->insertReservation(serviceId: $service->id, userId: $service->user_id);

        $this->configurationService->shouldReceive('assertServiceMatches')
            ->once()
            ->with($service, Mockery::on(fn ($row) => (int) $row->id === $reservationId));

        $context = $this->service()->beginProvisioning($service);

        $this->assertSame(4, $context['node_id']);
        $this->assertFalse($context['already_consumed']);
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'pending',
        ]);
        $this->assertNotNull(
            DB::table('ptero_resource_reservations')->where('id', $reservationId)->value('provisioning_started_at')
        );

        $this->assertNotNull($context['provisioning_lease_id']);
        $this->assertTrue(
            $this->service()->completeProvisioning(
                $service->id,
                $context['provisioning_lease_id']
            )
        );
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'confirmed',
        ]);
        $this->assertNotNull(
            DB::table('ptero_resource_reservations')->where('id', $reservationId)->value('consumed_at')
        );
    }

    public function test_active_provisioning_lease_rejects_a_second_worker(): void
    {
        $service = $this->makeService();
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            provisioningStartedAt: now()
        );

        $this->configurationService->shouldNotReceive('assertServiceMatches');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already being provisioned');

        $this->service()->beginProvisioning($service);
    }

    public function test_failed_provisioning_releases_only_the_attempt_lease(): void
    {
        $service = $this->makeService();
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            provisioningStartedAt: now(),
            provisioningLeaseId: 'active-lease'
        );

        $this->service()->failProvisioning(
            $service->id,
            'active-lease',
            new \RuntimeException('Pterodactyl unavailable')
        );

        $reservation = DB::table('ptero_resource_reservations')->where('id', $reservationId)->first();
        $this->assertSame('pending', $reservation->status);
        $this->assertNull($reservation->provisioning_started_at);
        $this->assertSame('Pterodactyl unavailable', $reservation->last_provisioning_error);
    }

    public function test_stale_worker_cannot_consume_or_clear_a_newer_lease(): void
    {
        $service = $this->makeService();
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            provisioningStartedAt: now(),
            provisioningLeaseId: 'new-lease'
        );

        try {
            $this->service()->completeProvisioning($service->id, 'old-lease');
            $this->fail('Expected stale provisioning lease rejection.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no longer owns', $exception->getMessage());
        }

        $this->service()->failProvisioning(
            $service->id,
            'old-lease',
            new \RuntimeException('stale worker failed')
        );

        $reservation = DB::table('ptero_resource_reservations')->where('id', $reservationId)->first();
        $this->assertSame('pending', $reservation->status);
        $this->assertSame('new-lease', $reservation->provisioning_lease_id);
        $this->assertNotNull($reservation->provisioning_started_at);
        $this->assertNull($reservation->last_provisioning_error);
    }

    public function test_completed_reservation_is_idempotent_for_server_reconciliation(): void
    {
        $service = $this->makeService();
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'confirmed',
            consumedAt: now()
        );

        $context = $this->service()->beginProvisioning($service);

        $this->assertTrue($context['already_consumed']);
        $this->assertTrue($this->service()->completeProvisioning($service->id));
    }

    private function service(): ReservationService
    {
        $reflection = new \ReflectionClass(ReservationService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'nodeService' => $this->nodeService,
            'configurationService' => $this->configurationService,
            'ttlMinutes' => 15,
        ] as $property => $value) {
            $instanceProperty = $reflection->getProperty($property);
            $instanceProperty->setAccessible(true);
            $instanceProperty->setValue($service, $value);
        }

        return $service;
    }

    private function makeService(): Service
    {
        $user = User::withoutEvents(fn () => User::factory()->create());
        $id = DB::table('services')->insertGetId([
            'user_id' => $user->id,
            'status' => 'pending',
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Service::query()->findOrFail($id);
    }

    private function insertReservation(
        ?int $serviceId = null,
        ?int $cartId = null,
        ?int $userId = null,
        string $status = 'pending',
        mixed $provisioningStartedAt = null,
        ?string $provisioningLeaseId = null,
        mixed $consumedAt = null
    ): int {
        return DB::table('ptero_resource_reservations')->insertGetId([
            'token' => str_repeat((string) random_int(1, 9), 64),
            'cart_item_id' => null,
            'cart_id' => $cartId,
            'server_extension_id' => null,
            'panel_identity' => null,
            'service_id' => $serviceId,
            'user_id' => $userId,
            'product_id' => null,
            'plan_id' => null,
            'quantity' => 1,
            'currency_code' => 'USD',
            'configuration_fingerprint' => str_repeat('a', 64),
            'configuration_payload' => json_encode([
                'customer_id' => $userId,
                'cart_id' => $cartId,
            ]),
            'pricing_version' => str_repeat('b', 64),
            'formula_version' => ReservationConfigurationService::FORMULA_VERSION,
            'node_id' => 4,
            'location_id' => 2,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'calculated_price' => 12.50,
            'pricing_breakdown' => json_encode([]),
            'status' => $status,
            'expires_at' => now()->addDay(),
            'provisioning_started_at' => $provisioningStartedAt,
            'provisioning_lease_id' => $provisioningLeaseId,
            'consumed_at' => $consumedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
