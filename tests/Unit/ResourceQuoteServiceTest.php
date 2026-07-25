<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\Product;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\StockUnavailableException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AllocationSelectionService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\NodeSelectionService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ProductResourceConfigurationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceQuoteService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\TestCase;

class ResourceQuoteServiceTest extends TestCase
{
    public function test_32_gb_product_cap_is_clamped_to_23_gb_live_stock(): void
    {
        $quote = $this->quote(
            requested: ['memory' => 32768, 'cpu' => 200, 'disk' => 51200],
            nodes: [$this->node(1, 23552, 800, 512000)]
        );

        $this->assertTrue($quote['adjusted']);
        $this->assertSame(23552, $quote['selection']['memory']);
        $this->assertSame(23552, $quote['bounds']['memory']['max']);
        $this->assertSame(10, $quote['bounds']['memory']['config_option_id']);
    }

    public function test_live_stock_above_product_cap_keeps_32_gb_maximum(): void
    {
        $quote = $this->quote(
            requested: ['memory' => 32768, 'cpu' => 200, 'disk' => 51200],
            nodes: [$this->node(1, 102400, 800, 512000)]
        );

        $this->assertFalse($quote['adjusted']);
        $this->assertSame(32768, $quote['selection']['memory']);
        $this->assertSame(32768, $quote['bounds']['memory']['max']);
    }

    public function test_live_bound_is_rounded_down_to_configured_step(): void
    {
        $quote = $this->quote(
            requested: ['memory' => 32768, 'cpu' => 200, 'disk' => 51200],
            nodes: [$this->node(1, 23000, 800, 512000)]
        );

        $this->assertSame(22528, $quote['selection']['memory']);
        $this->assertSame(22528, $quote['bounds']['memory']['max']);
    }

    public function test_bounds_never_combine_independent_maxima_from_different_nodes(): void
    {
        $quote = $this->quote(
            requested: ['memory' => 32768, 'cpu' => 200, 'disk' => 102400],
            nodes: [
                $this->node(1, 32768, 800, 51200),
                $this->node(2, 16384, 800, 102400),
            ]
        );

        $this->assertTrue($quote['adjusted']);
        $this->assertSame([
            'memory' => 32768,
            'cpu' => 200,
            'disk' => 51200,
        ], $quote['selection']);
        $this->assertSame(32768, $quote['bounds']['memory']['max']);
        $this->assertSame(
            51200,
            $quote['bounds']['disk']['max'],
            'Disk max must be conditional on the selected 32 GB RAM.'
        );
    }

    public function test_port_count_is_part_of_node_feasibility(): void
    {
        $configuration = $this->configuration();
        $configuration['allocation_count'] = 2;

        $this->expectException(StockUnavailableException::class);

        $this->runQuote($configuration, [
            $this->node(1, 32768, 800, 512000, allocationCount: 1),
        ]);
    }

    public function test_explicit_product_port_is_part_of_quote_feasibility(): void
    {
        $configuration = $this->configuration();
        $configuration['required_ports'] = [25570];

        $this->expectException(StockUnavailableException::class);

        $this->runQuote($configuration, [
            $this->node(1, 32768, 800, 512000),
        ]);
    }

    public function test_quote_and_reservation_share_deterministic_multi_ip_port_selection(): void
    {
        $configuration = $this->configuration();
        $configuration['required_ports'] = [25570];
        $node = $this->node(1, 32768, 800, 512000, allocationCount: 2);
        $node['available_allocations'] = [
            ['id' => 100, 'ip' => '192.0.2.1', 'port' => 25570],
            ['id' => 101, 'ip' => '192.0.2.2', 'port' => 25570],
        ];

        $product = new Product();
        $product->id = 99;
        $configurations = Mockery::mock(ProductResourceConfigurationService::class);
        $configurations->shouldReceive('forQuote')
            ->once()
            ->with($product, [])
            ->andReturn($configuration);
        $resources = Mockery::mock(ResourceCalculationService::class);
        $resources->shouldReceive('getLocationAvailability')
            ->twice()
            ->with(1, null)
            ->andReturn(['nodes' => [$node]]);
        $allocations = new AllocationSelectionService();

        $quote = (new ResourceQuoteService(
            $configurations,
            $resources,
            $allocations
        ))->quote($product, []);
        $selectedNode = (new NodeSelectionService($resources, $allocations))
            ->selectBestNodeWithAllocations(
                1,
                $configuration['resources'],
                $configuration['allocation_count'],
                null,
                $configuration['required_ports']
            );

        $this->assertTrue($quote['available']);
        $this->assertSame(
            [100],
            array_column($selectedNode['selected_allocations'], 'id'),
            'Reservation placement must use the same deterministic set accepted by the quote.'
        );
    }

