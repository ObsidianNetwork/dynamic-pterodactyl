<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\NodeSelectionService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class AvailabilityApiTest extends LaravelTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        require __DIR__ . '/../../routes/api.php';
    }

    public function test_has_capacity_false_when_cpu_exhausted_but_memory_positive(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->bindAvailabilityServices([
            'memory' => 1000,
            'cpu' => 0,
            'disk' => 1000,
        ]);

        $response = $this->actingAs($user)->getJson('/api/dynamic-pterodactyl/availability/1');

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'has_capacity' => false,
                'resource_capacity' => [
                    'memory' => true,
                    'cpu' => false,
                    'disk' => true,
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('nodes', $response->json('data'),
            'Customer availability response must not expose node-level data');
    }

    public function test_has_capacity_true_when_all_resources_positive(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->bindAvailabilityServices([
            'memory' => 1000,
            'cpu' => 100,
            'disk' => 1000,
        ]);

        $response = $this->actingAs($user)->getJson('/api/dynamic-pterodactyl/availability/1');

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'has_capacity' => true,
                'resource_capacity' => [
                    'memory' => true,
                    'cpu' => true,
                    'disk' => true,
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('nodes', $response->json('data'),
            'Customer availability response must not expose node-level data');
    }

    public function test_customer_failure_response_does_not_expose_internal_exception(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $resourceService = Mockery::mock(ResourceCalculationService::class);
        $resourceService->shouldReceive('getLocationAvailability')
            ->once()
            ->with(1)
            ->andThrow(new \RuntimeException('panel-internal-host:8080 failed'));
        $this->app->instance(ResourceCalculationService::class, $resourceService);

        $nodeService = Mockery::mock(NodeSelectionService::class);
        $nodeService->shouldNotReceive('getMaxAvailable');
        $this->app->instance(NodeSelectionService::class, $nodeService);

        $response = $this->actingAs($user)->getJson('/api/dynamic-pterodactyl/availability/1');

        $response->assertStatus(500)->assertExactJson([
            'success' => false,
            'message' => 'Failed to fetch availability',
        ]);
        $this->assertStringNotContainsString('panel-internal-host', $response->getContent());
    }

    private function bindAvailabilityServices(array $maxAvailable): void
    {
        $locationData = [
            'location_id' => 1,
            'nodes' => [['node_id' => 1, 'name' => 'Node 1']],
            'max_available' => $maxAvailable,
        ];

        $nodeService = Mockery::mock(NodeSelectionService::class);
        $nodeService->shouldReceive('getMaxAvailable')
            ->once()
            ->with(1, $locationData)
            ->andReturn($maxAvailable);
        $this->app->instance(NodeSelectionService::class, $nodeService);

        $resourceService = Mockery::mock(ResourceCalculationService::class);
        $resourceService->shouldReceive('getLocationAvailability')
            ->once()
            ->with(1)
            ->andReturn($locationData);
        $this->app->instance(ResourceCalculationService::class, $resourceService);
    }
}
