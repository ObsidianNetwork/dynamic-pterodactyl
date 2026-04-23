<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\ConfigOption;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ConfigOptionSetupService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class ConfigOptionSetupServiceTest extends LaravelTestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_createDynamicSliderOptions_rolls_back_on_mid_batch_failure(): void
    {
        $product = Product::factory()->create();

        try {
            app(ConfigOptionSetupService::class)->createDynamicSliderOptions(
                $product->id,
                [
                    'pricing_model' => 'tiered',
                    'memory_tiers' => [
                        ['up_to' => 8, 'rate' => 0.75],
                        ['up_to' => null, 'rate' => 0.50],
                    ],
                    'cpu_tiers' => [
                        ['up_to' => null, 'rate' => 2.00],
                    ],
                    'disk_tiers' => [],
                ]
            );

            $this->fail('Expected setup to reject the invalid disk pricing tiers.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('tier', strtolower($exception->getMessage()));
        }

        $this->assertSame(0, $this->countOptionsForProduct($product->id));
    }

    public function test_createDynamicSliderOptions_happy_path_creates_all_four(): void
    {
        $product = Product::factory()->create();

        $created = app(ConfigOptionSetupService::class)->createDynamicSliderOptions(
            $product->id,
            [
                'pricing_model' => 'linear',
                'base_price' => 5,
                'memory_rate' => 0.5,
                'cpu_rate' => 2,
                'disk_rate' => 0.02,
            ],
            [
                ['id' => 1, 'short' => 'nyc', 'long' => 'New York'],
                ['id' => 2, 'short' => 'lon', 'long' => 'London'],
            ]
        );

        $this->assertSame(['memory', 'cpu', 'disk', 'location'], array_keys($created));
        $this->assertSame(4, $this->countParentOptionsForProduct($product->id));
        $this->assertSame(2, ConfigOption::query()->where('parent_id', $created['location']->id)->count());
        $this->assertSame(4, DB::table('config_option_products')->where('product_id', $product->id)->count());
    }

    public function test_setup_run_audit_still_fires_on_successful_transaction(): void
    {
        $product = Product::factory()->create();
        $audit = Mockery::mock(AuditLogService::class);

        $audit->shouldReceive('log')
            ->once()
            ->with('setup_run', 'product_config', $product->id, Mockery::on(function (array $payload) {
                return $payload['sliders_configured'] === ['memory', 'cpu', 'disk', 'location']
                    && $payload['count'] === 4;
            }));

        app()->instance(AuditLogService::class, $audit);

        app(ConfigOptionSetupService::class)->createDynamicSliderOptions(
            $product->id,
            [
                'pricing_model' => 'linear',
                'memory_rate' => 0.5,
                'cpu_rate' => 2,
                'disk_rate' => 0.02,
            ],
            [
                ['id' => 1, 'short' => 'nyc', 'long' => 'New York'],
            ]
        );

        $this->assertSame(4, $this->countParentOptionsForProduct($product->id));
    }

    private function countOptionsForProduct(int $productId): int
    {
        return DB::table('config_options')
            ->join('config_option_products', 'config_options.id', '=', 'config_option_products.config_option_id')
            ->where('config_option_products.product_id', $productId)
            ->count();
    }

    private function countParentOptionsForProduct(int $productId): int
    {
        return DB::table('config_options')
            ->join('config_option_products', 'config_options.id', '=', 'config_option_products.config_option_id')
            ->where('config_option_products.product_id', $productId)
            ->whereNull('config_options.parent_id')
            ->count();
    }
}
