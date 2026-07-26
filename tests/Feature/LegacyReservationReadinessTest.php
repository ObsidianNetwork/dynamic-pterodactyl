<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use App\Models\ConfigOption;
use App\Models\ConfigOptionProduct;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Server;
use App\Models\User;
use App\Services\Service\CapacityServiceCreationCoordinator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\LegacyReservationReadinessService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationConfigurationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\UpgradeReservationIntegrityService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class LegacyReservationReadinessTest extends LaravelTestCase
{
    use DatabaseTransactions;

    public function test_empty_install_is_ready(): void
    {
        (new LegacyReservationReadinessService)->assertReady();

        $this->addToAssertionCount(1);
    }

    public function test_confirmed_legacy_checkout_reports_actionable_identity_fields(): void
    {
        $service = $this->service();
        $reservationId = $this->insertReservation($service, [
            'status' => 'confirmed',
            'configuration_fingerprint' => null,
            'configuration_payload' => null,
        ]);

        try {
            (new LegacyReservationReadinessService)->assertReady();
            $this->fail('Expected legacy confirmed identity to block migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                "reservation #{$reservationId} / service #{$service->id}",
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                'external_server_uuid',
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                'configuration_payload',
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                'never infer authority',
                $exception->getMessage()
            );
        }
    }

    public function test_active_upgrade_without_immutable_identity_blocks_migration(): void
    {
        $service = $this->service();
        $reservationId = $this->insertReservation($service, [
            'purpose' => 'upgrade',
            'status' => 'paid_committed',
            'configuration_fingerprint' => str_repeat('a', 64),
            'configuration_payload' => json_encode([], JSON_THROW_ON_ERROR),
        ]);

        $blockers = (new LegacyReservationReadinessService)->blockers();

        $this->assertSame($reservationId, $blockers[0]['reservation_id']);
        $this->assertSame('upgrade', $blockers[0]['purpose']);
        $this->assertContains(
            'configuration_payload.external_server_id',
            $blockers[0]['missing']
        );
    }

    public function test_complete_signed_upgrade_identity_passes_gate_across_decimal_materialization(): void
    {
        $this->insertCompleteUpgradeReservation();

        (new LegacyReservationReadinessService)->assertReady();

        $this->addToAssertionCount(1);
    }

    public function test_upgrade_can_preserve_a_legitimate_new_node_and_allocation_identity(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        $reservation = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->first();
        $this->assertNotNull($reservation);
        $upgrade = ServiceUpgrade::query()
            ->findOrFail((int) $reservation->service_upgrade_id);
        $payload = json_decode(
            (string) $reservation->configuration_payload,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $payload['node_id'] = 8;
        $payload['allocation_id'] = 8001;
        $payload['assigned_allocation_ids'] = [8001, 8002];
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'node_id' => 8,
                'configuration_payload' => json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                ),
                'configuration_fingerprint' =>
                    (new UpgradeReservationIntegrityService)
                        ->fingerprint($upgrade, $payload),
                'updated_at' => now(),
            ]);

        (new LegacyReservationReadinessService)->assertReady();

        $this->addToAssertionCount(1);
    }

    public function test_upgrade_resource_row_drift_blocks_migration(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update(['reserved_memory' => 0]);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $reservationId);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'valid configuration_fingerprint',
            $blocker['missing']
        );
    }

    public function test_upgrade_price_version_drift_blocks_migration(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'pricing_version' => str_repeat('b', 64),
                'updated_at' => now(),
            ]);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $reservationId);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'valid configuration_fingerprint',
            $blocker['missing']
        );
    }

    public function test_upgrade_live_target_drift_blocks_migration(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        $upgradeId = $this->upgradeIdForReservation($reservationId);
        $configId = DB::table('service_configs')
            ->where('configurable_type', ServiceUpgrade::class)
            ->where('configurable_id', $upgradeId)
            ->orderBy('id')
            ->value('id');
        $this->assertNotNull($configId);
        DB::table('service_configs')
            ->where('id', $configId)
            ->update(['slider_value' => 16384]);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $reservationId);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'valid configuration_fingerprint',
            $blocker['missing']
        );
    }

    public function test_upgrade_source_billing_anchor_drift_blocks_migration(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        $serviceId = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->value('service_id');
        $this->assertNotNull($serviceId);
        DB::table('services')
            ->where('id', $serviceId)
            ->update([
                'expires_at' => now()->addMonths(2),
                'updated_at' => now(),
            ]);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $reservationId);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'valid configuration_fingerprint',
            $blocker['missing']
        );
    }

    public function test_active_dynamic_upgrade_missing_reservation_blocks_migration(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        $upgradeId = $this->upgradeIdForReservation($reservationId);
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->delete();

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->first(
            fn (array $row): bool =>
                $row['purpose'] === 'upgrade'
                && in_array(
                    "dynamic service upgrade #{$upgradeId} "
                        .'requires exactly one coherent capacity reservation',
                    $row['missing'],
                    true
                )
        );

        $this->assertNotNull($blocker);
        $this->assertSame(0, $blocker['reservation_id']);
    }

    public function test_upgrade_lifecycle_status_drift_blocks_migration(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        $upgradeId = $this->upgradeIdForReservation($reservationId);
        DB::table('service_upgrades')
            ->where('id', $upgradeId)
            ->update([
                'status' => ServiceUpgrade::STATUS_PAID_COMMITTED,
                'updated_at' => now(),
            ]);

        $blockers = collect(
            (new LegacyReservationReadinessService)->blockers()
        );
        $this->assertTrue(
            $blockers->contains(
                fn (array $row): bool =>
                    $row['purpose'] === 'upgrade'
                    && in_array(
                        "dynamic service upgrade #{$upgradeId} "
                            .'requires exactly one coherent capacity reservation',
                        $row['missing'],
                        true
                    )
            )
        );
    }

    public function test_upgrade_snapshot_loss_blocks_migration(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        $upgradeId = $this->upgradeIdForReservation($reservationId);
        DB::table('service_upgrades')
            ->where('id', $upgradeId)
            ->update([
                'target_snapshot' => null,
                'target_fingerprint' => null,
                'updated_at' => now(),
            ]);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $reservationId);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'valid configuration_fingerprint',
            $blocker['missing']
        );
    }

    public function test_bound_unpaid_checkout_without_signed_snapshot_blocks_migration(): void
    {
        $service = $this->service();
        $reservationId = $this->insertReservation($service, [
            'status' => 'pending',
            'configuration_fingerprint' => null,
            'configuration_payload' => null,
            'external_server_id' => null,
            'external_user_id' => null,
            'external_server_uuid' => null,
            'external_server_identifier' => null,
        ]);

        $blockers = (new LegacyReservationReadinessService)->blockers();

        $this->assertSame($reservationId, $blockers[0]['reservation_id']);
        $this->assertContains(
            'configuration_payload',
            $blockers[0]['missing']
        );
        $this->assertNotContains(
            'external_server_id',
            $blockers[0]['missing']
        );
    }

    public function test_service_bound_commitment_retired_by_legacy_migration_still_blocks_readiness(): void
    {
        $service = $this->service();
        $reservationId = $this->insertReservation($service, [
            'status' => 'cancelled',
            'configuration_fingerprint' => null,
            'configuration_payload' => null,
            'admin_notes' =>
                'Retired during migration to server-owned reservations.',
        ]);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $reservationId);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'legacy bound commitment was retired before readiness',
            $blocker['missing']
        );
    }

    public function test_duplicate_commitment_retired_by_old_durable_migration_still_blocks_readiness(): void
    {
        $fixture = $this->insertCompleteCheckoutReservation();
        $retiredId = $this->duplicateReservation(
            $fixture['reservation_id'],
            [
                'status' => 'cancelled',
                'admin_notes' =>
                    'Retired duplicate service commitment during '
                    .'durable-fulfillment migration.',
            ]
        );

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $retiredId);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'legacy bound commitment was retired before readiness',
            $blocker['missing']
        );
    }

    public function test_checkout_commitment_migration_refuses_duplicate_paid_obligations_without_mutation(): void
    {
        $fixture = $this->insertCompleteCheckoutReservation(
            status: 'paid_committed'
        );
        $secondInvoice = Invoice::factory()->create([
            'user_id' => $fixture['service']->user_id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $duplicateId = $this->duplicateReservation(
            $fixture['reservation_id'],
            [
                'service_guard_id' => null,
                'invoice_id' => $secondInvoice->id,
                'configuration_fingerprint' => str_repeat('b', 64),
            ]
        );
        $this->insertAllocation(
            $duplicateId,
            $fixture['panel'],
            released: false,
            allocationId: 7002
        );

        try {
            $this->invokeCheckoutCommitmentPreflight();
            $this->fail(
                'Expected duplicate paid checkout commitments to block '
                .'the migration.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'refused to retire or release any obligation',
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                "service {$fixture['service']->id}",
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                "#{$fixture['reservation_id']}",
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                "#{$duplicateId}",
                $exception->getMessage()
            );
        }

        $this->assertSame(
            ['paid_committed', 'paid_committed'],
            DB::table('ptero_resource_reservations')
                ->whereIn('id', [
                    $fixture['reservation_id'],
                    $duplicateId,
                ])
                ->orderBy('id')
                ->pluck('status')
                ->all()
        );
        $this->assertSame(
            0,
            DB::table('ptero_reservation_allocations')
                ->whereIn('reservation_id', [
                    $fixture['reservation_id'],
                    $duplicateId,
                ])
                ->whereNotNull('released_at')
                ->count()
        );
    }

    public function test_checkout_commitment_migration_preserves_upgrade_history(): void
    {
        $upgradeReservationId =
            $this->insertCompleteUpgradeReservation();

        $this->invokeCheckoutCommitmentPreflight();

        $this->assertDatabaseHas('ptero_resource_reservations', [
            'id' => $upgradeReservationId,
            'purpose' => 'upgrade',
            'status' => 'pending',
        ]);
    }

    public function test_complete_signed_checkout_identity_passes_gate(): void
    {
        $this->insertCompleteCheckoutReservation();

        (new LegacyReservationReadinessService)->assertReady();

        $this->addToAssertionCount(1);
    }

    public function test_paid_committed_checkout_matches_provisioning_service_lifecycle(): void
    {
        $this->insertCompleteCheckoutReservation(
            status: 'paid_committed'
        );

        (new LegacyReservationReadinessService)->assertReady();

        $this->addToAssertionCount(1);
    }

    public function test_cancelled_service_preserves_its_confirmed_fulfillment_tombstone(): void
    {
        $fixture = $this->insertCompleteCheckoutReservation();
        DB::table('services')
            ->where('id', $fixture['service']->id)
            ->update([
                'status' => Service::STATUS_CANCELLED,
                'product_stock_released_at' => now(),
                'updated_at' => now(),
            ]);
        DB::table('ptero_resource_reservations')
            ->where('id', $fixture['reservation_id'])
            ->update([
                'cancellation_requested_at' => now(),
                'product_stock_released_at' => now(),
                'updated_at' => now(),
            ]);

        (new LegacyReservationReadinessService)->assertReady();

        $this->addToAssertionCount(1);
    }

    public function test_fractional_resource_identity_cannot_pass_readiness_by_integer_truncation(): void
    {
        $service = $this->service();
        $panel = hash('sha256', 'https://panel.example.com');
        $payload = $this->completeCheckoutPayload(
            $service,
            $panel,
            memory: 4096.5
        );
        $reservationId = $this->insertReservation($service, [
            'status' => 'confirmed',
            'server_extension_id' => 41,
            'panel_identity' => $panel,
            'configuration_fingerprint' =>
                (new ReservationConfigurationService)
                    ->fingerprint($payload),
            'configuration_payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'external_server_id' => 71,
            'external_user_id' => 44,
            'external_server_uuid' =>
                '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
            'external_server_identifier' => 'server-71',
        ]);
        $this->insertReleasedAllocation($reservationId, $panel);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $reservationId);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'signed checkout/service identity agreement',
            $blocker['missing']
        );
    }

    public function test_quantity_above_one_cannot_pass_legacy_readiness(): void
    {
        $service = $this->service();
        DB::table('services')->where('id', $service->id)->update([
            'quantity' => 2,
        ]);
        $service->refresh();
        $panel = hash('sha256', 'https://panel.example.com');
        $payload = $this->completeCheckoutPayload(
            $service,
            $panel,
            quantity: 2
        );
        $reservationId = $this->insertReservation($service, [
            'status' => 'confirmed',
            'quantity' => 2,
            'server_extension_id' => 41,
            'panel_identity' => $panel,
            'configuration_fingerprint' =>
                (new ReservationConfigurationService)
                    ->fingerprint($payload),
            'configuration_payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'external_server_id' => 71,
            'external_user_id' => 44,
            'external_server_uuid' =>
                '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
            'external_server_identifier' => 'server-71',
        ]);
        $this->insertReleasedAllocation($reservationId, $panel);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $reservationId);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'signed checkout/service identity agreement',
            $blocker['missing']
        );
    }

    public function test_bound_unpaid_checkout_requires_materialized_allocation_claims(): void
    {
        $fixture = $this->insertCompleteCheckoutReservation(
            status: 'pending',
            withAllocation: false
        );
        $reservationId = $fixture['reservation_id'];

        $blockers = (new LegacyReservationReadinessService)->blockers();
        $blocker = collect($blockers)->firstWhere(
            'reservation_id',
            $reservationId
        );

        $this->assertNotNull($blocker);
        $this->assertContains(
            'active signed allocation claims',
            $blocker['missing']
        );

        $this->insertAllocation(
            $reservationId,
            $fixture['panel'],
            released: false
        );

        (new LegacyReservationReadinessService)->assertReady();
        $this->addToAssertionCount(1);
    }

    public function test_dynamic_service_missing_checkout_reservation_blocks_reverse_scan(): void
    {
        $fixture = $this->insertCompleteCheckoutReservation();
        DB::table('ptero_resource_reservations')
            ->where('id', $fixture['reservation_id'])
            ->delete();

        $this->assertTrue(
            collect(
                (new LegacyReservationReadinessService)->blockers()
            )->contains(
                fn (array $row): bool =>
                    $row['purpose'] === 'checkout'
                    && $row['service_id'] === $fixture['service']->id
                    && in_array(
                        "dynamic service #{$fixture['service']->id} "
                            .'requires exactly one lifecycle-coherent '
                            .'checkout capacity reservation',
                        $row['missing'],
                        true
                    )
            )
        );
    }

    public function test_duplicate_checkout_reservations_block_reverse_scan_even_without_second_guard(): void
    {
        $fixture = $this->insertCompleteCheckoutReservation();
        $duplicateId = $this->duplicateReservation(
            $fixture['reservation_id'],
            [
                'service_guard_id' => null,
            ]
        );
        $this->insertAllocation(
            $duplicateId,
            $fixture['panel'],
            released: true
        );

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->first(
            fn (array $row): bool =>
                $row['purpose'] === 'checkout'
                && $row['reservation_id'] === 0
                && $row['service_id'] === $fixture['service']->id
        );

        $this->assertNotNull($blocker);
        $this->assertStringContainsString(
            'exactly one lifecycle-coherent checkout',
            implode(', ', $blocker['missing'])
        );
    }

    public function test_checkout_consumed_lifecycle_drift_blocks_readiness(): void
    {
        $fixture = $this->insertCompleteCheckoutReservation();
        DB::table('ptero_resource_reservations')
            ->where('id', $fixture['reservation_id'])
            ->update([
                'consumed_at' => null,
                'updated_at' => now(),
            ]);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $fixture['reservation_id']);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'checkout service guard/lifecycle agreement',
            $blocker['missing']
        );
    }

    public function test_checkout_service_guard_drift_blocks_readiness(): void
    {
        $fixture = $this->insertCompleteCheckoutReservation();
        DB::table('ptero_resource_reservations')
            ->where('id', $fixture['reservation_id'])
            ->update([
                'service_guard_id' => null,
                'updated_at' => now(),
            ]);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $fixture['reservation_id']);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'checkout service guard/lifecycle agreement',
            $blocker['missing']
        );
    }

    public function test_checkout_live_service_resource_drift_blocks_readiness(): void
    {
        $fixture = $this->insertCompleteCheckoutReservation();
        $memoryOption = DB::table('config_options')
            ->where('env_variable', 'memory')
            ->whereExists(function ($query) use ($fixture): void {
                $query->selectRaw('1')
                    ->from('config_option_products')
                    ->whereColumn(
                        'config_option_products.config_option_id',
                        'config_options.id'
                    )
                    ->where(
                        'config_option_products.product_id',
                        $fixture['service']->product_id
                    );
            })
            ->value('id');
        $this->assertNotNull($memoryOption);
        DB::table('service_configs')
            ->where('configurable_type', Service::class)
            ->where('configurable_id', $fixture['service']->id)
            ->where('config_option_id', $memoryOption)
            ->update([
                'slider_value' => 8192,
                'updated_at' => now(),
            ]);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $fixture['reservation_id']);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'delivered service resource agreement',
            $blocker['missing']
        );
    }

    public function test_checkout_price_version_drift_blocks_readiness(): void
    {
        $fixture = $this->insertCompleteCheckoutReservation();
        DB::table('ptero_resource_reservations')
            ->where('id', $fixture['reservation_id'])
            ->update([
                'pricing_version' => str_repeat('b', 64),
                'updated_at' => now(),
            ]);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $fixture['reservation_id']);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'signed checkout pricing agreement',
            $blocker['missing']
        );
    }

    public function test_checkout_invoice_line_drift_blocks_readiness(): void
    {
        $fixture = $this->insertCompleteCheckoutReservation();
        DB::table('invoice_items')
            ->where('invoice_id', $fixture['invoice_id'])
            ->where('reference_type', Service::class)
            ->where('reference_id', $fixture['service']->id)
            ->update([
                'price' => '9.00',
                'updated_at' => now(),
            ]);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $fixture['reservation_id']);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'checkout invoice/service billing agreement',
            $blocker['missing']
        );
    }

    public function test_completed_upgrade_missing_reservation_blocks_reverse_scan(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        $this->completeUpgrade($reservationId, applyTarget: true);
        $upgradeId = $this->upgradeIdForReservation($reservationId);
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->delete();

        $this->assertTrue(
            collect(
                (new LegacyReservationReadinessService)->blockers()
            )->contains(
                fn (array $row): bool =>
                    $row['purpose'] === 'upgrade'
                    && in_array(
                        "dynamic service upgrade #{$upgradeId} "
                            .'requires exactly one coherent capacity '
                            .'reservation',
                        $row['missing'],
                        true
                    )
            )
        );
    }

    public function test_completed_upgrade_duplicate_reservations_block_reverse_scan(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        $this->completeUpgrade($reservationId, applyTarget: true);
        $upgradeId = $this->upgradeIdForReservation($reservationId);
        $this->duplicateReservation($reservationId);

        $this->assertTrue(
            collect(
                (new LegacyReservationReadinessService)->blockers()
            )->contains(
                fn (array $row): bool =>
                    $row['purpose'] === 'upgrade'
                    && $row['reservation_id'] === 0
                    && in_array(
                        "dynamic service upgrade #{$upgradeId} "
                            .'requires exactly one coherent capacity '
                            .'reservation',
                        $row['missing'],
                        true
                    )
            )
        );
    }

    public function test_confirmed_upgrade_not_applied_to_service_blocks_readiness(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        $this->completeUpgrade($reservationId, applyTarget: false);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $reservationId);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'valid configuration_fingerprint',
            $blocker['missing']
        );
    }

    public function test_confirmed_upgrade_recurring_price_drift_blocks_readiness(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        $this->completeUpgrade($reservationId, applyTarget: true);
        $serviceId = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->value('service_id');
        $this->assertNotNull($serviceId);
        DB::table('services')->where('id', $serviceId)->update([
            'price' => '1.00',
            'updated_at' => now(),
        ]);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $reservationId);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'valid configuration_fingerprint',
            $blocker['missing']
        );
    }

    public function test_completed_upgrade_with_applied_target_passes_readiness(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        $this->completeUpgrade($reservationId, applyTarget: true);

        (new LegacyReservationReadinessService)->assertReady();

        $this->addToAssertionCount(1);
    }

    public function test_completed_upgrade_remains_ready_after_slider_policy_changes(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        $this->completeUpgrade($reservationId, applyTarget: true);
        $serviceId = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->value('service_id');
        $this->assertNotNull($serviceId);
        $optionId = DB::table('service_configs')
            ->join(
                'config_options',
                'config_options.id',
                '=',
                'service_configs.config_option_id'
            )
            ->where('service_configs.configurable_type', Service::class)
            ->where('service_configs.configurable_id', $serviceId)
            ->where('config_options.env_variable', 'memory')
            ->value('config_options.id');
        $this->assertNotNull($optionId);
        $option = ConfigOption::query()->findOrFail((int) $optionId);
        $metadata = (array) $option->metadata;
        $metadata['min'] = 9000;
        $metadata['step'] = 2048;
        $metadata['default'] = 9000;
        $option->metadata = $metadata;
        $option->save();

        try {
            $option->fresh()->normalizeDynamicSliderValue(8192);
            $this->fail(
                'Expected the historical target to violate the new slider policy.'
            );
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        (new LegacyReservationReadinessService)->assertReady();

        $this->addToAssertionCount(1);
    }

    public function test_upgrade_source_and_target_billing_anchors_must_match(): void
    {
        $reservationId = $this->insertCompleteUpgradeReservation();
        $upgradeId = $this->upgradeIdForReservation($reservationId);
        $upgrade = ServiceUpgrade::query()->findOrFail($upgradeId);
        $target = (array) $upgrade->target_snapshot;
        $target['billing_anchor']['stored_recurring_price'] = '999.00';
        $targetFingerprint = $this->upgradeSnapshotFingerprint($target);
        DB::table('service_upgrades')
            ->where('id', $upgradeId)
            ->update([
                'target_snapshot' => json_encode(
                    $target,
                    JSON_THROW_ON_ERROR
                ),
                'target_fingerprint' => $targetFingerprint,
                'updated_at' => now(),
            ]);
        $upgrade->refresh();
        $reservation = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->first();
        $this->assertNotNull($reservation);
        $payload = json_decode(
            (string) $reservation->configuration_payload,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $payload['target_fingerprint'] = $targetFingerprint;
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'configuration_payload' => json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                ),
                'configuration_fingerprint' =>
                    (new UpgradeReservationIntegrityService)
                        ->fingerprint($upgrade, $payload),
                'updated_at' => now(),
            ]);

        $blocker = collect(
            (new LegacyReservationReadinessService)->blockers()
        )->firstWhere('reservation_id', $reservationId);

        $this->assertNotNull($blocker);
        $this->assertContains(
            'valid configuration_fingerprint',
            $blocker['missing']
        );
    }

    private function service(): Service
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $plan = Plan::factory()->create([
            'priceable_id' => $product->id,
            'priceable_type' => Product::class,
        ]);

        return Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'quantity' => 1,
            'currency_code' => 'USD',
        ]);
    }

    /**
     * @return array{
     *     reservation_id: int,
     *     service: Service,
     *     server: Server,
     *     panel: string,
     *     payload: array<string, mixed>,
     *     invoice_id: int
     * }
     */
    private function insertCompleteCheckoutReservation(
        string $status = 'confirmed',
        bool $withAllocation = true,
        ?Service $service = null,
        ?Server $server = null
    ): array {
        if ($service === null || $server === null) {
            $fixture = $this->dynamicServiceFixture(
                match ($status) {
                    'pending' => Service::STATUS_PENDING,
                    'paid_committed' => Service::STATUS_PROVISIONING,
                    default => Service::STATUS_ACTIVE,
                }
            );
            $service = $fixture['service'];
            $server = $fixture['server'];
            $panel = $fixture['panel'];
        } else {
            $panel = hash('sha256', 'https://panel.example.com');
        }

        $service->load([
            'user',
            'product.server',
            'configs.configOption',
        ]);
        $payload = $this->completeCheckoutPayload($service, $panel);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => $status === 'pending'
                ? Invoice::STATUS_PENDING
                : Invoice::STATUS_PAID,
        ]);
        $invoice->items()->create([
            'description' => 'Dynamic service',
            'price' => $payload['calculated_price'],
            'quantity' => 1,
            'reference_id' => $service->id,
            'reference_type' => Service::class,
        ]);
        $confirmed = $status === 'confirmed';
        $reservationId = $this->insertReservation($service, [
            'purpose' => 'checkout',
            'status' => $status,
            'cart_id' => 99,
            'cart_item_guard_id' => 199,
            'service_guard_id' => $service->id,
            'server_extension_id' => $server->id,
            'panel_identity' => $panel,
            'configuration_fingerprint' =>
                (new ReservationConfigurationService)
                    ->fingerprint($payload),
            'configuration_payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'calculated_price' => $payload['calculated_price'],
            'pricing_version' => $payload['pricing_version'],
            'formula_version' => $payload['formula_version'],
            'invoice_id' => $invoice->id,
            'expires_at' => $confirmed
                ? now()->subDay()
                : now()->addDay(),
            'guaranteed_until' => $confirmed
                ? now()->subDay()
                : now()->addDay(),
            'consumed_at' => $confirmed ? now()->subDay() : null,
            'external_server_id' => $confirmed ? 71 : null,
            'external_user_id' => $confirmed ? 44 : null,
            'external_server_uuid' => $confirmed
                ? '2f4f28b0-0f36-4e6b-a2aa-a686c3466696'
                : null,
            'external_server_identifier' => $confirmed
                ? 'server-71'
                : null,
        ]);
        if ($withAllocation) {
            $this->insertAllocation(
                $reservationId,
                $panel,
                released: $confirmed
            );
        }

        return [
            'reservation_id' => $reservationId,
            'service' => $service,
            'server' => $server,
            'panel' => $panel,
            'payload' => $payload,
            'invoice_id' => (int) $invoice->id,
        ];
    }

    /**
     * @return array{service: Service, server: Server, panel: string}
     */
    private function dynamicServiceFixture(
        string $status = Service::STATUS_ACTIVE
    ): array {
        $panel = hash('sha256', 'https://panel.example.com');
        $server = Server::create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        $server->settings()->create([
            'key' => 'host',
            'value' => 'https://panel.example.com',
            'type' => 'string',
            'encrypted' => false,
        ]);
        $product = Product::factory()->create([
            'server_id' => $server->id,
            'hidden' => false,
        ]);
        $plan = Plan::factory()->create([
            'priceable_id' => $product->id,
            'priceable_type' => Product::class,
        ]);
        foreach ([
            'location_ids' => [[3], 'array'],
            'nest_id' => [1, 'integer'],
            'egg_id' => [2, 'integer'],
        ] as $key => [$value, $type]) {
            $product->settings()->create([
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'encrypted' => false,
            ]);
        }
        Price::factory()->create([
            'plan_id' => $plan->id,
            'price' => 10,
            'setup_fee' => 0,
            'currency_code' => 'USD',
        ]);
        $resources = [
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 20480,
        ];
        $options = [];
        foreach ($resources as $resource => $value) {
            $options[$resource] = $this->dynamicOption(
                $product,
                $resource
            );
        }
        $service = CapacityServiceCreationCoordinator::run(
            function () use (
                $product,
                $plan,
                $options,
                $resources,
                $status
            ): Service {
                $service = Service::factory()->create([
                    'user_id' => User::factory()->create()->id,
                    'product_id' => $product->id,
                    'plan_id' => $plan->id,
                    'status' => $status,
                    'price' => 10,
                    'quantity' => 1,
                    'currency_code' => 'USD',
                    'expires_at' => now()->addMonth(),
                ]);
                foreach ($resources as $resource => $value) {
                    $service->configs()->create([
                        'config_option_id' => $options[$resource]->id,
                        'config_value_id' => null,
                        'slider_value' => $value,
                    ]);
                }

                return $service;
            }
        );

        return compact('service', 'server', 'panel');
    }

    /**
     * @return array<string, mixed>
     */
    private function completeCheckoutPayload(
        Service $service,
        string $panel,
        mixed $memory = 4096,
        int $quantity = 1
    ): array {
        $service->loadMissing([
            'product.server',
            'configs.configOption',
        ]);
        $configOptions = $service->configs
            ->sortBy('config_option_id')
            ->map(function ($config) use ($memory): array {
                $option = $config->configOption;
                $resource = strtolower((string) (
                    $option?->getMetadata('resource_type', '')
                ));
                $value = $resource === 'memory'
                    ? $memory
                    : $config->slider_value;

                return [
                    'id' => (int) $config->config_option_id,
                    'type' => (string) ($option?->type ?? ''),
                    'environment_key' => strtolower((string) (
                        $option?->env_variable ?: $option?->name
                    )),
                    'resource_type' => $resource ?: null,
                    'value' => is_numeric($value)
                        ? (float) $value
                        : $value,
                    'metadata' => (array) ($option?->metadata ?? []),
                ];
            })
            ->values()
            ->all();
        $calculatedPrice = number_format(
            (float) $service->price,
            2,
            '.',
            ''
        );
        $payload = [
            'customer_id' => $service->user_id,
            'cart_id' => 99,
            'server_extension_id' =>
                (int) ($service->product?->server_id ?? 41),
            'panel_identity' => $panel,
            'product_id' => $service->product_id,
            'plan_id' => $service->plan_id,
            'quantity' => $quantity,
            'currency_code' => 'USD',
            'resources' => [
                'memory' => $memory,
                'cpu' => 200,
                'disk' => 20480,
            ],
            'location_id' => 3,
            'node_id' => 7,
            'calculated_price' => $calculatedPrice,
            'formula_version' => 'dynamic-pterodactyl-v1',
            'config_options' => $configOptions,
            'allocation_requirements' => [
                'required_count' => 1,
            ],
            'provisioning_identity' => [
                'nest_id' => 1,
                'egg_id' => 2,
                'user_external_id' =>
                    "paymenter-user-{$service->user_id}",
                'user_email' => (string) $service->user->email,
            ],
            'allocations' => [[
                'allocation_id' => 7001,
                'ip' => '192.0.2.10',
                'port' => 25565,
                'environment_key' => 'SERVER_PORT',
                'is_primary' => true,
            ]],
        ];
        $payload['pricing_version'] =
            (new ReservationConfigurationService)->fingerprint([
                'product_id' => (int) $service->product_id,
                'plan_id' => (int) $service->plan_id,
                'currency_code' => 'USD',
                'calculated_price' => $calculatedPrice,
                'config_options' => $configOptions,
            ]);

        return $payload;
    }

    private function insertReleasedAllocation(
        int $reservationId,
        string $panel
    ): void {
        $this->insertAllocation($reservationId, $panel, released: true);
    }

    private function insertAllocation(
        int $reservationId,
        string $panel,
        bool $released,
        int $allocationId = 7001
    ): void {
        DB::table('ptero_reservation_allocations')->insert([
            'reservation_id' => $reservationId,
            'panel_identity' => $panel,
            'node_id' => 7,
            'allocation_id' => $allocationId,
            'ip' => '192.0.2.10',
            'port' => $allocationId === 7001 ? 25565 : 25566,
            'environment_key' => 'SERVER_PORT',
            'is_primary' => true,
            'released_at' => $released ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCompleteUpgradeReservation(): int
    {
        $panel = hash('sha256', 'https://panel.example.com');
        $server = Server::create([
            'name' => 'Pterodactyl',
            'extension' => 'Pterodactyl',
            'type' => 'server',
            'enabled' => true,
        ]);
        $server->settings()->create([
            'key' => 'host',
            'value' => 'https://panel.example.com',
            'type' => 'string',
            'encrypted' => false,
        ]);
        $product = Product::factory()->create([
            'server_id' => $server->id,
            'hidden' => false,
        ]);
        $plan = Plan::factory()->create([
            'priceable_id' => $product->id,
            'priceable_type' => Product::class,
        ]);
        foreach ([
            'location_ids' => [[3], 'array'],
            'nest_id' => [1, 'integer'],
            'egg_id' => [2, 'integer'],
        ] as $key => [$value, $type]) {
            $product->settings()->create([
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'encrypted' => false,
            ]);
        }
        Price::factory()->create([
            'plan_id' => $plan->id,
            'price' => 10,
            'setup_fee' => 0,
            'currency_code' => 'USD',
        ]);
        $source = [
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 20480,
        ];
        $target = [
            'memory' => 8192,
            'cpu' => 400,
            'disk' => 40960,
        ];
        $delta = [
            'memory' => 4096,
            'cpu' => 200,
            'disk' => 20480,
        ];
        $options = [];
        foreach ($source as $resource => $value) {
            $options[$resource] = $this->dynamicOption(
                $product,
                $resource
            );
        }
        $service = CapacityServiceCreationCoordinator::run(function () use (
            $product,
            $plan,
            $options,
            $source
        ): Service {
            $service = Service::factory()->create([
                'user_id' => User::factory()->create()->id,
                'product_id' => $product->id,
                'plan_id' => $plan->id,
                'status' => Service::STATUS_ACTIVE,
                'price' => 10,
                'quantity' => 1,
                'currency_code' => 'USD',
                'expires_at' => now()->addMonth(),
            ]);
            foreach ($source as $resource => $value) {
                $service->configs()->create([
                    'config_option_id' => $options[$resource]->id,
                    'config_value_id' => null,
                    'slider_value' => $value,
                ]);
            }

            return $service;
        });

        $this->insertCompleteCheckoutReservation(
            service: $service,
            server: $server
        );

        $invoice = \App\Models\Invoice::factory()->create([
            'user_id' => $service->user_id,
            'currency_code' => 'USD',
            'status' => \App\Models\Invoice::STATUS_PENDING,
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $service->product_id,
            'plan_id' => $service->plan_id,
            'invoice_id' => $invoice->id,
            'status' => 'awaiting_payment',
            'active_service_guard_id' => $service->id,
            'type' => 'config_options',
            'quoted_amount' => '100.00',
            'currency_code' => 'USD',
            'credit_amount' => 0,
            'provisioning_attempts' => 0,
        ]);
        foreach ($target as $resource => $value) {
            $upgrade->configs()->create([
                'config_option_id' => $options[$resource]->id,
                'config_value_id' => null,
                'slider_value' => $value,
            ]);
        }
        $upgrade->load([
            'service.product.server.settings',
            'service.product.settings',
            'service.plan.prices',
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
            'price' => '100.00',
            'quantity' => 1,
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);
        $sourceFingerprint = (string) $upgrade->source_fingerprint;
        $targetFingerprint = (string) $upgrade->target_fingerprint;
        $upgradeId = (int) $upgrade->id;
        $payload = [
            'service_upgrade_id' => $upgradeId,
            'source_fingerprint' => $sourceFingerprint,
            'target_fingerprint' => $targetFingerprint,
            'panel_identity' => $panel,
            'node_id' => 7,
            'location_id' => 3,
            'external_server_id' => 71,
            'external_server_uuid' =>
                '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
            'external_server_identifier' => 'server-71',
            'external_server_external_id' => (string) $service->id,
            'external_user_id' => 44,
            'user_external_id' =>
                "paymenter-user-{$service->user_id}",
            'user_email' => (string) $service->user->email,
            'nest_id' => 1,
            'egg_id' => 2,
            'preserved_build' => [
                'swap' => 0,
                'io' => 500,
                'threads' => null,
                'databases' => 0,
                'allocations' => 0,
                'backups' => 0,
            ],
            'allocation_id' => 7001,
            'assigned_allocation_ids' => [7001],
            'source' => $source,
            'target' => $target,
            'delta' => $delta,
        ];

        return $this->insertReservation($service, [
            'purpose' => 'upgrade',
            'status' => 'pending',
            'service_guard_id' => null,
            'server_extension_id' => $server->id,
            'service_upgrade_id' => $upgradeId,
            'upgrade_guard_id' => $upgradeId,
            'panel_identity' => $panel,
            'configuration_fingerprint' =>
                (new UpgradeReservationIntegrityService)
                    ->fingerprint($upgrade, $payload),
            'configuration_payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'node_id' => 7,
            'location_id' => 3,
            'memory' => $target['memory'],
            'cpu' => $target['cpu'],
            'disk' => $target['disk'],
            'reserved_memory' => $delta['memory'],
            'reserved_cpu' => $delta['cpu'],
            'reserved_disk' => $delta['disk'],
            'external_server_id' => 71,
            'external_user_id' => 44,
            'external_server_uuid' =>
                '2f4f28b0-0f36-4e6b-a2aa-a686c3466696',
            'external_server_identifier' => 'server-71',
            'calculated_price' => '100.00',
            'pricing_version' =>
                (new UpgradeReservationIntegrityService)
                    ->pricingVersion($upgrade),
            'formula_version' => 'dynamic-upgrade-v1',
            'invoice_id' => $invoice->id,
            'expires_at' => now()->addDay(),
            'guaranteed_until' => now()->addDay(),
            'consumed_at' => null,
        ]);
    }

    private function upgradeIdForReservation(int $reservationId): int
    {
        $upgradeId = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->value('service_upgrade_id');
        $this->assertNotNull($upgradeId);

        return (int) $upgradeId;
    }

    private function completeUpgrade(
        int $reservationId,
        bool $applyTarget
    ): void {
        $reservation = DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->first();
        $this->assertNotNull($reservation);
        $upgrade = ServiceUpgrade::query()
            ->findOrFail((int) $reservation->service_upgrade_id);
        $target = (array) $upgrade->target_snapshot;
        $targetProperties = (array) ($target['properties'] ?? []);

        if ($applyTarget) {
            foreach (['memory', 'cpu', 'disk'] as $resource) {
                $optionId = DB::table('config_options')
                    ->where('env_variable', $resource)
                    ->whereExists(
                        function ($query) use ($upgrade): void {
                            $query->selectRaw('1')
                                ->from('config_option_products')
                                ->whereColumn(
                                    'config_option_products.config_option_id',
                                    'config_options.id'
                                )
                                ->where(
                                    'config_option_products.product_id',
                                    $upgrade->product_id
                                );
                        }
                    )
                    ->value('id');
                $this->assertNotNull($optionId);
                DB::table('service_configs')
                    ->where('configurable_type', Service::class)
                    ->where('configurable_id', $upgrade->service_id)
                    ->where('config_option_id', $optionId)
                    ->update([
                        'slider_value' => $targetProperties[$resource],
                        'updated_at' => now(),
                    ]);
            }
            DB::table('services')
                ->where('id', $upgrade->service_id)
                ->update([
                    'price' => $target['recurring_price'],
                    'updated_at' => now(),
                ]);
        }

        DB::table('invoices')
            ->where('id', $upgrade->invoice_id)
            ->update([
                'status' => Invoice::STATUS_PAID,
                'updated_at' => now(),
            ]);
        DB::table('service_upgrades')
            ->where('id', $upgrade->id)
            ->update([
                'status' => ServiceUpgrade::STATUS_COMPLETED,
                'active_service_guard_id' => null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
        DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->update([
                'status' => 'confirmed',
                'upgrade_guard_id' => null,
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function duplicateReservation(
        int $reservationId,
        array $overrides = []
    ): int {
        $row = (array) DB::table('ptero_resource_reservations')
            ->where('id', $reservationId)
            ->first();
        $this->assertNotSame([], $row);
        foreach ([
            'id',
            'active_idempotency_key',
            'active_cart_item_id',
            'active_upgrade_id',
            'active_checkout_service_id',
        ] as $generatedColumn) {
            unset($row[$generatedColumn]);
        }
        $row['token'] = bin2hex(random_bytes(32));
        $row['created_at'] = now();
        $row['updated_at'] = now();

        return DB::table('ptero_resource_reservations')->insertGetId(
            array_merge($row, $overrides)
        );
    }

    private function invokeCheckoutCommitmentPreflight(): void
    {
        $migration = require dirname(__DIR__, 2)
            .'/database/migrations/'
            .'2026_07_26_000030_enforce_one_checkout_commitment_per_service.php';
        $method = new \ReflectionMethod(
            $migration,
            'assertNoDuplicateCheckoutCommitments'
        );
        $method->setAccessible(true);
        $method->invoke($migration);
    }

    /**
     * @param  array<string|int, mixed>  $snapshot
     */
    private function upgradeSnapshotFingerprint(array $snapshot): string
    {
        $canonicalize = function (array $value) use (&$canonicalize): array {
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $canonicalize($item);
                }
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        };

        return hash('sha256', json_encode(
            $canonicalize($snapshot),
            JSON_THROW_ON_ERROR
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_SLASHES
        ));
    }

    private function dynamicOption(
        Product $product,
        string $resource
    ): ConfigOption {
        $option = ConfigOption::create([
            'name' => ucfirst($resource),
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertReservation(
        Service $service,
        array $overrides
    ): int {
        return DB::table('ptero_resource_reservations')->insertGetId(
            array_merge([
                'token' => bin2hex(random_bytes(32)),
                'purpose' => 'checkout',
                'server_extension_id' => 41,
                'panel_identity' =>
                    hash('sha256', 'https://panel.example.com'),
                'service_id' => $service->id,
                'service_guard_id' => $service->id,
                'user_id' => $service->user_id,
                'product_id' => $service->product_id,
                'plan_id' => $service->plan_id,
                'quantity' => 1,
                'currency_code' => 'USD',
                'node_id' => 7,
                'location_id' => 3,
                'memory' => 4096,
                'cpu' => 200,
                'disk' => 20480,
                'reserved_memory' => 0,
                'reserved_cpu' => 0,
                'reserved_disk' => 0,
                'calculated_price' => '10.00',
                'pricing_breakdown' => json_encode(
                    [],
                    JSON_THROW_ON_ERROR
                ),
                'status' => 'confirmed',
                'expires_at' => now()->subDay(),
                'guaranteed_until' => now()->subDay(),
                'consumed_at' => now()->subDay(),
                'external_server_id' => null,
                'external_user_id' => null,
                'external_server_uuid' => null,
                'external_server_identifier' => null,
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ], $overrides)
        );
    }
}
