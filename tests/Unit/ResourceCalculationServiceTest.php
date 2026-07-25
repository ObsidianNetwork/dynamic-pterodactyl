<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\NodeCapacityPolicy;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\PterodactylInventoryService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
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

    public function test_customer_allocation_claims_make_node_ineligible_even_without_headroom(): void
    {
        $this->createCpuPolicy();
        $node = $this->service(servers: [[
            'id' => 81,
            'node' => 5,
            'memory' => 1024,
            'cpu' => 100,
            'disk' => 10240,
            'allocation_limit' => 2,
            'assigned_allocation_ids' => [501, 502],
            'allocation_headroom' => 0,
        ]])->getLocationAvailability(1)['nodes'][0];

        $this->assertFalse($node['eligible']);
        $this->assertContains(
            'customer_allocation_headroom',
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
        ]);
        $this->insertReservation('paid-other', 'paid_committed', [
            'memory' => 1024,
            'cpu' => 50,
            'disk' => 5120,
        ], expiresAt: now()->subDay());

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
        DB::table('ptero_resource_reservations')->insert([
            'purpose' => 'upgrade',
            'token' => 'upgrade-delta',
            'panel_identity' => self::PANEL_IDENTITY,
            'node_id' => 5,
            'location_id' => 1,
            // Target values must not be double-counted as newly reserved stock.
            'memory' => 8192,
            'cpu' => 400,
            'disk' => 51200,
            'reserved_memory' => 2048,
            'reserved_cpu' => 100,
            'reserved_disk' => 10240,
            'calculated_price' => 9.99,
            'pricing_breakdown' => json_encode([]),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $node = $this->service()->getLocationAvailability(1)['nodes'][0];

        $this->assertSame([
            'memory' => 2048,
            'cpu' => 100,
            'disk' => 10240,
        ], $node['reserved']);
    }

    public function test_confirmed_checkout_stays_overlaid_until_same_snapshot_proves_target(): void
    {
        $this->createCpuPolicy();
        $serviceRecord = \App\Models\Service::factory()->create();
        DB::table('services')->where('id', $serviceRecord->id)->update([
            'status' => \App\Models\Service::STATUS_ACTIVE,
        ]);
        $reservationId = $this->insertReservation(
            'confirmed-checkout-overlay',
            'confirmed',
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480]
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
        $serviceRecord = \App\Models\Service::factory()->create();
        DB::table('services')->where('id', $serviceRecord->id)->update([
            'status' => \App\Models\Service::STATUS_ACTIVE,
        ]);
        $checkoutId = $this->insertReservation(
            'confirmed-checkout-before-upgrade',
            'confirmed',
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480]
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
        $upgradeId = $this->insertReservation(
            'confirmed-upgrade-target',
            'confirmed',
            ['memory' => 8192, 'cpu' => 400, 'disk' => 40960]
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $upgradeId)
            ->update([
                'purpose' => 'upgrade',
                'service_id' => $serviceRecord->id,
                'configuration_payload' => json_encode([
                    'external_server_id' => 81,
                ]),
                'reserved_memory' => 4096,
                'reserved_cpu' => 200,
                'reserved_disk' => 20480,
                'consumed_at' => now(),
            ]);

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

    public function test_multiple_confirmed_upgrades_keep_only_latest_target(): void
    {
        $this->createCpuPolicy();
        $serviceRecord = \App\Models\Service::factory()->create();
        DB::table('services')->where('id', $serviceRecord->id)->update([
            'status' => \App\Models\Service::STATUS_ACTIVE,
        ]);

        foreach ([
            [
                'token' => 'confirmed-base',
                'purpose' => 'checkout',
                'resources' => [4096, 200, 20480],
                'consumed_at' => now()->subHours(2),
            ],
            [
                'token' => 'confirmed-upgrade-eight',
                'purpose' => 'upgrade',
                'resources' => [8192, 400, 40960],
                'consumed_at' => now()->subHour(),
            ],
            [
                'token' => 'confirmed-upgrade-six',
                'purpose' => 'upgrade',
                'resources' => [6144, 300, 30720],
                'consumed_at' => now(),
            ],
        ] as $expectation) {
            [$memory, $cpu, $disk] = $expectation['resources'];
            $reservationId = $this->insertReservation(
                $expectation['token'],
                'confirmed',
                compact('memory', 'cpu', 'disk')
            );
            DB::table('ptero_resource_reservations')
                ->where('id', $reservationId)
                ->update([
                    'purpose' => $expectation['purpose'],
                    'service_id' => $serviceRecord->id,
                    'external_server_id' =>
                        $expectation['purpose'] === 'checkout' ? 81 : null,
                    'external_server_uuid' =>
                        $expectation['purpose'] === 'checkout'
                            ? '10000000-0000-4000-8000-000000000081'
                            : null,
                    'external_server_identifier' =>
                        $expectation['purpose'] === 'checkout'
                            ? 'server-81'
                            : null,
                    'configuration_payload' => json_encode([
                        'external_server_id' => 81,
                    ]),
                    'consumed_at' => $expectation['consumed_at'],
                ]);
        }

        $node = $this->service(servers: [[
            'id' => 81,
            'uuid' => '10000000-0000-4000-8000-000000000081',
            'identifier' => 'server-81',
            'external_id' => (string) $serviceRecord->id,
            'node' => 5,
            'memory' => 6144,
            'cpu' => 300,
            'disk' => 30720,
        ]])->getLocationAvailability(1)['nodes'][0];

        $this->assertSame(
            ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            $node['reserved']
        );
    }

    public function test_conflicting_confirmed_server_identities_fail_snapshot_proof_closed(): void
    {
        $this->createCpuPolicy();
        $serviceRecord = \App\Models\Service::factory()->create();
        DB::table('services')->where('id', $serviceRecord->id)->update([
            'status' => \App\Models\Service::STATUS_ACTIVE,
        ]);
        $checkoutId = $this->insertReservation(
            'identity-checkout',
            'confirmed',
            ['memory' => 4096, 'cpu' => 200, 'disk' => 20480]
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $checkoutId)
            ->update([
                'purpose' => 'checkout',
                'service_id' => $serviceRecord->id,
                'external_server_id' => 81,
                'consumed_at' => now()->subHour(),
            ]);
        $upgradeId = $this->insertReservation(
            'identity-upgrade',
            'confirmed',
            ['memory' => 8192, 'cpu' => 400, 'disk' => 40960]
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $upgradeId)
            ->update([
                'purpose' => 'upgrade',
                'service_id' => $serviceRecord->id,
                'configuration_payload' => json_encode([
                    'external_server_id' => 82,
                ]),
                'consumed_at' => now(),
            ]);

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
        $serviceRecord = \App\Models\Service::factory()->create();
        DB::table('services')->where('id', $serviceRecord->id)->update([
            'status' => \App\Models\Service::STATUS_ACTIVE,
        ]);
        $reservationId = $this->insertReservation(
            'confirmed-allocation-claim',
            'confirmed',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240]
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'service_id' => $serviceRecord->id,
                'external_server_id' => 81,
                'consumed_at' => now(),
            ]);
        DB::table('ptero_reservation_allocations')->insert([
            'reservation_id' => $reservationId,
            'panel_identity' => self::PANEL_IDENTITY,
            'node_id' => 5,
            'allocation_id' => 501,
            'ip' => '192.0.2.5',
            'port' => 25565,
            'is_primary' => true,
            'released_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
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
        $reservationId = $this->insertReservation('allocation-hold', 'pending', [
            'memory' => 1024,
            'cpu' => 100,
            'disk' => 10240,
        ]);
        DB::table('ptero_reservation_allocations')->insert([
            'reservation_id' => $reservationId,
            'panel_identity' => self::PANEL_IDENTITY,
            'node_id' => 5,
            'allocation_id' => 502,
            'ip' => '192.0.2.5',
            'port' => 25566,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240]
        );
        DB::table('ptero_reservation_allocations')->insert([
            'reservation_id' => $reservationId,
            'panel_identity' => self::PANEL_IDENTITY,
            'node_id' => 5,
            'allocation_id' => 601,
            'ip' => '2001:db8::1',
            'port' => 25565,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
        $reservationId = $this->insertReservation(
            'dedicated-allocation-hold',
            'pending',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240]
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'configuration_payload' => json_encode([
                    'allocation_requirements' => ['dedicated_ip' => true],
                ]),
            ]);
        DB::table('ptero_reservation_allocations')->insert([
            'reservation_id' => $reservationId,
            'panel_identity' => self::PANEL_IDENTITY,
            'node_id' => 5,
            'allocation_id' => 502,
            'ip' => '192.0.2.5',
            'port' => 25566,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    public function test_confirmed_dedicated_server_blocks_ip_until_service_is_cancelled(): void
    {
        $this->createCpuPolicy();
        $serviceRecord = \App\Models\Service::factory()->create();
        DB::table('services')->where('id', $serviceRecord->id)->update([
            'status' => \App\Models\Service::STATUS_ACTIVE,
        ]);
        $reservationId = $this->insertReservation(
            'confirmed-dedicated',
            'confirmed',
            ['memory' => 1024, 'cpu' => 100, 'disk' => 10240]
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'service_id' => $serviceRecord->id,
                'configuration_payload' => json_encode([
                    'allocation_requirements' => ['dedicated_ip' => true],
                ]),
            ]);
        DB::table('ptero_reservation_allocations')->insert([
            'reservation_id' => $reservationId,
            'panel_identity' => self::PANEL_IDENTITY,
            'node_id' => 5,
            'allocation_id' => 501,
            'ip' => '192.0.2.5',
            'port' => 25565,
            'is_primary' => true,
            'released_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
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
        $reservationId = $this->insertReservation(
            'other-panel-hold',
            'pending',
            ['memory' => 2048, 'cpu' => 100, 'disk' => 10240],
            panelIdentity: str_repeat('f', 64)
        );
        DB::table('ptero_reservation_allocations')->insert([
            'reservation_id' => $reservationId,
            'panel_identity' => str_repeat('f', 64),
            'node_id' => 5,
            'allocation_id' => 501,
            'ip' => '192.0.2.5',
            'port' => 25565,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

    private function insertReservation(
        string $token,
        string $status,
        array $resources,
        mixed $expiresAt = null,
        string $panelIdentity = self::PANEL_IDENTITY
    ): int {
        return DB::table('ptero_resource_reservations')->insertGetId([
            'token' => $token,
            'panel_identity' => $panelIdentity,
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
    }
}
