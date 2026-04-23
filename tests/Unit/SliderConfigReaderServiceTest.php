<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\ConfigOption;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\SliderConfigReaderService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class SliderConfigReaderServiceTest extends LaravelTestCase
{
    use DatabaseTransactions;

    private SliderConfigReaderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SliderConfigReaderService();
    }

    public function test_get_config_returns_slider_info(): void
    {
        $product = Product::factory()->create();

        $this->attachSlider($product, 'Memory', 'memory', [
            'min' => 1024,
            'max' => 8192,
            'step' => 1024,
            'default' => 4096,
            'unit' => 'MB',
            'display_unit' => 'GB',
            'display_divisor' => 1024,
            'pricing' => ['model' => 'linear', 'rate_per_unit' => 2.00],
        ]);

        $this->attachSlider($product, 'CPU', 'cpu', [
            'min' => 100,
            'max' => 400,
            'step' => 100,
            'default' => 200,
            'unit' => '%',
            'display_unit' => 'cores',
            'display_divisor' => 100,
            'pricing' => ['model' => 'linear', 'rate_per_unit' => 3.00],
        ]);

        $config = $this->service->getConfig($product->id);

        $this->assertTrue($config['has_config']);
        $this->assertArrayHasKey('memory', $config['sliders']);
        $this->assertArrayHasKey('cpu', $config['sliders']);
        $this->assertEquals(1024, $config['sliders']['memory']['min']);
        $this->assertEquals('GB', $config['sliders']['memory']['display_unit']);
    }

    public function test_get_config_returns_empty_payload_when_no_sliders_exist(): void
    {
        $product = Product::factory()->create();

        $config = $this->service->getConfig($product->id);

        $this->assertFalse($config['has_config']);
        $this->assertSame([], $config['sliders']);
    }

    public function test_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(SliderConfigReaderService::class, app(SliderConfigReaderService::class));
    }

    private function attachSlider(Product $product, string $name, string $resourceType, array $metadata): void
    {
        $option = ConfigOption::create([
            'name' => $name,
            'env_variable' => strtoupper($resourceType),
            'type' => 'dynamic_slider',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => true,
            'metadata' => array_merge(['resource_type' => $resourceType], $metadata),
        ]);

        DB::table('config_option_products')->insert([
            'product_id' => $product->id,
            'config_option_id' => $option->id,
        ]);
    }
    public function test_get_config_handles_json_encoded_metadata(): void
    {
        $product = Product::factory()->create();

        $option = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'MEMORY',
            'type' => 'dynamic_slider',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => true,
            'metadata' => null,
        ]);

        // Force a JSON string into the metadata column (simulates rows where cast is bypassed)
        \Illuminate\Support\Facades\DB::table('config_options')
            ->where('id', $option->id)
            ->update(['metadata' => json_encode([
                'resource_type' => 'memory',
                'min' => 1024,
                'max' => 8192,
                'step' => 1024,
                'default' => 4096,
                'unit' => 'MB',
                'display_unit' => 'GB',
                'display_divisor' => 1024,
                'pricing' => ['model' => 'linear', 'rate_per_unit' => 2.00],
            ])]);

        DB::table('config_option_products')->insert([
            'product_id' => $product->id,
            'config_option_id' => $option->id,
        ]);

        $config = $this->service->getConfig($product->id);

        $this->assertTrue($config['has_config']);
        $this->assertArrayHasKey('memory', $config['sliders']);
        $this->assertEquals(1024, $config['sliders']['memory']['min']);
        $this->assertEquals('GB', $config['sliders']['memory']['display_unit']);
    }

}
