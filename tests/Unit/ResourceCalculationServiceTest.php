<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\NodeCapacityPolicy;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\PterodactylInventoryService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationConfigurationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\UpgradeReservationIntegrityService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class ResourceCalculationServiceTest extends LaravelTestCase
{
    use DatabaseTransactions;

    private const PANEL_IDENTITY = '7c6e2c72d2dd80adbf79bdce3fd8931102831742545776c0df049b9e2daef06f';

    public function test_cpu_policy_mutation_uses_scope_lock_and_rejects_live_commitment(): void
    {
        $policy = $this->createCpuPolicy();
        $this->assertDatabaseHas('ptero_capacity_scopes', [
            'panel_identity' => self::PANEL_IDENTITY,
            'location_id' => 1,
        ]);
        $this->insertReservation('cpu-policy-hold', 'pending', [
            'memory' => 1024,
            'cpu' => 100,
            'disk' => 10240,
        ]);

        try {
            $policy->update(['cpu_capacity_percent' => 1600]);
            $this->fail('A live capacity hold must serialize and block policy mutation.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'live capacity commitment',
                $exception->getMessage()
            );
        }

        $this->assertSame(800, $policy->fresh()->cpu_capacity_percent);
    }

    public function test_node_move_fails_closed_until_cpu_policy_identity_is_resynced(): void
    {
        $this->createCpuPolicy();

        $node = $this->service(
            node: $this->node(['location_id' => 2])
        )->getNodeAvailability(5);

        $this->assertNotNull($node);
        $this->assertFalse($node['eligible']);
        $this->assertSame(0, $node['total']['cpu']);
        $this->assertContains(
            'cpu_policy_identity_mismatch',
            $node['ineligible_reasons']
        );
    }

    public function test_calculation_uses_conservative_maximum_across_node_and_server_snapshots(): void
    {
        $this->createCpuPolicy(capacity: 800, overcommit: 15000);
        $service = $this->service(
            node: $this->node([
                'memory' => 1001,
                'disk' => 2001,
                'memory_overallocate' => 10,
                'disk_overallocate' => 50,
                'allocated_resources' => ['memory' => 1000, 'disk' => 200],
            ]),
            servers: [[
                'id' => 81,
                'node' => 5,
                // Node wins for memory; the newer server list wins for disk.
                'memory' => 999,
                'cpu' => 100,
                'disk' => 999,
            ]]
        );

        $result = $service->getLocationAvailability(1);
        $node = $result['nodes'][0];

        $this->assertSame(['memory' => 1101, 'cpu' => 1200, 'disk' => 3001], $node['total']);
        $this->assertSame(['memory' => 1000, 'cpu' => 100, 'disk' => 999], $node['allocated']);
        $this->assertSame(['memory' => 101, 'cpu' => 1100, 'disk' => 2002], $node['available']);
        $this->assertTrue($node['eligible']);
        $this->assertTrue($node['cpu_capacity_enforced']);
    }

    public function test_any_unbounded_existing_server_limit_makes_node_ineligible(): void
    {
        $this->createCpuPolicy();

        foreach (['memory', 'cpu', 'disk'] as $unboundedResource) {
            $server = [
                'id' => 81,
                'node' => 5,
                'memory' => 1024,
                'cpu' => 100,
                'disk' => 10240,
            ];
            $server[$unboundedResource] = 0;

            $node = $this->service(servers: [$server])
                ->getLocationAvailability(1)['nodes'][0];

            $this->assertFalse(
                $node['eligible'],
                "A zero {$unboundedResource} limit must fail the node closed."
            );
            $this->assertContains('unlimited_existing_resource', $node['ineligible_reasons']);
        }
    }

    public function test_unbounded_node_overallocation_settings_fail_closed(): void
    {
        $this->createCpuPolicy();

        foreach ([
            'memory_overallocate' => 'unbounded_memory_overallocation',
            'disk_overallocate' => 'unbounded_disk_overallocation',
        ] as $setting => $reason) {
            $node = $this->service(node: $this->node([$setting => -1]))
                ->getLocationAvailability(1)['nodes'][0];

            $this->assertFalse(
                $node['eligible'],
                "A Pterodactyl {$setting} value of -1 must fail the node closed."
            );
            $this->assertContains($reason, $node['ineligible_reasons']);
            $this->assertSame(
                $this->node()[$setting === 'memory_overallocate' ? 'memory' : 'disk'],
                $node['total'][$setting === 'memory_overallocate' ? 'memory' : 'disk']
            );
        }
    }

    public function test_any_customer_allocation_capability_makes_node_ineligible(): void
    {
        $this->createCpuPolicy();
        $node = $this->service(servers: [[
            'id' => 81,
            'node' => 5,
            'memory' => 1024,
            'cpu' => 100,
            'disk' => 10240,
            'allocation_limit' => 1,
            'assigned_allocation_ids' => [501],
            'allocation_headroom' => 0,
        ]])->getLocationAvailability(1)['nodes'][0];

        $this->assertFalse($node['eligible']);
        $this->assertContains(
            'customer_allocation_management',
            $node['ineligible_reasons']
        );
    }

    public function test_missing_cpu_policy_makes_node_ineligible_and_advertises_zero_cpu(): void
    {
        $node = $this->service()->getLocationAvailability(1)['nodes'][0];

        $this->assertFalse($node['eligible']);
        $this->assertSame(0, $node['total']['cpu']);
        $this->assertSame(0, $node['available']['cpu']);
        $this->assertContains('cpu_policy_missing', $node['ineligible_reasons']);
    }

    public function test_cpu_policy_cannot_change_during_a_live_commitment(): void
    {
        $policy = $this->createCpuPolicy();
        $this->insertReservation(
            'policy-hold',
            'pending',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240]
        );
        $policy->cpu_overcommit_bps = 20000;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('live capacity commitment');

        $policy->save();
    }

    public function test_cpu_policy_cannot_be_deleted_during_a_live_commitment(): void
    {
        $policy = $this->createCpuPolicy();
        $this->insertReservation(
            'policy-delete-hold',
            'paid_committed',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('live capacity commitment');

        $policy->delete();
    }

    public function test_private_maintenance_and_allocationless_nodes_are_excluded(): void
    {
        $this->createCpuPolicy();
        $node = $this->service(
            node: $this->node(['public' => false, 'maintenance_mode' => true]),
            allocations: []
        )->getLocationAvailability(1)['nodes'][0];

        $this->assertFalse($node['eligible']);
        $this->assertSame([
            'private_node',
            'maintenance_mode',
            'no_available_allocation',
        ], $node['ineligible_reasons']);
    }

    public function test_pending_and_paid_committed_capacity_is_counted_with_self_exclusion(): void
    {
        $this->createCpuPolicy();
        $this->insertReservation('pending-self', 'pending', [
            'memory' => 2048,
            'cpu' => 100,
            'disk' => 10240,
        ], purpose: 'checkout');
        $this->insertReservation('paid-other', 'paid_committed', [
            'memory' => 1024,
            'cpu' => 50,
            'disk' => 5120,
        ], expiresAt: now()->subDay(), purpose: 'checkout');

        $node = $this->service()
            ->getLocationAvailability(1, 'pending-self')['nodes'][0];

        $this->assertSame(
            ['memory' => 1024, 'cpu' => 50, 'disk' => 5120],
            $node['reserved']
        );
    }

    public function test_upgrade_holds_count_only_positive_reserved_deltas(): void
    {
        $this->createCpuPolicy();
        $this->insertUpgradeReservation(
            'upgrade-delta',
            'pending',
            ['memory' => 6144, 'cpu' => 300, 'disk' => 40960],
            // Target values must not be double-counted as newly reserved stock.
            ['memory' => 8192, 'cpu' => 400, 'disk' => 51200],
            ['memory' => 2048, 'cpu' => 100, 'disk' => 10240]
        );

        $node = $this->service()->getLocationAvailability(1)['nodes'][0];

        $this->assertSame([
            'memory' => 2048,
            'cpu' => 100,
            'disk' => 10240,
        ], $node['reserved']);
    }

    public function test_upgrade_delta_row_drift_fails_stock_closed(): void
    {
        $this->createCpuPolicy();
        $reservationId = $this->insertUpgradeReservation(
            'upgrade-delta-drift',
            'pending',
            ['memory' => 6144, 'cpu' => 300, 'disk' => 40960],
            ['memory' => 8192, 'cpu' => 400, 'disk' => 51200],
            ['memory' => 2048, 'cpu' => 100, 'disk' => 10240]
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update(['reserved_memory' => 0]);

        $this->expectException(InvalidStockConfigurationException::class);
        $this->expectExceptionMessage(
            'upgrade capacity snapshot failed its immutable integrity check'
        );

        $this->service()->getLocationAvailability(1);
    }

    public function test_upgrade_invoice_lifecycle_drift_fails_stock_closed(): void
    {
        $this->createCpuPolicy();
        $reservationId = $this->insertUpgradeReservation(
            'upgrade-invoice-drift',
            'pending',
            ['memory' => 6144, 'cpu' => 300, 'disk' => 40960],
            ['memory' => 8192, 'cpu' => 400, 'disk' => 51200],
            ['memory' => 2048, 'cpu' => 100, 'disk' => 10240]
        );
        $invoiceId = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->value('invoice_id');
        $this->assertNotNull($invoiceId);
        DB::table('invoices')
            ->where('id', $invoiceId)
            ->update(['status' => \App\Models\Invoice::STATUS_PAID]);

        $this->expectException(InvalidStockConfigurationException::class);
        $this->expectExceptionMessage(
            'upgrade capacity snapshot failed its immutable integrity check'
        );

        $this->service()->getLocationAvailability(1);
    }

    public function test_confirmed_checkout_stays_overlaid_until_same_snapshot_proves_target(): void
    {
        $this->createCpuPolicy();
        $serviceRecord = \App\Models\Service::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);
        DB::table('services')->where('id', $serviceRecord->id)->update([
            'status' => \App\Models\Service::STATUS_ACTIVE,
        ]);
        $reservationId = $this->insertReservation(
            'confirmed-checkout-overlay',
            'confirmed',
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480],
            purpose: 'checkout'
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'purpose' => 'checkout',
                'service_id' => $serviceRecord->id,
                'external_server_id' => 81,
                'external_server_uuid' =>
                    '10000000-0000-4000-8000-000000000081',
                'external_server_identifier' => 'server-81',
                'consumed_at' => now(),
            ]);

        $stale = $this->service()
            ->getLocationAvailability(1)['nodes'][0];
        $this->assertSame(
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480],
            $stale['reserved']
        );

        $reflected = $this->service(servers: [[
            'id' => 81,
            'uuid' => '10000000-0000-4000-8000-000000000081',
            'identifier' => 'server-81',
            'external_id' => (string) $serviceRecord->id,
            'node' => 5,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 20480,
        ]])->getLocationAvailability(1)['nodes'][0];
        $this->assertSame(
            ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            $reflected['reserved']
        );
    }

    public function test_confirmed_upgrade_supersedes_checkout_and_overlays_only_snapshot_deficit(): void
    {
        $this->createCpuPolicy();
        $serviceRecord = \App\Models\Service::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);
        DB::table('services')->where('id', $serviceRecord->id)->update([
            'status' => \App\Models\Service::STATUS_ACTIVE,
        ]);
        $checkoutId = $this->insertReservation(
            'confirmed-checkout-before-upgrade',
            'confirmed',
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480],
            purpose: 'checkout'
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $checkoutId)
            ->update([
                'purpose' => 'checkout',
                'service_id' => $serviceRecord->id,
                'external_server_id' => 81,
                'external_server_uuid' =>
                    '10000000-0000-4000-8000-000000000081',
                'external_server_identifier' => 'server-81',
                'consumed_at' => now()->subHour(),
            ]);
        $this->insertUpgradeReservation(
            'confirmed-upgrade-target',
            'confirmed',
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480],
            ['memory' => 8192, 'cpu' => 400, 'disk' => 40960],
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480],
            service: $serviceRecord,
            externalServerId: 81,
            consumedAt: now()
        );

        $reflected = $this->service(servers: [[
            'id' => 81,
            'uuid' => '10000000-0000-4000-8000-000000000081',
            'identifier' => 'server-81',
            'external_id' => (string) $serviceRecord->id,
            'node' => 5,
            'memory' => 8192,
            'cpu' => 400,
            'disk' => 40960,
        ]])->getLocationAvailability(1)['nodes'][0];
        $this->assertSame(
            ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            $reflected['reserved'],
            'The obsolete checkout vector must not remain overlaid.'
        );

        $stale = $this->service(servers: [[
            'id' => 81,
            'uuid' => '10000000-0000-4000-8000-000000000081',
            'identifier' => 'server-81',
            'external_id' => (string) $serviceRecord->id,
            'node' => 5,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 20480,
        ]])->getLocationAvailability(1)['nodes'][0];
        $this->assertSame(
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480],
            $stale['reserved']
        );
    }

    public function test_multiple_confirmed_upgrades_use_immutable_upgrade_order_not_mutable_timestamps(): void
    {
        $this->createCpuPolicy();
        $serviceRecord = \App\Models\Service::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);
        DB::table('services')->where('id', $serviceRecord->id)->update([
            'status' => \App\Models\Service::STATUS_ACTIVE,
        ]);

        $checkoutId = $this->insertReservation(
            'confirmed-base',
            'confirmed',
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480],
            purpose: 'checkout'
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $checkoutId)
            ->update([
                'service_id' => $serviceRecord->id,
                'external_server_id' => 81,
                'external_server_uuid' =>
                    '10000000-0000-4000-8000-000000000081',
                'external_server_identifier' => 'server-81',
                'consumed_at' => now()->subHours(2),
            ]);
        $this->insertUpgradeReservation(
            'confirmed-upgrade-eight',
            'confirmed',
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480],
            ['memory' => 8192, 'cpu' => 400, 'disk' => 40960],
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480],
            service: $serviceRecord,
            externalServerId: 81,
            consumedAt: now()
        );
        $this->insertUpgradeReservation(
            'confirmed-upgrade-six',
            'confirmed',
            ['memory' => 8192, 'cpu' => 400, 'disk' => 40960],
            ['memory' => 6144, 'cpu' => 300, 'disk' => 40960],
            ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            service: $serviceRecord,
            externalServerId: 81,
            consumedAt: now()->subDay()
        );

        $node = $this->service(servers: [[
            'id' => 81,
            'uuid' => '10000000-0000-4000-8000-000000000081',
            'identifier' => 'server-81',
            'external_id' => (string) $serviceRecord->id,
            'node' => 5,
            'memory' => 6144,
            'cpu' => 300,
            'disk' => 40960,
        ]])->getLocationAvailability(1)['nodes'][0];

        $this->assertSame(
            ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            $node['reserved']
        );
    }

    public function test_conflicting_confirmed_server_identities_fail_snapshot_proof_closed(): void
    {
        $this->createCpuPolicy();
        $serviceRecord = \App\Models\Service::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);
        DB::table('services')->where('id', $serviceRecord->id)->update([
            'status' => \App\Models\Service::STATUS_ACTIVE,
        ]);
        $checkoutId = $this->insertReservation(
            'identity-checkout',
            'confirmed',
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480],
            purpose: 'checkout'
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $checkoutId)
            ->update([
                'purpose' => 'checkout',
                'service_id' => $serviceRecord->id,
                'external_server_id' => 81,
                'consumed_at' => now()->subHour(),
            ]);
        $this->insertUpgradeReservation(
            'identity-upgrade',
            'confirmed',
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480],
            ['memory' => 8192, 'cpu' => 400, 'disk' => 40960],
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480],
            service: $serviceRecord,
            externalServerId: 82,
            consumedAt: now()
        );

        $node = $this->service(servers: [[
            'id' => 82,
            'uuid' => '10000000-0000-4000-8000-000000000082',
            'identifier' => 'server-82',
            'external_id' => (string) $serviceRecord->id,
            'node' => 5,
            'memory' => 8192,
            'cpu' => 400,
            'disk' => 40960,
        ]])->getLocationAvailability(1)['nodes'][0];

        $this->assertSame(
            ['memory' => 8192, 'cpu' => 400, 'disk' => 40960],
            $node['reserved']
        );
    }

    public function test_server_assignment_wins_over_stale_node_allocation_snapshot(): void
    {
        $this->createCpuPolicy();
        $node = $this->service(
            servers: [[
                'id' => 81,
                'node' => 5,
                'memory' => 1024,
                'cpu' => 100,
                'disk' => 10240,
                'assigned_allocation_ids' => [501],
            ]],
            allocations: [
                ['id' => 501, 'ip' => '192.0.2.5', 'port' => 25565],
                ['id' => 502, 'ip' => '192.0.2.5', 'port' => 25566],
                ['id' => 503, 'ip' => '192.0.2.6', 'port' => 25565],
            ]
        )->getLocationAvailability(1)['nodes'][0];

        $this->assertSame(
            [502, 503],
            array_column($node['available_allocations'], 'id')
        );
        $this->assertTrue($node['available_allocations'][0]['ip_in_use']);
    }

    public function test_confirmed_local_claim_blocks_a_stale_free_allocation_snapshot(): void
    {
        $this->createCpuPolicy();
        $serviceRecord = \App\Models\Service::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);
        DB::table('services')->where('id', $serviceRecord->id)->update([
            'status' => \App\Models\Service::STATUS_ACTIVE,
        ]);
        $reservationId = $this->insertReservation(
            'confirmed-allocation-claim',
            'confirmed',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            purpose: 'checkout',
            allocation: [
                'allocation_id' => 501,
                'ip' => '192.0.2.5',
                'port' => 25565,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ]
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'service_id' => $serviceRecord->id,
                'external_server_id' => 81,
                'consumed_at' => now(),
            ]);
        $node = $this->service(allocations: [
            ['id' => 501, 'ip' => '192.0.2.5', 'port' => 25565],
            ['id' => 502, 'ip' => '192.0.2.6', 'port' => 25566],
        ])->getLocationAvailability(1)['nodes'][0];

        $this->assertSame(
            [502],
            array_column($node['available_allocations'], 'id')
        );
    }

    public function test_locally_reserved_allocation_is_removed_from_panel_unassigned_inventory(): void
    {
        $this->createCpuPolicy();
        $this->insertReservation(
            'allocation-hold',
            'pending',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            purpose: 'checkout',
            allocation: [
                'allocation_id' => 502,
                'ip' => '192.0.2.5',
                'port' => 25566,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ]
        );

        $service = $this->service(allocations: [
            ['id' => 501, 'ip' => '192.0.2.5', 'port' => 25565],
            ['id' => 502, 'ip' => '192.0.2.5', 'port' => 25566],
        ]);
        $node = $service->getLocationAvailability(1)['nodes'][0];

        $this->assertSame([501], array_column($node['available_allocations'], 'id'));
        $this->assertTrue($node['available_allocations'][0]['ip_in_use']);
        $editedNode = $service
            ->getLocationAvailability(1, 'allocation-hold')['nodes'][0];
        $this->assertSame(
            [501, 502],
            array_column($editedNode['available_allocations'], 'id')
        );
        $this->assertSame(
            [false, false],
            array_column($editedNode['available_allocations'], 'ip_in_use')
        );
    }

    public function test_equivalent_ipv6_reservation_marks_the_whole_ip_in_use(): void
    {
        $this->createCpuPolicy();
        $reservationId = $this->insertReservation(
            'ipv6-allocation-hold',
            'pending',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            purpose: 'checkout',
            allocation: [
                'allocation_id' => 601,
                'ip' => '2001:db8::1',
                'port' => 25565,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ]
        );

        $node = $this->service(allocations: [[
            'id' => 602,
            'ip' => '2001:0db8:0:0:0:0:0:1',
            'port' => 25566,
        ]])->getLocationAvailability(1)['nodes'][0];

        $this->assertTrue($node['available_allocations'][0]['ip_in_use']);
    }

    public function test_pending_dedicated_hold_removes_its_whole_ip_from_all_stock(): void
    {
        $this->createCpuPolicy();
        $this->insertReservation(
            'dedicated-allocation-hold',
            'pending',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            purpose: 'checkout',
            allocation: [
                'allocation_id' => 502,
                'ip' => '192.0.2.5',
                'port' => 25566,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ],
            dedicatedIp: true
        );
        $service = $this->service(allocations: [
            ['id' => 501, 'ip' => '192.0.2.5', 'port' => 25565],
            ['id' => 502, 'ip' => '192.0.2.5', 'port' => 25566],
            ['id' => 503, 'ip' => '192.0.2.6', 'port' => 25565],
        ]);

        $node = $service->getLocationAvailability(1)['nodes'][0];
        $this->assertSame(
            [503],
            array_column($node['available_allocations'], 'id')
        );

        $editing = $service
            ->getLocationAvailability(1, 'dedicated-allocation-hold')['nodes'][0];
        $this->assertSame(
            [501, 502, 503],
            array_column($editing['available_allocations'], 'id')
        );
    }

    public function test_tampered_dedicated_ip_claim_fails_stock_closed(): void
    {
        $this->createCpuPolicy();
        $reservationId = $this->insertReservation(
            'tampered-dedicated-allocation-hold',
            'pending',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            purpose: 'checkout',
            allocation: [
                'allocation_id' => 502,
                'ip' => '192.0.2.5',
                'port' => 25566,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ],
            dedicatedIp: true
        );
        $tampered = json_decode(
            (string) DB::table('ptero_resource_reservations')
                ->where('id', $reservationId)
                ->value('configuration_payload'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $tampered['allocation_requirements']['dedicated_ip'] = false;
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'configuration_payload' => json_encode(
                    $tampered,
                    JSON_THROW_ON_ERROR
                ),
            ]);

        $this->expectException(InvalidStockConfigurationException::class);
        $this->expectExceptionMessage(
            'allocation snapshot failed its immutable capacity integrity check'
        );

        $this->service(allocations: [
            ['id' => 501, 'ip' => '192.0.2.5', 'port' => 25565],
            ['id' => 502, 'ip' => '192.0.2.5', 'port' => 25566],
        ])->getLocationAvailability(1);
    }

    public function test_missing_checkout_allocation_claim_fails_stock_closed_even_when_self_excluded(): void
    {
        $this->createCpuPolicy();
        $reservationId = $this->insertReservation(
            'missing-self-claim',
            'pending',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            purpose: 'checkout'
        );
        DB::table('ptero_reservation_allocations')
            ->where('reservation_id', $reservationId)
            ->delete();

        $this->expectException(InvalidStockConfigurationException::class);
        $this->expectExceptionMessage(
            'Allocation claims no longer match the immutable checkout reservation'
        );

        $this->service()->getLocationAvailability(
            1,
            'missing-self-claim'
        );
    }

    #[DataProvider('allocationClaimDriftCases')]
    public function test_each_checkout_allocation_tuple_field_is_verified(
        string $field,
        mixed $value
    ): void {
        $this->createCpuPolicy();
        $reservationId = $this->insertReservation(
            "claim-drift-{$field}",
            'pending',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            purpose: 'checkout',
            allocation: [
                'allocation_id' => 502,
                'ip' => '192.0.2.5',
                'port' => 25566,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ]
        );
        DB::table('ptero_reservation_allocations')
            ->where('reservation_id', $reservationId)
            ->update([$field => $value]);

        $this->expectException(InvalidStockConfigurationException::class);
        $this->expectExceptionMessage(
            'Allocation claims no longer match the immutable checkout reservation'
        );

        $this->service()->getLocationAvailability(1);
    }

    public function test_extra_checkout_allocation_claim_fails_stock_closed(): void
    {
        $this->createCpuPolicy();
        $reservationId = $this->insertReservation(
            'extra-claim',
            'pending',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            purpose: 'checkout'
        );
        DB::table('ptero_reservation_allocations')->insert([
            'reservation_id' => $reservationId,
            'panel_identity' => self::PANEL_IDENTITY,
            'node_id' => 5,
            'allocation_id' => 991001,
            'ip' => '192.0.2.99',
            'port' => 29999,
            'environment_key' => 'QUERY_PORT',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(InvalidStockConfigurationException::class);
        $this->expectExceptionMessage(
            'Allocation claims no longer match the immutable checkout reservation'
        );

        $this->service()->getLocationAvailability(1);
    }

    #[DataProvider('activeClaimStatuses')]
    public function test_pending_and_paid_claims_must_remain_unreleased(
        string $status
    ): void {
        $this->createCpuPolicy();
        $reservationId = $this->insertReservation(
            "released-{$status}",
            $status,
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            expiresAt: $status === 'paid_committed'
                ? now()->subDay()
                : now()->addDay(),
            purpose: 'checkout'
        );
        DB::table('ptero_reservation_allocations')
            ->where('reservation_id', $reservationId)
            ->update(['released_at' => now()]);

        $this->expectException(InvalidStockConfigurationException::class);
        $this->expectExceptionMessage(
            'Allocation claims no longer match the immutable checkout reservation'
        );

        $this->service()->getLocationAvailability(1);
    }

    public function test_confirmed_claim_must_be_released_before_stock_can_be_quoted(): void
    {
        $this->createCpuPolicy();
        $reservationId = $this->insertReservation(
            'confirmed-unreleased',
            'confirmed',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            purpose: 'checkout'
        );
        DB::table('ptero_reservation_allocations')
            ->where('reservation_id', $reservationId)
            ->update(['released_at' => null]);

        $this->expectException(InvalidStockConfigurationException::class);
        $this->expectExceptionMessage(
            'Allocation claims no longer match the immutable checkout reservation'
        );

        $this->service()->getLocationAvailability(1);
    }

    public function test_expired_pending_commitment_stays_counted_until_atomic_cleanup(): void
    {
        $this->createCpuPolicy();
        $this->insertReservation(
            'pending-awaiting-cleanup',
            'pending',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            expiresAt: now()->subMinute(),
            purpose: 'checkout'
        );

        $node = $this->service()->getLocationAvailability(1)['nodes'][0];

        $this->assertSame(
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            $node['reserved']
        );
        $this->assertSame([], $node['available_allocations']);
    }

    public function test_materialized_terminal_commitment_with_unreleased_claim_fails_stock_closed(): void
    {
        $this->createCpuPolicy();
        $this->insertReservation(
            'expired-unreleased',
            'expired',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            expiresAt: now()->subMinute(),
            purpose: 'checkout'
        );

        $this->expectException(InvalidStockConfigurationException::class);
        $this->expectExceptionMessage(
            'terminal capacity commitment still owns an unreleased allocation claim'
        );

        $this->service()->getLocationAvailability(1);
    }

    public function test_upgrade_commitment_cannot_own_checkout_allocation_claims(): void
    {
        $this->createCpuPolicy();
        $reservationId = $this->insertReservation(
            'upgrade-with-claim',
            'pending',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            purpose: 'upgrade'
        );
        DB::table('ptero_reservation_allocations')->insert([
            'reservation_id' => $reservationId,
            'panel_identity' => self::PANEL_IDENTITY,
            'node_id' => 5,
            'allocation_id' => 991002,
            'ip' => '192.0.2.100',
            'port' => 30000,
            'environment_key' => 'SERVER_PORT',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(InvalidStockConfigurationException::class);
        $this->expectExceptionMessage(
            'resource upgrade unexpectedly owns checkout allocation claims'
        );

        $this->service()->getLocationAvailability(1);
    }

    public function test_signed_required_allocation_count_must_match_claim_set(): void
    {
        $this->createCpuPolicy();
        $reservationId = $this->insertReservation(
            'required-count-drift',
            'pending',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            purpose: 'checkout'
        );
        $payload = json_decode(
            (string) DB::table('ptero_resource_reservations')
                ->where('id', $reservationId)
                ->value('configuration_payload'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $payload['allocation_requirements']['required_count'] = 2;
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'configuration_payload' => json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                ),
                'configuration_fingerprint' =>
                    (new ReservationConfigurationService)
                        ->fingerprint($payload),
            ]);

        $this->expectException(InvalidStockConfigurationException::class);
        $this->expectExceptionMessage(
            'no valid signed allocation set'
        );

        $this->service()->getLocationAvailability(1);
    }

    public function test_confirmed_dedicated_server_blocks_ip_until_service_is_cancelled(): void
    {
        $this->createCpuPolicy();
        $serviceRecord = \App\Models\Service::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);
        DB::table('services')->where('id', $serviceRecord->id)->update([
            'status' => \App\Models\Service::STATUS_ACTIVE,
        ]);
        $reservationId = $this->insertReservation(
            'confirmed-dedicated',
            'confirmed',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            purpose: 'checkout',
            allocation: [
                'allocation_id' => 501,
                'ip' => '192.0.2.5',
                'port' => 25565,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ],
            dedicatedIp: true
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'service_id' => $serviceRecord->id,
            ]);
        $stock = $this->service(allocations: [
            ['id' => 502, 'ip' => '192.0.2.5', 'port' => 25566],
            ['id' => 503, 'ip' => '192.0.2.6', 'port' => 25565],
        ]);

        $this->assertSame(
            [503],
            array_column(
                $stock->getLocationAvailability(1)['nodes'][0]['available_allocations'],
                'id'
            )
        );

        DB::table('services')->where('id', $serviceRecord->id)->update([
            'status' => \App\Models\Service::STATUS_CANCELLED,
        ]);
        $this->assertSame(
            [502, 503],
            array_column(
                $stock->getLocationAvailability(1)['nodes'][0]['available_allocations'],
                'id'
            )
        );
    }

    public function test_holds_from_another_panel_do_not_reduce_colliding_node_or_allocation_stock(): void
    {
        $this->createCpuPolicy();
        $this->insertReservation(
            'other-panel-hold',
            'pending',
            ['memory' => 2048, 'cpu' => 100, 'disk' => 10240],
            panelIdentity: str_repeat('f', 64),
            purpose: 'checkout',
            allocation: [
                'allocation_id' => 501,
                'ip' => '192.0.2.5',
                'port' => 25565,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ]
        );

        $node = $this->service()->getLocationAvailability(1)['nodes'][0];

        $this->assertSame(
            ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            $node['reserved']
        );
        $this->assertSame(
            [501],
            array_column($node['available_allocations'], 'id')
        );
    }

    public function test_fixed_node_upgrade_can_skip_new_allocation_but_not_other_eligibility_rules(): void
    {
        $this->createCpuPolicy();
        $service = $this->service(allocations: []);

        $this->assertTrue($service->verifyNodeCapacity(
            5,
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240],
            allocationCount: 0
        ));
        $this->assertFalse($service->verifyAvailability(
            5,
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240]
        ));
    }

    /**
     * @param  list<array{
     *     id: int,
     *     node: int,
     *     memory: int,
     *     cpu: int,
     *     disk: int,
     *     allocation_limit?: int,
     *     assigned_allocation_ids?: list<int>,
     *     allocation_headroom?: int
     * }>  $servers
     * @param  list<array{id: int, ip: string, port: int}>|null  $allocations
     */
    private function service(
        ?array $node = null,
        array $servers = [],
        ?array $allocations = null
    ): ResourceCalculationService {
        $node ??= $this->node();
        $allocations ??= [['id' => 501, 'ip' => '192.0.2.5', 'port' => 25565]];
        $inventory = Mockery::mock(PterodactylInventoryService::class);
        $inventory->shouldReceive('panelIdentity')
            ->zeroOrMoreTimes()
            ->andReturn(self::PANEL_IDENTITY);
        $inventory->shouldReceive('nodesInLocation')
            ->zeroOrMoreTimes()
            ->with(1)
            ->andReturn([$node]);
        $inventory->shouldReceive('nodes')
            ->zeroOrMoreTimes()
            ->andReturn([$node]);
        $inventory->shouldReceive('serversForNodes')
            ->zeroOrMoreTimes()
            ->with([5])
            ->andReturn([5 => $servers]);
        $inventory->shouldReceive('availableAllocationsForNode')
            ->zeroOrMoreTimes()
            ->with(5)
            ->andReturn($allocations);

        return new ResourceCalculationService($inventory);
    }

    /**
     * @return array<string, mixed>
     */
    private function node(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 5,
            'uuid' => '00000000-0000-4000-8000-000000000005',
            'name' => 'Node 5',
            'fqdn' => 'node-5.example.com',
            'public' => true,
            'maintenance_mode' => false,
            'location_id' => 1,
            'memory' => 32768,
            'disk' => 512000,
            'memory_overallocate' => 0,
            'disk_overallocate' => 0,
            'allocated_resources' => [
                'memory' => 0,
                'disk' => 0,
            ],
        ], $overrides);
    }

    private function createCpuPolicy(
        int $capacity = 800,
        int $overcommit = 10000
    ): NodeCapacityPolicy {
        return NodeCapacityPolicy::query()->updateOrCreate([
            'panel_identity' => self::PANEL_IDENTITY,
            'node_uuid' => '00000000-0000-4000-8000-000000000005',
        ], [
            'node_id' => 5,
            'location_id' => 1,
            'cpu_capacity_percent' => $capacity,
            'cpu_overcommit_bps' => $overcommit,
            'enabled' => true,
        ]);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function allocationClaimDriftCases(): array
    {
        return [
            'panel' => ['panel_identity', str_repeat('f', 64)],
            'node' => ['node_id', 6],
            'allocation' => ['allocation_id', 503],
            'ip' => ['ip', '192.0.2.6'],
            'port' => ['port', 25567],
            'environment' => ['environment_key', 'QUERY_PORT'],
            'primary' => ['is_primary', false],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function activeClaimStatuses(): array
    {
        return [
            'pending' => ['pending'],
            'paid committed' => ['paid_committed'],
        ];
    }

    private function insertUpgradeReservation(
        string $token,
        string $status,
        array $source,
        array $target,
        array $delta,
        ?\App\Models\Service $service = null,
        int $externalServerId = 81,
        mixed $consumedAt = null
    ): int {
        if (
            $service === null
            || $service->product_id === null
            || $service->plan_id === null
        ) {
            $product = \App\Models\Product::factory()->create();
            $plan = \App\Models\Plan::factory()->create([
                'priceable_id' => $product->id,
                'priceable_type' => \App\Models\Product::class,
            ]);
            $service ??= \App\Models\Service::factory()->create([
                'user_id' => User::factory()->create()->id,
            ]);
            DB::table('services')->where('id', $service->id)->update([
                'product_id' => $product->id,
                'plan_id' => $plan->id,
                'quantity' => 1,
                'currency_code' => 'USD',
            ]);
            $service->refresh();
        }
        $product = \App\Models\Product::query()
            ->findOrFail($service->product_id);
        $server = $product->server;
        if ($server?->extension !== 'Pterodactyl') {
            $server = \App\Models\Server::query()->create([
                'name' => "Pterodactyl Upgrade {$token}",
                'extension' => 'Pterodactyl',
                'type' => 'server',
                'enabled' => true,
            ]);
            DB::table('products')
                ->where('id', $service->product_id)
                ->update(['server_id' => $server->id]);
        }
        $sourceSnapshot = [
            'service_id' => (int) $service->id,
            'product_id' => (int) $service->product_id,
            'plan_id' => (int) $service->plan_id,
            'quantity' => 1,
            'currency_code' => 'USD',
            'properties' => [
                ...$source,
                'location' => 1,
            ],
            'billing_anchor' => [],
        ];
        $targetSnapshot = [
            'service_id' => (int) $service->id,
            'product_id' => (int) $service->product_id,
            'plan_id' => (int) $service->plan_id,
            'quantity' => 1,
            'currency_code' => 'USD',
            'properties' => [
                ...$target,
                'location' => 1,
            ],
            'recurring_price' => '0.00',
            'billing_anchor' => [],
        ];
        $invoice = \App\Models\Invoice::factory()->create([
            'user_id' => $service->user_id,
            'status' => match ($status) {
                'pending' => \App\Models\Invoice::STATUS_PENDING,
                'paid_committed',
                'confirmed' => \App\Models\Invoice::STATUS_PAID,
                default => throw new \InvalidArgumentException(
                    "Unsupported upgrade reservation status {$status}."
                ),
            },
            'currency_code' => 'USD',
        ]);
        $sourceFingerprint = $this->serviceUpgradeSnapshotFingerprint(
            $sourceSnapshot
        );
        $targetFingerprint = $this->serviceUpgradeSnapshotFingerprint(
            $targetSnapshot
        );
        $upgradeId = DB::table('service_upgrades')->insertGetId([
            'service_id' => $service->id,
            'product_id' => $service->product_id,
            'plan_id' => $service->plan_id,
            'invoice_id' => $invoice->id,
            'status' => match ($status) {
                'confirmed' => 'completed',
                'paid_committed' => 'paid_committed',
                'pending' => 'awaiting_payment',
                default => throw new \InvalidArgumentException(
                    "Unsupported upgrade reservation status {$status}."
                ),
            },
            'active_service_guard_id' => $status === 'confirmed'
                ? null
                : $service->id,
            'type' => 'config_options',
            'source_snapshot' => json_encode(
                $sourceSnapshot,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
            ),
            'target_snapshot' => json_encode(
                $targetSnapshot,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
            ),
            'source_fingerprint' => $sourceFingerprint,
            'target_fingerprint' => $targetFingerprint,
            'quoted_amount' => '9.90',
            'currency_code' => 'USD',
            'credit_amount' => 0,
            'provisioning_attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = [
            'service_upgrade_id' => $upgradeId,
            'source_fingerprint' => $sourceFingerprint,
            'target_fingerprint' => $targetFingerprint,
            'panel_identity' => self::PANEL_IDENTITY,
            'node_id' => 5,
            'location_id' => 1,
            'external_server_id' => $externalServerId,
            'external_server_uuid' =>
                sprintf(
                    '10000000-0000-4000-8000-%012d',
                    $externalServerId
                ),
            'external_server_identifier' =>
                "server-{$externalServerId}",
            'external_server_external_id' => (string) $service->id,
            'external_user_id' => 44,
            'user_external_id' =>
                "paymenter-user-{$service->user_id}",
            'user_email' => (string) $service->user->email,
            'nest_id' => 1,
            'egg_id' => 2,
            'preserved_build' => [
                'swap' => 0,
                'io' => 500,
                'threads' => null,
                'databases' => 0,
                'allocations' => 0,
                'backups' => 0,
            ],
            'allocation_id' => 501,
            'assigned_allocation_ids' => [501],
            'source' => $source,
            'target' => $target,
            'delta' => $delta,
        ];
        $upgrade = (object) [
            'id' => $upgradeId,
            'service_id' => $service->id,
            'source_fingerprint' => $sourceFingerprint,
            'target_fingerprint' => $targetFingerprint,
            'quoted_amount' => '9.90',
            'currency_code' => 'USD',
        ];

        return DB::table('ptero_resource_reservations')->insertGetId([
            'purpose' => 'upgrade',
            'token' => $token,
            'service_id' => $service->id,
            'service_upgrade_id' => $upgradeId,
            'upgrade_guard_id' => $status === 'confirmed'
                ? null
                : $upgradeId,
            'server_extension_id' => $server->id,
            'invoice_id' => $invoice->id,
            'user_id' => $service->user_id,
            'product_id' => $service->product_id,
            'plan_id' => $service->plan_id,
            'quantity' => 1,
            'currency_code' => 'USD',
            'panel_identity' => self::PANEL_IDENTITY,
            'configuration_fingerprint' =>
                (new UpgradeReservationIntegrityService)
                    ->fingerprint($upgrade, $payload),
            'configuration_payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'pricing_version' =>
                (new UpgradeReservationIntegrityService)
                    ->pricingVersion($upgrade),
            'formula_version' => 'dynamic-upgrade-v1',
            'node_id' => 5,
            'location_id' => 1,
            'memory' => $target['memory'],
            'cpu' => $target['cpu'],
            'disk' => $target['disk'],
            'reserved_memory' => $delta['memory'],
            'reserved_cpu' => $delta['cpu'],
            'reserved_disk' => $delta['disk'],
            'external_server_id' => $externalServerId,
            'external_user_id' => 44,
            'external_server_uuid' =>
                $payload['external_server_uuid'],
            'external_server_identifier' =>
                $payload['external_server_identifier'],
            'calculated_price' => '9.90',
            'pricing_breakdown' => json_encode(
                [],
                JSON_THROW_ON_ERROR
            ),
            'status' => $status,
            'expires_at' => now()->addDay(),
            'consumed_at' => $consumedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function serviceUpgradeSnapshotFingerprint(
        array $snapshot
    ): string {
        $canonicalize = function (array $value) use (
            &$canonicalize
        ): array {
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $canonicalize($item);
                }
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        };

        return hash('sha256', json_encode(
            $canonicalize($snapshot),
            JSON_THROW_ON_ERROR
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_SLASHES
        ));
    }

    private function insertReservation(
        string $token,
        string $status,
        array $resources,
        mixed $expiresAt = null,
        string $panelIdentity = self::PANEL_IDENTITY,
        string $purpose = 'checkout',
        ?array $allocation = null,
        bool $dedicatedIp = false
    ): int {
        if (! in_array($purpose, ['checkout', 'upgrade'], true)) {
            throw new \InvalidArgumentException(
                'The test reservation purpose is invalid.'
            );
        }

        $allocation ??= [
            'allocation_id' =>
                100000 + (int) sprintf('%u', crc32($token)),
            'ip' => '198.51.100.10',
            'port' =>
                20000 + ((int) sprintf('%u', crc32($token)) % 40000),
            'environment_key' => 'SERVER_PORT',
            'is_primary' => true,
        ];
        $payload = $purpose === 'checkout'
            ? [
                'panel_identity' => $panelIdentity,
                'node_id' => 5,
                'location_id' => 1,
                'resources' => $resources,
                'allocation_requirements' => [
                    'required_count' => 1,
                    'dedicated_ip' => $dedicatedIp,
                ],
                'allocations' => [$allocation],
            ]
            : [];

        $reservationId = DB::table(
            'ptero_resource_reservations'
        )->insertGetId([
            'purpose' => $purpose,
            'token' => $token,
            'panel_identity' => $panelIdentity,
            'configuration_fingerprint' =>
                (new ReservationConfigurationService)
                    ->fingerprint($payload),
            'configuration_payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'node_id' => 5,
            'location_id' => 1,
            'memory' => $resources['memory'],
            'cpu' => $resources['cpu'],
            'disk' => $resources['disk'],
            'calculated_price' => 9.99,
            'pricing_breakdown' => json_encode([]),
            'status' => $status,
            'expires_at' => $expiresAt ?? now()->addMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($purpose === 'checkout') {
            DB::table('ptero_reservation_allocations')->insert([
                'reservation_id' => $reservationId,
                'panel_identity' => $panelIdentity,
                'node_id' => 5,
                'allocation_id' => $allocation['allocation_id'],
                'ip' => $allocation['ip'],
                'port' => $allocation['port'],
                'environment_key' => $allocation['environment_key'],
                'is_primary' => $allocation['is_primary'],
                'released_at' => $status === 'confirmed' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $reservationId;
    }
}
