<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\AlertConfigResource;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class AlertConfigResourceTest extends LaravelTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_location_select_preserves_panel_location_ids(): void
    {
        $resources = Mockery::mock(ResourceCalculationService::class);
        $resources->shouldReceive('getLocations')
            ->once()
            ->andReturn([
                ['id' => 7, 'long' => 'Sydney'],
                ['id' => 42, 'long' => 'Melbourne'],
            ]);
        $this->app->instance(ResourceCalculationService::class, $resources);

        $method = new \ReflectionMethod(
            AlertConfigResource::class,
            'getScopedLocationOptions'
        );
        $method->setAccessible(true);
        $options = $method->invoke(null);

        $this->assertSame([
            '' => 'Global (All Locations)',
            7 => 'Sydney',
            42 => 'Melbourne',
        ], $options);
    }
}
