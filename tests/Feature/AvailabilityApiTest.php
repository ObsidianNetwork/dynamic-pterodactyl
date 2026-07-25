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

    public function test_cpu_is_explicitly_not_enforced_as_capacity(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->bindAvailabilityServices([
            'memory' => 1000,
            'cpu' => 0,
            'disk' => 1000,
        ]);

        $response = $this->actingAs($user)
            ->withSession($this->loginUser($user))
            ->getJson('/api/dynamic-pterodactyl/availability/1');

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'has_capacity' => true,
                'resource_capacity' => [
                    'memory' => true,
                    'cpu' => null,
                    'disk' => true,
                ],
                'cpu_capacity_enforced' => false,
            ],
        ]);
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

        $response = $this->actingAs($user)
            ->withSession($this->loginUser($user))
            ->getJson('/api/dynamic-pterodactyl/availability/1');

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'has_capacity' => true,
                'resource_capacity' => [
                    'memory' => true,
                    'cpu' => null,
                    'disk' => true,
                ],
                'cpu_capacity_enforced' => false,
            ],
        ]);
    }

    private function bindAvailabilityServices(array $maxAvailable): void
    {
        $nodeService = Mockery::mock(NodeSelectionService::class);
        $nodeService->shouldReceive('getMaxAvailable')
            ->once()
            ->with(1)
            ->andReturn($maxAvailable);
        $this->app->instance(NodeSelectionService::class, $nodeService);

        $resourceService = Mockery::mock(ResourceCalculationService::class);
        $resourceService->shouldReceive('getLocationAvailability')
            ->once()
            ->with(1)
            ->andReturn([
                'location_id' => 1,
                'nodes' => [['node_id' => 1, 'name' => 'Node 1']],
            ]);
        $this->app->instance(ResourceCalculationService::class, $resourceService);
    }
}
