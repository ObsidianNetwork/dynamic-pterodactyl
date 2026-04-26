<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class ResourceCalculationServiceTest extends LaravelTestCase
{
    use DatabaseTransactions;

    private ResourceCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('settings.debug', false);
        Http::preventStrayRequests();

        $reflection = new \ReflectionClass(ResourceCalculationService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();

        foreach (['apiUrl' => 'https://panel.example.com', 'apiKey' => 'test-api-key'] as $property => $value) {
            $propertyReflection = $reflection->getProperty($property);
            $propertyReflection->setAccessible(true);
            $propertyReflection->setValue($this->service, $value);
        }
    }

    public function test_get_locations_returns_parsed_array(): void
    {
        Http::fake([
            'panel.example.com/*' => Http::response([
                'data' => [
                    ['attributes' => ['id' => 1, 'short' => 'us', 'long' => 'US East']],
                ],
            ], 200),
        ]);

        $result = $this->service->getLocations();

        Http::assertSentCount(1);
        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['id']);
        $this->assertEquals('us', $result[0]['short']);
    }

    public function test_429_throws_runtime_exception_with_rate_limit_message(): void
    {
        Http::fake([
            'panel.example.com/*' => Http::response([], 429),
        ]);

        try {
            $this->service->getLocations();
            $this->fail('Expected RuntimeException for 429 response');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/rate limit/i', $e->getMessage());
        } finally {
            Http::assertSentCount(1); // 429 must NOT retry
        }
    }

    public function test_500_throws_sanitized_runtime_exception(): void
    {
        Http::fake([
            'panel.example.com/*' => Http::response(['error' => 'panel down'], 500),
        ]);

        try {
            $this->service->getLocations();
            $this->fail('Expected RuntimeException for 500 response');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/500/', $e->getMessage());
            $this->assertStringNotContainsString('panel down', $e->getMessage());
        } finally {
            Http::assertSentCount(1); // 500 must NOT retry
        }
    }

    public function test_connection_exception_retries_and_throws(): void
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new \Illuminate\Http\Client\ConnectionException('timed out');
        });

        $this->expectException(\RuntimeException::class);

        try {
            $this->service->getLocations();
        } catch (\RuntimeException $e) {
            $this->assertSame(2, $attempts); // retry(2) = 2 total attempts (1 original + 1 retry)

            throw $e;
        }
    }

    public function test_connection_failure_message_is_sanitized(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 7: Failed to connect to panel-internal-host:8080'
            );
        });

        try {
            $this->service->getLocations();
            $this->fail('Expected RuntimeException on connection failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('connection failed', $e->getMessage());
            $this->assertStringNotContainsString('panel-internal-host', $e->getMessage());
            $this->assertStringNotContainsString('8080', $e->getMessage());
            $this->assertStringNotContainsString('cURL', $e->getMessage());
        }
    }

    public function test_malformed_json_body_throws_runtime_exception(): void
    {
        Http::fake([
            'panel.example.com/*' => Http::response('<html>not json</html>', 200),
        ]);

        try {
            $this->service->getLocations();
            $this->fail('Expected RuntimeException for invalid JSON');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/invalid JSON payload/i', $e->getMessage());
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_get_node_location_throws_when_location_id_missing(): void
    {
        Http::fake([
            'panel.example.com/*' => Http::response([
                'attributes' => [
                    'id' => 5,
                    // location_id intentionally absent
                ],
            ], 200),
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getNodeLocation');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing location_id/');

        $method->invoke($this->service, 5);
    }

    public function test_get_location_availability_excludes_given_reservation_token(): void
    {
        $this->insertPendingReservation('token-a', 5, 1, ['memory' => 2048, 'cpu' => 100, 'disk' => 10240]);
        $this->insertPendingReservation('token-b', 5, 1, ['memory' => 1024, 'cpu' => 50, 'disk' => 5120]);

        Http::fake($this->availabilityHttpFake(nodeId: 5, locationId: 1, totalMemory: 8192, totalDisk: 51200, totalCpuThreads: 4));

        $result = $this->service->getLocationAvailability(1, 'token-a');

        $this->assertSame(['memory' => 1024, 'cpu' => 50, 'disk' => 5120], $result['nodes'][0]['reserved']);
        $this->assertSame(['memory' => 7168, 'cpu' => 350, 'disk' => 46080], $result['nodes'][0]['available']);
    }

    public function test_verify_availability_with_self_exclusion_succeeds_on_edge_fit(): void
    {
        $resources = ['memory' => 4096, 'cpu' => 200, 'disk' => 10240];
        $this->insertPendingReservation('edge-fit', 5, 1, $resources);

        Http::fake($this->availabilityHttpFake(nodeId: 5, locationId: 1, totalMemory: 4096, totalDisk: 10240, totalCpuThreads: 2));

        $this->assertTrue($this->service->verifyAvailability(5, $resources, 'edge-fit'));
    }

    public function test_verify_availability_without_exclusion_fails_on_edge_fit(): void
    {
        $resources = ['memory' => 4096, 'cpu' => 200, 'disk' => 10240];
        $this->insertPendingReservation('edge-fit', 5, 1, $resources);

        Http::fake($this->availabilityHttpFake(nodeId: 5, locationId: 1, totalMemory: 4096, totalDisk: 10240, totalCpuThreads: 2));

        $this->assertFalse($this->service->verifyAvailability(5, $resources));
    }

    public function test_snapshot_with_single_location_single_node(): void
    {
        $calls = 0;

        Http::fake($this->clusterSnapshotHttpFake(
            $calls,
            locations: [
                ['id' => 1, 'short' => 'dc1', 'long' => 'Data Center 1'],
            ],
            nodePages: [[
                $this->nodeWithServersPayload(1, 1, 'Node 1', [
                    ['memory' => 2048, 'cpu' => 100, 'disk' => 10240],
                ]),
            ]],
        ));

        $snapshot = $this->service->buildClusterSnapshot();

        $this->assertArrayNotHasKey('error', $snapshot);
        $this->assertCount(1, $snapshot['locations']);
        $this->assertSame([1], $snapshot['by_location'][1]['nodes']);
        $this->assertSame(['memory' => 8192, 'cpu' => 400, 'disk' => 51200], $snapshot['nodes'][1]['totals']);
        $this->assertSame(['memory' => 2048, 'cpu' => 100, 'disk' => 10240], $snapshot['nodes'][1]['allocated']);
        $this->assertSame(['memory' => 6144, 'cpu' => 300, 'disk' => 40960], $snapshot['nodes'][1]['available']);
        $this->assertSame(['memory' => 8192, 'cpu' => 400, 'disk' => 51200], $snapshot['by_location'][1]['totals']);
        $this->assertLessThanOrEqual(4, $calls);
    }

    public function test_snapshot_aggregates_across_locations(): void
    {
        $calls = 0;

        Http::fake($this->clusterSnapshotHttpFake(
            $calls,
            locations: [
                ['id' => 1, 'short' => 'dc1', 'long' => 'Data Center 1'],
                ['id' => 2, 'short' => 'dc2', 'long' => 'Data Center 2'],
            ],
            nodePages: [[
                $this->nodeWithServersPayload(1, 1, 'Node 1', [['memory' => 1024, 'cpu' => 50, 'disk' => 5120]]),
                $this->nodeWithServersPayload(2, 1, 'Node 2', [['memory' => 2048, 'cpu' => 100, 'disk' => 10240]]),
                $this->nodeWithServersPayload(3, 2, 'Node 3', [['memory' => 3072, 'cpu' => 150, 'disk' => 15360]]),
                $this->nodeWithServersPayload(4, 2, 'Node 4', []),
                $this->nodeWithServersPayload(5, 2, 'Node 5', [['memory' => 1024, 'cpu' => 50, 'disk' => 5120]]),
            ]],
        ));

        $snapshot = $this->service->buildClusterSnapshot();

        $this->assertCount(5, $snapshot['nodes']);
        $this->assertSame([1, 2], $snapshot['by_location'][1]['nodes']);
        $this->assertSame([3, 4, 5], $snapshot['by_location'][2]['nodes']);
        $this->assertSame(['memory' => 16384, 'cpu' => 800, 'disk' => 102400], $snapshot['by_location'][1]['totals']);
        $this->assertSame(['memory' => 3072, 'cpu' => 150, 'disk' => 15360], $snapshot['by_location'][1]['allocated']);
        $this->assertSame(['memory' => 24576, 'cpu' => 1200, 'disk' => 153600], $snapshot['by_location'][2]['totals']);
        $this->assertSame(['memory' => 4096, 'cpu' => 200, 'disk' => 20480], $snapshot['by_location'][2]['allocated']);
        $this->assertLessThanOrEqual(4, $calls);
    }

    public function test_snapshot_handles_paginated_node_response(): void
    {
        $calls = 0;

        Http::fake($this->clusterSnapshotHttpFake(
            $calls,
            locations: [
                ['id' => 1, 'short' => 'dc1', 'long' => 'Data Center 1'],
            ],
            nodePages: [
                [
                    $this->nodeWithServersPayload(1, 1, 'Node 1', []),
                    $this->nodeWithServersPayload(2, 1, 'Node 2', []),
                ],
                [
                    $this->nodeWithServersPayload(3, 1, 'Node 3', []),
                ],
            ],
        ));

        $snapshot = $this->service->buildClusterSnapshot();

        $this->assertSame([1, 2, 3], $snapshot['by_location'][1]['nodes']);
        $this->assertCount(3, $snapshot['nodes']);
        $this->assertLessThanOrEqual(4, $calls);
    }

    public function test_snapshot_handles_pterodactyl_5xx_gracefully(): void
    {
        $calls = 0;

        Http::fake(function ($request) use (&$calls) {
            $calls++;

            if (str_contains($request->url(), '/api/application/locations')) {
                return Http::response([
                    'data' => [
                        ['attributes' => ['id' => 1, 'short' => 'dc1', 'long' => 'Data Center 1']],
                    ],
                ], 200);
            }

            return Http::response(['errors' => [['detail' => 'panel down']]], 500);
        });

        $snapshot = $this->service->buildClusterSnapshot();

        $this->assertSame('Pterodactyl unavailable', $snapshot['error']);
        $this->assertSame([], $snapshot['nodes']);
        $this->assertSame([], $snapshot['by_location']);
        $this->assertLessThanOrEqual(4, $calls);
    }

    public function test_snapshot_keeps_pterodactyl_call_count_constant(): void
    {
        $calls = 0;
        $nodes = [];

        for ($nodeId = 1; $nodeId <= 55; $nodeId++) {
            $nodes[] = $this->nodeWithServersPayload($nodeId, ($nodeId % 5) + 1, 'Node '.$nodeId, []);
        }

        Http::fake($this->clusterSnapshotHttpFake(
            $calls,
            locations: [
                ['id' => 1, 'short' => 'dc1', 'long' => 'Data Center 1'],
                ['id' => 2, 'short' => 'dc2', 'long' => 'Data Center 2'],
                ['id' => 3, 'short' => 'dc3', 'long' => 'Data Center 3'],
                ['id' => 4, 'short' => 'dc4', 'long' => 'Data Center 4'],
                ['id' => 5, 'short' => 'dc5', 'long' => 'Data Center 5'],
            ],
            nodePages: [array_slice($nodes, 0, 50), array_slice($nodes, 50)],
        ));

        $snapshot = $this->service->buildClusterSnapshot();

        $this->assertCount(55, $snapshot['nodes']);
        $this->assertLessThanOrEqual(4, $calls);
    }

    private function insertPendingReservation(string $token, int $nodeId, int $locationId, array $resources): void
    {
        DB::table('ptero_resource_reservations')->insert([
            'token' => $token,
            'node_id' => $nodeId,
            'location_id' => $locationId,
            'memory' => $resources['memory'],
            'cpu' => $resources['cpu'],
            'disk' => $resources['disk'],
            'calculated_price' => 9.99,
            'pricing_breakdown' => json_encode([]),
            'status' => 'pending',
            'expires_at' => \now()->addMinutes(15),
            'created_at' => \now(),
            'updated_at' => \now(),
        ]);
    }

    private function availabilityHttpFake(int $nodeId, int $locationId, int $totalMemory, int $totalDisk, int $totalCpuThreads): callable
    {
        return function ($request) use ($nodeId, $locationId, $totalMemory, $totalDisk, $totalCpuThreads) {
            $url = $request->url();

            if (str_contains($url, '/api/application/nodes?')) {
                return Http::response([
                    'data' => [[
                        'attributes' => [
                            'id' => $nodeId,
                            'location_id' => $locationId,
                            'name' => 'Node '.$nodeId,
                            'fqdn' => 'node-'.$nodeId.'.example.com',
                            'memory' => $totalMemory,
                            'disk' => $totalDisk,
                            'cpu_threads' => $totalCpuThreads,
                            'memory_overallocate' => 0,
                            'disk_overallocate' => 0,
                            'maintenance_mode' => false,
                        ],
                    ]],
                ], 200);
            }

            if (str_contains($url, "/api/application/nodes/{$nodeId}?include=servers")) {
                return Http::response([
                    'attributes' => [
                        'relationships' => [
                            'servers' => [
                                'data' => [],
                            ],
                        ],
                    ],
                ], 200);
            }

            if (str_ends_with($url, "/api/application/nodes/{$nodeId}")) {
                return Http::response([
                    'attributes' => [
                        'id' => $nodeId,
                        'location_id' => $locationId,
                    ],
                ], 200);
            }

            return Http::response([], 404);
        };
    }

    private function clusterSnapshotHttpFake(int &$calls, array $locations, array $nodePages): callable
    {
        return function ($request) use (&$calls, $locations, $nodePages) {
            $calls++;

            $url = $request->url();
            $query = [];
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

            if (str_contains($url, '/api/application/locations')) {
                return Http::response([
                    'data' => array_map(fn (array $location) => ['attributes' => $location], $locations),
                    'meta' => [
                        'pagination' => [
                            'current_page' => 1,
                            'total_pages' => 1,
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, '/api/application/nodes')) {
                $page = (int) ($query['page'] ?? 1);

                return Http::response([
                    'data' => $nodePages[$page - 1] ?? [],
                    'meta' => [
                        'pagination' => [
                            'current_page' => $page,
                            'total_pages' => count($nodePages),
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 404);
        };
    }

    private function nodeWithServersPayload(int $nodeId, int $locationId, string $name, array $servers): array
    {
        return [
            'attributes' => [
                'id' => $nodeId,
                'location_id' => $locationId,
                'name' => $name,
                'fqdn' => 'node-'.$nodeId.'.example.com',
                'memory' => 8192,
                'disk' => 51200,
                'cpu_threads' => 4,
                'memory_overallocate' => 0,
                'disk_overallocate' => 0,
                'maintenance_mode' => false,
                'relationships' => [
                    'servers' => [
                        'data' => array_map(fn (array $server, int $index) => [
                            'attributes' => [
                                'id' => ($nodeId * 100) + $index,
                                'node' => $nodeId,
                                'limits' => $server,
                            ],
                        ], $servers, array_keys($servers)),
                    ],
                ],
            ],
        ];
    }
}
