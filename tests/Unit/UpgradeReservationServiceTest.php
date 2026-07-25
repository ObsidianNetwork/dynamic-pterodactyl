<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\PermanentProvisioningException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\UpgradeJob;
use App\Models\ConfigOption;
use App\Models\ConfigOptionProduct;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidResourceSelectionException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\PterodactylInventoryService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationConfigurationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\UpgradeReservationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class UpgradeReservationServiceTest extends LaravelTestCase
{
    public function test_expiry_paths_follow_the_capacity_invoice_lock_order(): void
    {
        $upgradeSource = file_get_contents(
            dirname(__DIR__, 2).'/Services/UpgradeReservationService.php'
        );
        $upgradeSource = substr(
            $upgradeSource,
            strpos($upgradeSource, 'public function expireUnpaidUpgrades'),
            strpos($upgradeSource, 'public function reconcileStalledUpgrades')
                - strpos(
                    $upgradeSource,
                    'public function expireUnpaidUpgrades'
                )
        );
        $checkoutSource = file_get_contents(
            dirname(__DIR__, 2).'/Services/ReservationService.php'
        );
        $checkoutSource = substr(
            $checkoutSource,
            strpos($checkoutSource, 'public function cleanupExpired'),
            strpos(
                $checkoutSource,
                'public function reconcileStalledPaidCommitments'
            )
                - strpos(
                    $checkoutSource,
                    'public function cleanupExpired'
                )
        );

        $this->assertOrderedSourceMarkers($upgradeSource, [
            '$invoice = $candidate->invoice_id',
            'Service::query()',
            '$upgrade = ServiceUpgrade::query()',
            '$reservation = UpgradeReservation::query()',
            '$invoice->items()',
        ]);
        $this->assertOrderedSourceMarkers($checkoutSource, [
            '$invoice = $candidate->invoice_id',
            '$services = Service::query()',
            '$reservations = DB::table(',
            '$lockedItems = $invoice !== null',
        ]);
    }

    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_fixed_node_quote_clamps_32_gb_to_23_gb_and_accepts_select_key(): void
    {
        $fixture = $this->fixture();

        $quote = $fixture->upgrades->quoteForService(
            $fixture->service,
            [
                $fixture->options['memory']->id => 32768,
                $fixture->options['cpu']->id => 200,
                $fixture->options['disk']->id => 20480,
                $fixture->select->id => $fixture->selectChild->id,
            ]
        );

        $this->assertTrue($quote['adjusted']);
        $this->assertSame(23552, $quote['selection']['memory']);
        $this->assertSame(23552, $quote['bounds']['memory']['max']);
    }

    public function test_duplicate_active_resource_slider_fails_closed_but_hidden_one_does_not(): void
    {
        $fixture = $this->fixture();
        $duplicate = $this->option(
            $fixture->product,
            'memory',
            1024,
            32768,
            1024,
            4096
        );

        try {
            $fixture->upgrades->quoteForService(
                $fixture->service,
                $this->selection($fixture)
            );
            $this->fail('Expected duplicate memory sliders to fail closed.');
        } catch (InvalidStockConfigurationException $exception) {
            $this->assertStringContainsString(
                'Multiple active memory',
                $exception->getMessage()
            );
        }

        $duplicate->hidden = true;
        $duplicate->save();
        $fixture->service->unsetRelation('product');

        $quote = $fixture->upgrades->quoteForService(
            $fixture->service,
            $this->selection($fixture)
        );
        $this->assertSame(23552, $quote['bounds']['memory']['max']);
    }

    public function test_upgrade_reserves_only_positive_delta_but_keeps_full_target(): void
    {
        $fixture = $this->fixture();
        [$upgrade, $invoice] = $this->upgrade($fixture);

        $reservation = $fixture->upgrades->reserveForUpgrade(
            $upgrade,
            $invoice->due_at
        );

        $this->assertSame(8192, (int) $reservation->memory);
        $this->assertSame(100, (int) $reservation->cpu);
        $this->assertSame(30720, (int) $reservation->disk);
        $this->assertSame(4096, (int) $reservation->reserved_memory);
        $this->assertSame(0, (int) $reservation->reserved_cpu);
        $this->assertSame(10240, (int) $reservation->reserved_disk);
        $this->assertSame(99, (int) $reservation->external_server_id);
        $this->assertSame(44, (int) $reservation->external_user_id);
        $this->assertSame(
            '10000000-0000-4000-8000-000000000099',
            $reservation->external_server_uuid
        );
        $this->assertSame(
            'server-99',
            $reservation->external_server_identifier
        );
    }

    public function test_unchanged_resource_vector_is_not_an_upgrade(): void
    {
        $fixture = $this->fixture();
        [$upgrade, $invoice] = $this->upgrade($fixture);
        foreach ([
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 20480,
        ] as $resource => $value) {
            $upgrade->configs()
                ->where('config_option_id', $fixture->options[$resource]->id)
                ->update(['slider_value' => $value]);
        }
        $this->recaptureUpgrade($upgrade);

        $this->expectException(InvalidResourceSelectionException::class);
        $this->expectExceptionMessage('must change at least one');

        $fixture->upgrades->reserveForUpgrade(
            $upgrade->fresh(),
            $invoice->due_at
        );
    }

    public function test_checkout_identity_survives_product_and_customer_email_edits(): void
    {
        $fixture = $this->fixture();
        $checkoutEmail = strtolower((string) $fixture->user->email);
        $fixture->user->update([
            'email' => "renamed-{$fixture->user->id}@example.com",
        ]);
        $fixture->product->settings()->updateOrCreate(
            ['key' => 'nest_id'],
            ['value' => 77, 'type' => 'integer', 'encrypted' => false]
        );
        $fixture->product->settings()->updateOrCreate(
            ['key' => 'egg_id'],
            ['value' => 88, 'type' => 'integer', 'encrypted' => false]
        );
        $fixture->service->unsetRelation('product');
        $fixture->service->unsetRelation('user');
        [$upgrade, $invoice] = $this->upgrade($fixture);

        $reservation = $fixture->upgrades->reserveForUpgrade(
            $upgrade,
            $invoice->due_at
        );
        $payload = (array) $reservation->configuration_payload;

        $this->assertSame(1, $payload['nest_id']);
        $this->assertSame(2, $payload['egg_id']);
        $this->assertSame($checkoutEmail, $payload['user_email']);
    }

    public function test_server_identity_drift_is_rejected_before_upgrade_reservation(): void
    {
        $mutations = [
            ['uuid', '20000000-0000-4000-8000-000000000099'],
            ['identifier', 'replacement-server'],
            ['external_id', 'another-service'],
            ['user_id', 45],
            ['user_external_id', 'paymenter-user-999'],
            ['nest_id', 7],
            ['egg_id', 8],
        ];

        foreach ($mutations as [$field, $value]) {
            $fixture = $this->fixture();
            $fixture->remote->server[$field] = $value;
            [$upgrade, $invoice] = $this->upgrade($fixture);

            try {
                $fixture->upgrades->reserveForUpgrade(
                    $upgrade,
                    $invoice->due_at
                );
                $this->fail("Expected {$field} drift to fail closed.");
            } catch (InvalidResourceSelectionException $exception) {
                $this->assertStringContainsString(
                    'immutable checkout commitment',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_paid_upgrade_commit_returns_explicit_handled_proof(): void
    {
        Queue::fake();
        $fixture = $this->fixture();
        [$upgrade, $invoice] = $this->upgrade($fixture);
        $reservation = $fixture->upgrades->reserveForUpgrade(
            $upgrade,
            $invoice->due_at
        );

        $this->assertTrue(
            $fixture->upgrades->commitPaidUpgrade($upgrade, $invoice)
        );
        $this->assertSame(
            'paid_committed',
            $reservation->fresh()->status
        );
        $this->assertSame(
            ServiceUpgrade::STATUS_PAID_COMMITTED,
            $upgrade->fresh()->status
        );
    }

    public function test_dynamic_upgrade_rejects_non_resource_configuration_change(): void
    {
        $fixture = $this->fixture();
        [$upgrade, $invoice] = $this->upgrade($fixture);
        $upgrade->configs()->create([
            'config_option_id' => $fixture->select->id,
            'config_value_id' => $fixture->selectChild->id,
            'slider_value' => null,
        ]);
        $upgrade->unsetRelation('configs');
        $upgrade->load([
            'service.product.server.settings',
            'service.product.settings',
            'service.configs.configOption',
            'service.configs.configValue',
            'product.server.settings',
            'product.settings',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
        ]);
        $upgrade->captureSnapshots();
        $upgrade->save();

        $this->expectException(InvalidResourceSelectionException::class);
        $this->expectExceptionMessage(
            'may change only RAM, CPU, and disk'
        );

        $fixture->upgrades->reserveForUpgrade($upgrade, $invoice->due_at);
    }

    public function test_old_worker_cannot_clear_newer_upgrade_lease(): void
    {
        $fixture = $this->fixture();
        [$upgrade, $invoice] = $this->upgrade($fixture);
        $reservation = $fixture->upgrades->reserveForUpgrade(
            $upgrade,
            $invoice->due_at
        );
        $reservation->forceFill([
            'status' => 'paid_committed',
            'provisioning_lease_id' => 'new-worker',
            'provisioning_started_at' => now(),
        ])->save();

        $this->assertFalse($fixture->upgrades->failProvisioning(
            $upgrade,
            new \RuntimeException('old worker failed'),
            'old-worker'
        ));
        $this->assertSame(
            'new-worker',
            $reservation->fresh()->provisioning_lease_id
        );

        $this->assertTrue($fixture->upgrades->failProvisioning(
            $upgrade,
            new \RuntimeException('current worker failed'),
            'new-worker'
        ));
        $this->assertNull($reservation->fresh()->provisioning_lease_id);
    }

    public function test_paid_upgrade_payload_is_verified_before_leasing(): void
    {
        Queue::fake();
        $fixture = $this->fixture();
        [$upgrade, $invoice] = $this->upgrade($fixture);
        $reservation = $fixture->upgrades->reserveForUpgrade(
            $upgrade,
            $invoice->due_at
        );
        $fixture->upgrades->commitPaidUpgrade($upgrade, $invoice);

        $payload = (array) $reservation->fresh()->configuration_payload;
        $payload['target']['memory'] = 16384;
        DB::table('ptero_resource_reservations')
            ->whereKey($reservation->id)
            ->update([
                'configuration_payload' => json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                ),
            ]);

        try {
            $fixture->upgrades->beginProvisioning($upgrade->fresh());
            $this->fail(
                'Expected a mutated paid upgrade payload to fail closed.'
            );
        } catch (PermanentProvisioningException $exception) {
            $this->assertStringContainsString(
                'immutable integrity',
                $exception->getMessage()
            );
        }

        $this->assertNull(
            $reservation->fresh()->provisioning_lease_id
        );
    }

    public function test_tampered_upgrade_payload_is_rejected_before_payment(): void
    {
        $fixture = $this->fixture();
        [$upgrade, $invoice] = $this->upgrade($fixture);
        $reservation = $fixture->upgrades->reserveForUpgrade(
            $upgrade,
            $invoice->due_at
        );
        $payload = (array) $reservation->configuration_payload;
        $payload['target']['memory'] = 16384;
        DB::table('ptero_resource_reservations')
            ->whereKey($reservation->id)
            ->update([
                'configuration_payload' => json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                ),
            ]);

        $failure = $fixture->upgrades->preflightPaidUpgrade(
            $upgrade,
            $invoice
        );

        $this->assertStringContainsString(
            'immutable integrity',
            strtolower((string) $failure)
        );
        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $upgrade->fresh()->status
        );
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame('cancelled', $reservation->fresh()->status);
    }

    public function test_tampered_invoice_line_is_cancelled_before_paid_transition(): void
    {
        $fixture = $this->fixture();
        [$upgrade, $invoice] = $this->upgrade($fixture);
        $reservation = $fixture->upgrades->reserveForUpgrade(
            $upgrade,
            $invoice->due_at
        );
        $invoice->items()->firstOrFail()->update(['price' => 1]);

        $failure = $fixture->upgrades->preflightPaidUpgrade(
            $upgrade,
            $invoice
        );
        $this->assertStringContainsString(
            'invoice line',
            strtolower((string) $failure)
        );

        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $upgrade->fresh()->status
        );
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame('cancelled', $reservation->fresh()->status);
    }

    public function test_renewal_after_quote_invalidates_upgrade_before_payment(): void
    {
        $fixture = $this->fixture();
        $fixture->service->expires_at = now()->addDays(10);
        $fixture->service->save();
        [$upgrade, $invoice] = $this->upgrade($fixture);
        $reservation = $fixture->upgrades->reserveForUpgrade(
            $upgrade,
            $invoice->due_at
        );

        // Simulate the current service renewing on its old resource vector.
        $fixture->service->expires_at = now()->addDays(35);
        $fixture->service->save();

        $failure = $fixture->upgrades->preflightPaidUpgrade(
            $upgrade,
            $invoice
        );
        $this->assertStringContainsString(
            'service changed',
            strtolower((string) $failure)
        );

        $this->assertSame(
            ServiceUpgrade::STATUS_CANCELLED,
            $upgrade->fresh()->status
        );
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame('cancelled', $reservation->fresh()->status);
    }

    public function test_add_payment_preserves_rejected_upgrade_attention_and_payment_evidence(): void
    {
        Queue::fake();
        $fixture = $this->fixture();
        [$upgrade, $invoice] = $this->upgrade($fixture);
        $reservation = $fixture->upgrades->reserveForUpgrade(
            $upgrade,
            $invoice->due_at
        );

        // Simulate renewal/admin drift beneath the model guard so the real
        // synchronous payment listener encounters a stale immutable source.
        DB::table('services')
            ->where('id', $fixture->service->id)
            ->update([
                'price' => '11.00',
                'updated_at' => now(),
            ]);

        ExtensionHelper::addPayment(
            $invoice->id,
            null,
            '100.00',
            transactionId: 'paid-but-stale-upgrade'
        );

        $this->assertDatabaseHas('invoice_transactions', [
            'invoice_id' => $invoice->id,
            'transaction_id' => 'paid-but-stale-upgrade',
            'status' => InvoiceTransactionStatus::Succeeded->value,
        ]);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_required_at
        );
        $this->assertStringContainsString(
            'refund or account-credit review',
            (string) $invoice->fresh()->payment_attention_reason
        );
        $this->assertSame(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            $upgrade->fresh()->status
        );
        $this->assertSame('cancelled', $reservation->fresh()->status);
        $this->assertNull($reservation->fresh()->upgrade_guard_id);
        Queue::assertNotPushed(UpgradeJob::class);
    }

    public function test_expiry_with_partial_payment_releases_delta_and_requires_attention(): void
    {
        $fixture = $this->fixture();
        [$upgrade, $invoice] = $this->upgrade($fixture);
        $reservation = $fixture->upgrades->reserveForUpgrade(
            $upgrade,
            $invoice->due_at
        );
        $reservation->forceFill([
            'expires_at' => now()->subSecond(),
            'guaranteed_until' => now()->subSecond(),
        ])->save();
        InvoiceTransaction::create([
            'invoice_id' => $invoice->id,
            'amount' => 1,
            'fee' => 0,
            'status' => InvoiceTransactionStatus::Processing,
        ]);

        $this->assertSame(1, $fixture->upgrades->expireUnpaidUpgrades());
        $this->assertSame('expired', $reservation->fresh()->status);
        $this->assertSame(
            ServiceUpgrade::STATUS_NEEDS_ATTENTION,
            $upgrade->fresh()->status
        );
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertNotNull(
            $invoice->fresh()->payment_attention_required_at
        );
    }

    private function fixture(): object
    {
        $user = User::factory()->create();
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
        $product = Product::factory()->create([
            'server_id' => $server->id,
            'hidden' => false,
        ]);
        $product->settings()->create([
            'key' => 'location_ids',
            'value' => [1],
            'type' => 'array',
            'encrypted' => false,
        ]);
        foreach (['nest_id' => 1, 'egg_id' => 2] as $key => $value) {
            $product->settings()->create([
                'key' => $key,
                'value' => $value,
                'type' => 'integer',
                'encrypted' => false,
            ]);
        }
        $plan = Plan::factory()->create([
            'priceable_id' => $product->id,
            'priceable_type' => Product::class,
            'name' => 'Monthly',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        Price::factory()->create([
            'plan_id' => $plan->id,
            'price' => 10,
            'setup_fee' => 0,
            'currency_code' => 'USD',
        ]);

        // Create the service while the product is still non-dynamic; the
        // production checkout creates dynamic services through its coordinator.
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'quantity' => 1,
            'price' => 10,
            'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE,
        ]);
        $options = [
            'memory' => $this->option(
                $product,
                'memory',
                1024,
                32768,
                1024,
                4096
            ),
            'cpu' => $this->option(
                $product,
                'cpu',
                100,
                800,
                100,
                200
            ),
            'disk' => $this->option(
                $product,
                'disk',
                10240,
                102400,
                10240,
                20480
            ),
        ];
        foreach ([
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 20480,
        ] as $resource => $value) {
            ServiceConfig::create([
                'configurable_id' => $service->id,
                'configurable_type' => Service::class,
                'config_option_id' => $options[$resource]->id,
                'config_value_id' => null,
                'slider_value' => $value,
            ]);
        }
        $select = ConfigOption::create([
            'name' => 'Template',
            'env_variable' => 'template',
            'type' => 'select',
            'hidden' => false,
            'upgradable' => true,
        ]);
        ConfigOptionProduct::create([
            'product_id' => $product->id,
            'config_option_id' => $select->id,
        ]);
        $selectChild = ConfigOption::create([
            'name' => 'Default',
            'env_variable' => 'default',
            'type' => 'option',
            'hidden' => false,
            'parent_id' => $select->id,
        ]);

        $panelIdentity = hash('sha256', 'https://panel.example');
        $checkoutEmail = strtolower((string) $user->email);
        $checkoutPayload = [
            'customer_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'plan_id' => (int) $plan->id,
            'panel_identity' => $panelIdentity,
            'node_id' => 1,
            'resources' => [
                'memory' => 4096,
                'cpu' => 200,
                'disk' => 20480,
            ],
            'provisioning_identity' => [
                'nest_id' => 1,
                'egg_id' => 2,
                'user_external_id' => "paymenter-user-{$user->id}",
                'user_email' => $checkoutEmail,
            ],
        ];
        $checkoutConfiguration = new ReservationConfigurationService;
        DB::table('ptero_resource_reservations')->insert([
            'purpose' => 'checkout',
            'token' => hash('sha256', "checkout:{$service->id}"),
            'service_id' => $service->id,
            'service_guard_id' => $service->id,
            'user_id' => $user->id,
            'node_id' => 1,
            'location_id' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 20480,
            'reserved_memory' => 4096,
            'reserved_cpu' => 200,
            'reserved_disk' => 20480,
            'calculated_price' => 10,
            'pricing_breakdown' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => 'confirmed',
            'expires_at' => now()->addDays(30),
            'guaranteed_until' => now()->addDays(30),
            'panel_identity' => $panelIdentity,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'quantity' => 1,
            'currency_code' => 'USD',
            'configuration_fingerprint' =>
                $checkoutConfiguration->fingerprint($checkoutPayload),
            'configuration_payload' => json_encode(
                $checkoutPayload,
                JSON_THROW_ON_ERROR
            ),
            'pricing_version' => str_repeat('a', 64),
            'formula_version' =>
                ReservationConfigurationService::FORMULA_VERSION,
            'external_server_id' => 99,
            'external_user_id' => 44,
            'external_server_uuid' =>
                '10000000-0000-4000-8000-000000000099',
            'external_server_identifier' => 'server-99',
            'consumed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $remote = (object) ['server' => [
            'id' => 99,
            'uuid' => '10000000-0000-4000-8000-000000000099',
            'identifier' => 'server-99',
            'external_id' => (string) $service->id,
            'user_id' => 44,
            'user_external_id' => "paymenter-user-{$user->id}",
            'user_email' => $checkoutEmail,
            'nest_id' => 1,
            'egg_id' => 2,
            'node' => 1,
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 20480,
            'swap' => 0,
            'io' => 500,
            'threads' => null,
            'database_limit' => 2,
            'allocation_limit' => 0,
            'backup_limit' => 3,
            'allocation' => 123,
            'assigned_allocation_ids' => [123],
        ]];
        $inventory = Mockery::mock(PterodactylInventoryService::class);
        $inventory->shouldReceive('assertExclusiveProvisioningControl')
            ->zeroOrMoreTimes();
        $inventory->shouldReceive('panelIdentity')
            ->zeroOrMoreTimes()
            ->andReturn($panelIdentity);
        $inventory->shouldReceive('serverByExternalId')
            ->zeroOrMoreTimes()
            ->with($service->id)
            ->andReturnUsing(fn (): array => $remote->server);
        $inventory->shouldReceive('nodes')->zeroOrMoreTimes()->andReturn([[
            'id' => 1,
            'uuid' => 'node-1',
            'location_id' => 1,
        ]]);
        $resources = Mockery::mock(ResourceCalculationService::class);
        $resources->shouldReceive('getNodeAvailability')
            ->zeroOrMoreTimes()
            ->with(1)
            ->andReturn([
                'available' => [
                    'memory' => 19456,
                    'cpu' => 600,
                    'disk' => 512000,
                ],
            ]);
        $resources->shouldReceive('verifyNodeCapacity')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        return (object) [
            'user' => $user,
            'server' => $server,
            'product' => $product,
            'plan' => $plan,
            'service' => $service,
            'options' => $options,
            'select' => $select,
            'selectChild' => $selectChild,
            'remote' => $remote,
            'upgrades' => new UpgradeReservationService(
                $inventory,
                $resources
            ),
        ];
    }

    private function upgrade(object $fixture): array
    {
        $dueAt = now()->addDays(7);
        $invoice = Invoice::factory()->create([
            'user_id' => $fixture->user->id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => $dueAt,
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $fixture->service->id,
            'product_id' => $fixture->product->id,
            'plan_id' => $fixture->plan->id,
            'invoice_id' => $invoice->id,
            'status' => ServiceUpgrade::STATUS_AWAITING_PAYMENT,
            'active_service_guard_id' => $fixture->service->id,
            'quoted_amount' => 100,
            'currency_code' => 'USD',
        ]);
        foreach ([
            'memory' => 8192,
            'cpu' => 100,
            'disk' => 30720,
        ] as $resource => $value) {
            $upgrade->configs()->create([
                'config_option_id' => $fixture->options[$resource]->id,
                'config_value_id' => null,
                'slider_value' => $value,
            ]);
        }
        $upgrade->load([
            'service.product.server.settings',
            'service.product.settings',
            'service.configs.configOption',
            'service.configs.configValue',
            'product.server.settings',
            'product.settings',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
        ]);
        $upgrade->captureSnapshots();
        $upgrade->save();
        $invoice->items()->create([
            'description' => 'Resource upgrade',
            'price' => 100,
            'quantity' => 1,
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);

        return [$upgrade->fresh(), $invoice->fresh()];
    }

    private function recaptureUpgrade(ServiceUpgrade $upgrade): void
    {
        $upgrade->unsetRelations();
        $upgrade->load([
            'service.product.server.settings',
            'service.product.settings',
            'service.configs.configOption',
            'service.configs.configValue',
            'product.server.settings',
            'product.settings',
            'plan.prices',
            'configs.configOption',
            'configs.configValue',
        ]);
        $upgrade->captureSnapshots();
        $upgrade->save();
    }

    private function option(
        Product $product,
        string $resource,
        int $min,
        int $max,
        int $step,
        int $default
    ): ConfigOption {
        $option = ConfigOption::create([
            'name' => ucfirst($resource),
            'env_variable' => $resource,
            'type' => 'dynamic_slider',
            'hidden' => false,
            'upgradable' => true,
            'metadata' => [
                'managed_by' => 'dynamic_pterodactyl',
                'managed_product_id' => $product->id,
                'resource_type' => $resource,
                'min' => $min,
                'max' => $max,
                'step' => $step,
                'default' => $default,
                'display_divisor' => 1,
                'pricing' => [
                    'model' => 'linear',
                    'rate_per_unit' => 1,
                ],
            ],
        ]);
        ConfigOptionProduct::create([
            'product_id' => $product->id,
            'config_option_id' => $option->id,
        ]);

        return $option;
    }

    private function selection(object $fixture): array
    {
        return [
            $fixture->options['memory']->id => 32768,
            $fixture->options['cpu']->id => 200,
            $fixture->options['disk']->id => 20480,
            $fixture->select->id => $fixture->selectChild->id,
        ];
    }

    /**
     * @param  list<string>  $markers
     */
    private function assertOrderedSourceMarkers(
        string $source,
        array $markers
    ): void {
        $previous = -1;
        foreach ($markers as $marker) {
            $position = strpos($source, $marker);
            $this->assertNotFalse(
                $position,
                "Missing lock-order marker: {$marker}"
            );
            $this->assertGreaterThan(
                $previous,
                $position,
                "Lock-order marker is out of order: {$marker}"
            );
            $previous = $position;
        }
    }
}
