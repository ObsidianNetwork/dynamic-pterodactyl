<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\ConfigOption;
use Illuminate\Support\Collection;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\PricingCalculatorService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class PricingCalculatorServiceTest extends LaravelTestCase
{
    private PricingCalculatorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PricingCalculatorService();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test linear pricing calculates correctly with all components.
     */
    public function test_linear_pricing_calculates_correctly(): void
    {
        $this->mockConfigOptions([
            $this->createConfigOption('memory', 'linear', ['rate_per_unit' => 2.00]),
            $this->createConfigOption('cpu', 'linear', ['rate_per_unit' => 3.00]),
            $this->createConfigOption('disk', 'linear', ['rate_per_unit' => 0.10]),
        ]);

        $result = $this->service->calculate(1, [
            'memory' => 4096,  // 4GB
            'cpu' => 200,      // 2 cores
            'disk' => 51200,   // 50GB
        ]);

        // (4 * 2) + (2 * 3) + (50 * 0.1) = 8 + 6 + 5 = 19
        $this->assertEquals(19.00, $result['total']);
        $this->assertEquals('linear', $result['model']);
        $this->assertCount(3, $result['breakdown']);
    }

    /**
     * Test linear pricing with only memory.
     */
    public function test_linear_pricing_memory_only(): void
    {
        $this->mockConfigOptions([
            $this->createConfigOption('memory', 'linear', ['rate_per_unit' => 1.00]),
        ]);

        $result = $this->service->calculate(1, [
            'memory' => 2048,  // 2GB
            'cpu' => 0,
            'disk' => 0,
        ]);

        // 2 * 1 = 2
        $this->assertEquals(2.00, $result['total']);
    }

    /**
     * Test tiered pricing with a single tier.
     */
    public function test_tiered_pricing_single_tier(): void
    {
        $this->mockConfigOptions([
            $this->createConfigOption('memory', 'tiered', [
                'tiers' => [
                    ['up_to' => 100, 'rate' => 1.00],
                ],
            ]),
        ]);

        $result = $this->service->calculate(1, [
            'memory' => 8192,  // 8GB
            'cpu' => 0,
            'disk' => 0,
        ]);

        // 8GB * $1/GB = $8
        $this->assertEquals(8.00, $result['total']);
        $this->assertEquals('tiered', $result['model']);
    }

    /**
     * Test tiered pricing with multiple tiers (volume discount).
     */
    public function test_tiered_pricing_multiple_tiers(): void
    {
        $this->mockConfigOptions([
            $this->createConfigOption('memory', 'tiered', [
                'tiers' => [
                    ['up_to' => 4, 'rate' => 2.00],   // First 4GB at $2
                    ['up_to' => 8, 'rate' => 1.50],   // Next 4GB at $1.50
                    ['up_to' => 16, 'rate' => 1.00],  // Rest at $1
                ],
            ]),
        ]);

        $result = $this->service->calculate(1, [
            'memory' => 10240,  // 10GB
            'cpu' => 0,
            'disk' => 0,
        ]);

        // (4 * 2) + (4 * 1.5) + (2 * 1) = 8 + 6 + 2 = 16
        $this->assertEquals(16.00, $result['total']);
    }

    /**
     * Test tiered pricing when amount exceeds all defined tiers.
     */
    public function test_tiered_pricing_exceeds_all_tiers(): void
    {
        $this->mockConfigOptions([
            $this->createConfigOption('memory', 'tiered', [
                'tiers' => [
                    ['up_to' => 4, 'rate' => 2.00],
                    ['up_to' => 8, 'rate' => 1.00],
                ],
            ]),
        ]);

        // Requesting 16GB, but tiers only go up to 8GB
        $result = $this->service->calculate(1, [
            'memory' => 16384,  // 16GB
            'cpu' => 0,
            'disk' => 0,
        ]);

        // Only charges up to 8GB: (4 * 2) + (4 * 1) = 8 + 4 = 12
        $this->assertEquals(12.00, $result['total']);
    }

    /**
     * Test base+addon pricing with no overage.
     */
    public function test_base_plus_addon_no_overage(): void
    {
        $this->mockConfigOptions([
            $this->createConfigOption('memory', 'base_addon', [
                'included_units' => 4,  // 4GB included
                'overage_rate' => 2.00,
            ]),
            $this->createConfigOption('cpu', 'base_addon', [
                'included_units' => 2,  // 2 cores included
                'overage_rate' => 3.00,
            ]),
            $this->createConfigOption('disk', 'base_addon', [
                'included_units' => 50,  // 50GB included
                'overage_rate' => 0.10,
            ]),
        ]);

        $result = $this->service->calculate(1, [
            'memory' => 4096,   // 4GB (included)
            'cpu' => 200,       // 2 cores (included)
            'disk' => 51200,    // 50GB (included)
        ]);

        // No overage, all within included amounts
        $this->assertEquals(0.00, $result['total']);
        $this->assertEquals('base_addon', $result['model']);
    }

    /**
     * Test base+addon pricing with memory overage only.
     */
    public function test_base_plus_addon_with_memory_overage(): void
    {
        $this->mockConfigOptions([
            $this->createConfigOption('memory', 'base_addon', [
                'included_units' => 4,
                'overage_rate' => 2.00,
            ]),
        ]);

        $result = $this->service->calculate(1, [
            'memory' => 8192,   // 8GB (4GB extra)
            'cpu' => 0,
            'disk' => 0,
        ]);

        // (8 - 4) * 2 = 4 * 2 = 8
        $this->assertEquals(8.00, $result['total']);
    }

    /**
     * Test base+addon pricing with all resource overages.
     */
    public function test_base_plus_addon_with_all_overage(): void
    {
        $this->mockConfigOptions([
            $this->createConfigOption('memory', 'base_addon', [
                'included_units' => 4,
                'overage_rate' => 2.00,
            ]),
            $this->createConfigOption('cpu', 'base_addon', [
                'included_units' => 2,
                'overage_rate' => 5.00,
            ]),
            $this->createConfigOption('disk', 'base_addon', [
                'included_units' => 50,
                'overage_rate' => 0.10,
            ]),
        ]);

        $result = $this->service->calculate(1, [
            'memory' => 8192,   // 8GB (4GB extra)
            'cpu' => 400,       // 4 cores (2 extra)
            'disk' => 102400,   // 100GB (50GB extra)
        ]);

        // Memory: (8 - 4) * 2 = 8
        // CPU: (4 - 2) * 5 = 10
        // Disk: (100 - 50) * 0.1 = 5
        // Total = 8 + 10 + 5 = 23
        $this->assertEquals(23.00, $result['total']);
    }

    /**
     * Test that no config options returns zero total.
     */
    public function test_returns_zero_for_no_config_options(): void
    {
        $this->mockConfigOptions([]);

        $result = $this->service->calculate(1, $this->standardResources());

        $this->assertEquals(0, $result['total']);
        $this->assertEquals('none', $result['model']);
        $this->assertEmpty($result['breakdown']);
    }

    /**
     * Test getConfig returns proper slider configuration.
     */
    public function test_get_config_returns_slider_info(): void
    {
        $this->mockConfigOptions([
            $this->createConfigOption('memory', 'linear', ['rate_per_unit' => 2.00]),
            $this->createConfigOption('cpu', 'linear', ['rate_per_unit' => 3.00]),
        ]);

        $config = $this->service->getConfig(1);

        $this->assertTrue($config['has_config']);
        $this->assertArrayHasKey('memory', $config['sliders']);
        $this->assertArrayHasKey('cpu', $config['sliders']);
        $this->assertEquals(1024, $config['sliders']['memory']['min']);
        $this->assertEquals('GB', $config['sliders']['memory']['display_unit']);
    }

    /**
     * Mock ConfigOption query to return specified options.
     */
    private function mockConfigOptions(array $options): void
    {
        $collection = new Collection($options);

        // Mock the ConfigOption model's whereHas chain
        $mock = Mockery::mock('overload:' . ConfigOption::class);

        $mock->shouldReceive('whereHas')
            ->andReturnSelf();

        $mock->shouldReceive('where')
            ->andReturnSelf();

        $mock->shouldReceive('whereNull')
            ->andReturnSelf();

        $mock->shouldReceive('get')
            ->andReturn($collection);
    }
}
