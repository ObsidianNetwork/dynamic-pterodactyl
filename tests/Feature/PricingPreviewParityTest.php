<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use App\Models\ConfigOption;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class PricingPreviewParityTest extends LaravelTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        require __DIR__ . '/../../routes/api.php';
    }

    public function test_pricing_calculate_matches_core_base_plus_slider_deltas(): void
    {
        /** @var User $user */
        $user = User::withoutEvents(fn () => User::factory()->create());
        $product = Product::factory()->create();
        $plan = Plan::factory()->create([
            'priceable_id' => $product->id,
            'priceable_type' => Product::class,
            'name' => 'Monthly',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
            'dynamic_slider_base_price' => 5.00,
        ]);

        Price::factory()->create([
            'plan_id' => $plan->id,
            'price' => 10.00,
            'setup_fee' => 0.00,
            'currency_code' => 'USD',
        ]);

        $memory = $this->attachSlider($product, 'Memory', 'memory', 1024, [
            'model' => 'linear',
            'rate_per_unit' => 1.50,
        ]);
        $cpu = $this->attachSlider($product, 'CPU', 'cpu', 100, [
            'model' => 'linear',
            'rate_per_unit' => 2.00,
        ]);
        $disk = $this->attachSlider($product, 'Disk', 'disk', 1024, [
            'model' => 'linear',
            'rate_per_unit' => 0.25,
        ]);

        $payload = [
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 20480,
        ];

        $response = $this->actingAs($user)->postJson('/api/dynamic-pterodactyl/pricing/calculate', $payload);

        $response->assertOk()->assertJson(['success' => true]);

        $expectedDelta = $memory->calculateDynamicPriceDelta(4096, 1, 'month')
            + $cpu->calculateDynamicPriceDelta(200, 1, 'month')
            + $disk->calculateDynamicPriceDelta(20480, 1, 'month');

        $this->assertEquals(round($expectedDelta + 5.00, 2), $response->json('data.total'));
        $response->assertJsonCount(3, 'data.breakdown');
        $response->assertJsonPath('data.model', 'linear');
    }

    private function attachSlider(Product $product, string $name, string $resourceType, int $displayDivisor, array $pricing): ConfigOption
    {
        $option = ConfigOption::create([
            'name' => $name,
            'env_variable' => strtoupper($resourceType),
            'type' => 'dynamic_slider',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => true,
            'metadata' => [
                'resource_type' => $resourceType,
                'min' => $displayDivisor,
                'max' => $displayDivisor * 64,
                'step' => $displayDivisor,
                'default' => $displayDivisor,
                'unit' => $resourceType === 'cpu' ? '%' : 'MB',
                'display_unit' => $resourceType === 'cpu' ? 'cores' : 'GB',
                'display_divisor' => $displayDivisor,
                'pricing' => $pricing,
            ],
        ]);

        DB::table('config_option_products')->insert([
            'product_id' => $product->id,
            'config_option_id' => $option->id,
        ]);

        return $option;
    }
    public function test_pricing_calculate_with_foreign_plan_id_returns_client_error(): void
    {
        /** @var User $user */
        $user = User::withoutEvents(fn () => User::factory()->create());

        $product = Product::factory()->create();
        $otherProduct = Product::factory()->create();

        $this->attachSlider($product, 'Memory', 'memory', 1024, [
            'model' => 'linear',
            'rate_per_unit' => 1.50,
        ]);

        $foreignPlan = Plan::factory()->create([
            'priceable_id' => $otherProduct->id,
            'priceable_type' => Product::class,
            'name' => 'Other Plan',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
            'dynamic_slider_base_price' => 5.00,
        ]);

        Price::factory()->create([
            'plan_id' => $foreignPlan->id,
            'price' => 10.00,
            'setup_fee' => 0.00,
            'currency_code' => 'USD',
        ]);

        $payload = [
            'product_id' => $product->id,
            'plan_id'    => $foreignPlan->id,
            'memory'     => 4096,
            'cpu'        => 200,
            'disk'       => 20480,
        ];

        $response = $this->actingAs($user)->postJson('/api/dynamic-pterodactyl/pricing/calculate', $payload);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

}
