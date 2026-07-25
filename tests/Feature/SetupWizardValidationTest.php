<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use App\Models\Product;
use App\Models\Server;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Pages\SetupWizard;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ConfigOptionSetupService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\PterodactylInventoryService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class SetupWizardValidationTest extends LaravelTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $locations = [
            ['id' => 1, 'short' => 'nyc', 'long' => 'New York'],
            ['id' => 2, 'short' => 'lon', 'long' => 'London'],
        ];

        $resourceService = Mockery::mock(ResourceCalculationService::class);
        $resourceService->shouldReceive('getLocations')->zeroOrMoreTimes()->andReturn($locations);

        $this->app->instance(ResourceCalculationService::class, $resourceService);
        $inventory = Mockery::mock(PterodactylInventoryService::class);
        $inventory->shouldReceive('locations')->zeroOrMoreTimes()->andReturn($locations);
        $inventory->shouldReceive('panelIdentity')
            ->zeroOrMoreTimes()
            ->andReturn(hash('sha256', 'https://panel.example'));
        $this->app->instance(PterodactylInventoryService::class, $inventory);
        $this->app['view']->addNamespace('dynamic-pterodactyl', __DIR__ . '/../../resources/views');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_wizard_rejects_invalid_pricing_with_form_error(): void
    {
        $this->actingAsAdmin();

        $product = $this->eligibleProduct();

        Livewire::test(SetupWizard::class)
            ->fillForm([
                ...$this->baseWizardData($product->id),
                'pricing_model' => 'tiered',
                'memory_tiers' => [],
                'cpu_tiers' => [],
                'disk_tiers' => [],
            ])
            ->callAction('setup')
            ->assertNotified(Notification::make()
                ->title('Pricing config rejected')
                ->body('Tiered pricing must have at least one tier.')
                ->danger());

        $this->assertSame(0, $this->countParentOptionsForProduct($product->id));
    }

    public function test_wizard_creates_three_sliders_and_location_on_valid_submission(): void
    {
        $this->actingAsAdmin();

        $product = $this->eligibleProduct();

        // Filament harness fallback — see dp-13 plan commit 4 notes.
        app(ConfigOptionSetupService::class)->createDynamicSliderOptions(
            $product->id,
            $this->baseWizardData($product->id),
            [
                ['id' => 1, 'short' => 'nyc', 'long' => 'New York'],
                ['id' => 2, 'short' => 'lon', 'long' => 'London'],
            ]
        );

        $locationParentId = DB::table('config_options')
            ->join('config_option_products', 'config_options.id', '=', 'config_option_products.config_option_id')
            ->where('config_option_products.product_id', $product->id)
            ->where('config_options.name', 'Location')
            ->value('config_options.id');

        $this->assertSame(4, $this->countParentOptionsForProduct($product->id));
        $this->assertSame(4, DB::table('config_option_products')->where('product_id', $product->id)->count());
        $this->assertSame(2, DB::table('config_options')->where('parent_id', $locationParentId)->count());
        $this->assertTrue(DB::table('ptero_audit_logs')->where('action', 'setup_run')->where('entity_id', $product->id)->exists());
    }

    public function test_wizard_rollback_on_validator_failure_mid_batch(): void
    {
        $this->actingAsAdmin();

        $product = $this->eligibleProduct();

        // Filament harness fallback — see dp-13 plan commit 4 notes.
        try {
            app(ConfigOptionSetupService::class)->createDynamicSliderOptions(
                $product->id,
                [
                    ...$this->baseWizardData($product->id),
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

            $this->fail('Expected the setup service to reject the invalid disk tiers.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('tier', strtolower($exception->getMessage()));
        }

        $this->assertSame(0, $this->countParentOptionsForProduct($product->id));
    }

    private function baseWizardData(int $productId): array
    {
        return [
            'product_id' => $productId,
            'pricing_model' => 'linear',
            'base_price' => 5,
            'enable_memory_slider' => true,
            'enable_cpu_slider' => true,
            'enable_disk_slider' => true,
            'memory_min' => 1,
            'memory_max' => 64,
            'memory_step' => 1,
            'memory_default' => 4,
            'cpu_min' => 1,
            'cpu_max' => 8,
            'cpu_step' => 1,
            'cpu_default' => 2,
            'disk_min' => 10,
            'disk_max' => 500,
            'disk_step' => 10,
            'disk_default' => 50,
            'memory_rate' => 0.50,
            'cpu_rate' => 2.00,
            'disk_rate' => 0.02,
            'locations' => [1],
        ];
    }

    private function countParentOptionsForProduct(int $productId): int
    {
        return DB::table('config_options')
            ->join('config_option_products', 'config_options.id', '=', 'config_option_products.config_option_id')
            ->where('config_option_products.product_id', $productId)
            ->whereNull('config_options.parent_id')
            ->count();
    }

    private function actingAsAdmin(): User
    {
        /** @var User $admin */
        $admin = User::factory()->createOne(['role_id' => 1]);
        $this->actingAs($admin);

        return $admin;
    }

    private function eligibleProduct(): Product
    {
        $server = Server::create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        $server->settings()->create([
            'key' => 'host',
            'value' => 'https://panel.example',
            'type' => 'string',
            'encrypted' => false,
        ]);

        return Product::factory()->create([
            'server_id' => $server->id,
            'hidden' => false,
        ]);
    }
}
