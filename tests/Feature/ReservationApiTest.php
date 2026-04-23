<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\NodeSelectionService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class ReservationApiTest extends LaravelTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__ . '/../../routes/api.php';

        $nodeSelectionService = Mockery::mock(NodeSelectionService::class);
        $nodeSelectionService->shouldReceive('selectBestNode')
            ->byDefault()
            ->andReturn(['node_id' => 1, 'name' => 'Node 1']);
        $this->app->instance(NodeSelectionService::class, $nodeSelectionService);

        $auditLogService = Mockery::mock(AuditLogService::class);
        $auditLogService->shouldReceive('log')->byDefault()->andReturn(1);
        $this->app->instance(AuditLogService::class, $auditLogService);
    }

    public function test_store_with_idempotency_key_returns_same_reservation_on_retry(): void
    {
        /** @var User $user */
        $user = User::withoutEvents(fn () => User::factory()->create());
        /** @var Product $product */
        $product = $this->createConfiguredProduct();

        $response = $this->actingAs($user)->withHeaders([
            'Idempotency-Key' => 'idem-12345',
        ])->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
        ]);

        $retry = $this->actingAs($user)->withHeaders([
            'Idempotency-Key' => 'idem-12345',
        ])->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $retry->assertOk()->assertJson(['success' => true]);

        $this->assertSame($response->json('data.id'), $retry->json('data.id'));
        $this->assertSame($response->json('data.token'), $retry->json('data.token'));
        $this->assertEquals(1, ResourceReservation::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'user_id' => $user->id,
            'idempotency_key' => 'idem-12345',
            'status' => 'pending',
        ]);
    }

    public function test_store_without_idempotency_key_creates_fresh_each_time(): void
    {
        /** @var User $user */
        $user = User::withoutEvents(fn () => User::factory()->create());
        /** @var Product $product */
        $product = $this->createConfiguredProduct();

        $first = $this->actingAs($user)->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
        ]);

        $second = $this->actingAs($user)->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
        ]);

        $first->assertOk()->assertJson(['success' => true]);
        $second->assertOk()->assertJson(['success' => true]);

        $this->assertNotSame($first->json('data.token'), $second->json('data.token'));
        $this->assertEquals(2, ResourceReservation::query()->where('user_id', $user->id)->count());
    }

    public function test_store_rejects_invalid_idempotency_key(): void
    {
        /** @var User $user */
        $user = User::withoutEvents(fn () => User::factory()->create());
        /** @var Product $product */
        $product = $this->createConfiguredProduct();

        $response = $this->actingAs($user)->withHeaders([
            'Idempotency-Key' => 'bad key',
        ])->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('idempotency_key');
    }

    public function test_store_rejects_unconfigured_product(): void
    {
        /** @var User $user */
        $user = User::withoutEvents(fn () => User::factory()->create());
        /** @var Product $product */
        $product = Product::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('product_id');
    }

    public function test_store_rejects_out_of_bounds_memory(): void
    {
        /** @var User $user */
        $user = User::withoutEvents(fn () => User::factory()->create());
        /** @var Product $product */
        $product = $this->createConfiguredProduct();

        $response = $this->actingAs($user)->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 99999,
            'cpu' => 200,
            'disk' => 51200,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('memory');
    }

    private function createConfiguredProduct(): Product
    {
        /** @var Product $product */
        $product = Product::factory()->create();

        foreach ([
            'memory' => ['name' => 'Memory', 'min' => 1024, 'max' => 8192, 'step' => 1024, 'unit' => 'MB'],
            'cpu' => ['name' => 'CPU', 'min' => 100, 'max' => 400, 'step' => 100, 'unit' => '%'],
            'disk' => ['name' => 'Disk', 'min' => 10240, 'max' => 102400, 'step' => 10240, 'unit' => 'MB'],
        ] as $resourceType => $slider) {
            $optionId = DB::table('config_options')->insertGetId([
                'name' => $slider['name'],
                'type' => 'dynamic_slider',
                'sort' => 0,
                'hidden' => false,
                'upgradable' => true,
                'metadata' => json_encode([
                    'resource_type' => $resourceType,
                    'min' => $slider['min'],
                    'max' => $slider['max'],
                    'step' => $slider['step'],
                    'default' => $slider['min'],
                    'unit' => $slider['unit'],
                    'display_unit' => $slider['unit'],
                    'display_divisor' => 1,
                    'pricing' => ['model' => 'linear', 'rate_per_unit' => 1],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('config_option_products')->insert([
                'product_id' => $product->id,
                'config_option_id' => $optionId,
            ]);
        }

        return $product;
    }
}
