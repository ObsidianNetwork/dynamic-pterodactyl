<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
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
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
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
                            'name' => 'Node ' . $nodeId,
                            'fqdn' => 'node-' . $nodeId . '.example.com',
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
}
