<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\LegacyReservationReadinessService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationConfigurationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class LegacyReservationReadinessTest extends LaravelTestCase
{
    use DatabaseTransactions;

    public function test_empty_install_is_ready(): void
    {
        (new LegacyReservationReadinessService)->assertReady();

        $this->addToAssertionCount(1);
    }

    public function test_confirmed_legacy_checkout_reports_actionable_identity_fields(): void
    {
        $service = $this->service();
        $reservationId = $this->insertReservation($service, [
            'status' => 'confirmed',
            'configuration_fingerprint' => null,
            'configuration_payload' => null,
        ]);

        try {
            (new LegacyReservationReadinessService)->assertReady();
            $this->fail('Expected legacy confirmed identity to block migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                "reservation #{$reservationId} / service #{$service->id}",
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                'external_server_uuid',
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                'configuration_payload',
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                'never infer them',
                $exception->getMessage()
            );
        }
    }

    public function test_active_upgrade_without_immutable_identity_blocks_migration(): void
    {
        $service = $this->service();
        $reservationId = $this->insertReservation($service, [
            'purpose' => 'upgrade',
            'status' => 'paid_committed',
            'configuration_fingerprint' => str_repeat('a', 64),
            'configuration_payload' => json_encode([], JSON_THROW_ON_ERROR),
        ]);

        $blockers = (new LegacyReservationReadinessService)->blockers();

        $this->assertSame($reservationId, $blockers[0]['reservation_id']);
        $this->assertSame('upgrade', $blockers[0]['purpose']);
        $this->assertContains(
            'configuration_payload.external_server_id',
            $blockers[0]['missing']
        );
    }

    public function test_bound_unpaid_checkout_without_signed_snapshot_blocks_migration(): void
    {
        $service = $this->service();
        $reservationId = $this->insertReservation($service, [
            'status' => 'pending',
            'configuration_fingerprint' => null,
            'configuration_payload' => null,
            'external_server_id' => null,
            'external_user_id' => null,
            'external_server_uuid' => null,
            'external_server_identifier' => null,
        ]);

        $blockers = (new LegacyReservationReadinessService)->blockers();

        $this->assertSame($reservationId, $blockers[0]['reservation_id']);
        $this->assertContains(
            'configuration_payload',
            $blockers[0]['missing']
        );
        $this->assertNotContains(
            'external_server_id',
            $blockers[0]['missing']
        );
    }

    public function test_complete_signed_checkout_identity_passes_gate(): void
    {
        $service = $this->service();
        $panel = hash('sha256', 'https://panel.example.com');
        $payload = [
            'customer_id' => $service->user_id,
            'server_extension_id' => 41,
            'panel_identity' => $panel,
            'product_id' => $service->product_id,
            'plan_id' => $service->plan_id,
            'quantity' => 1,
            'currency_code' => 'USD',
            'resources' => [
                'memory' => 4096,
                'cpu' => 200,
                'disk' => 20480,
            ],
            'location_id' => 3,
            'node_id' => 7,
            'provisioning_identity' => [
                'nest_id' => 1,
                'egg_id' => 2,
                'user_external_id' =>
                    "paymenter-user-{$service->user_id}",
                'user_email' => (string) $service->user->email,
            ],
            'allocations' => [[
                'allocation_id' => 7001,
                'ip' => '192.0.2.10',
                'port' => 25565,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ]],
        ];
        $fingerprint = (new ReservationConfigurationService)
            ->fingerprint($payload);
        $this->insertReservation($service, [
            'status' => 'confirmed',
            'server_extension_id' => 41,
            'panel_identity' => $panel,
            'configuration_fingerprint' => $fingerprint,
            'configuration_payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'external_server_id' => 71,
            'external_user_id' => 44,
            'external_server_uuid' =>
                '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
            'external_server_identifier' => 'server-71',
        ]);

        (new LegacyReservationReadinessService)->assertReady();

        $this->addToAssertionCount(1);
    }

    public function test_bound_unpaid_checkout_requires_materialized_allocation_claims(): void
    {
        $service = $this->service();
        $panel = hash('sha256', 'https://panel.example.com');
        $payload = [
            'customer_id' => $service->user_id,
            'server_extension_id' => 41,
            'panel_identity' => $panel,
            'product_id' => $service->product_id,
            'plan_id' => $service->plan_id,
            'quantity' => 1,
            'currency_code' => 'USD',
            'resources' => [
                'memory' => 4096,
                'cpu' => 200,
                'disk' => 20480,
            ],
            'location_id' => 3,
            'node_id' => 7,
            'provisioning_identity' => [
                'nest_id' => 1,
                'egg_id' => 2,
                'user_external_id' =>
                    "paymenter-user-{$service->user_id}",
                'user_email' => (string) $service->user->email,
            ],
            'allocations' => [[
                'allocation_id' => 7001,
                'ip' => '192.0.2.10',
                'port' => 25565,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ]],
        ];
        $fingerprint = (new ReservationConfigurationService)
            ->fingerprint($payload);
        $reservationId = $this->insertReservation($service, [
            'status' => 'pending',
            'server_extension_id' => 41,
            'panel_identity' => $panel,
            'configuration_fingerprint' => $fingerprint,
            'configuration_payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'consumed_at' => null,
        ]);

        $blockers = (new LegacyReservationReadinessService)->blockers();
        $blocker = collect($blockers)->firstWhere(
            'reservation_id',
            $reservationId
        );

        $this->assertNotNull($blocker);
        $this->assertContains(
            'active signed allocation claims',
            $blocker['missing']
        );

        DB::table('ptero_reservation_allocations')->insert([
            'reservation_id' => $reservationId,
            'panel_identity' => $panel,
            'node_id' => 7,
            'allocation_id' => 7001,
            'ip' => '192.0.2.10',
            'port' => 25565,
            'environment_key' => 'SERVER_PORT',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new LegacyReservationReadinessService)->assertReady();
        $this->addToAssertionCount(1);
    }

    private function service(): Service
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $plan = Plan::factory()->create([
            'priceable_id' => $product->id,
            'priceable_type' => Product::class,
        ]);

        return Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'quantity' => 1,
            'currency_code' => 'USD',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertReservation(
        Service $service,
        array $overrides
    ): int {
        return DB::table('ptero_resource_reservations')->insertGetId(
            array_merge([
                'token' => bin2hex(random_bytes(32)),
                'purpose' => 'checkout',
                'server_extension_id' => 41,
                'panel_identity' =>
                    hash('sha256', 'https://panel.example.com'),
                'service_id' => $service->id,
                'service_guard_id' => $service->id,
                'user_id' => $service->user_id,
                'product_id' => $service->product_id,
                'plan_id' => $service->plan_id,
                'quantity' => 1,
                'currency_code' => 'USD',
                'node_id' => 7,
                'location_id' => 3,
                'memory' => 4096,
                'cpu' => 200,
                'disk' => 20480,
                'reserved_memory' => 0,
                'reserved_cpu' => 0,
                'reserved_disk' => 0,
                'calculated_price' => '10.00',
                'pricing_breakdown' => json_encode(
                    [],
                    JSON_THROW_ON_ERROR
                ),
                'status' => 'confirmed',
                'expires_at' => now()->subDay(),
                'guaranteed_until' => now()->subDay(),
                'consumed_at' => now()->subDay(),
                'external_server_id' => null,
                'external_user_id' => null,
                'external_server_uuid' => null,
                'external_server_identifier' => null,
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ], $overrides)
        );
    }
}
