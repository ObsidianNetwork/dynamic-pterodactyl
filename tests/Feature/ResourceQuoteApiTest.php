<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use App\Models\Cart;
use App\Models\ConfigOption;
use App\Models\Product;
use App\Models\Server;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\StockUnavailableException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceQuoteService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class ResourceQuoteApiTest extends LaravelTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        require __DIR__.'/../../routes/api.php';
    }

    public function test_guest_receives_customer_safe_quote_contract(): void
    {
        $product = $this->quoteableProduct();
        $quotes = Mockery::mock(ResourceQuoteService::class);
        $quotes->shouldReceive('quote')
            ->once()
            ->with(
                Mockery::on(fn (Product $resolved): bool => $resolved->is($product)),
                ['10' => 32768],
                null
            )
            ->andReturn([
                'available' => true,
                'adjusted' => true,
                'selection' => [
                    'memory' => 23552,
                    'cpu' => 200,
                    'disk' => 51200,
                ],
                'bounds' => [
                    'memory' => [
                        'config_option_id' => 10,
                        'min' => 1024,
                        'max' => 23552,
                        'configured_max' => 32768,
                        'step' => 1024,
                    ],
                ],
            ]);
        $this->app->instance(ResourceQuoteService::class, $quotes);

        $response = $this->postJson(
            "/api/dynamic-pterodactyl/products/{$product->id}/resource-quote",
            ['config_options' => ['10' => 32768]]
        );

        $response->assertOk()->assertExactJson([
            'data' => [
                'available' => true,
                'adjusted' => true,
                'selection' => [
                    'memory' => 23552,
                    'cpu' => 200,
                    'disk' => 51200,
                ],
                'bounds' => [
                    'memory' => [
                        'config_option_id' => 10,
                        'min' => 1024,
                        'max' => 23552,
                        'configured_max' => 32768,
                        'step' => 1024,
                    ],
                ],
            ],
        ]);
        $this->assertStringNotContainsString('node', $response->getContent());
    }

    public function test_invalid_request_has_safe_top_level_422_message(): void
    {
        $product = $this->quoteableProduct();

        $this->postJson(
            "/api/dynamic-pterodactyl/products/{$product->id}/resource-quote",
            []
        )->assertStatus(422)->assertJson([
            'message' => 'The resource quote request is invalid.',
        ]);
    }

    public function test_stock_conflict_has_safe_top_level_409_message(): void
    {
        $product = $this->quoteableProduct();
        $quotes = Mockery::mock(ResourceQuoteService::class);
        $quotes->shouldReceive('quote')
            ->once()
            ->andThrow(new StockUnavailableException('No server currently has enough stock.'));
        $this->app->instance(ResourceQuoteService::class, $quotes);

        $this->postJson(
            "/api/dynamic-pterodactyl/products/{$product->id}/resource-quote",
            ['config_options' => []]
        )->assertStatus(409)->assertExactJson([
            'message' => 'No server currently has enough stock.',
        ]);
    }

    public function test_upstream_failure_does_not_leak_panel_details(): void
    {
        $product = $this->quoteableProduct();
        $quotes = Mockery::mock(ResourceQuoteService::class);
        $quotes->shouldReceive('quote')
            ->once()
            ->andThrow(new \RuntimeException('panel-internal.example:8443 refused'));
        $this->app->instance(ResourceQuoteService::class, $quotes);

        $response = $this->postJson(
            "/api/dynamic-pterodactyl/products/{$product->id}/resource-quote",
            ['config_options' => []]
        );

        $response->assertStatus(503)->assertExactJson([
            'message' => 'Dynamic stock is temporarily unavailable.',
        ]);
        $this->assertStringNotContainsString('panel-internal', $response->getContent());
    }

    public function test_owned_cart_item_quote_excludes_its_current_hold(): void
    {
        $product = $this->quoteableProduct();
        $cart = Cart::create(['currency_code' => 'USD']);
        $cartItemId = DB::table('cart_items')->insertGetId([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'plan_id' => $product->plans()->value('id'),
            'config_options' => json_encode([]),
            'checkout_config' => json_encode([]),
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ptero_resource_reservations')->insert([
            'token' => 'owned-cart-hold',
            'cart_item_id' => $cartItemId,
            'cart_item_guard_id' => $cartItemId,
            'node_id' => 5,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'calculated_price' => 0,
            'pricing_breakdown' => json_encode([]),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quotes = Mockery::mock(ResourceQuoteService::class);
        $quotes->shouldReceive('quote')
            ->once()
            ->with(
                Mockery::on(fn (Product $resolved): bool => $resolved->is($product)),
                [],
                'owned-cart-hold'
            )
            ->andReturn([
                'available' => true,
                'adjusted' => false,
                'selection' => ['memory' => 4096, 'cpu' => 200, 'disk' => 51200],
                'bounds' => [],
            ]);
        $this->app->instance(ResourceQuoteService::class, $quotes);

        $this->withCookie('cart', $cart->ulid)
            ->postJson(
                "/api/dynamic-pterodactyl/products/{$product->id}/resource-quote",
                ['config_options' => [], 'cart_item_id' => $cartItemId]
            )
            ->assertOk();
    }

    public function test_cart_item_from_another_cart_cannot_be_used_for_exclusion(): void
    {
        $product = $this->quoteableProduct();
        $ownedCart = Cart::create(['currency_code' => 'USD']);
        $otherCart = Cart::create(['currency_code' => 'USD']);
        $otherItemId = DB::table('cart_items')->insertGetId([
            'cart_id' => $otherCart->id,
            'product_id' => $product->id,
            'plan_id' => $product->plans()->value('id'),
            'config_options' => json_encode([]),
            'checkout_config' => json_encode([]),
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withCookie('cart', $ownedCart->ulid)
            ->postJson(
                "/api/dynamic-pterodactyl/products/{$product->id}/resource-quote",
                ['config_options' => [], 'cart_item_id' => $otherItemId]
            )
            ->assertStatus(422)
            ->assertJson([
                'message' => 'The cart item is not available for this resource quote.',
            ]);
    }

    public function test_hidden_product_is_rejected_before_quote_service(): void
    {
        $product = $this->quoteableProduct();
        $product->update(['hidden' => true]);

        $this->postJson(
            "/api/dynamic-pterodactyl/products/{$product->id}/resource-quote",
            ['config_options' => []]
        )->assertStatus(404);
    }

    public function test_out_of_stock_product_is_rejected_before_quote_service(): void
    {
        $product = $this->quoteableProduct();
        $product->update(['stock' => 0]);

        $this->postJson(
            "/api/dynamic-pterodactyl/products/{$product->id}/resource-quote",
            ['config_options' => []]
        )->assertStatus(404);
    }

    public function test_unpriced_product_is_rejected_before_quote_service(): void
    {
        $product = $this->quoteableProduct();
        $product->plans()->delete();

        $this->postJson(
            "/api/dynamic-pterodactyl/products/{$product->id}/resource-quote",
            ['config_options' => []]
        )->assertStatus(404);
    }

    public function test_non_pterodactyl_product_is_rejected_before_panel_read(): void
    {
        $product = $this->quoteableProduct();
        $product->server->update(['extension' => 'OtherServer']);

        $this->postJson(
            "/api/dynamic-pterodactyl/products/{$product->id}/resource-quote",
            ['config_options' => []]
        )->assertStatus(404);
    }

    public function test_non_dynamic_product_is_rejected_before_panel_read(): void
    {
        $product = $this->quoteableProduct();
        DB::table('config_option_products')
            ->where('product_id', $product->id)
            ->delete();

        $this->postJson(
            "/api/dynamic-pterodactyl/products/{$product->id}/resource-quote",
            ['config_options' => []]
        )->assertStatus(404);
    }

    private function quoteableProduct(): Product
    {
        $server = Server::create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        $product = Product::factory()->create([
            'server_id' => $server->id,
            'hidden' => false,
            'stock' => null,
        ]);
        $product->plans()->create([
            'name' => 'Free',
            'type' => 'free',
            'billing_period' => 1,
            'billing_unit' => 'month',
        ]);
        $slider = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'memory',
            'type' => 'dynamic_slider',
            'sort' => 0,
            'hidden' => false,
            'upgradable' => false,
            'metadata' => [
                'resource_type' => 'memory',
                'min' => 1024,
                'max' => 32768,
                'step' => 1024,
                'default' => 4096,
            ],
        ]);
        DB::table('config_option_products')->insert([
            'product_id' => $product->id,
            'config_option_id' => $slider->id,
        ]);

        return $product;
    }
}
