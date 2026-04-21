<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class ResourceCalculationServiceTest extends LaravelTestCase
{
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
}
