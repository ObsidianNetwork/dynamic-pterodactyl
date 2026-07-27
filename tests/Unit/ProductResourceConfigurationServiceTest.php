<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\ConfigOption;
use App\Models\Product;
use App\Models\Server;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidResourceSelectionException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ProductResourceConfigurationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\PterodactylInventoryService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class ProductResourceConfigurationServiceTest extends LaravelTestCase
{
    use DatabaseTransactions;

    public function test_resolves_complete_strict_product_vector_and_location(): void
    {
        [$product, $options, $locationChild] = $this->dynamicProduct();
        $configuration = $this->service()->forQuote($product, [
            $options['memory']->id => 4096,
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $locationChild->id,
        ]);

        $this->assertSame(1, $configuration['location_id']);
        $this->assertSame([
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
        ], $configuration['resources']);
        $this->assertSame($options['memory']->id, $configuration['sliders']['memory']['config_option_id']);
        $this->assertSame(1, $configuration['allocation_count']);
        $this->assertSame([], $configuration['required_ports']);
        $this->assertSame([[
            'environment_key' => 'SERVER_PORT',
            'requested_port' => null,
            'is_primary' => true,
        ]], $configuration['allocation_mappings']);
    }

    public function test_decimal_and_off_step_values_are_rejected(): void
    {
        [$product, $options, $locationChild] = $this->dynamicProduct();
        $base = [
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $locationChild->id,
        ];

        foreach (['2048.5', 1536] as $forgedValue) {
            try {
                $this->service()->forQuote(
                    $product,
                    [$options['memory']->id => $forgedValue] + $base
                );
                $this->fail('Expected forged slider value to be rejected.');
            } catch (InvalidResourceSelectionException $exception) {
                $this->assertStringContainsString('memory', strtolower($exception->getMessage()));
            }
        }
    }

    public function test_location_must_be_a_child_of_products_location_option(): void
    {
        [$product, $options] = $this->dynamicProduct();
        $foreignChild = ConfigOption::create([
            'name' => 'Foreign',
            'env_variable' => '99',
            'type' => 'select',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => false,
            'parent_id' => null,
        ]);

        $this->expectException(InvalidResourceSelectionException::class);

        $this->service()->forQuote($product, [
            $options['memory']->id => 4096,
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $foreignChild->id,
        ]);
    }

    public function test_hidden_location_is_not_quoteable(): void
    {
        [$product, $options, $locationChild] = $this->dynamicProduct();
        $locationChild->update(['hidden' => true]);

        $this->expectException(InvalidResourceSelectionException::class);

        $this->service()->forQuote($product, [
            $options['memory']->id => 4096,
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $locationChild->id,
        ]);
    }

    public function test_hidden_retired_root_options_are_ignored_and_cannot_be_submitted(): void
    {
        [$product, $options, $locationChild] = $this->dynamicProduct();
        $retiredMemory = $this->slider(
            $product,
            'Retired Memory',
            'memory',
            1024,
            65536,
            1024,
            8192
        );
        $retiredLocation = ConfigOption::create([
            'name' => 'Location',
            'env_variable' => 'location',
            'type' => 'select',
            'sort' => 10,
            'hidden' => false,
            'upgradable' => false,
        ]);
        DB::table('config_option_products')->insert([
            'product_id' => $product->id,
            'config_option_id' => $retiredLocation->id,
        ]);

        // Simulate the checkout product being loaded before a wizard rerun
        // retires obsolete options. The resolver must use current DB state.
        $product->load('configOptions.children');
        $retiredMemory->update(['hidden' => true]);
        $retiredLocation->update(['hidden' => true]);

        $activeSelection = [
            $options['memory']->id => 4096,
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $locationChild->id,
        ];

        $configuration = $this->service()->forQuote($product, $activeSelection);

        $this->assertSame(4096, $configuration['resources']['memory']);
        $this->assertSame(
            $options['memory']->id,
            $configuration['sliders']['memory']['config_option_id']
        );
        $this->assertSame(1, $configuration['location_id']);

        foreach ([
            $retiredMemory->id => 8192,
            $retiredLocation->id => $locationChild->id,
        ] as $retiredOptionId => $value) {
            try {
                $this->service()->forQuote(
                    $product,
                    $activeSelection + [$retiredOptionId => $value]
                );
                $this->fail('Expected a retired option submission to be rejected.');
            } catch (InvalidResourceSelectionException $exception) {
                $this->assertStringContainsString(
                    'not available',
                    strtolower($exception->getMessage())
                );
            }
        }
    }

    public function test_attached_child_option_cannot_be_submitted_as_a_root_option(): void
    {
        [$product, $options, $locationChild] = $this->dynamicProduct();
        DB::table('config_option_products')->insert([
            'product_id' => $product->id,
            'config_option_id' => $locationChild->id,
        ]);

        $this->expectException(InvalidResourceSelectionException::class);
        $this->expectExceptionMessage('not available');

        $this->service()->forQuote($product, [
            $options['memory']->id => 4096,
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $locationChild->id,
            $locationChild->id => 1,
        ]);
    }

    public function test_overflowing_selection_is_rejected_before_cast(): void
    {
        [$product, $options, $locationChild] = $this->dynamicProduct();

        $this->expectException(InvalidResourceSelectionException::class);

        $this->service()->forQuote($product, [
            $options['memory']->id => '999999999999999999999999999999',
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $locationChild->id,
        ]);
    }

    public function test_non_empty_port_mapping_requires_server_port(): void
    {
        [$product, $options, $locationChild] = $this->dynamicProduct();
        $product->settings()->create([
            'key' => 'port_array',
            'value' => json_encode(['QUERY_PORT' => 25566]),
        ]);

        $this->expectException(
            InvalidStockConfigurationException::class
        );
        $this->expectExceptionMessage('SERVER_PORT');

        $this->service()->forQuote($product, [
            $options['memory']->id => 4096,
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $locationChild->id,
        ]);
    }

    public function test_port_mapping_preserves_environment_and_primary_identity(): void
    {
        [$product, $options, $locationChild] = $this->dynamicProduct();
        $product->settings()->create([
            'key' => 'port_array',
            'value' => json_encode([
                'SERVER_PORT' => 25570,
                'QUERY_PORT' => 25571,
                'NONE' => [25572, 25573],
            ]),
        ]);

        $configuration = $this->service()->forQuote($product, [
            $options['memory']->id => 4096,
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $locationChild->id,
        ]);

        $this->assertSame(4, $configuration['allocation_count']);
        $this->assertSame(
            [25570, 25571, 25572, 25573],
            $configuration['required_ports']
        );
        $this->assertSame([
            [
                'environment_key' => 'SERVER_PORT',
                'requested_port' => 25570,
                'is_primary' => true,
            ],
            [
                'environment_key' => 'QUERY_PORT',
                'requested_port' => 25571,
                'is_primary' => false,
            ],
            [
                'environment_key' => 'NONE',
                'requested_port' => 25572,
                'is_primary' => false,
            ],
            [
                'environment_key' => 'NONE',
                'requested_port' => 25573,
                'is_primary' => false,
            ],
        ], $configuration['allocation_mappings']);
    }

    public function test_port_mapping_rejects_multiple_ports_for_one_egg_environment_key(): void
    {
        [$product, $options, $locationChild] = $this->dynamicProduct();
        $product->settings()->create([
            'key' => 'port_array',
            'value' => json_encode([
                'SERVER_PORT' => 25570,
                'QUERY_PORT' => [25571, 25572],
            ]),
        ]);

        $this->expectException(
            InvalidStockConfigurationException::class
        );
        $this->expectExceptionMessage(
            'may assign exactly one port to QUERY_PORT'
        );

        $this->service()->forQuote($product, [
            $options['memory']->id => 4096,
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $locationChild->id,
        ]);
    }

    public function test_additional_allocations_are_preclaimed_without_fake_environment_keys(): void
    {
        [$product, $options, $locationChild] = $this->dynamicProduct();
        $product->settings()->create([
            'key' => 'additional_allocations',
            'value' => '2',
        ]);

        $configuration = $this->service()->forQuote($product, [
            $options['memory']->id => 4096,
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $locationChild->id,
        ]);

        $this->assertSame(3, $configuration['allocation_count']);
        $this->assertSame([
            [
                'environment_key' => 'SERVER_PORT',
                'requested_port' => null,
                'is_primary' => true,
            ],
            [
                'environment_key' => 'NONE',
                'requested_port' => null,
                'is_primary' => false,
            ],
            [
                'environment_key' => 'NONE',
                'requested_port' => null,
                'is_primary' => false,
            ],
        ], $configuration['allocation_mappings']);
    }

    public function test_dedicated_ip_and_port_ranges_are_strictly_normalized(): void
    {
        [$product, $options, $locationChild] = $this->dynamicProduct();
        $product->settings()->create([
            'key' => 'dedicated_ip',
            'value' => 'true',
        ]);
        $product->settings()->create([
            'key' => 'port_range',
            'value' => json_encode([
                '25580-25590',
                '25565',
                '25566-25579',
            ]),
        ]);

        $configuration = $this->service()->forQuote($product, [
            $options['memory']->id => 4096,
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $locationChild->id,
        ]);

        $this->assertTrue($configuration['dedicated_ip']);
        $this->assertSame([
            ['from' => 25565, 'to' => 25590],
        ], $configuration['allowed_port_ranges']);
    }

    public function test_port_array_cannot_silently_bypass_deployment_constraints(): void
    {
        [$product, $options, $locationChild] = $this->dynamicProduct();
        $product->settings()->createMany([
            [
                'key' => 'port_array',
                'value' => json_encode(['SERVER_PORT' => 25565]),
            ],
            ['key' => 'dedicated_ip', 'value' => '1'],
        ]);

        $this->expectException(
            InvalidStockConfigurationException::class
        );
        $this->expectExceptionMessage('cannot combine');

        $this->service()->forQuote($product, [
            $options['memory']->id => 4096,
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $locationChild->id,
        ]);
    }

    public function test_static_node_and_cpu_pinning_cannot_bypass_dynamic_placement(): void
    {
        foreach ([
            ['key' => 'node', 'value' => '12', 'message' => 'must not be pinned'],
            [
                'key' => 'cpu_pinning',
                'value' => '0,2-3',
                'message' => 'CPU pinning',
            ],
        ] as $case) {
            [$product, $options, $locationChild] = $this->dynamicProduct();
            $product->settings()->create([
                'key' => $case['key'],
                'value' => $case['value'],
            ]);

            try {
                $this->service()->forQuote($product, [
                    $options['memory']->id => 4096,
                    $options['cpu']->id => 200,
                    $options['disk']->id => 51200,
                    $options['location']->id => $locationChild->id,
                ]);
                $this->fail(
                    "Expected {$case['key']} to fail dynamic stock closed."
                );
            } catch (
                InvalidStockConfigurationException
                $exception
            ) {
                $this->assertStringContainsString(
                    $case['message'],
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_unconfirmed_exclusive_provisioning_control_fails_quote_configuration(): void
    {
        [$product, $options, $locationChild] = $this->dynamicProduct();
        $inventory = Mockery::mock(PterodactylInventoryService::class);
        $inventory->shouldReceive('panelIdentity')
            ->andReturn(hash('sha256', 'https://panel.example.com'));
        $inventory->shouldReceive('assertExclusiveProvisioningControl')
            ->once()
            ->andThrow(new \RuntimeException('exclusive pool required'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exclusive pool required');

        (new ProductResourceConfigurationService($inventory))->forQuote($product, [
            $options['memory']->id => 4096,
            $options['cpu']->id => 200,
            $options['disk']->id => 51200,
            $options['location']->id => $locationChild->id,
        ]);
    }

    private function service(): ProductResourceConfigurationService
    {
        $inventory = Mockery::mock(PterodactylInventoryService::class);
        $inventory->shouldReceive('panelIdentity')
            ->zeroOrMoreTimes()
            ->andReturn(hash('sha256', 'https://panel.example.com'));
        $inventory->shouldReceive('assertExclusiveProvisioningControl')
            ->zeroOrMoreTimes();

        return new ProductResourceConfigurationService($inventory);
    }

    /**
     * @return array{Product, array<string, ConfigOption>, ConfigOption}
     */
    private function dynamicProduct(): array
    {
        $server = Server::create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        $server->settings()->create([
            'key' => 'host',
            'value' => 'https://panel.example.com/',
        ]);
        $product = Product::factory()->create([
            'server_id' => $server->id,
            'hidden' => false,
        ]);

        $options = [
            'memory' => $this->slider($product, 'Memory', 'memory', 1024, 32768, 1024, 4096),
            'cpu' => $this->slider($product, 'CPU', 'cpu', 100, 1600, 100, 200),
            'disk' => $this->slider($product, 'Disk', 'disk', 10240, 512000, 10240, 51200),
        ];
        $options['location'] = ConfigOption::create([
            'name' => 'Location',
            'env_variable' => 'location',
            'type' => 'select',
            'sort' => 3,
            'hidden' => false,
            'upgradable' => false,
        ]);
        $locationChild = ConfigOption::create([
            'name' => 'Melbourne',
            'env_variable' => '1',
            'type' => 'select',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => false,
            'parent_id' => $options['location']->id,
        ]);
        DB::table('config_option_products')->insert([
            'product_id' => $product->id,
            'config_option_id' => $options['location']->id,
        ]);

        return [$product, $options, $locationChild];
    }

    private function slider(
        Product $product,
        string $name,
        string $resource,
        int $minimum,
        int $maximum,
        int $step,
        int $default
    ): ConfigOption {
        $option = ConfigOption::create([
            'name' => $name,
            'env_variable' => strtoupper($resource),
            'type' => 'dynamic_slider',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => false,
            'metadata' => [
                'resource_type' => $resource,
                'min' => $minimum,
                'max' => $maximum,
                'step' => $step,
                'default' => $default,
            ],
        ]);
        DB::table('config_option_products')->insert([
            'product_id' => $product->id,
            'config_option_id' => $option->id,
        ]);

        return $option;
    }
}
