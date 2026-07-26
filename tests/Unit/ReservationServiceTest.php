<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Exceptions\PermanentProvisioningException;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\SuspendJob;
use App\Helpers\ExtensionHelper;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ConfigOption;
use App\Models\Extension;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Extensions\ExtensionLifecycleGuard;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\Invoice\MarkInvoicePaidService;
use App\Services\Service\DurableFulfillmentService;
use App\Services\Service\FulfillmentStatusTransitionService;
use App\Services\Service\RenewServiceService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\NodeSelectionService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ProductResourceConfigurationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationConfigurationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class ReservationServiceTest extends LaravelTestCase
{
    use DatabaseTransactions;

    private NodeSelectionService $nodeService;

    private ReservationConfigurationService $configurationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nodeService = Mockery::mock(NodeSelectionService::class);
        $this->configurationService = Mockery::mock(ReservationConfigurationService::class)
            ->makePartial();

        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('log')->zeroOrMoreTimes();
        $this->app->instance(AuditLogService::class, $audit);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_guest_holds_transfer_with_cart_ownership(): void
    {
        $user = User::withoutEvents(fn () => User::factory()->create());
        $reservationId = $this->insertReservation(
            cartId: 71,
            userId: null
        );
        $payload = json_decode(
            DB::table('ptero_resource_reservations')
                ->where('id', $reservationId)
                ->value('configuration_payload'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $payload['config_options'] = [[
            'id' => 1,
            'value' => 4096.0,
        ]];
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'configuration_fingerprint' =>
                    $this->configurationService->fingerprint($payload),
                'configuration_payload' => json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                ),
            ]);
        $persisted = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->first();
        $persistedPayload = json_decode(
            $persisted->configuration_payload,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame(
            $this->configurationService->fingerprint($persistedPayload),
            $persisted->configuration_fingerprint,
            'The immutable fingerprint must survive a JSON-column numeric round trip before ownership transfer.'
        );

        $updated = $this->service()->transferCartOwnership(71, $user->id);

        $this->assertSame(1, $updated);
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'cart_id' => 71,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
        $reservation = DB::table('ptero_resource_reservations')->where('cart_id', 71)->first();
        $payload = json_decode($reservation->configuration_payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsInt($payload['config_options'][0]['value']);
        $this->assertSame($user->id, $payload['customer_id']);
        $this->assertSame(
            $this->configurationService->fingerprint($payload),
            $reservation->configuration_fingerprint
        );
    }

    public function test_cart_reservation_rejects_resource_slider_added_after_checkout_render(): void
    {
        $product = $this->pterodactylProduct();
        $memory = $this->resourceOption('Memory', 'memory');
        $cpu = $this->resourceOption('CPU', 'cpu');
        DB::table('config_option_products')->insert([
            [
                'product_id' => $product->id,
                'config_option_id' => $memory->id,
            ],
            [
                'product_id' => $product->id,
                'config_option_id' => $cpu->id,
            ],
        ]);
        $plan = Plan::factory()->create([
            'priceable_id' => $product->id,
            'priceable_type' => Product::class,
            'type' => 'free',
            'billing_unit' => null,
            'billing_period' => 1,
        ]);
        $cart = new Cart([
            'currency_code' => 'USD',
        ]);
        $cart->id = 71;
        $cart->exists = true;
        $cartItem = new CartItem([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'quantity' => 1,
            'config_options' => [[
                'option_id' => $memory->id,
                'value' => 4096,
            ]],
        ]);
        $cartItem->setRelation('cart', $cart);
        $cartItem->setRelation('product', $product);
        $cartItem->setRelation('plan', $plan);

        $stockConfiguration = Mockery::mock(
            ProductResourceConfigurationService::class
        );
        $stockConfiguration->shouldReceive('forQuote')
            ->once()
            ->andReturn([
                'sliders' => [
                    'memory' => ['config_option_id' => $memory->id],
                    'cpu' => ['config_option_id' => $cpu->id],
                ],
            ]);
        $this->app->instance(
            ProductResourceConfigurationService::class,
            $stockConfiguration
        );

        $this->expectException(\App\Exceptions\DisplayException::class);
        $this->expectExceptionMessage(
            'Reload checkout and explicitly select every resource again.'
        );

        (new ReservationConfigurationService)->forCartItem($cartItem);
    }

    public function test_only_one_pending_hold_may_use_a_cart_item_guard(): void
    {
        $this->insertReservation(cartItemGuardId: 42);

        $this->expectException(QueryException::class);

        $this->insertReservation(cartItemGuardId: 42);
    }

    public function test_only_one_active_checkout_commitment_may_use_a_service(): void
    {
        $service = $this->makeService();
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed'
        );

        $this->expectException(QueryException::class);
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'confirmed'
        );
    }

    public function test_only_one_active_upgrade_commitment_may_use_an_upgrade_guard(): void
    {
        $this->insertReservation(
            status: 'paid_committed',
            purpose: 'upgrade',
            upgradeGuardId: 991
        );

        $this->expectException(QueryException::class);
        $this->insertReservation(
            status: 'pending',
            purpose: 'upgrade',
            upgradeGuardId: 991
        );
    }

    public function test_only_one_active_hold_may_claim_an_allocation(): void
    {
        $first = $this->insertReservation();
        $second = $this->insertReservation();
        $this->insertAllocation($first, 7001);

        $this->expectException(QueryException::class);
        $this->insertAllocation($second, 7001);
    }

    public function test_admin_cancellation_rolls_back_status_when_allocation_release_fails(): void
    {
        $reservationId = $this->insertReservation();
        $this->insertAllocation($reservationId);
        $token = (string) DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->value('token');
        $service = new class(
            $this->nodeService,
            $this->configurationService
        ) extends ReservationService
        {
            protected function releaseAllocationClaims(
                int $reservationId
            ): void {
                throw new \RuntimeException(
                    'Injected allocation release failure.'
                );
            }
        };

        try {
            $service->cancel($token, 'Atomic cancellation regression');
            $this->fail('The injected claim release failure must abort cancellation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Injected allocation release failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'pending',
        ]);
        $this->assertNull(
            DB::table('ptero_reservation_allocations')
                ->where('reservation_id', $reservationId)
                ->value('released_at')
        );
    }

    public function test_reservation_snapshot_is_built_under_the_configuration_lock(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../Services/ReservationService.php'
        );
        $method = strpos(
            $source,
            'public function reserveForCartItem'
        );
        $transaction = strpos(
            $source,
            'return DB::transaction',
            $method
        );
        $configurationLock = strpos(
            $source,
            '->lockProduct(',
            $transaction
        );
        $snapshot = strpos(
            $source,
            '->forCartItem($cartItem)',
            $configurationLock
        );
        $insert = strpos(
            $source,
            "DB::table('ptero_resource_reservations')->insertGetId",
            $snapshot
        );

        $this->assertNotFalse($method);
        $this->assertNotFalse($transaction);
        $this->assertNotFalse($configurationLock);
        $this->assertNotFalse($snapshot);
        $this->assertNotFalse($insert);
        $this->assertLessThan($configurationLock, $transaction);
        $this->assertLessThan($snapshot, $configurationLock);
        $this->assertLessThan($insert, $snapshot);
        $this->assertFalse(
            strpos(
                substr($source, $method, $transaction - $method),
                '->forCartItem('
            ),
            'No mutable configuration snapshot may be built before the transaction.'
        );
    }

    public function test_checkout_binding_uses_global_capacity_invoice_lock_order(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../Services/ReservationService.php'
        );
        $method = strpos(
            $source,
            'public function bindCartItemToService'
        );
        $nextMethod = strpos(
            $source,
            'public function preflightPaidService',
            $method
        );
        $body = substr($source, $method, $nextMethod - $method);
        $invoice = strpos($body, '$lockedInvoice = Invoice::query()');
        $service = strpos($body, '$lockedService = Service::query()');
        $reservation = strpos(
            $body,
            "DB::table('ptero_resource_reservations')"
        );
        $items = strpos($body, "DB::table('invoice_items')");

        $this->assertNotFalse($invoice);
        $this->assertNotFalse($service);
        $this->assertNotFalse($reservation);
        $this->assertNotFalse($items);
        $this->assertLessThan($service, $invoice);
        $this->assertLessThan($reservation, $service);
        $this->assertLessThan($items, $reservation);
    }

    public function test_active_commitment_blocks_extension_deactivation(): void
    {
        $this->insertReservation();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be disabled, replaced, or uninstalled');

        app(ExtensionLifecycleGuard::class)
            ->assertCanDeactivate(ExtensionLifecycleGuard::DYNAMIC_PTERODACTYL);
    }

    public function test_terminal_history_does_not_block_extension_deactivation(): void
    {
        $this->insertReservation(status: 'cancelled');

        app(ExtensionLifecycleGuard::class)
            ->assertCanDeactivate(ExtensionLifecycleGuard::DYNAMIC_PTERODACTYL);

        $this->addToAssertionCount(1);
    }

    public function test_confirmed_active_service_blocks_extension_deactivation(): void
    {
        $service = $this->makeService(Service::STATUS_ACTIVE);
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'confirmed',
            consumedAt: now()
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'cannot be disabled, replaced, or uninstalled'
        );

        app(ExtensionLifecycleGuard::class)
            ->assertCanDeactivate(
                ExtensionLifecycleGuard::DYNAMIC_PTERODACTYL
            );
    }

    public function test_confirmed_active_service_allows_same_identity_upgrade_only_in_maintenance(): void
    {
        $service = $this->makeService(Service::STATUS_ACTIVE);
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'confirmed',
            consumedAt: now()
        );
        $guard = app(ExtensionLifecycleGuard::class);

        try {
            $guard->assertCanUpgrade(
                ExtensionLifecycleGuard::DYNAMIC_PTERODACTYL
            );
            $this->fail(
                'Live durable services must require deployment maintenance.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'deployment maintenance',
                $exception->getMessage()
            );
        }

        Artisan::call('down');
        try {
            $guard->assertCanUpgrade(
                ExtensionLifecycleGuard::DYNAMIC_PTERODACTYL
            );
            $this->addToAssertionCount(1);
        } finally {
            Artisan::call('up');
        }
    }

    public function test_pending_commitment_keeps_its_snapshot_while_future_slider_metadata_changes(): void
    {
        $product = Product::factory()->create();
        $option = $this->resourceOption('Memory', 'memory');
        $option->products()->attach($product->id);
        $reservationId = $this->insertReservation(productId: $product->id);
        $originalPayload = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->value('configuration_payload');
        $originalFingerprint = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->value('configuration_fingerprint');

        $metadata = $option->metadata;
        $metadata['max'] = 200000;
        $option->metadata = $metadata;
        $option->save();

        $this->assertSame(200000, $option->fresh()->metadata['max']);
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'pending',
            'configuration_payload' => $originalPayload,
            'configuration_fingerprint' => $originalFingerprint,
        ]);
    }

    public function test_pending_commitment_keeps_its_snapshot_while_future_child_price_changes(): void
    {
        $product = Product::factory()->create();
        $root = ConfigOption::create([
            'name' => 'Location',
            'env_variable' => 'location',
            'type' => 'select',
            'hidden' => false,
            'upgradable' => false,
        ]);
        $root->products()->attach($product->id);
        $child = ConfigOption::create([
            'name' => 'Melbourne',
            'env_variable' => 'location',
            'type' => 'select',
            'hidden' => false,
            'upgradable' => false,
            'parent_id' => $root->id,
        ]);
        $plan = $child->plans()->create([
            'name' => 'Monthly',
            'type' => 'recurring',
            'billing_period' => 1,
            'billing_unit' => 'month',
        ]);
        $price = $plan->prices()->create([
            'currency_code' => 'USD',
            'price' => 5,
            'setup_fee' => 0,
        ]);
        $this->insertReservation(productId: $product->id);

        $price->price = 7;
        $price->save();

        $this->assertEquals(7.0, (float) $price->fresh()->price);
    }

    public function test_pending_commitment_prevents_detaching_its_configuration(): void
    {
        $product = Product::factory()->create();
        $option = $this->resourceOption('Disk', 'disk');
        $option->products()->attach($product->id);
        $this->insertReservation(productId: $product->id);

        try {
            $option->products()->detach($product->id);
            $this->fail('Expected the product assignment to remain frozen.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'required by an unresolved or active capacity commitment',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('config_option_products', [
            'config_option_id' => $option->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_pending_commitment_prevents_reassigning_resource_identity(): void
    {
        $product = Product::factory()->create();
        $option = $this->resourceOption('Memory', 'memory');
        $option->products()->attach($product->id);
        $this->insertReservation(productId: $product->id);

        $option->env_variable = 'different_memory';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'required by an unresolved or active capacity commitment'
        );

        $option->save();
    }

    public function test_pending_commitment_prevents_deleting_its_product(): void
    {
        $product = Product::factory()->create();
        $this->insertReservation(productId: $product->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'required by an unresolved or active capacity commitment'
        );

        $product->delete();
    }

    public function test_confirmed_active_service_keeps_its_configuration_identity(): void
    {
        $product = Product::factory()->create();
        $option = $this->resourceOption('Disk', 'disk');
        $option->products()->attach($product->id);
        $service = $this->makeService(Service::STATUS_ACTIVE);
        DB::table('services')->where('id', $service->id)->update([
            'product_id' => $product->id,
        ]);
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            productId: $product->id,
            status: 'confirmed',
            consumedAt: now()
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'required by an unresolved or active capacity commitment'
        );

        $option->products()->detach($product->id);
    }

    public function test_confirmed_cancelled_service_releases_configuration_identity(): void
    {
        $product = Product::factory()->create();
        $option = $this->resourceOption('Disk', 'disk');
        $option->products()->attach($product->id);
        $service = $this->makeService(Service::STATUS_CANCELLED);
        DB::table('services')->where('id', $service->id)->update([
            'product_id' => $product->id,
        ]);
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            productId: $product->id,
            status: 'confirmed',
            consumedAt: now()
        );

        $option->products()->detach($product->id);

        $this->assertDatabaseMissing('config_option_products', [
            'config_option_id' => $option->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_dynamic_slider_cannot_be_attached_to_product_with_unmigrated_live_service(): void
    {
        $product = $this->pterodactylProduct();
        $service = $this->makeService(Service::STATUS_ACTIVE);
        DB::table('services')->where('id', $service->id)->update([
            'product_id' => $product->id,
        ]);
        $option = $this->resourceOption('Memory', 'memory');

        try {
            $option->products()->attach($product->id);
            $this->fail('Expected legacy service conversion to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'has no confirmed checkout reservation',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing('config_option_products', [
            'config_option_id' => $option->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_attached_option_cannot_be_activated_as_dynamic_for_unmigrated_live_service(): void
    {
        $product = $this->pterodactylProduct();
        $service = $this->makeService(Service::STATUS_ACTIVE);
        DB::table('services')->where('id', $service->id)->update([
            'product_id' => $product->id,
        ]);
        $option = ConfigOption::create([
            'name' => 'Memory',
            'env_variable' => 'memory',
            'type' => 'number',
            'hidden' => false,
            'upgradable' => false,
            'metadata' => ['resource_type' => 'memory'],
        ]);
        $option->products()->attach($product->id);
        $option->type = 'dynamic_slider';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'has no confirmed checkout reservation'
        );

        $option->save();
    }

    public function test_product_with_dynamic_slider_cannot_switch_to_pterodactyl_with_unmigrated_live_service(): void
    {
        $legacyHost = Server::query()->create([
            'name' => 'Legacy host',
            'extension' => 'LegacyHost',
            'type' => 'server',
            'enabled' => true,
        ]);
        $pterodactyl = Server::query()->create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        $product = Product::factory()->create([
            'server_id' => $legacyHost->id,
        ]);
        $option = $this->resourceOption('Memory', 'memory');
        $option->products()->attach($product->id);
        $service = $this->makeService(Service::STATUS_ACTIVE);
        DB::table('services')->where('id', $service->id)->update([
            'product_id' => $product->id,
        ]);
        $product->server_id = $pterodactyl->id;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'has no confirmed checkout reservation'
        );

        $product->save();
    }

    public function test_confirmed_service_allows_future_dynamic_slider_attachment(): void
    {
        $product = $this->pterodactylProduct();
        $service = $this->makeService(Service::STATUS_ACTIVE);
        DB::table('services')->where('id', $service->id)->update([
            'product_id' => $product->id,
        ]);
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'confirmed',
            consumedAt: now(),
            productId: $product->id
        );
        $option = $this->resourceOption('CPU', 'cpu');

        $option->products()->attach($product->id);

        $this->assertDatabaseHas('config_option_products', [
            'config_option_id' => $option->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_cancelled_legacy_service_allows_dynamic_slider_attachment(): void
    {
        $product = $this->pterodactylProduct();
        $service = $this->makeService(Service::STATUS_CANCELLED);
        DB::table('services')->where('id', $service->id)->update([
            'product_id' => $product->id,
        ]);
        $option = $this->resourceOption('Disk', 'disk');

        $option->products()->attach($product->id);

        $this->assertDatabaseHas('config_option_products', [
            'config_option_id' => $option->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_fulfilled_history_allows_future_configuration_changes(): void
    {
        $product = Product::factory()->create();
        $option = $this->resourceOption('CPU', 'cpu');
        $option->products()->attach($product->id);
        $this->insertReservation(
            productId: $product->id,
            status: 'confirmed',
            consumedAt: now()
        );

        $metadata = $option->metadata;
        $metadata['max'] = 200000;
        $option->metadata = $metadata;
        $option->save();

        $this->assertSame(200000, $option->fresh()->metadata['max']);
    }

    public function test_begin_keeps_paid_commitment_reserved_until_verified_completion(): void
    {
        $service = $this->makeService();
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed'
        );
        $this->insertAllocation($reservationId);

        $this->configurationService->shouldReceive('assertServiceMatches')
            ->once()
            ->with($service, Mockery::on(fn ($row) => (int) $row->id === $reservationId));

        $context = $this->service()->beginProvisioning($service);

        $this->assertSame(4, $context['node_id']);
        $this->assertFalse($context['already_consumed']);
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'paid_committed',
        ]);
        $this->assertNotNull(
            DB::table('ptero_resource_reservations')->where('id', $reservationId)->value('provisioning_started_at')
        );

        $this->assertNotNull($context['provisioning_lease_id']);
        $this->assertTrue(
            $this->service()->completeProvisioning(
                $service->id,
                $context['provisioning_lease_id'],
                $this->externalServer()
            )
        );
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'confirmed',
        ]);
        $this->assertNotNull(
            DB::table('ptero_resource_reservations')->where('id', $reservationId)->value('consumed_at')
        );
        $this->assertSame('active', $service->fresh()->status);
        $this->assertNotNull(
            DB::table('ptero_reservation_allocations')
                ->where('reservation_id', $reservationId)
                ->value('released_at')
        );
    }

    public function test_active_provisioning_lease_rejects_a_second_worker(): void
    {
        $service = $this->makeService();
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed',
            provisioningStartedAt: now()
        );
        $this->insertAllocation($reservationId);

        $this->configurationService->shouldNotReceive('assertServiceMatches');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already being provisioned');

        $this->service()->beginProvisioning($service);
    }

    public function test_failed_provisioning_releases_only_the_attempt_lease(): void
    {
        $service = $this->makeService();
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed',
            provisioningStartedAt: now(),
            provisioningLeaseId: 'active-lease'
        );

        $this->service()->failProvisioning(
            $service->id,
            'active-lease',
            new \RuntimeException('Pterodactyl unavailable')
        );

        $reservation = DB::table('ptero_resource_reservations')->where('id', $reservationId)->first();
        $this->assertSame('paid_committed', $reservation->status);
        $this->assertNull($reservation->provisioning_started_at);
        $this->assertSame('Pterodactyl unavailable', $reservation->last_provisioning_error);
        $this->assertNotNull($reservation->next_provisioning_attempt_at);
    }

    public function test_stale_worker_cannot_consume_or_clear_a_newer_lease(): void
    {
        $service = $this->makeService();
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed',
            provisioningStartedAt: now(),
            provisioningLeaseId: 'new-lease'
        );

        try {
            $this->service()->completeProvisioning(
                $service->id,
                'old-lease',
                $this->externalServer()
            );
            $this->fail('Expected stale provisioning lease rejection.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no longer owns', $exception->getMessage());
        }

        $this->service()->failProvisioning(
            $service->id,
            'old-lease',
            new \RuntimeException('stale worker failed')
        );

        $reservation = DB::table('ptero_resource_reservations')->where('id', $reservationId)->first();
        $this->assertSame('paid_committed', $reservation->status);
        $this->assertSame('new-lease', $reservation->provisioning_lease_id);
        $this->assertNotNull($reservation->provisioning_started_at);
        $this->assertNull($reservation->last_provisioning_error);
    }

    public function test_completed_reservation_is_idempotent_for_server_reconciliation(): void
    {
        $service = $this->makeService();
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'confirmed',
            consumedAt: now()
        );
        $reservationId = (int) DB::table('ptero_resource_reservations')
            ->where('service_id', $service->id)
            ->value('id');
        $this->insertAllocation($reservationId);

        $context = $this->service()->beginProvisioning($service);

        $this->assertTrue($context['already_consumed']);
        $this->assertTrue($this->service()->completeProvisioning($service->id));
    }

    public function test_bound_invoice_commits_even_if_current_metadata_no_longer_creates_new_holds(): void
    {
        $service = $this->makeService();
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
        ]);
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            guaranteedUntil: now()->addDays(7)
        );
        $this->insertAllocation($reservationId);
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'price' => 12.50,
            'quantity' => 1,
            'description' => 'Dynamic service',
        ]);

        $this->configurationService->shouldNotReceive('requiresReservation');
        $this->configurationService->shouldReceive('assertExclusiveProvisioningControl')->once();
        $this->configurationService->shouldReceive('assertServiceMatches')->once();

        $this->assertTrue($this->service()->commitPaidService($service, $invoice));
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'invoice_id' => $invoice->id,
            'status' => 'paid_committed',
        ]);
        $this->assertSame('provisioning', $service->fresh()->status);
    }

    public function test_allocation_drift_rejects_payment_before_capacity_is_committed(): void
    {
        $service = $this->makeService();
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
        ]);
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            guaranteedUntil: now()->addDays(7)
        );
        $this->insertAllocation($reservationId);
        DB::table('ptero_reservation_allocations')
            ->where('reservation_id', $reservationId)
            ->update(['port' => 25566]);
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'price' => 12.50,
            'quantity' => 1,
            'description' => 'Dynamic service',
        ]);

        $this->configurationService->shouldNotReceive('requiresReservation');
        $this->configurationService
            ->shouldReceive('assertExclusiveProvisioningControl')
            ->once();
        $this->configurationService
            ->shouldReceive('assertServiceMatches')
            ->once();

        try {
            $this->service()->commitPaidService($service, $invoice);
            $this->fail(
                'Expected allocation drift to reject payment commitment.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Allocation claims no longer match',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'pending',
        ]);
    }

    public function test_non_dynamic_service_does_not_require_stock_control_gate(): void
    {
        $service = $this->makeService();
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'status' => Invoice::STATUS_PENDING,
        ]);
        $this->configurationService->shouldReceive('requiresReservation')
            ->once()
            ->with((int) $service->product_id)
            ->andReturnFalse();
        $this->configurationService->shouldNotReceive(
            'assertExclusiveProvisioningControl'
        );

        $this->assertFalse(
            $this->service()->commitPaidService($service, $invoice)
        );
    }

    public function test_tampered_invoice_line_cannot_consume_reserved_capacity(): void
    {
        $service = $this->makeService();
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
        ]);
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            guaranteedUntil: now()->addDays(7)
        );
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'price' => 0.01,
            'quantity' => 1,
            'description' => 'Tampered dynamic service',
        ]);

        $this->configurationService->shouldNotReceive('requiresReservation');
        $this->configurationService->shouldReceive('assertExclusiveProvisioningControl')->once();
        $this->configurationService->shouldNotReceive('assertServiceMatches');

        try {
            $this->service()->commitPaidService($service, $invoice);
            $this->fail('Expected invoice-line drift to reject the payment commitment.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'invoice line no longer matches',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'pending',
        ]);
    }

    public function test_payment_after_the_seven_day_guarantee_fails_closed(): void
    {
        $service = $this->makeService();
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
        ]);
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'price' => 12.50,
            'quantity' => 1,
            'description' => 'Dynamic server',
        ]);
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            invoiceId: $invoice->id,
            userId: $service->user_id,
            guaranteedUntil: now()->subSecond()
        );

        $this->configurationService->shouldNotReceive('requiresReservation');
        $this->configurationService->shouldReceive('assertExclusiveProvisioningControl')->once();
        $this->configurationService->shouldNotReceive('assertServiceMatches');

        try {
            $this->service()->commitPaidService($service, $invoice);
            $this->fail('Expected the expired capacity guarantee to reject payment.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('seven-day capacity guarantee expired', $exception->getMessage());
        }

        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'pending',
        ]);
        $this->assertSame('pending', $service->fresh()->status);
    }

    public function test_completion_requires_a_durable_external_identity(): void
    {
        $service = $this->makeService();
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed',
            provisioningStartedAt: now(),
            provisioningLeaseId: 'lease'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('complete external server identity');

        try {
            $this->service()->completeProvisioning($service->id, 'lease', ['attributes' => ['id' => 8]]);
        } finally {
            $this->assertDatabaseHas('ptero_resource_reservations', [
                'id' => $reservationId,
                'status' => 'paid_committed',
            ]);
            $this->assertSame('pending', $service->fresh()->status);
        }
    }

    public function test_cancellation_tombstone_wins_after_external_create(): void
    {
        $service = $this->makeService();
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed',
            provisioningStartedAt: now(),
            provisioningLeaseId: 'lease',
            cancellationRequestedAt: now()
        );
        $this->insertAllocation($reservationId);

        $this->assertFalse(
            $this->service()->completeProvisioning(
                $service,
                'lease',
                $this->externalServer()
            )
        );

        $this->assertSame('cancellation_pending', $service->fresh()->status);
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'paid_committed',
            'external_server_id' => 71,
        ]);
    }

    public function test_unpinned_paid_cancellation_exposes_only_the_signed_checkout_contract(): void
    {
        $service = $this->makeService('provisioning');
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed'
        );
        $this->insertAllocation($reservationId);
        $this->service()->requestServiceCancellation($service);

        $context = $this->service()
            ->cancellationReconciliationContext($service);

        $this->assertSame($reservationId, $context['reservation_id']);
        $this->assertSame(
            (string) $service->id,
            $context['external_server_external_id']
        );
        $this->assertSame(
            "paymenter-user-{$service->user_id}",
            $context['user_external_id']
        );
        $this->assertSame(4, $context['node_id']);
        $this->assertSame(4096, $context['memory']);
        $this->assertSame(200, $context['cpu']);
        $this->assertSame(51200, $context['disk']);
        $this->assertSame(0, $context['client_allocation_limit']);
        $this->assertFalse($context['provisioning_in_flight']);
        $this->assertSame([7001], array_column(
            $context['allocations'],
            'allocation_id'
        ));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $context['configuration_fingerprint']
        );
    }

    public function test_absent_unpinned_server_completes_paid_cancellation(): void
    {
        $service = $this->makeService('provisioning');
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed'
        );
        $this->insertAllocation($reservationId);
        $this->service()->requestServiceCancellation($service);

        $this->assertTrue(
            $this->service()->completeServiceCancellation($service)
        );

        $this->assertSame(
            Service::STATUS_CANCELLED,
            $service->fresh()->status
        );
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'cancelled',
        ]);
        $this->assertNotNull(
            DB::table('ptero_reservation_allocations')
                ->where('reservation_id', $reservationId)
                ->value('released_at')
        );
    }

    public function test_paid_cancellation_context_marks_an_active_create_race(): void
    {
        $service = $this->makeService('provisioning');
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed',
            provisioningStartedAt: now(),
            provisioningLeaseId: 'active-create'
        );
        $this->insertAllocation($reservationId);
        $this->service()->requestServiceCancellation($service);

        $context = $this->service()
            ->cancellationReconciliationContext($service);

        $this->assertTrue($context['provisioning_in_flight']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('still in flight');

        $this->service()->pinCancellationServerIdentity(
            $service,
            $this->cancellationServer($service->id),
            44
        );
    }

    public function test_timed_out_create_identity_is_pinned_idempotently_before_cancellation(): void
    {
        $service = $this->makeService('provisioning');
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed'
        );
        $this->insertAllocation($reservationId);
        $this->service()->requestServiceCancellation($service);
        $server = $this->cancellationServer($service->id);

        $first = $this->service()->pinCancellationServerIdentity(
            $service,
            $server,
            44
        );
        $second = $this->service()->pinCancellationServerIdentity(
            $service,
            $server,
            44
        );

        $this->assertSame(71, $first['external_server_id']);
        $this->assertSame($first, $second);
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'paid_committed',
            'external_server_id' => 71,
            'external_user_id' => 44,
            'external_server_identifier' => 'created',
        ]);
    }

    public function test_mismatched_timed_out_create_is_never_pinned(): void
    {
        $service = $this->makeService('provisioning');
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed'
        );
        $this->insertAllocation($reservationId);
        $this->service()->requestServiceCancellation($service);
        $server = $this->cancellationServer($service->id);
        $server['attributes']['limits']['disk']++;

        try {
            $this->service()->pinCancellationServerIdentity(
                $service,
                $server,
                44
            );
            $this->fail('Expected mismatched server rejection.');
        } catch (PermanentProvisioningException $exception) {
            $this->assertStringContainsString(
                'reserved disk',
                $exception->getMessage()
            );
        }

        $this->assertNull(
            DB::table('ptero_resource_reservations')
                ->where('id', $reservationId)
                ->value('external_server_id')
        );
    }

    public function test_unpaid_cancellation_returns_product_stock_exactly_once(): void
    {
        [$service, $product] = $this->makeStockedService(stock: 4);
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id
        );
        $this->insertAllocation($reservationId);

        $this->assertTrue($this->service()->requestServiceCancellation($service));
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertTrue(
            app(DurableFulfillmentService::class)
                ->cancellationIsDurablyComplete($service)
        );
        $this->assertNotNull(
            DB::table('ptero_resource_reservations')
                ->where('id', $reservationId)
                ->value('product_stock_released_at')
        );

        $this->assertFalse($this->service()->requestServiceCancellation($service));
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_paid_cancellation_returns_stock_only_after_external_absence(): void
    {
        [$service, $product] = $this->makeStockedService(
            stock: 4,
            status: Service::STATUS_ACTIVE
        );
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'confirmed',
            consumedAt: now()
        );

        $this->assertTrue($this->service()->requestServiceCancellation($service));
        $this->assertSame(4, $product->fresh()->stock);
        $this->assertFalse(
            app(DurableFulfillmentService::class)
                ->cancellationIsDurablyComplete($service)
        );
        $this->assertNull(
            DB::table('ptero_resource_reservations')
                ->where('id', $reservationId)
                ->value('product_stock_released_at')
        );

        $this->assertTrue($this->service()->completeServiceCancellation($service));
        $this->assertTrue($this->service()->completeServiceCancellation($service));
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertTrue(
            app(DurableFulfillmentService::class)
                ->cancellationIsDurablyComplete($service)
        );
    }

    public function test_failed_external_cancellation_keeps_product_stock_committed(): void
    {
        [$service, $product] = $this->makeStockedService(
            stock: 4,
            status: Service::STATUS_ACTIVE
        );
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'confirmed',
            consumedAt: now()
        );
        $this->service()->requestServiceCancellation($service);

        $this->service()->recordPermanentCancellationFailure(
            $service,
            new \RuntimeException('Pterodactyl delete failed')
        );

        $this->assertSame(4, $product->fresh()->stock);
        $this->assertNull(
            DB::table('ptero_resource_reservations')
                ->where('id', $reservationId)
                ->value('product_stock_released_at')
        );
    }

    public function test_invoice_cancellation_atomically_releases_checkout_obligations(): void
    {
        [$service, $product] = $this->makeStockedService(4);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
        ]);
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'price' => 12.50,
            'quantity' => 1,
            'description' => 'Dynamic server',
        ]);
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            invoiceId: $invoice->id,
            userId: $service->user_id
        );
        $this->insertAllocation($reservationId);

        app(CancelInvoiceService::class)->handle($invoice);

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => 'cancelled',
        ]);
        $this->assertNotNull(
            DB::table('ptero_reservation_allocations')
                ->where('reservation_id', $reservationId)
                ->value('released_at')
        );
    }

    public function test_capacity_invoice_cannot_be_raw_cancelled_or_deleted(): void
    {
        $service = $this->makeService();
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'status' => Invoice::STATUS_PENDING,
        ]);
        $this->insertReservation(
            serviceId: $service->id,
            invoiceId: $invoice->id,
            userId: $service->user_id
        );

        try {
            $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
            $this->fail('Expected raw capacity invoice cancellation to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'fulfillment coordinator',
                $exception->getMessage()
            );
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be deleted');
        $invoice->delete();
    }

    public function test_active_allocation_cannot_be_claimed_twice(): void
    {
        $first = $this->insertReservation();
        $second = $this->insertReservation();
        $this->insertAllocation($first, allocationId: 7001);

        $this->expectException(QueryException::class);
        $this->insertAllocation($second, allocationId: 7001);
    }

    public function test_dynamic_service_status_cannot_bypass_fulfillment_state_machine(): void
    {
        $service = $this->makeService();
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed'
        );
        $service->status = 'active';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fulfillment state machine');

        $service->save();
    }

    public function test_reservation_identity_guard_survives_mutable_product_classification(): void
    {
        $service = $this->makeService();
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id
        );

        $service->currency_code = 'AUD';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reservation-backed service identity');

        $service->save();
    }

    public function test_reserved_panel_host_cannot_change_while_commitment_is_live(): void
    {
        $server = Server::query()->create([
            'name' => 'Reserved Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        $host = $server->settings()->create([
            'key' => 'host',
            'value' => 'https://panel.example.com',
            'type' => 'string',
            'encrypted' => false,
        ]);
        $reservationId = $this->insertReservation();
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update(['server_extension_id' => $server->id]);

        $host->value = 'https://different-panel.example.com';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pinned by active capacity commitments');

        $host->save();
    }

    public function test_exclusive_provisioning_control_cannot_change_while_commitment_is_live(): void
    {
        $extension = Extension::query()->firstOrCreate(
            [
                'extension' => ExtensionLifecycleGuard::DYNAMIC_PTERODACTYL,
                'type' => 'other',
            ],
            [
                'name' => 'Dynamic Pterodactyl',
                'enabled' => true,
            ]
        );
        $control = $extension->settings()->firstOrCreate(
            ['key' => 'exclusive_provisioning_control'],
            [
                'value' => true,
                'type' => 'boolean',
                'encrypted' => false,
            ]
        );
        if (! (bool) $control->value) {
            $control->value = true;
            $control->save();
        }
        $this->insertReservation();

        $control->value = false;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be disabled, replaced, or uninstalled');

        $control->save();
    }

    public function test_stock_control_setting_cannot_be_deleted_while_commitment_is_live(): void
    {
        $extension = Extension::query()->firstOrCreate(
            [
                'extension' => ExtensionLifecycleGuard::DYNAMIC_PTERODACTYL,
                'type' => 'other',
            ],
            [
                'name' => 'Dynamic Pterodactyl',
                'enabled' => true,
            ]
        );
        $control = $extension->settings()->firstOrCreate(
            ['key' => 'exclusive_provisioning_control'],
            [
                'value' => true,
                'type' => 'boolean',
                'encrypted' => false,
            ]
        );
        $this->insertReservation();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be disabled, replaced, or uninstalled');

        $control->delete();
    }

    public function test_resource_property_create_update_and_delete_bypasses_are_blocked(): void
    {
        $service = $this->makeService();
        $update = $service->properties()->create([
            'key' => 'memory',
            'value' => 4096,
        ]);
        $delete = $service->properties()->create([
            'key' => 'disk',
            'value' => 51200,
        ]);
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id
        );

        $operations = [
            fn () => $service->properties()->create([
                'key' => 'cpu',
                'value' => 300,
            ]),
            function () use ($update): void {
                $update->value = 8192;
                $update->save();
            },
            fn () => $delete->delete(),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Expected resource property mutation to be blocked.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'capacity-aware fulfillment coordinator',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_resource_config_create_update_and_delete_bypasses_are_blocked(): void
    {
        $service = $this->makeService();
        $memory = $this->resourceOption('Memory', 'memory');
        $cpu = $this->resourceOption('CPU', 'cpu');
        $disk = $this->resourceOption('Disk', 'disk');
        $update = $service->configs()->create([
            'config_option_id' => $memory->id,
            'config_value_id' => null,
            'slider_value' => 4096,
        ]);
        $delete = $service->configs()->create([
            'config_option_id' => $disk->id,
            'config_value_id' => null,
            'slider_value' => 51200,
        ]);
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id
        );

        $operations = [
            fn () => $service->configs()->create([
                'config_option_id' => $cpu->id,
                'config_value_id' => null,
                'slider_value' => 300,
            ]),
            function () use ($update): void {
                $update->slider_value = 8192;
                $update->save();
            },
            fn () => $delete->delete(),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Expected resource config mutation to be blocked.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'capacity-aware fulfillment coordinator',
                    $exception->getMessage()
                );
            }
        }

        FulfillmentStatusTransitionService::run(
            $service,
            function () use ($update): void {
                $update->slider_value = 8192;
                $update->save();
            }
        );
        $this->assertSame(8192.0, (float) $update->fresh()->slider_value);
    }

    public function test_reservation_backed_service_cannot_be_hard_deleted(): void
    {
        $service = $this->makeService();
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'confirmed'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('retained as fulfillment records');

        $service->delete();
    }

    public function test_exact_guarantee_boundary_expires_order_and_blocks_payment(): void
    {
        Carbon::setTestNow('2026-07-26 12:00:00');

        try {
            $service = $this->makeService();
            $invoice = Invoice::factory()->create([
                'user_id' => $service->user_id,
                'status' => Invoice::STATUS_PENDING,
                'due_at' => now(),
            ]);
            $invoice->items()->create([
                'reference_type' => Service::class,
                'reference_id' => $service->id,
                'price' => 10,
                'quantity' => 1,
                'description' => 'Dynamic server',
            ]);
            $reservationId = $this->insertReservation(
                serviceId: $service->id,
                invoiceId: $invoice->id,
                userId: $service->user_id,
                guaranteedUntil: now()
            );
            $this->insertAllocation($reservationId);

            $this->assertSame(1, $this->service()->cleanupExpired());
            $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
            $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
            $this->assertDatabaseHas('ptero_resource_reservations', [
                'id' => $reservationId,
                'status' => 'expired',
            ]);
            $this->assertNotNull(
                DB::table('ptero_reservation_allocations')
                    ->where('reservation_id', $reservationId)
                    ->value('released_at')
            );
            try {
                app(MarkInvoicePaidService::class)->handle($invoice);
                $this->fail('Expected the cancelled day-eight invoice to reject payment.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'capacity guarantee deadline expired',
                    $exception->getMessage()
                );
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_gateway_payment_is_preserved_for_refund_review(): void
    {
        Carbon::setTestNow('2026-07-26 12:00:01');

        try {
            $service = $this->makeService();
            $invoice = Invoice::factory()->create([
                'user_id' => $service->user_id,
                'status' => Invoice::STATUS_PENDING,
                'due_at' => now()->subSecond(),
                'currency_code' => 'USD',
            ]);
            $invoice->items()->create([
                'reference_type' => Service::class,
                'reference_id' => $service->id,
                'price' => 12.50,
                'quantity' => 1,
                'description' => 'Dynamic server',
            ]);
            $reservationId = $this->insertReservation(
                serviceId: $service->id,
                invoiceId: $invoice->id,
                userId: $service->user_id,
                guaranteedUntil: now()->subSecond()
            );

            ExtensionHelper::addPayment(
                $invoice->id,
                null,
                12.50,
                transactionId: 'late-gateway-transaction'
            );

            $this->assertSame(1, $invoice->transactions()->count());
            $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
            $this->assertNotNull(
                $invoice->fresh()->payment_attention_required_at
            );
            $this->assertDatabaseHas('ptero_resource_reservations', [
                'id' => $reservationId,
                'status' => 'pending',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_partial_payment_expiry_releases_capacity_without_cancelling_payment_fact(): void
    {
        Carbon::setTestNow('2026-07-26 12:00:00');

        try {
            $service = $this->makeService();
            $invoice = Invoice::factory()->create([
                'user_id' => $service->user_id,
                'status' => Invoice::STATUS_PENDING,
                'due_at' => now()->addDays(7),
                'currency_code' => 'USD',
            ]);
            $invoice->items()->create([
                'reference_type' => Service::class,
                'reference_id' => $service->id,
                'price' => 12.50,
                'quantity' => 1,
                'description' => 'Dynamic server',
            ]);
            $reservationId = $this->insertReservation(
                serviceId: $service->id,
                invoiceId: $invoice->id,
                userId: $service->user_id,
                guaranteedUntil: now()->addDays(7)
            );
            $this->insertAllocation($reservationId);
            ExtensionHelper::addPayment(
                $invoice->id,
                null,
                5.00,
                transactionId: 'partial-gateway-transaction'
            );

            Carbon::setTestNow(now()->addDays(7));
            $this->assertSame(1, $this->service()->cleanupExpired());

            $this->assertSame(1, $invoice->transactions()->count());
            $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
            $this->assertNotNull(
                $invoice->fresh()->payment_attention_required_at
            );
            $this->assertSame(
                Service::STATUS_PROVISIONING_FAILED,
                $service->fresh()->status
            );
            $this->assertDatabaseHas('ptero_resource_reservations', [
                'id' => $reservationId,
                'status' => 'expired',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_mixed_invoice_expiry_releases_dynamic_and_static_stock_once(): void
    {
        Carbon::setTestNow('2026-07-26 12:00:00');

        try {
            [$dynamicService, $dynamicProduct] = $this->makeStockedService(4);
            [$staticService, $staticProduct] = $this->makeStockedService(
                7,
                userId: $dynamicService->user_id
            );
            $invoice = Invoice::factory()->create([
                'user_id' => $dynamicService->user_id,
                'status' => Invoice::STATUS_PENDING,
                'due_at' => now(),
                'currency_code' => 'USD',
            ]);
            foreach ([$dynamicService, $staticService] as $linkedService) {
                $invoice->items()->create([
                    'reference_type' => Service::class,
                    'reference_id' => $linkedService->id,
                    'price' => 12.50,
                    'quantity' => 1,
                    'description' => 'Server',
                ]);
            }
            $reservationId = $this->insertReservation(
                serviceId: $dynamicService->id,
                invoiceId: $invoice->id,
                userId: $dynamicService->user_id,
                guaranteedUntil: now()
            );
            $this->insertAllocation($reservationId);

            $this->assertSame(1, $this->service()->cleanupExpired());
            $this->assertSame(0, $this->service()->cleanupExpired());

            $this->assertSame(5, $dynamicProduct->fresh()->stock);
            $this->assertSame(8, $staticProduct->fresh()->stock);
            $this->assertSame(
                Service::STATUS_CANCELLED,
                $dynamicService->fresh()->status
            );
            $this->assertSame(
                Service::STATUS_CANCELLED,
                $staticService->fresh()->status
            );
            $this->assertSame(
                Invoice::STATUS_CANCELLED,
                $invoice->fresh()->status
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_mixed_invoice_payment_attention_releases_all_stock_once(): void
    {
        Carbon::setTestNow('2026-07-26 12:00:00');

        try {
            [$dynamicService, $dynamicProduct] = $this->makeStockedService(4);
            [$staticService, $staticProduct] = $this->makeStockedService(
                7,
                userId: $dynamicService->user_id
            );
            $invoice = Invoice::factory()->create([
                'user_id' => $dynamicService->user_id,
                'status' => Invoice::STATUS_PENDING,
                'due_at' => now()->addDays(7),
                'currency_code' => 'USD',
            ]);
            foreach ([$dynamicService, $staticService] as $linkedService) {
                $invoice->items()->create([
                    'reference_type' => Service::class,
                    'reference_id' => $linkedService->id,
                    'price' => 12.50,
                    'quantity' => 1,
                    'description' => 'Server',
                ]);
            }
            $reservationId = $this->insertReservation(
                serviceId: $dynamicService->id,
                invoiceId: $invoice->id,
                userId: $dynamicService->user_id,
                guaranteedUntil: now()->addDays(7)
            );
            $this->insertAllocation($reservationId);
            ExtensionHelper::addPayment(
                $invoice->id,
                null,
                5,
                transactionId: 'mixed-partial-payment'
            );

            Carbon::setTestNow(now()->addDays(7));
            $this->assertSame(1, $this->service()->cleanupExpired());
            $this->assertSame(0, $this->service()->cleanupExpired());

            $this->assertSame(5, $dynamicProduct->fresh()->stock);
            $this->assertSame(8, $staticProduct->fresh()->stock);
            $this->assertSame(
                Service::STATUS_PROVISIONING_FAILED,
                $dynamicService->fresh()->status
            );
            $this->assertSame(
                Service::STATUS_PROVISIONING_FAILED,
                $staticService->fresh()->status
            );
            $this->assertSame(
                Invoice::STATUS_PENDING,
                $invoice->fresh()->status
            );
            $this->assertNotNull(
                $invoice->fresh()->payment_attention_required_at
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_generic_order_age_cron_cannot_shorten_seven_day_capacity_guarantee(): void
    {
        Carbon::setTestNow('2026-07-26 12:00:00');

        try {
            config(['settings.cronjob_order_cancel' => 3]);
            $service = $this->makeService();
            DB::table('services')
                ->where('id', $service->id)
                ->update(['created_at' => now()->subDays(4)]);
            $reservationId = $this->insertReservation(
                serviceId: $service->id,
                userId: $service->user_id,
                guaranteedUntil: now()->addDays(3)
            );
            DB::table('ptero_resource_reservations')
                ->where('id', $reservationId)
                ->update(['expires_at' => now()->addDays(3)]);
            $this->insertAllocation($reservationId);

            $this->artisan('app:cron-job')->assertExitCode(0);
            $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
            $this->assertDatabaseHas('ptero_resource_reservations', [
                'id' => $reservationId,
                'status' => 'pending',
            ]);

            Carbon::setTestNow(now()->addDays(3));
            $this->assertSame(1, $this->service()->cleanupExpired());
            $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_checkout_cleanup_never_reclaims_upgrade_reservations(): void
    {
        $service = $this->makeService();
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            guaranteedUntil: now()->subSecond()
        );
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'purpose' => 'upgrade',
                'expires_at' => now()->subSecond(),
            ]);
        $this->insertAllocation($reservationId);

        $this->assertSame(0, $this->service()->cleanupExpired());
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'purpose' => 'upgrade',
            'status' => ResourceReservation::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('ptero_reservation_allocations', [
            'reservation_id' => $reservationId,
            'released_at' => null,
        ]);
    }

    public function test_cron_can_suspend_a_confirmed_dynamic_service_through_coordinator(): void
    {
        Queue::fake();
        config([
            'settings.cronjob_order_suspend' => 2,
            'settings.cronjob_invoice' => 7,
        ]);
        $service = $this->makeBillableService(Service::STATUS_ACTIVE);
        DB::table('services')->where('id', $service->id)->update([
            'expires_at' => now()->subDays(3),
            'price' => 10,
        ]);
        $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'confirmed'
        );

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
        Queue::assertPushed(
            SuspendJob::class,
            fn (SuspendJob $job): bool => (int) $job->service->id === (int) $service->id
        );
    }

    public function test_paid_renewal_can_reactivate_confirmed_dynamic_service(): void
    {
        Queue::fake();
        $service = $this->makeBillableService(Service::STATUS_SUSPENDED);
        DB::table('services')->where('id', $service->id)->update([
            'price' => '10.00',
        ]);
        $service = $service->fresh();
        $this->insertConfirmedCheckoutCommitment($service);
        $renewalInvoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => $service->currency_code,
            'due_at' => $service->expires_at,
        ]);
        $renewalInvoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'price' => $service->price,
            'quantity' => $service->quantity,
            'description' => 'Dynamic service renewal',
        ]);

        app(MarkInvoicePaidService::class)->handle($renewalInvoice);

        $this->assertSame(
            Invoice::STATUS_PAID,
            $renewalInvoice->fresh()->status
        );
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
    }

    public function test_mixed_paid_invoice_routes_only_the_row_backed_service_through_dynamic_commit(): void
    {
        Bus::fake([CreateJob::class]);
        $server = Server::query()->create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        $dynamicProduct = Product::factory()->create(['server_id' => $server->id]);
        $staticProduct = Product::factory()->create(['server_id' => $server->id]);
        $dynamicPlan = $dynamicProduct->plans()->create([
            'name' => 'Dynamic',
            'type' => 'recurring',
            'billing_period' => 1,
            'billing_unit' => 'month',
        ]);
        $staticPlan = $staticProduct->plans()->create([
            'name' => 'Static',
            'type' => 'recurring',
            'billing_period' => 1,
            'billing_unit' => 'month',
        ]);
        $user = User::withoutEvents(fn () => User::factory()->create());
        $dynamicService = Service::withoutEvents(fn () => Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $dynamicProduct->id,
            'plan_id' => $dynamicPlan->id,
            'status' => Service::STATUS_PENDING,
            'currency_code' => 'USD',
        ]));
        $staticService = Service::withoutEvents(fn () => Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $staticProduct->id,
            'plan_id' => $staticPlan->id,
            'status' => Service::STATUS_PENDING,
            'currency_code' => 'USD',
        ]));
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
        ]);
        foreach ([$dynamicService, $staticService] as $invoiceService) {
            $invoice->items()->create([
                'reference_type' => Service::class,
                'reference_id' => $invoiceService->id,
                'price' => 12.50,
                'quantity' => 1,
                'description' => 'Mixed checkout service',
            ]);
        }
        $this->insertReservation(
            serviceId: $dynamicService->id,
            invoiceId: $invoice->id,
            userId: $user->id,
            guaranteedUntil: now()->addDays(7)
        );

        $reservationService = Mockery::mock(ReservationService::class);
        $reservationService->shouldReceive('commitPaidService')
            ->once()
            ->withArgs(
                fn (Service $candidate, Invoice $paidInvoice): bool =>
                    $candidate->is($dynamicService) && $paidInvoice->is($invoice)
            )
            ->andReturnTrue();
        $this->app->instance(ReservationService::class, $reservationService);

        $fulfillment = app(DurableFulfillmentService::class);
        $this->assertTrue($fulfillment->isReservationBacked($dynamicService));
        $this->assertFalse($fulfillment->isReservationBacked($staticService));

        app(RenewServiceService::class)->handle($dynamicService, $invoice);
        app(RenewServiceService::class)->handle($staticService, $invoice);

        $this->assertCount(2, Bus::dispatched(CreateJob::class));
        Bus::assertDispatched(
            CreateJob::class,
            fn (CreateJob $job): bool => $job->service->is($dynamicService)
        );
        Bus::assertDispatched(
            CreateJob::class,
            fn (CreateJob $job): bool => $job->service->is($staticService)
        );
        $this->assertSame(Service::STATUS_ACTIVE, $staticService->fresh()->status);
    }

    public function test_missing_extension_runtime_cannot_fall_back_to_legacy_paid_provisioning(): void
    {
        Bus::fake([CreateJob::class]);
        $service = $this->makeService();
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD',
        ]);
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            invoiceId: $invoice->id,
            userId: $service->user_id,
            guaranteedUntil: now()->addDays(7)
        );
        $missingRuntime = new class extends DurableFulfillmentService
        {
            protected function reservationService(): ?object
            {
                return null;
            }
        };
        $this->app->instance(
            DurableFulfillmentService::class,
            $missingRuntime
        );

        try {
            app(RenewServiceService::class)->handle($service, $invoice);
            $this->fail('Expected missing durable runtime to block provisioning.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'durable fulfillment extension is unavailable',
                $exception->getMessage()
            );
        }

        Bus::assertNotDispatched(CreateJob::class);
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => ResourceReservation::STATUS_PENDING,
        ]);
    }

    public function test_missing_extension_runtime_cannot_partially_cancel_or_release_stock(): void
    {
        [$service, $product] = $this->makeStockedService(4);
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id
        );
        $missingRuntime = new class extends DurableFulfillmentService
        {
            protected function reservationService(): ?object
            {
                return null;
            }
        };

        try {
            $missingRuntime->requestCancellation($service);
            $this->fail('Expected missing durable runtime to block cancellation.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'durable fulfillment extension is unavailable',
                $exception->getMessage()
            );
        }

        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        $this->assertNull($service->fresh()->product_stock_released_at);
        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $reservationId,
            'status' => ResourceReservation::STATUS_PENDING,
            'product_stock_released_at' => null,
        ]);
    }

    public function test_allocation_row_drift_is_rejected_before_external_provisioning(): void
    {
        $service = $this->makeService();
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed'
        );
        $this->insertAllocation($reservationId);
        DB::table('ptero_reservation_allocations')
            ->where('reservation_id', $reservationId)
            ->update(['port' => 25566]);

        $this->configurationService->shouldNotReceive('assertServiceMatches');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Allocation claims no longer match');

        $this->service()->beginProvisioning($service);
    }

    public function test_reconciler_recovers_a_stale_crashed_worker_lease(): void
    {
        Bus::fake([CreateJob::class]);
        $service = $this->makeService(status: 'provisioning');
        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            userId: $service->user_id,
            status: 'paid_committed',
            provisioningStartedAt: now()->subMinutes(6),
            provisioningLeaseId: 'abandoned-lease'
        );

        $this->assertSame(1, $this->service()->reconcileStalledPaidCommitments());
        $reservation = DB::table('ptero_resource_reservations')->find($reservationId);
        $this->assertNull($reservation->provisioning_started_at);
        $this->assertNull($reservation->provisioning_lease_id);
        $this->assertNotNull($reservation->next_provisioning_attempt_at);
        Bus::assertDispatched(
            CreateJob::class,
            fn (CreateJob $job) => $job->service->is($service)
        );
    }

    public function test_fixed_port_is_claimed_before_an_earlier_wildcard_mapping(): void
    {
        $service = $this->service();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('mapAllocationRequirements');
        $method->setAccessible(true);

        $mapped = $method->invoke($service, [
            ['id' => 7001, 'ip' => '192.0.2.10', 'port' => 27015],
            ['id' => 7002, 'ip' => '192.0.2.10', 'port' => 25565],
        ], [
            [
                'environment_key' => 'SERVER_PORT',
                'requested_port' => null,
                'is_primary' => true,
            ],
            [
                'environment_key' => 'QUERY_PORT',
                'requested_port' => 27015,
                'is_primary' => false,
            ],
        ]);

        $this->assertSame([
            [
                'allocation_id' => 7001,
                'ip' => '192.0.2.10',
                'port' => 27015,
                'environment_key' => 'QUERY_PORT',
                'is_primary' => false,
            ],
            [
                'allocation_id' => 7002,
                'ip' => '192.0.2.10',
                'port' => 25565,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ],
        ], $mapped);
    }

    private function service(): ReservationService
    {
        $reflection = new \ReflectionClass(ReservationService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'nodeService' => $this->nodeService,
            'configurationService' => $this->configurationService,
            'ttlMinutes' => 15,
        ] as $property => $value) {
            $instanceProperty = $reflection->getProperty($property);
            $instanceProperty->setAccessible(true);
            $instanceProperty->setValue($service, $value);
        }

        return $service;
    }

    private function makeService(string $status = 'pending'): Service
    {
        $user = User::withoutEvents(fn () => User::factory()->create());
        $id = DB::table('services')->insertGetId([
            'user_id' => $user->id,
            'status' => $status,
            'currency_code' => 'USD',
            'quantity' => 1,
            'price' => '0.00',
            'expires_at' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Service::query()->findOrFail($id);
    }

    private function makeBillableService(string $status): Service
    {
        $product = $this->pterodactylProduct();
        $plan = Plan::factory()->create([
            'priceable_id' => $product->id,
            'priceable_type' => Product::class,
            'type' => 'recurring',
            'billing_unit' => 'month',
            'billing_period' => 1,
        ]);
        $service = $this->makeService($status);
        DB::table('services')
            ->where('id', $service->id)
            ->update([
                'product_id' => $product->id,
                'plan_id' => $plan->id,
            ]);

        return $service->fresh();
    }

    /**
     * @return array{Service, Product}
     */
    private function makeStockedService(
        int $stock,
        string $status = Service::STATUS_PENDING,
        ?int $userId = null
    ): array {
        $product = Product::factory()->create(['stock' => $stock]);
        $service = $this->makeService($status);
        DB::table('services')
            ->where('id', $service->id)
            ->update(array_filter([
                'product_id' => $product->id,
                'user_id' => $userId,
            ], fn ($value): bool => $value !== null));

        return [$service->fresh(), $product];
    }

    private function resourceOption(string $name, string $resource): ConfigOption
    {
        return ConfigOption::create([
            'name' => $name,
            'env_variable' => $resource,
            'type' => 'dynamic_slider',
            'hidden' => false,
            'upgradable' => true,
            'metadata' => [
                'resource_type' => $resource,
                'min' => 1,
                'max' => 100000,
                'step' => 1,
                'default' => 1,
                'display_divisor' => 1,
                'pricing' => [
                    'model' => 'linear',
                    'rate_per_unit' => 0,
                ],
            ],
        ]);
    }

    private function pterodactylProduct(): Product
    {
        $server = Server::query()->create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);

        return Product::factory()->create(['server_id' => $server->id]);
    }

    private function insertReservation(
        ?int $serviceId = null,
        ?int $invoiceId = null,
        ?int $cartId = null,
        ?int $cartItemGuardId = null,
        ?int $userId = null,
        string $status = 'pending',
        mixed $provisioningStartedAt = null,
        ?string $provisioningLeaseId = null,
        mixed $consumedAt = null,
        mixed $cancellationRequestedAt = null,
        mixed $guaranteedUntil = null,
        ?int $productId = null,
        ?int $planId = null,
        string $purpose = 'checkout',
        ?int $upgradeGuardId = null
    ): int {
        $payload = [
            'customer_id' => $userId,
            'cart_id' => $cartId,
            'server_extension_id' => 0,
            'product_id' => (int) $productId,
            'plan_id' => (int) $planId,
            'quantity' => 1,
            'currency_code' => 'USD',
            'panel_identity' => str_repeat('c', 64),
            'node_id' => 4,
            'location_id' => 2,
            'resources' => [
                'memory' => 4096,
                'cpu' => 200,
                'disk' => 51200,
            ],
            'provisioning_identity' => [
                'nest_id' => 1,
                'egg_id' => 2,
                'user_external_id' => $userId !== null
                    ? "paymenter-user-{$userId}"
                    : null,
                'user_email' => $userId !== null
                    ? (string) User::query()->whereKey($userId)->value('email')
                    : null,
            ],
            'allocation_requirements' => [
                'required_count' => 1,
                'mappings' => [[
                    'environment_key' => 'SERVER_PORT',
                    'requested_port' => null,
                    'is_primary' => true,
                ]],
                'allowed_port_ranges' => [],
                'dedicated_ip' => false,
            ],
            'allocations' => [[
                'allocation_id' => 7001,
                'ip' => '192.0.2.10',
                'port' => 25565,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ]],
        ];

        return DB::table('ptero_resource_reservations')->insertGetId([
            'token' => Str::random(64),
            'purpose' => $purpose,
            'cart_item_id' => null,
            'cart_item_guard_id' => $cartItemGuardId,
            'cart_id' => $cartId,
            'server_extension_id' => null,
            'panel_identity' => str_repeat('c', 64),
            'service_id' => $serviceId,
            'service_guard_id' => $serviceId,
            'upgrade_guard_id' => $upgradeGuardId,
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'product_id' => $productId,
            'plan_id' => $planId,
            'quantity' => 1,
            'currency_code' => 'USD',
            'configuration_fingerprint' => $this->configurationService->fingerprint($payload),
            'configuration_payload' => json_encode($payload),
            'pricing_version' => str_repeat('b', 64),
            'formula_version' => ReservationConfigurationService::FORMULA_VERSION,
            'node_id' => 4,
            'location_id' => 2,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 51200,
            'calculated_price' => 12.50,
            'pricing_breakdown' => json_encode([]),
            'status' => $status,
            'expires_at' => now()->addDay(),
            'guaranteed_until' => $guaranteedUntil ?? now()->addDay(),
            'provisioning_started_at' => $provisioningStartedAt,
            'provisioning_lease_id' => $provisioningLeaseId,
            'cancellation_requested_at' => $cancellationRequestedAt,
            'consumed_at' => $consumedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAllocation(int $reservationId, int $allocationId = 7001): void
    {
        $status = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->value('status');

        DB::table('ptero_reservation_allocations')->insert([
            'reservation_id' => $reservationId,
            'panel_identity' => str_repeat('c', 64),
            'node_id' => 4,
            'allocation_id' => $allocationId,
            'ip' => '192.0.2.10',
            'port' => 25565,
            'environment_key' => 'SERVER_PORT',
            'is_primary' => true,
            'released_at' => $status === 'confirmed' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertConfirmedCheckoutCommitment(
        Service $service
    ): int {
        $checkoutInvoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'status' => Invoice::STATUS_PAID,
            'currency_code' => $service->currency_code,
            'due_at' => now()->subMonth(),
        ]);
        $checkoutInvoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'price' => $service->price,
            'quantity' => $service->quantity,
            'description' => 'Original dynamic checkout',
        ]);

        $reservationId = $this->insertReservation(
            serviceId: $service->id,
            invoiceId: $checkoutInvoice->id,
            userId: $service->user_id,
            status: ResourceReservation::STATUS_CONFIRMED,
            consumedAt: now()->subMonth(),
            productId: $service->product_id,
            planId: $service->plan_id
        );
        $serverExtensionId = (int) Product::query()
            ->whereKey($service->product_id)
            ->value('server_id');
        $payload = json_decode(
            (string) DB::table('ptero_resource_reservations')
                ->where('id', $reservationId)
                ->value('configuration_payload'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $payload['server_extension_id'] = $serverExtensionId;

        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'server_extension_id' => $serverExtensionId,
                'configuration_payload' => json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                ),
                'configuration_fingerprint' =>
                    $this->configurationService->fingerprint($payload),
                'paid_committed_at' => now()->subMonth(),
                'external_server_id' => 71,
                'external_user_id' => 44,
                'external_server_uuid' =>
                    '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
                'external_server_identifier' => 'created',
                'updated_at' => now(),
            ]);
        $this->insertAllocation($reservationId);

        return $reservationId;
    }

    /**
     * @return array<string, mixed>
     */
    private function externalServer(): array
    {
        return [
            'attributes' => [
                'id' => 71,
                'uuid' => '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
                'identifier' => 'created',
                'user' => 44,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancellationServer(int $serviceId): array
    {
        return [
            'attributes' => [
                'id' => 71,
                'uuid' =>
                    '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
                'identifier' => 'created',
                'external_id' => (string) $serviceId,
                'user' => 44,
                'node' => 4,
                'nest' => 1,
                'egg' => 2,
                'allocation' => 7001,
                'limits' => [
                    'memory' => 4096,
                    'cpu' => 200,
                    'disk' => 51200,
                ],
                'feature_limits' => [
                    'allocations' => 0,
                ],
                'relationships' => [
                    'allocations' => [
                        'data' => [[
                            'attributes' => ['id' => 7001],
                        ]],
                    ],
                ],
            ],
        ];
    }
}