    public function test_customer_quote_contains_no_node_identity(): void
    {
        $quote = $this->quote(
            requested: ['memory' => 4096, 'cpu' => 200, 'disk' => 51200],
            nodes: [$this->node(55, 32768, 800, 512000)]
        );
        $encoded = json_encode($quote, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('node_id', $encoded);
        $this->assertStringNotContainsString('fqdn', $encoded);
        $this->assertStringNotContainsString('available_allocations', $encoded);
    }

    #[DataProvider('unboundedInventoryReasons')]
    public function test_unbounded_inventory_is_configuration_failure(string $reason): void
    {
        $configuration = $this->configuration();
        $product = new Product();
        $product->id = 99;
        $configurations = Mockery::mock(ProductResourceConfigurationService::class);
        $configurations->shouldReceive('forQuote')->andReturn($configuration);
        $resources = Mockery::mock(ResourceCalculationService::class);
        $resources->shouldReceive('getLocationAvailability')->andReturn([
            'nodes' => [[
                'eligible' => false,
                'ineligible_reasons' => [$reason],
                'available_allocations' => [],
            ]],
        ]);

        $this->expectException(InvalidStockConfigurationException::class);

        (new ResourceQuoteService(
            $configurations,
            $resources,
            new AllocationSelectionService()
        ))
            ->quote($product, []);
    }

    public static function unboundedInventoryReasons(): array
    {
        return [
            'missing CPU policy' => ['cpu_policy_missing'],
            'unbounded existing server' => ['unlimited_existing_resource'],
            'unbounded node memory' => ['unbounded_memory_overallocation'],
            'unbounded node disk' => ['unbounded_disk_overallocation'],
        ];
    }

    private function quote(array $requested, array $nodes): array
    {
        $configuration = $this->configuration();
        $configuration['resources'] = $requested;

        return $this->runQuote($configuration, $nodes);
    }

    private function runQuote(array $configuration, array $nodes): array
    {
        $product = new Product();
        $product->id = 99;
        $configurations = Mockery::mock(ProductResourceConfigurationService::class);
        $configurations->shouldReceive('forQuote')
            ->once()
            ->with($product, [])
            ->andReturn($configuration);
        $resources = Mockery::mock(ResourceCalculationService::class);
        $resources->shouldReceive('getLocationAvailability')
            ->once()
            ->with(1, null)
            ->andReturn(['nodes' => $nodes]);

        return (new ResourceQuoteService(
            $configurations,
            $resources,
            new AllocationSelectionService()
        ))
            ->quote($product, []);
    }

    private function configuration(): array
    {
        return [
            'product_id' => 99,
            'location_id' => 1,
            'resources' => [
                'memory' => 32768,
                'cpu' => 200,
                'disk' => 51200,
            ],
            'sliders' => [
                'memory' => [
                    'config_option_id' => 10,
                    'min' => 1024,
                    'max' => 32768,
                    'step' => 1024,
                    'default' => 4096,
                ],
                'cpu' => [
                    'config_option_id' => 11,
                    'min' => 100,
                    'max' => 800,
                    'step' => 100,
                    'default' => 200,
                ],
                'disk' => [
                    'config_option_id' => 12,
                    'min' => 10240,
                    'max' => 512000,
                    'step' => 10240,
                    'default' => 51200,
                ],
            ],
            'allocation_count' => 1,
            'required_ports' => [],
            'allocation_mappings' => [[
                'environment_key' => 'SERVER_PORT',
                'requested_port' => null,
                'is_primary' => true,
            ]],
        ];
    }

    private function node(
        int $nodeId,
        int $memory,
        int $cpu,
        int $disk,
        int $allocationCount = 1
    ): array {
        return [
            'node_id' => $nodeId,
            'eligible' => true,
            'available' => [
                'memory' => $memory,
                'cpu' => $cpu,
                'disk' => $disk,
            ],
            'total' => [
                'memory' => $memory,
                'cpu' => $cpu,
                'disk' => $disk,
            ],
            'available_allocations' => $allocationCount === 0
                ? []
                : array_map(
                    fn (int $offset): array => [
                        'id' => ($nodeId * 100) + $offset,
                        'ip' => '192.0.2.'.$nodeId,
                        'port' => 25565 + $offset,
                    ],
                    range(1, $allocationCount)
                ),
        ];
    }
}
