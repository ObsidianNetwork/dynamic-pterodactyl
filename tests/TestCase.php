<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create a mock pricing config object
     */
    protected function createPricingConfig(string $model, array $config): object
    {
        return (object) [
            'id' => 1,
            'product_id' => 1,
            'pricing_model' => $model,
            'pricing_config' => json_encode($config),
            'is_active' => true,
        ];
    }

    /**
     * Create a mock node data array
     */
    protected function createNodeData(
        int $nodeId,
        string $name,
        array $total,
        array $available,
        bool $maintenance = false
    ): array {
        return [
            'node_id' => $nodeId,
            'name' => $name,
            'maintenance_mode' => $maintenance,
            'eligible' => ! $maintenance,
            'total' => $total,
            'available' => $available,
            'available_allocations' => [[
                'id' => ($nodeId * 1000) + 1,
                'ip' => '192.0.2.'.$nodeId,
                'port' => 25565,
            ]],
        ];
    }

    /**
     * Standard resource requirements for testing
     */
    protected function standardResources(): array
    {
        return [
            'memory' => 4096,  // 4GB
            'cpu' => 200,     // 2 cores
            'disk' => 51200,  // 50GB
        ];
    }
}
