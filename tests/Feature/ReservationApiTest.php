<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        $nodeSelectionService = $this->mock(NodeSelectionService::class);
        $nodeSelectionService->shouldReceive('selectBestNode')
            ->byDefault()
            ->andReturn(['node_id' => 1, 'name' => 'Node 1']);

        $auditLogService = $this->mock(AuditLogService::class);
        $auditLogService->shouldReceive('log')->byDefault()->andReturn(1);
    }

    public function test_store_with_idempotency_key_returns_same_reservation_on_retry(): void
    {
        /** @var User $user */
        $user = User::withoutEvents(fn () => User::factory()->create());
        /** @var Product $product */
        $product = $this->createConfiguredProduct();
        $cartItemId = $this->createCartItemForUser($user, $product->id);

        $response = $this->actingAs($user)->withHeaders([
            'Idempotency-Key' => 'idem-12345',
        ])->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'cart_item_id' => $cartItemId,
        ]);

        $retry = $this->actingAs($user)->withHeaders([
            'Idempotency-Key' => 'idem-12345',
        ])->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'cart_item_id' => $cartItemId,
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
        $firstCartItemId = $this->createCartItemForUser($user, $product->id);
        $secondCartItemId = $this->createCartItemForUser($user, $product->id);

        $first = $this->actingAs($user)->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'cart_item_id' => $firstCartItemId,
        ]);

        $second = $this->actingAs($user)->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'cart_item_id' => $secondCartItemId,
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
        $cartItemId = $this->createCartItemForUser($user, $product->id);

        $response = $this->actingAs($user)->withHeaders([
            'Idempotency-Key' => 'bad key',
        ])->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'cart_item_id' => $cartItemId,
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
        $cartItemId = $this->createCartItemForUser($user, $product->id);

        $response = $this->actingAs($user)->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'cart_item_id' => $cartItemId,
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
        $cartItemId = $this->createCartItemForUser($user, $product->id);

        $response = $this->actingAs($user)->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 99999,
            'cpu' => 200,
            'disk' => 51200,
            'cart_item_id' => $cartItemId,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('memory');
    }

    public function test_user_cannot_create_reservation_against_anothers_cart_item(): void
    {
        /** @var User $owner */
        $owner = User::withoutEvents(fn () => User::factory()->create());
        /** @var User $stranger */
        $stranger = User::withoutEvents(fn () => User::factory()->create());
        /** @var Product $product */
        $product = $this->createConfiguredProduct();
        $cartItemId = $this->createCartItemForUser($owner, $product->id);

        $response = $this->actingAs($stranger)->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'cart_item_id' => $cartItemId,
        ]);

        $response->assertForbidden();
    }

    public function test_user_can_create_reservation_against_own_cart_item(): void
    {
        /** @var User $user */
        $user = User::withoutEvents(fn () => User::factory()->create());
        /** @var Product $product */
        $product = $this->createConfiguredProduct();
        $cartItemId = $this->createCartItemForUser($user, $product->id);

        $response = $this->actingAs($user)->postJson('/api/dynamic-pterodactyl/reservation', [
            'product_id' => $product->id,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'cart_item_id' => $cartItemId,
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'user_id' => $user->id,
            'cart_item_id' => $cartItemId,
            'status' => 'pending',
        ]);
    }

    public function test_admin_can_view_other_users_reservation(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());
        $admin = $this->makeAdminUser();
        $reservation = $this->makeReservation($owner);

        $this->actingAs($admin)
            ->getJson('/api/dynamic-pterodactyl/reservation/' . $reservation->token)
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_admin_can_cancel_other_users_reservation(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());
        $admin = $this->makeAdminUser();
        $reservation = $this->makeReservation($owner);

        $this->actingAs($admin)
            ->deleteJson('/api/dynamic-pterodactyl/reservation/' . $reservation->token)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservation->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_admin_can_extend_other_users_reservation(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());
        $admin = $this->makeAdminUser();
        $reservation = $this->makeReservation($owner);

        $this->actingAs($admin)
            ->postJson('/api/dynamic-pterodactyl/reservation/' . $reservation->token . '/extend', ['minutes' => 20])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_stranger_cannot_view_other_users_reservation(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());
        $stranger = User::withoutEvents(fn () => User::factory()->create());
        $reservation = $this->makeReservation($owner);

        $this->actingAs($stranger)
            ->getJson('/api/dynamic-pterodactyl/reservation/' . $reservation->token)
            ->assertForbidden();
    }

    public function test_stranger_cannot_cancel_other_users_reservation(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());
        $stranger = User::withoutEvents(fn () => User::factory()->create());
        $reservation = $this->makeReservation($owner);

        $this->actingAs($stranger)
            ->deleteJson('/api/dynamic-pterodactyl/reservation/' . $reservation->token)
            ->assertForbidden();
    }

    public function test_stranger_cannot_extend_other_users_reservation(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());
        $stranger = User::withoutEvents(fn () => User::factory()->create());
        $reservation = $this->makeReservation($owner);

        $this->actingAs($stranger)
            ->postJson('/api/dynamic-pterodactyl/reservation/' . $reservation->token . '/extend', ['minutes' => 20])
            ->assertForbidden();
    }

    public function test_owner_can_view_own_reservation(): void
    {
        $owner = User::withoutEvents(fn () => User::factory()->create());
        $reservation = $this->makeReservation($owner);

        $this->actingAs($owner)
            ->getJson('/api/dynamic-pterodactyl/reservation/' . $reservation->token)
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_reservation_create_throttles_at_10_per_minute(): void
    {
        $user = User::withoutEvents(fn () => User::factory()->create());
        /** @var Product $product */
        $product = Product::factory()->create();
        $cartItemId = $this->createCartItemForUser($user, $product->id);

        $payload = [
            'product_id'   => $product->id,
            'location_id'  => 1,
            'memory'       => 4096,
            'cpu'          => 200,
            'disk'         => 51200,
            'cart_item_id' => $cartItemId,
        ];

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($user)
                ->postJson('/api/dynamic-pterodactyl/reservation', $payload)
                ->assertStatus(422);
        }

        $this->actingAs($user)
            ->postJson('/api/dynamic-pterodactyl/reservation', $payload)
            ->assertStatus(429);
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

    private function makeAdminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'Admin']);

        return User::withoutEvents(fn () => User::factory()->create(['role_id' => $role->id]));
    }

    private function makeReservation(User $user, array $attributes = []): ResourceReservation
    {
        return ResourceReservation::create(array_merge([
            'token' => (string) Str::random(64),
            'user_id' => $user->id,
            'node_id' => 1,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'calculated_price' => 9.99,
            'pricing_breakdown' => [],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ], $attributes));
    }

    private function createCartItemForUser(User $user, int $productId): int
    {
        $cartId = DB::table('carts')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('cart_items')->insertGetId([
            'cart_id' => $cartId,
            'product_id' => $productId,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
