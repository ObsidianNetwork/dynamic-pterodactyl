<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Support\PanelEndpointIdentity;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\PterodactylInventoryService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class PterodactylInventoryServiceTest extends LaravelTestCase
{
    private PterodactylInventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->inventory = new PterodactylInventoryService([
            'pterodactyl_url' => 'https://panel.example.com/',
            'pterodactyl_api_key' => 'application-key',
            'exclusive_provisioning_control' => true,
        ]);
    }

    public function test_panel_identity_preserves_case_sensitive_path(): void
    {
        $inventory = new PterodactylInventoryService([
            'pterodactyl_url' =>
                'HTTPS://Panel.Example.com:443/PanelA/',
            'pterodactyl_api_key' => 'application-key',
            'exclusive_provisioning_control' => true,
        ]);

        $this->assertSame(
            PanelEndpointIdentity::hash(
                'https://panel.example.com/PanelA'
            ),
            $inventory->panelIdentity()
        );
        $this->assertNotSame(
            PanelEndpointIdentity::hash(
                'https://panel.example.com/panela'
            ),
            $inventory->panelIdentity()
        );
    }

    public function test_nodes_are_paginated_and_location_is_filtered_locally(): void
    {
        Http::fake(function ($request) {
            $query = [];
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $this->assertArrayNotHasKey('filter', $query);
            $this->assertSame('allocations', $query['include'] ?? null);
            $page = (int) ($query['page'] ?? 1);

            return Http::response([
                'data' => $page === 1
                    ? [$this->nodeResource(10, 1), $this->nodeResource(20, 2)]
                    : [$this->nodeResource(30, 1)],
                'meta' => ['pagination' => [
                    'current_page' => $page,
                    'total_pages' => 2,
                ]],
            ]);
        });

        $nodes = $this->inventory->nodesInLocation(1);

        $this->assertSame([10, 30], array_column($nodes, 'id'));
        $this->assertSame(
            ['memory' => 1024, 'disk' => 2048],
            $nodes[0]['allocated_resources']
        );
        $this->assertSame(
            [10001],
            array_column(
                $this->inventory->availableAllocationsForNode(10),
                'id'
            )
        );
        $this->assertSame(
            [30001],
            array_column(
                $this->inventory->availableAllocationsForNode(30),
                'id'
            )
        );
        Http::assertSentCount(2);
    }

    public function test_locations_use_stock_application_api_shape(): void
    {
        Http::fake([
            '*' => Http::response(['data' => [[
                'object' => 'location',
                'attributes' => [
                    'id' => 1,
                    'short' => 'mel',
                    'long' => 'Melbourne',
                ],
            ]]]),
        ]);

        $this->assertSame([[
            'id' => 1,
            'short' => 'mel',
            'long' => 'Melbourne',
        ]], $this->inventory->locations());
    }

    public function test_free_allocation_reports_when_its_ip_is_already_assigned(): void
    {
        $node = $this->nodeResource(10, 1);
        $node['relationships']['allocations']['data'][] =
            $this->allocationResource(10002, true);

        Http::fake([
            '*' => Http::response(['data' => [$node]]),
        ]);

        $allocations = $this->inventory->nodes()[0]['available_allocations'];

        $this->assertCount(1, $allocations);
        $this->assertSame(10001, $allocations[0]['id']);
        $this->assertTrue($allocations[0]['ip_in_use']);
    }

    public function test_server_and_allocation_shapes_are_read_without_optimistic_defaults(): void
    {
        Http::fake(function ($request) {
            $query = [];
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $page = (int) ($query['page'] ?? 1);

            if (str_contains($request->url(), '/servers/external/paymenter-44')) {
                return Http::response($this->serverResource(
                    44,
                    10,
                    memory: 4096,
                    cpu: 200,
                    disk: 20480,
                    allocation: 501
                ));
            }

            if (str_contains($request->url(), '/api/application/users/44')) {
                return Http::response([
                    'object' => 'user',
                    'attributes' => [
                        'id' => 44,
                        'external_id' => 'paymenter-user-30',
                        'email' => 'customer@example.com',
                    ],
                ]);
            }

            if (str_contains($request->url(), '/api/application/servers')) {
                $this->assertSame('allocations', $query['include'] ?? null);

                return Http::response([
                    'data' => $page === 1
                        ? [$this->serverResource(44, 10, 4096, 200, 20480, 501)]
                        : [$this->serverResource(45, 10, 2048, 100, 10240, 502)],
                    'meta' => ['pagination' => [
                        'current_page' => $page,
                        'total_pages' => 2,
                    ]],
                ]);
            }

            if (str_contains($request->url(), '/nodes/10/allocations')) {
                return Http::response([
                    'data' => $page === 1
                        ? [
                            $this->allocationResource(501, true),
                            $this->allocationResource(502, false),
                        ]
                        : [$this->allocationResource(503, false)],
                    'meta' => ['pagination' => [
                        'current_page' => $page,
                        'total_pages' => 2,
                    ]],
                ]);
            }

            return Http::response([], 404);
        });

        $servers = $this->inventory->serversForNodes([10]);
        $allocations = $this->inventory->availableAllocationsForNode(10);
        $external = $this->inventory->serverByExternalId('paymenter-44');

        $this->assertSame([
            'id' => 44,
            'uuid' => '10000000-0000-4000-8000-000000000044',
            'identifier' => 'server-44',
            'external_id' => '44',
            'node' => 10,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 20480,
            'allocation_limit' => 1,
            'assigned_allocation_ids' => [501],
            'allocation_headroom' => 0,
        ], $servers[10][0]);
        $this->assertSame([44, 45], array_column($servers[10], 'id'));
        $this->assertSame([502, 503], array_column($allocations, 'id'));
        $this->assertSame(501, $external['allocation']);
        $this->assertSame([501], $external['assigned_allocation_ids']);
        $this->assertSame('paymenter-user-30', $external['user_external_id']);
        $this->assertSame('customer@example.com', $external['user_email']);
        $this->assertSame(1, $external['nest_id']);
        $this->assertSame(2, $external['egg_id']);
    }

    public function test_missing_overallocation_field_fails_inventory_closed(): void
    {
        $node = $this->nodeResource(10, 1);
        unset($node['attributes']['memory_overallocate']);

        Http::fake([
            '*' => Http::response(['data' => [$node]]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('memory_overallocate');

        $this->inventory->nodes();
    }

    public function test_integer_overflow_is_rejected_instead_of_saturated(): void
    {
        $node = $this->nodeResource(10, 1);
        $node['attributes']['memory'] = '999999999999999999999999999999';

        Http::fake([
            '*' => Http::response(['data' => [$node]]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('supported integer range');

        $this->inventory->nodes();
    }

    public function test_customer_allocation_headroom_is_derived_from_assigned_relationship(): void
    {
        $server = $this->serverResource(44, 10, 4096, 200, 20480, 501);
        $server['attributes']['feature_limits']['allocations'] = 2;

        Http::fake([
            '*' => Http::response(['data' => [$server]]),
        ]);

        $inventory = $this->inventory->serversForNodes([10])[10][0];

        $this->assertSame(2, $inventory['allocation_limit']);
        $this->assertSame([501], $inventory['assigned_allocation_ids']);
        $this->assertSame(1, $inventory['allocation_headroom']);
    }

    public function test_missing_server_allocation_relationship_fails_inventory_closed(): void
    {
        $server = $this->serverResource(44, 10, 4096, 200, 20480, 501);
        unset($server['relationships']);

        Http::fake([
            '*' => Http::response(['data' => [$server]]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('allocations relationship');

        $this->inventory->serversForNodes([10]);
    }

    public function test_missing_server_identity_fails_snapshot_proof_closed(): void
    {
        $server = $this->serverResource(44, 10, 4096, 200, 20480, 501);
        unset($server['attributes']['uuid']);

        Http::fake([
            '*' => Http::response(['data' => [$server]]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('server.uuid');

        $this->inventory->serversForNodes([10]);
    }

    public function test_connection_check_exercises_nodes_servers_and_allocations_permissions(): void
    {
        $paths = [];
        $nodeIncludes = [];

        Http::fake(function ($request) use (&$paths, &$nodeIncludes) {
            $paths[] = parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/nodes')) {
                $query = [];
                parse_str(
                    parse_url($request->url(), PHP_URL_QUERY) ?? '',
                    $query
                );
                $nodeIncludes[] = $query['include'] ?? null;

                return Http::response(['data' => [$this->nodeResource(10, 1)]]);
            }
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/servers')) {
                return Http::response(['data' => []]);
            }
            if (str_contains($request->url(), '/nodes/10/allocations')) {
                return Http::response(['data' => [$this->allocationResource(502, false)]]);
            }

            return Http::response([], 404);
        });

        $result = $this->inventory->testConnection();

        $this->assertTrue($result['success']);
        $this->assertContains('/api/application/nodes', $paths);
        $this->assertContains('/api/application/servers', $paths);
        $this->assertSame(['allocations'], $nodeIncludes);
        $this->assertNotContains(
            '/api/application/nodes/10/allocations',
            $paths,
            'The included allocation snapshot must avoid an N+1 node scan.'
        );
    }

    public function test_legacy_allocation_only_acknowledgement_is_not_a_capacity_guarantee(): void
    {
        $inventory = new PterodactylInventoryService([
            'pterodactyl_url' => 'https://panel.example.com',
            'pterodactyl_api_key' => 'application-key',
            'exclusive_allocation_pool' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exclusive provisioning control');

        $inventory->assertExclusiveProvisioningControl();
    }

    private function nodeResource(int $id, int $locationId): array
    {
        return [
            'object' => 'node',
            'attributes' => [
                'id' => $id,
                'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
                'name' => 'Node '.$id,
                'fqdn' => 'node-'.$id.'.example.com',
                'public' => true,
                'maintenance_mode' => false,
                'location_id' => $locationId,
                'memory' => 32768,
                'disk' => 512000,
                'memory_overallocate' => 0,
                'disk_overallocate' => 0,
                'allocated_resources' => [
                    'memory' => 1024,
                    'disk' => 2048,
                ],
            ],
            'relationships' => [
                'allocations' => [
                    'data' => [
                        $this->allocationResource(
                            ($id * 1000) + 1,
                            false
                        ),
                    ],
                ],
            ],
        ];
    }

    private function serverResource(
        int $id,
        int $node,
        int $memory,
        int $cpu,
        int $disk,
        int $allocation
    ): array {
        return [
            'object' => 'server',
            'attributes' => [
                'id' => $id,
                'uuid' => sprintf(
                    '10000000-0000-4000-8000-%012d',
                    $id
                ),
                'identifier' => 'server-'.$id,
                'external_id' => (string) $id,
                'user' => 44,
                'nest' => 1,
                'egg' => 2,
                'node' => $node,
                'allocation' => $allocation,
                'feature_limits' => [
                    'databases' => 2,
                    'allocations' => 1,
                    'backups' => 3,
                ],
                'limits' => [
                    'memory' => $memory,
                    'cpu' => $cpu,
                    'disk' => $disk,
                    'swap' => 0,
                    'io' => 500,
                    'threads' => null,
                ],
            ],
            'relationships' => [
                'allocations' => [
                    'data' => [[
                        'object' => 'allocation',
                        'attributes' => [
                            'id' => $allocation,
                        ],
                    ]],
                ],
            ],
        ];
    }

    private function allocationResource(int $id, bool $assigned): array
    {
        return [
            'object' => 'allocation',
            'attributes' => [
                'id' => $id,
                'ip' => '192.0.2.10',
                'port' => 25000 + $id,
                'assigned' => $assigned,
            ],
        ];
    }
}
