<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\ConfigOption;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\CapacityServiceCreationCoordinator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ConfigOptionSetupService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\PterodactylInventoryService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class ConfigOptionSetupServiceTest extends LaravelTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $inventory = Mockery::mock(PterodactylInventoryService::class);
        $inventory->shouldReceive('locations')->zeroOrMoreTimes()->andReturn([
            ['id' => 1, 'short' => 'nyc', 'long' => 'New York'],
            ['id' => 2, 'short' => 'lon', 'long' => 'London'],
        ]);
        $inventory->shouldReceive('panelIdentity')
            ->zeroOrMoreTimes()
            ->andReturn(hash('sha256', 'https://panel.example'));
        app()->instance(PterodactylInventoryService::class, $inventory);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_createDynamicSliderOptions_rolls_back_on_mid_batch_failure(): void
    {
        $product = $this->eligibleProduct();

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
                ],
                [['id' => 1, 'short' => 'nyc', 'long' => 'New York']]
            );

            $this->fail('Expected setup to reject the invalid disk pricing tiers.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('tier', strtolower($exception->getMessage()));
        }

        $this->assertSame(0, $this->countOptionsForProduct($product->id));
    }

    public function test_createDynamicSliderOptions_happy_path_creates_all_four(): void
    {
        $product = $this->eligibleProduct();

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
        $product = $this->eligibleProduct();
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

    public function test_fractional_display_values_scale_exactly_without_truncation(): void
    {
        $product = $this->eligibleProduct();

        $created = app(ConfigOptionSetupService::class)
            ->createDynamicSliderOptions(
                $product->id,
                [
                    'pricing_model' => 'linear',
                    'memory_min' => '0.5',
                    'memory_max' => '2',
                    'memory_step' => '0.5',
                    'memory_default' => '1',
                    'memory_rate' => 1,
                    'cpu_rate' => 1,
                    'disk_rate' => 1,
                ],
                [['id' => 1, 'short' => 'nyc', 'long' => 'New York']]
            );

        $this->assertSame(512, $created['memory']->metadata['min']);
        $this->assertSame(512, $created['memory']->metadata['step']);
        $this->assertSame(1024, $created['memory']->metadata['default']);
        $this->assertSame(2048, $created['memory']->metadata['max']);
    }

    public function test_fractional_display_value_that_cannot_scale_exactly_is_rejected(): void
    {
        $product = $this->eligibleProduct();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('represented exactly');
        app(ConfigOptionSetupService::class)->createDynamicSliderOptions(
            $product->id,
            [
                'pricing_model' => 'linear',
                'memory_min' => '0.0001',
                'memory_rate' => 1,
                'cpu_rate' => 1,
                'disk_rate' => 1,
            ],
            [['id' => 1, 'short' => 'nyc', 'long' => 'New York']]
        );
    }

    public function test_zero_resource_minimum_is_rejected_before_it_can_mean_unlimited(): void
    {
        $product = $this->eligibleProduct();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Memory minimum must be greater than zero.'
        );

        app(ConfigOptionSetupService::class)->createDynamicSliderOptions(
            $product->id,
            [
                'pricing_model' => 'linear',
                'memory_min' => 0,
                'memory_rate' => 1,
                'cpu_rate' => 1,
                'disk_rate' => 1,
            ],
            [['id' => 1, 'short' => 'nyc', 'long' => 'New York']]
        );
    }

    public function test_scaled_value_overflow_is_rejected(): void
    {
        $product = $this->eligibleProduct();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the supported range');
        app(ConfigOptionSetupService::class)->createDynamicSliderOptions(
            $product->id,
            [
                'pricing_model' => 'linear',
                'memory_max' => (string) PHP_INT_MAX,
                'memory_rate' => 1,
                'cpu_rate' => 1,
                'disk_rate' => 1,
            ],
            [['id' => 1, 'short' => 'nyc', 'long' => 'New York']]
        );
    }

    public function test_unmanaged_resource_name_collision_fails_closed(): void
    {
        $product = $this->eligibleProduct();
        $option = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'memory',
            'type' => 'number',
            'hidden' => false,
        ]);
        $option->products()->attach($product->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unmanaged memory');
        app(ConfigOptionSetupService::class)->createDynamicSliderOptions(
            $product->id,
            [
                'pricing_model' => 'linear',
                'memory_rate' => 1,
                'cpu_rate' => 1,
                'disk_rate' => 1,
            ],
            [['id' => 1, 'short' => 'nyc', 'long' => 'New York']]
        );
    }

    public function test_setup_rejects_a_product_on_a_different_panel(): void
    {
        $product = $this->eligibleProduct([
            'provisioner_host' => 'https://other-panel.example',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same panel');
        app(ConfigOptionSetupService::class)->createDynamicSliderOptions(
            $product->id,
            [
                'pricing_model' => 'linear',
                'memory_rate' => 1,
                'cpu_rate' => 1,
                'disk_rate' => 1,
            ],
            [['id' => 1, 'short' => 'nyc', 'long' => 'New York']]
        );
    }

    public function test_wizard_rerun_preserves_bound_invoice_guarantee_snapshot(): void
    {
        $product = $this->eligibleProduct();
        $service = app(ConfigOptionSetupService::class);
        $service->createDynamicSliderOptions(
            $product->id,
            [
                'pricing_model' => 'linear',
                'memory_max' => 32,
                'memory_rate' => 1,
                'cpu_rate' => 1,
                'disk_rate' => 1,
            ],
            [['id' => 1, 'short' => 'nyc', 'long' => 'New York']]
        );

        $user = User::factory()->create();
        $boundService = CapacityServiceCreationCoordinator::run(
            fn () => Service::factory()->create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 10,
                'currency_code' => 'USD',
                'status' => Service::STATUS_PENDING,
            ])
        );
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(4),
        ]);
        $payload = [
            'product_id' => $product->id,
            'memory' => 32768,
            'cpu' => 200,
            'disk' => 20480,
        ];
        $reservationId = DB::table('ptero_resource_reservations')->insertGetId([
            'token' => Str::random(64),
            'purpose' => 'checkout',
            'idempotency_key' => hash('sha256', Str::random()),
            'server_extension_id' => $product->server_id,
            'panel_identity' => str_repeat('a', 64),
            'service_id' => $boundService->id,
            'service_guard_id' => $boundService->id,
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'currency_code' => 'USD',
            'configuration_fingerprint' => hash(
                'sha256',
                json_encode($payload, JSON_THROW_ON_ERROR)
            ),
            'configuration_payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'pricing_version' => str_repeat('b', 64),
            'formula_version' => 'test-v1',
            'node_id' => 1,
            'location_id' => 1,
            'memory' => 32768,
            'cpu' => 200,
            'disk' => 20480,
            'calculated_price' => 10,
            'pricing_breakdown' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'expires_at' => now()->addDays(4),
            'guaranteed_until' => now()->addDays(4),
            'created_at' => now()->subDays(3),
            'updated_at' => now(),
        ]);

        $service->createDynamicSliderOptions(
            $product->id,
            [
                'pricing_model' => 'linear',
                'memory_max' => 64,
                'memory_rate' => 2,
                'cpu_rate' => 1,
                'disk_rate' => 1,
            ],
            [['id' => 1, 'short' => 'nyc', 'long' => 'New York']]
        );

        $reservation = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->first();
        $this->assertSame('pending', $reservation->status);
        $this->assertSame(
            $payload,
            json_decode($reservation->configuration_payload, true)
        );
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    private function countOptionsForProduct(int $productId): int
    {
        return DB::table('config_options')
            ->join('config_option_products', 'config_options.id', '=', 'config_option_products.config_option_id')
            ->where('config_option_products.product_id', $productId)
            ->count();
    }

    private function eligibleProduct(array $attributes = []): Product
    {
        $host = (string) (
            $attributes['provisioner_host']
            ?? 'https://panel.example'
        );
        unset($attributes['provisioner_host']);
        $server = Server::create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        $server->settings()->create([
            'key' => 'host',
            'value' => $host,
            'type' => 'string',
            'encrypted' => false,
        ]);

        return Product::factory()->create(array_merge([
            'server_id' => $server->id,
            'hidden' => false,
        ], $attributes));
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
