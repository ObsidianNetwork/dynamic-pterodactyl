<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Exceptions\DisplayException;
use App\Exceptions\PermanentProvisioningException;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\CancelInvoiceService;
use App\Services\Invoice\CapacityInvoicePaymentService;
use App\Services\Service\CapacityConfigurationLockService;
use App\Services\ServiceUpgrade\CapacityUpgradeReservationIdentity;
use App\Services\ServiceUpgrade\ServiceUpgradeDispatchRecoveryService;
use App\Services\ServiceUpgrade\ServiceUpgradeMutationCoordinator;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use App\Support\PanelEndpointIdentity;
use App\Support\StrictInteger;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidResourceSelectionException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\StockUnavailableException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\UpgradeReservation;

class UpgradeReservationService
{
    private readonly UpgradeReservationIntegrityService $integrity;

    public function __construct(
        private readonly PterodactylInventoryService $inventory,
        private readonly ResourceCalculationService $resources,
        ?UpgradeReservationIntegrityService $integrity = null
    ) {
        $this->integrity = $integrity
            ?? new UpgradeReservationIntegrityService;
    }

    /**
     * Reserve only the positive resource delta while retaining the immutable
     * target vector for provisioning proof.
     */
    public function reserveForUpgrade(
        ServiceUpgrade $upgrade,
        CarbonInterface $guaranteedUntil
    ): UpgradeReservation {
        $this->inventory->assertExclusiveProvisioningControl();
        $this->assertServiceAcceptsUpgrade($upgrade->service);
        $context = $this->upgradeContext($upgrade, requireSourceMatch: true);
        $fingerprint = $this->reservationFingerprint($upgrade, $context);

        return DB::transaction(function () use (
            $upgrade,
            $guaranteedUntil,
            $context,
            $fingerprint
        ): UpgradeReservation {
            $this->lockCapacityScope(
                $context['panel_identity'],
                $context['location_id']
            );

            DB::table('ptero_resource_reservations')
                ->where('panel_identity', $context['panel_identity'])
                ->where('location_id', $context['location_id'])
                ->where(function (Builder $query): void {
                    $query->where(function (Builder $query): void {
                        $query->where('status', 'pending')
                            ->where('expires_at', '>', now());
                    })->orWhere('status', 'paid_committed');
                })
                ->lockForUpdate()
                ->get();

            $existing = UpgradeReservation::query()
                ->where('purpose', 'upgrade')
                ->where('service_upgrade_id', $upgrade->id)
                ->whereIn('status', ['pending', 'paid_committed'])
                ->lockForUpdate()
                ->first();

            if ($existing?->status === 'paid_committed') {
                if (! hash_equals(
                    (string) $existing->configuration_fingerprint,
                    $fingerprint
                )) {
                    throw new DisplayException(
                        'The paid upgrade commitment does not match this configuration.'
                    );
                }
                $this->integrity->verifiedSnapshot($upgrade, $existing);

                return $existing;
            }

            $excludeToken = $existing?->token;
            if (! $this->resources->verifyNodeCapacity(
                $context['node_id'],
                $context['delta'],
                0,
                $excludeToken
            )) {
                throw new StockUnavailableException(
                    'The current node does not have enough stock for this resource upgrade.'
                );
            }

            $payload = [
                'service_upgrade_id' => (int) $upgrade->id,
                'source_fingerprint' => (string) $upgrade->source_fingerprint,
                'target_fingerprint' => (string) $upgrade->target_fingerprint,
                'panel_identity' => $context['panel_identity'],
                'node_id' => $context['node_id'],
                'location_id' => $context['location_id'],
                'external_server_id' => $context['external_server_id'],
                'external_server_uuid' => $context['external_server_uuid'],
                'external_server_identifier' => $context['external_server_identifier'],
                'external_server_external_id' => $context['external_server_external_id'],
                'external_user_id' => $context['external_user_id'],
                'user_external_id' => $context['user_external_id'],
                'user_email' => $context['user_email'],
                'nest_id' => $context['nest_id'],
                'egg_id' => $context['egg_id'],
                'preserved_build' => $context['preserved_build'],
                'allocation_id' => $context['allocation_id'],
                'assigned_allocation_ids' => $context['assigned_allocation_ids'],
                'source' => $context['source'],
                'target' => $context['target'],
                'delta' => $context['delta'],
            ];
            $values = [
                'purpose' => 'upgrade',
                'idempotency_key' => hash(
                    'sha256',
                    "dynamic-upgrade:{$upgrade->id}:{$fingerprint}"
                ),
                'cart_item_id' => null,
                'cart_item_guard_id' => null,
                'cart_id' => null,
                'server_extension_id' => $upgrade->product->server_id,
                'panel_identity' => $context['panel_identity'],
                'node_id' => $context['node_id'],
                'location_id' => $context['location_id'],
                'service_id' => $upgrade->service_id,
                'service_upgrade_id' => $upgrade->id,
                'upgrade_guard_id' => $upgrade->id,
                'invoice_id' => $upgrade->invoice_id,
                'user_id' => $upgrade->service->user_id,
                'product_id' => $upgrade->product_id,
                'plan_id' => $upgrade->plan_id,
                'quantity' => 1,
                'currency_code' => strtoupper((string) $upgrade->currency_code),
                'configuration_fingerprint' => $fingerprint,
                'configuration_payload' => $payload,
                'pricing_version' => $this->integrity->pricingVersion($upgrade),
                'formula_version' => 'dynamic-upgrade-v1',
                // Full target values are provisioning truth.
                'memory' => $context['target']['memory'],
                'cpu' => $context['target']['cpu'],
                'disk' => $context['target']['disk'],
                // Capacity accounting uses only positive deltas.
                'reserved_memory' => $context['delta']['memory'],
                'reserved_cpu' => $context['delta']['cpu'],
                'reserved_disk' => $context['delta']['disk'],
                'calculated_price' => (float) $upgrade->quoted_amount,
                'external_server_id' => $context['external_server_id'],
                'external_user_id' => $context['external_user_id'],
                'external_server_uuid' => $context['external_server_uuid'],
                'external_server_identifier' => $context['external_server_identifier'],
                'pricing_breakdown' => [
                    'kind' => 'resource_upgrade',
                    'source' => $context['source'],
                    'target' => $context['target'],
                    'delta' => $context['delta'],
                ],
                'status' => 'pending',
                'expires_at' => $guaranteedUntil,
                'guaranteed_until' => $guaranteedUntil,
                'admin_notes' => 'Capacity held for an immutable service upgrade.',
            ];

            if ($existing !== null) {
                $existing->fill($values);
                $existing->save();
                $saved = $existing->fresh();
            } else {
                $saved = UpgradeReservation::create(array_merge($values, [
                    'token' => Str::random(64),
                ]))->fresh();
            }

            $this->integrity->verifiedSnapshot($upgrade, $saved);

            return $saved;
        }, 5);
    }

    /**
     * Fulfillment-owned invoice processing calls this for every upgrade while
     * this extension is installed, so non-dynamic upgrades deliberately
     * delegate to the core lifecycle.
     */
    public function commitPaidUpgrade(
        ServiceUpgrade $upgrade,
        ?Invoice $invoice
    ): bool {
        $upgrade->loadMissing(['service.product.configOptions', 'product.configOptions']);
        if (! $this->usesDynamicCapacity($upgrade)) {
            app(ServiceUpgradeService::class)->markPaidCommitted($upgrade);

            return true;
        }

        DB::transaction(function () use ($upgrade, $invoice): void {
            $lockedInvoice = $invoice !== null
                ? Invoice::query()
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail()
                : null;
            // Serialize the final billing-anchor proof with renewal,
            // cancellation, and any other service mutation. The earlier
            // preflight is advisory unless the service is locked again inside
            // the transaction that commits the paid upgrade.
            Service::query()
                ->whereKey($upgrade->service_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedUpgrade = ServiceUpgrade::query()
                ->with(['service.product.configOptions', 'product.configOptions'])
                ->lockForUpdate()
                ->findOrFail($upgrade->id);
            $reservation = UpgradeReservation::query()
                ->where('purpose', 'upgrade')
                ->where('service_upgrade_id', $lockedUpgrade->id)
                ->lockForUpdate()
                ->firstOrFail();

            $alreadyPaid = $reservation->status === 'paid_committed';
            if (! $alreadyPaid && $reservation->status !== 'pending') {
                throw new DisplayException(
                    'The upgrade capacity commitment is no longer payable.'
                );
            }
            if (
                ! $alreadyPaid
                && $reservation->guaranteed_until?->isPast()
            ) {
                throw new DisplayException(
                    'The upgrade capacity guarantee has expired.'
                );
            }
            if (
                $lockedInvoice !== null
                && $lockedUpgrade->invoice_id !== null
                && (int) $lockedUpgrade->invoice_id
                    !== (int) $lockedInvoice->id
            ) {
                throw new \RuntimeException(
                    'The paid invoice does not belong to this service upgrade.'
                );
            }
            if (
                $lockedInvoice !== null
                && $lockedInvoice->status !== Invoice::STATUS_PAID
            ) {
                throw new \RuntimeException(
                    'The upgrade invoice is not paid.'
                );
            }
            if (
                $lockedInvoice === null
                && ((float) $lockedUpgrade->quoted_amount > 0
                    || $lockedUpgrade->invoice_id !== null)
            ) {
                throw new \RuntimeException(
                    'Only an unbilled zero-value or downgrade can be committed without an invoice.'
                );
            }
            $this->assertServiceAcceptsUpgrade($lockedUpgrade->service);
            if ($lockedInvoice !== null) {
                $invoiceMismatch = $this->invoiceMismatch(
                    $lockedUpgrade,
                    $lockedInvoice
                );
                if ($invoiceMismatch !== null) {
                    throw new \RuntimeException($invoiceMismatch);
                }
            }
            if (
                ($integrityFailure = $this->reservationIntegrityError(
                    $lockedUpgrade,
                    $reservation,
                    ! $alreadyPaid ? $lockedInvoice : null
                )) !== null
            ) {
                throw new PermanentProvisioningException($integrityFailure);
            }

            if (! $alreadyPaid) {
                $reservation->forceFill([
                    'status' => 'paid_committed',
                    'invoice_id' => $lockedInvoice?->id,
                    'paid_committed_at' => now(),
                    'last_provisioning_error' => null,
                ])->save();
            }

            app(ServiceUpgradeService::class)->markPaidCommitted($lockedUpgrade);
        }, 5);

        return true;
    }

    /**
     * Run while the invoice coordinator holds its paid transaction open.
     * Invalid commitments are persisted and surfaced without throwing so
     * external payment evidence and the needs-attention state commit together.
     */
    public function preflightPaidUpgrade(
        ServiceUpgrade $upgrade,
        Invoice $invoice
    ): ?string {
        return DB::transaction(function () use ($upgrade, $invoice): ?string {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            Service::query()
                ->whereKey($upgrade->service_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedUpgrade = ServiceUpgrade::query()
                ->with([
                    'service.product.settings',
                    'service.configs.configOption',
                    'service.configs.configValue',
                    'product.configOptions',
                ])
                ->lockForUpdate()
                ->findOrFail($upgrade->id);

            if (! $this->usesDynamicCapacity($lockedUpgrade)) {
                return null;
            }

            $reservation = UpgradeReservation::query()
                ->where('purpose', 'upgrade')
                ->where('service_upgrade_id', $lockedUpgrade->id)
                ->lockForUpdate()
                ->first();
            $reason = null;

            if (
                $lockedInvoice->status !== Invoice::STATUS_PENDING
                || (int) $lockedUpgrade->invoice_id !== (int) $lockedInvoice->id
            ) {
                $reason = 'The upgrade invoice is no longer payable.';
            } elseif (
                $lockedUpgrade->service->status !== Service::STATUS_ACTIVE
                || $lockedUpgrade->service->cancellation()->exists()
            ) {
                $reason = 'The service is no longer eligible for this resource upgrade.';
            } elseif (
                ($invoiceMismatch = $this->invoiceMismatch(
                    $lockedUpgrade,
                    $lockedInvoice
                )) !== null
            ) {
                $reason = $invoiceMismatch;
            } elseif (
                $reservation === null
                || $reservation->status !== 'pending'
                || $reservation->guaranteed_until?->isPast()
            ) {
                $reason = 'The upgrade capacity guarantee has expired.';
            } elseif (! $lockedUpgrade->sourceStillMatches()) {
                $reason = 'The service changed after the upgrade was quoted.';
            } elseif (
                ($integrityFailure = $this->reservationIntegrityError(
                    $lockedUpgrade,
                    $reservation
                )) !== null
            ) {
                $reason = $integrityFailure;
            }

            if ($reason === null) {
                return null;
            }

            $paymentAttention = app(
                CapacityInvoicePaymentService::class
            )->hasInFlightOrSucceededPayment($lockedInvoice);
            $lockedUpgrade->forceFill([
                'status' => $paymentAttention
                    ? ServiceUpgrade::STATUS_NEEDS_ATTENTION
                    : ServiceUpgrade::STATUS_CANCELLED,
                'active_service_guard_id' => $paymentAttention
                    ? $lockedUpgrade->service_id
                    : null,
                'last_error' => $paymentAttention
                    ? "{$reason} External payment evidence exists; refund or account-credit review is required."
                    : $reason,
                'failed_at' => now(),
            ]);
            ServiceUpgradeMutationCoordinator::save($lockedUpgrade);
            if ($reservation !== null && $reservation->status === 'pending') {
                $reservation->forceFill([
                    'status' => $reservation->guaranteed_until?->isPast()
                        ? 'expired'
                        : 'cancelled',
                    'upgrade_guard_id' => null,
                    'admin_notes' => $reason,
                ])->save();
            }
            if (
                ! $paymentAttention
                && $lockedInvoice->status === Invoice::STATUS_PENDING
            ) {
                app(CancelInvoiceService::class)
                    ->markCancelledAfterFulfillment($lockedInvoice);
            }

            return $reason;
        }, 5);
    }

    public function expireUnpaidUpgrades(int $limit = 100): int
    {
        $limit = max(1, min($limit, 500));

        return app(SchedulerHealthService::class)->processEligibleRows(
            SchedulerHealthService::TASK_EXPIRE_UPGRADES,
            'resource_reservation',
            $limit,
            fn () => UpgradeReservation::query()
                ->where('purpose', 'upgrade')
                ->where('status', 'pending')
                ->where('guaranteed_until', '<=', now()),
            function (int $reservationId): bool {
                return DB::transaction(function () use (
                    $reservationId
                ): bool {
                    $candidate = UpgradeReservation::query()
                        ->find($reservationId);
                    if ($candidate === null) {
                        return false;
                    }

                    $invoice = $candidate->invoice_id !== null
                        ? Invoice::query()
                            ->whereKey($candidate->invoice_id)
                            ->lockForUpdate()
                            ->first()
                        : null;
                    $serviceId = ServiceUpgrade::query()
                        ->whereKey($candidate->service_upgrade_id)
                        ->value('service_id');
                    if ($serviceId !== null) {
                        Service::query()
                            ->whereKey($serviceId)
                            ->lockForUpdate()
                            ->firstOrFail();
                    }
                    $upgrade = ServiceUpgrade::query()
                        ->whereKey($candidate->service_upgrade_id)
                        ->lockForUpdate()
                        ->first();
                    $reservation = UpgradeReservation::query()
                        ->whereKey($reservationId)
                        ->lockForUpdate()
                        ->first();
                    if ($invoice !== null) {
                        $invoice->items()
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get();
                    }

                    if (
                        $reservation === null
                        || $reservation->status !== 'pending'
                        || $reservation->guaranteed_until?->isFuture()
                        || $invoice?->status === Invoice::STATUS_PAID
                    ) {
                        return false;
                    }

                    $reason = 'The unpaid upgrade capacity guarantee expired.';
                    $paymentAttention = $invoice !== null
                        && $invoice->status === Invoice::STATUS_PENDING
                        && app(CapacityInvoicePaymentService::class)
                            ->hasInFlightOrSucceededPayment($invoice);
                    $reservation->forceFill([
                        'status' => 'expired',
                        'upgrade_guard_id' => null,
                        'admin_notes' => $reason,
                    ])->save();
                    if ($upgrade !== null && in_array($upgrade->status, [
                        ServiceUpgrade::STATUS_PENDING,
                        ServiceUpgrade::STATUS_AWAITING_PAYMENT,
                    ], true)) {
                        $upgrade->forceFill([
                            'status' => $paymentAttention
                                ? ServiceUpgrade::STATUS_NEEDS_ATTENTION
                                : ServiceUpgrade::STATUS_CANCELLED,
                            'active_service_guard_id' => $paymentAttention
                                ? $upgrade->service_id
                                : null,
                            'last_error' => $paymentAttention
                                ? 'The capacity guarantee expired with payment activity; refund or account-credit review is required.'
                                : $reason,
                            'failed_at' => now(),
                        ]);
                        ServiceUpgradeMutationCoordinator::save($upgrade);
                    }
                    if ($paymentAttention) {
                        app(CapacityInvoicePaymentService::class)
                            ->requireAttention(
                                $invoice,
                                'The seven-day upgrade capacity guarantee expired after a partial or in-flight payment. Capacity was released; refund or account-credit review is required.'
                            );
                    } elseif (
                        $invoice?->status === Invoice::STATUS_PENDING
                    ) {
                        app(CancelInvoiceService::class)
                            ->markCancelledAfterFulfillment($invoice);
                    }

                    return true;
                }, 5);
            }
        );
    }

    public function reconcileStalledUpgrades(int $limit = 100): int
    {
        $limit = max(1, min($limit, 500));

        return app(SchedulerHealthService::class)->processEligibleRows(
            SchedulerHealthService::TASK_RECONCILE_UPGRADES,
            'service_upgrade',
            $limit,
            fn () => ServiceUpgrade::query()
                ->where('status', ServiceUpgrade::STATUS_PROVISIONING)
                ->where(
                    'provisioning_started_at',
                    '<=',
                    now()->subMinutes(10)
                ),
            function (int $upgradeId): bool {
                return DB::transaction(function () use (
                    $upgradeId
                ): bool {
                    $upgrade = ServiceUpgrade::query()
                        ->whereKey($upgradeId)
                        ->lockForUpdate()
                        ->first();
                    $reservation = UpgradeReservation::query()
                        ->where('purpose', 'upgrade')
                        ->where('service_upgrade_id', $upgradeId)
                        ->lockForUpdate()
                        ->first();

                    if (
                        $upgrade === null
                        || $reservation === null
                        || $upgrade->status
                            !== ServiceUpgrade::STATUS_PROVISIONING
                        || $upgrade->provisioning_started_at?->isAfter(
                            now()->subMinutes(10)
                        )
                        || $reservation->status !== 'paid_committed'
                    ) {
                        return false;
                    }

                    $message = 'A stale upgrade worker lease was recovered for retry.';
                    $reservation->forceFill([
                        'provisioning_lease_id' => null,
                        'provisioning_started_at' => null,
                        'last_provisioning_error' => $message,
                    ])->save();
                    $upgrade->forceFill([
                        'status' => ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                        'last_error' => $message,
                        'failed_at' => now(),
                    ]);
                    ServiceUpgradeMutationCoordinator::save($upgrade);

                    DB::afterCommit(
                        fn () => app(
                            ServiceUpgradeDispatchRecoveryService::class
                        )->dispatchById($upgradeId)
                    );

                    return true;
                }, 5);
            }
        );
    }

    /**
     * Acquire a retry-safe provisioning lease and return the exact contract
     * passed to the built-in Pterodactyl extension.
     *
     * @return array<string, mixed>
     */
    public function beginProvisioning(ServiceUpgrade $upgrade): array
    {
        return DB::transaction(function () use ($upgrade): array {
            $service = Service::query()
                ->whereKey($upgrade->service_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedUpgrade = ServiceUpgrade::query()
                ->whereKey($upgrade->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $lockedUpgrade->service_id !== (int) $service->id) {
                throw new PermanentProvisioningException(
                    'The paid upgrade no longer belongs to its locked service.'
                );
            }
            if (! $this->serviceAcceptsUpgrade($service)) {
                throw new PermanentProvisioningException(
                    'The service was cancelled before its paid upgrade could be applied.'
                );
            }
            $reservation = UpgradeReservation::query()
                ->where('purpose', 'upgrade')
                ->where('service_upgrade_id', $lockedUpgrade->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->status !== 'paid_committed') {
                throw new PermanentProvisioningException(
                    'The upgrade does not have a paid capacity commitment.'
                );
            }

            if (
                $reservation->provisioning_lease_id !== null
                && $reservation->provisioning_started_at?->gt(now()->subMinutes(10))
            ) {
                throw new \RuntimeException(
                    'Another worker currently owns this upgrade provisioning lease.'
                );
            }

            $payload = (array) $reservation->configuration_payload;
            if (
                ($integrityFailure = $this->reservationIntegrityError(
                    $lockedUpgrade,
                    $reservation
                )) !== null
            ) {
                throw new PermanentProvisioningException(
                    $integrityFailure
                );
            }

            $leaseId = (string) Str::uuid();
            $reservation->forceFill([
                'provisioning_lease_id' => $leaseId,
                'provisioning_started_at' => now(),
                'provisioning_attempts' => (int) $reservation->provisioning_attempts + 1,
                'last_provisioning_attempt_at' => now(),
                'last_provisioning_error' => null,
            ])->save();

            return [
                'reservation_id' => (int) $reservation->id,
                'provisioning_lease_id' => $leaseId,
                'panel_identity' => (string) $reservation->panel_identity,
                'node_id' => (int) $reservation->node_id,
                'location_id' => (int) $reservation->location_id,
                'source' => (array) ($payload['source'] ?? []),
                'target' => (array) ($payload['target'] ?? []),
                'external_server_id' => (int) ($payload['external_server_id'] ?? 0),
                'external_server_uuid' => (string) ($payload['external_server_uuid'] ?? ''),
                'external_server_identifier' => (string) ($payload['external_server_identifier'] ?? ''),
                'external_server_external_id' => (string) ($payload['external_server_external_id'] ?? ''),
                'external_user_id' => (int) ($payload['external_user_id'] ?? 0),
                'user_external_id' => (string) ($payload['user_external_id'] ?? ''),
                'user_email' => (string) ($payload['user_email'] ?? ''),
                'nest_id' => (int) ($payload['nest_id'] ?? 0),
                'egg_id' => (int) ($payload['egg_id'] ?? 0),
                'preserved_build' => (array) (
                    $payload['preserved_build'] ?? []
                ),
                'allocation_id' => (int) ($payload['allocation_id'] ?? 0),
                'assigned_allocation_ids' => array_values(array_map(
                    'intval',
                    (array) ($payload['assigned_allocation_ids'] ?? [])
                )),
            ];
        }, 5);
    }

    public function completeProvisioning(
        ServiceUpgrade $upgrade,
        ?string $leaseId
    ): void {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException(
                'Upgrade completion requires the core completion transaction.'
            );
        }
        if ($leaseId === null || $leaseId === '') {
            throw new \RuntimeException('An upgrade provisioning lease is required.');
        }

        $reservation = UpgradeReservation::query()
            ->where('purpose', 'upgrade')
            ->where('service_upgrade_id', $upgrade->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($reservation->status === 'confirmed') {
            if ($upgrade->status !== ServiceUpgrade::STATUS_COMPLETED) {
                throw new \RuntimeException(
                    'The confirmed capacity commitment has no completed core upgrade.'
                );
            }

            return;
        }
        if (
            $upgrade->status !== ServiceUpgrade::STATUS_PROVISIONING
            ||
            $reservation->status !== 'paid_committed'
            || ! hash_equals((string) $reservation->provisioning_lease_id, $leaseId)
        ) {
            throw new \RuntimeException(
                'The upgrade provisioning lease is stale or invalid.'
            );
        }
        if (
            ($integrityFailure = $this->reservationIntegrityError(
                $upgrade,
                $reservation
            )) !== null
        ) {
            throw new \RuntimeException($integrityFailure);
        }

        $reservation->forceFill([
            'status' => 'confirmed',
            'upgrade_guard_id' => null,
            'provisioning_lease_id' => null,
            'consumed_at' => now(),
            'last_provisioning_error' => null,
        ])->save();
    }

    public function failProvisioning(
        ServiceUpgrade $upgrade,
        \Throwable $exception,
        ?string $leaseId = null
    ): bool {
        $reservation = UpgradeReservation::query()
            ->where('purpose', 'upgrade')
            ->where('service_upgrade_id', $upgrade->id)
            ->lockForUpdate()
            ->first();
        if ($reservation === null || $reservation->status !== 'paid_committed') {
            return false;
        }
        if ($leaseId === null) {
            if ($reservation->provisioning_lease_id !== null) {
                return false;
            }
        } elseif (
            $reservation->provisioning_lease_id === null
            || ! hash_equals(
                (string) $reservation->provisioning_lease_id,
                $leaseId
            )
        ) {
            return false;
        }

        $reservation->forceFill([
            'provisioning_lease_id' => null,
            'provisioning_started_at' => null,
            'last_provisioning_error' => mb_substr($exception->getMessage(), 0, 65535),
        ])->save();

        return true;
    }

    public function cancelUpgrade(ServiceUpgrade $upgrade, string $reason): void
    {
        $reservation = UpgradeReservation::query()
            ->where('purpose', 'upgrade')
            ->where('service_upgrade_id', $upgrade->id)
            ->lockForUpdate()
            ->first();
        if ($reservation === null || $reservation->status === 'cancelled') {
            return;
        }
        if ($reservation->status !== 'pending') {
            throw new \RuntimeException(
                'Only an unpaid upgrade capacity hold can be cancelled.'
            );
        }

        $reservation->forceFill([
            'status' => 'cancelled',
            'upgrade_guard_id' => null,
            'admin_notes' => $reason,
        ])->save();
    }

    /**
     * Authenticated, customer-safe bounds for an existing server's fixed node.
     *
     * @param  array<int|string, mixed>  $submittedOptions
     * @return array<string, mixed>
     */
    public function quoteForService(Service $service, array $submittedOptions): array
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(
                fn (): array => $this->quoteForService(
                    $service,
                    $submittedOptions
                ),
                5
            );
        }

        $lockedProduct = app(
            CapacityConfigurationLockService::class
        )->lockProduct((int) $service->product_id);
        $service->setRelation('product', $lockedProduct);
        $this->inventory->assertExclusiveProvisioningControl();
        $service->loadMissing([
            'product.upgradableConfigOptions',
            'product.server.settings',
            'configs.configOption',
        ]);
        $this->assertServiceAcceptsUpgrade($service);

        if (! $service->product->usesDynamicResources()) {
            throw new InvalidStockConfigurationException(
                'This service does not use dynamic resources.'
            );
        }

        $this->assertProductPanel($service);
        $server = $this->inventory->serverByExternalId($service->id);
        $checkoutIdentity = $this->checkoutServerIdentity($service);
        $this->assertExternalServerMatchesCheckout(
            $server,
            $checkoutIdentity,
            (int) $service->id
        );
        $node = collect($this->inventory->nodes())->firstWhere('id', $server['node']);
        if (! is_array($node)) {
            throw new InvalidStockConfigurationException(
                'The service node is missing from Pterodactyl inventory.'
            );
        }

        $allOptions = $service->product->upgradableConfigOptions->keyBy('id');
        $options = $allOptions
            ->filter(fn ($option): bool => $option->isDynamicSlider());
        $submitted = collect($submittedOptions)
            ->mapWithKeys(fn ($value, $key): array => [(int) $key => $value]);
        $unknown = $submitted->keys()->diff($allOptions->keys());
        if ($unknown->isNotEmpty()) {
            throw new InvalidResourceSelectionException(
                'The resource upgrade contains an unknown option.'
            );
        }

        $selection = [
            'memory' => $server['memory'],
            'cpu' => $server['cpu'],
            'disk' => $server['disk'],
        ];
        $sliders = [];
        foreach ($options as $option) {
            $resource = strtolower((string) $option->getMetadata('resource_type', ''));
            if (! in_array($resource, ['memory', 'cpu', 'disk'], true)) {
                continue;
            }
            if (array_key_exists($resource, $sliders)) {
                throw new InvalidStockConfigurationException(
                    "Multiple active {$resource} sliders are attached to this product."
                );
            }

            $minimum = $this->metadataInteger($option, 'min');
            $maximum = $this->metadataInteger($option, 'max');
            $step = $this->metadataInteger($option, 'step');
            $value = $submitted->has($option->id)
                ? $option->normalizeDynamicSliderValue($submitted->get($option->id))
                : $selection[$resource];
            $selection[$resource] = $value;
            $sliders[$resource] = [
                'config_option_id' => (int) $option->id,
                'min' => $minimum,
                'max' => $maximum,
                'step' => $step,
            ];
        }

        if ($sliders === []) {
            throw new InvalidStockConfigurationException(
                'No dynamic resource is enabled for upgrades.'
            );
        }

        $availability = $this->resources->getNodeAvailability($server['node']);
        if ($availability === null) {
            throw new StockUnavailableException(
                'The current server node is not available.'
            );
        }

        $bounds = [];
        $adjusted = false;
        foreach ($sliders as $resource => $slider) {
            $minimum = $resource === 'disk'
                ? $this->snapUp(
                    max($slider['min'], $server['disk']),
                    $slider['min'],
                    $slider['step']
                )
                : $slider['min'];
            $maximum = $this->snapDown(
                min(
                    $slider['max'],
                    $server[$resource] + (int) $availability['available'][$resource]
                ),
                $slider['min'],
                $slider['step']
            );

            if ($maximum < $minimum) {
                throw new StockUnavailableException(
                    "The current node cannot satisfy the {$resource} upgrade minimum."
                );
            }

            $candidate = min($maximum, max($minimum, $selection[$resource]));
            $candidate = $this->snapDown(
                $candidate,
                $slider['min'],
                $slider['step']
            );
            if ($candidate !== $selection[$resource]) {
                $selection[$resource] = $candidate;
                $adjusted = true;
            }

            $bounds[$resource] = [
                'config_option_id' => $slider['config_option_id'],
                'min' => $minimum,
                'max' => $maximum,
                'configured_max' => $slider['max'],
                'step' => $slider['step'],
            ];
        }

        $delta = $this->positiveDelta($server, $selection);
        if (! $this->resources->verifyNodeCapacity($server['node'], $delta, 0)) {
            throw new StockUnavailableException(
                'The current node cannot satisfy the complete resource upgrade.'
            );
        }

        return [
            'available' => true,
            'adjusted' => $adjusted,
            'selection' => $selection,
            'bounds' => $bounds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function upgradeContext(
        ServiceUpgrade $upgrade,
        bool $requireSourceMatch
    ): array {
        $upgrade->loadMissing([
            'service.product.server.settings',
            'service.product.configOptions',
            'service.user',
            'product.server.settings',
            'product.configOptions',
        ]);
        $this->assertServiceAcceptsUpgrade($upgrade->service);
        if (! $this->usesDynamicCapacity($upgrade)) {
            throw new InvalidStockConfigurationException(
                'This upgrade does not use dynamic capacity.'
            );
        }
        if ((int) $upgrade->product_id !== (int) $upgrade->service->product_id) {
            throw new InvalidResourceSelectionException(
                'Dynamic resource upgrades cannot change products.'
            );
        }
        if ((int) $upgrade->plan_id !== (int) $upgrade->service->plan_id) {
            throw new InvalidResourceSelectionException(
                'Dynamic resource upgrades cannot change billing plans.'
            );
        }
        $this->assertOnlyResourcePropertiesChanged($upgrade);

        $this->assertProductPanel($upgrade->service);
        $server = $this->inventory->serverByExternalId($upgrade->service_id);
        $checkoutIdentity = $this->checkoutServerIdentity(
            $upgrade->service
        );
        $node = collect($this->inventory->nodes())->firstWhere('id', $server['node']);
        if (! is_array($node)) {
            throw new InvalidStockConfigurationException(
                'The existing Pterodactyl node is unavailable.'
            );
        }

        $this->assertExternalServerMatchesCheckout(
            $server,
            $checkoutIdentity,
            (int) $upgrade->service_id
        );
        $sourceProperties = (array) data_get(
            $upgrade->source_snapshot,
            'properties',
            []
        );
        $source = $this->resourceVector($sourceProperties, 'source');
        $targetResources = $upgrade->targetResources();
        $targetLocation = $targetResources['location'];
        $target = $targetResources;
        unset($target['location']);
        if ($source === $target) {
            throw new InvalidResourceSelectionException(
                'A dynamic upgrade must change at least one of RAM, CPU, or disk.'
            );
        }

        if ($requireSourceMatch) {
            foreach (['memory', 'cpu', 'disk'] as $resource) {
                if ((int) $server[$resource] !== $source[$resource]) {
                    throw new InvalidResourceSelectionException(
                        "Pterodactyl {$resource} no longer matches the service being upgraded."
                    );
                }
            }
        }

        if (
            $targetLocation <= 0
            || $targetLocation !== (int) $node['location_id']
        ) {
            throw new InvalidResourceSelectionException(
                'Dynamic upgrades must remain in the existing Pterodactyl location.'
            );
        }
        if ($target['disk'] < $server['disk']) {
            throw new InvalidResourceSelectionException(
                'Pterodactyl disk cannot be reduced by this upgrade workflow.'
            );
        }

        return [
            'panel_identity' => $checkoutIdentity['panel_identity'],
            'node_id' => (int) $server['node'],
            'location_id' => (int) $node['location_id'],
            'external_server_id' => $checkoutIdentity['id'],
            'external_server_uuid' => $checkoutIdentity['uuid'],
            'external_server_identifier' => $checkoutIdentity['identifier'],
            'external_server_external_id' => (string) $upgrade->service_id,
            'external_user_id' => $checkoutIdentity['external_user_id'],
            'user_external_id' => $checkoutIdentity['user_external_id'],
            'user_email' => $checkoutIdentity['user_email'],
            'nest_id' => $checkoutIdentity['nest_id'],
            'egg_id' => $checkoutIdentity['egg_id'],
            'preserved_build' => $this->preservedBuild($server),
            'allocation_id' => (int) $server['allocation'],
            'assigned_allocation_ids' => $this->assignedAllocationIds($server),
            'source' => $source,
            'target' => $target,
            'delta' => $this->positiveDelta($server, $target),
        ];
    }

    /**
     * The original checkout commitment is the durable proof that this exact
     * external server—not merely any server reusing the same external ID—is
     * eligible for an in-place resource upgrade.
     *
     * @return array{
     *     id: int,
     *     uuid: string,
     *     identifier: string,
     *     panel_identity: string,
     *     external_user_id: int,
     *     nest_id: int,
     *     egg_id: int,
     *     user_external_id: string,
     *     user_email: string
     * }
     */
    private function checkoutServerIdentity(Service $service): array
    {
        $reservation = DB::table('ptero_resource_reservations')
            ->where('purpose', 'checkout')
            ->where('service_id', $service->id)
            ->where('status', 'confirmed')
            ->orderByDesc('id')
            ->first();
        $id = StrictInteger::parse($reservation?->external_server_id);
        $externalUserId = StrictInteger::parse(
            $reservation?->external_user_id
        );
        $uuid = $reservation?->external_server_uuid;
        $identifier = $reservation?->external_server_identifier;
        try {
            $payload = json_decode(
                (string) ($reservation?->configuration_payload ?? ''),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new InvalidStockConfigurationException(
                'The confirmed checkout commitment is unreadable.',
                previous: $exception
            );
        }
        $configuration = new ReservationConfigurationService;
        $identity = is_array($payload)
            ? (array) ($payload['provisioning_identity'] ?? [])
            : [];
        $panelIdentity = is_array($payload)
            ? (string) ($payload['panel_identity'] ?? '')
            : '';
        $nestId = StrictInteger::parse($identity['nest_id'] ?? null);
        $eggId = StrictInteger::parse($identity['egg_id'] ?? null);
        $userExternalId = trim((string) (
            $identity['user_external_id'] ?? ''
        ));
        $userEmail = strtolower(trim((string) (
            $identity['user_email'] ?? ''
        )));
        $expectedUserExternalId = "paymenter-user-{$service->user_id}";
        if (
            $reservation === null
            || $id === null
            || $id <= 0
            || $externalUserId === null
            || $externalUserId <= 0
            || ! is_string($uuid)
            || ! Str::isUuid($uuid)
            || ! is_string($identifier)
            || trim($identifier) === ''
            || ! is_array($payload)
            || ! hash_equals(
                (string) $reservation->configuration_fingerprint,
                $configuration->fingerprint($payload)
            )
            || (int) $reservation->user_id !== (int) $service->user_id
            || (int) $reservation->product_id !== (int) $service->product_id
            || (int) $reservation->plan_id !== (int) $service->plan_id
            || (int) ($payload['customer_id'] ?? 0)
                !== (int) $reservation->user_id
            || (int) ($payload['product_id'] ?? 0)
                !== (int) $reservation->product_id
            || (int) ($payload['plan_id'] ?? 0)
                !== (int) $reservation->plan_id
            || ! is_string($reservation->panel_identity)
            || preg_match('/^[a-f0-9]{64}$/D', $panelIdentity) !== 1
            || ! hash_equals(
                (string) $reservation->panel_identity,
                $panelIdentity
            )
            || ! hash_equals(
                $this->inventory->panelIdentity(),
                $panelIdentity
            )
            || $nestId === null
            || $nestId <= 0
            || $eggId === null
            || $eggId <= 0
            || $userEmail === ''
            || ! hash_equals($expectedUserExternalId, $userExternalId)
        ) {
            throw new InvalidStockConfigurationException(
                'Dynamic upgrades require the exact external server identity from a confirmed checkout commitment.'
            );
        }

        return [
            'id' => $id,
            'uuid' => $uuid,
            'identifier' => trim($identifier),
            'panel_identity' => $panelIdentity,
            'external_user_id' => $externalUserId,
            'nest_id' => $nestId,
            'egg_id' => $eggId,
            'user_external_id' => $userExternalId,
            'user_email' => $userEmail,
        ];
    }

    /**
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>  $checkoutIdentity
     */
    private function assertExternalServerMatchesCheckout(
        array $server,
        array $checkoutIdentity,
        int $serviceId
    ): void {
        if (
            (int) ($server['id'] ?? 0) !== $checkoutIdentity['id']
            || ! hash_equals(
                $checkoutIdentity['uuid'],
                (string) ($server['uuid'] ?? '')
            )
            || ! hash_equals(
                $checkoutIdentity['identifier'],
                (string) ($server['identifier'] ?? '')
            )
            || ! hash_equals(
                (string) $serviceId,
                (string) ($server['external_id'] ?? '')
            )
            || StrictInteger::parse($server['user_id'] ?? null) === null
            || (int) $server['user_id']
                !== $checkoutIdentity['external_user_id']
            || ! hash_equals(
                $checkoutIdentity['user_external_id'],
                (string) ($server['user_external_id'] ?? '')
            )
            || (int) ($server['nest_id'] ?? 0)
                !== $checkoutIdentity['nest_id']
            || (int) ($server['egg_id'] ?? 0)
                !== $checkoutIdentity['egg_id']
        ) {
            throw new InvalidResourceSelectionException(
                'The Pterodactyl server identity no longer matches the immutable checkout commitment.'
            );
        }
    }

    private function assertProductPanel(Service $service): void
    {
        $server = $service->product->server;
        if ($server === null || $server->extension !== 'Pterodactyl') {
            throw new InvalidStockConfigurationException(
                'Dynamic resources require the Pterodactyl server extension.'
            );
        }

        $settings = ExtensionHelper::settingsToArray($server->settings);
        $host = trim((string) ($settings['host'] ?? ''));
        try {
            $panelIdentity = PanelEndpointIdentity::hash($host);
        } catch (\InvalidArgumentException) {
            $panelIdentity = '';
        }
        if (
            $panelIdentity === ''
            || ! hash_equals(
                $this->inventory->panelIdentity(),
                $panelIdentity
            )
        ) {
            throw new InvalidStockConfigurationException(
                'The stock service and provisioner target different Pterodactyl panels.'
            );
        }
    }

    private function assertOnlyResourcePropertiesChanged(
        ServiceUpgrade $upgrade
    ): void {
        $source = collect((array) data_get(
            $upgrade->source_snapshot,
            'properties',
            []
        ))->mapWithKeys(fn ($value, $key): array => [
            strtolower((string) $key) => $value,
        ])->all();
        $target = collect((array) data_get(
            $upgrade->target_snapshot,
            'properties',
            []
        ))->mapWithKeys(fn ($value, $key): array => [
            strtolower((string) $key) => $value,
        ])->all();
        $keys = array_unique(array_merge(
            array_keys($source),
            array_keys($target)
        ));

        foreach ($keys as $key) {
            if (in_array($key, ['memory', 'cpu', 'disk'], true)) {
                continue;
            }
            if (
                ! array_key_exists($key, $source)
                || ! array_key_exists($key, $target)
                || $source[$key] !== $target[$key]
            ) {
                throw new InvalidResourceSelectionException(
                    'Dynamic upgrades may change only RAM, CPU, and disk.'
                );
            }
        }
    }

    private function lockCapacityScope(string $panelIdentity, int $locationId): void
    {
        DB::table('ptero_capacity_scopes')->insertOrIgnore([
            'panel_identity' => $panelIdentity,
            'location_id' => $locationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ptero_capacity_scopes')
            ->where('panel_identity', $panelIdentity)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array{memory: int, cpu: int, disk: int}
     */
    private function resourceVector(array $properties, string $label): array
    {
        $properties = collect($properties)
            ->mapWithKeys(fn ($value, $key): array => [
                strtolower((string) $key) => $value,
            ]);
        $vector = [];
        foreach (['memory', 'cpu', 'disk'] as $resource) {
            $vector[$resource] = $this->wholeNumber(
                $properties->get($resource),
                "{$label} {$resource}"
            );
        }

        return $vector;
    }

    /**
     * @param  array<string, int>  $source
     * @param  array<string, int>  $target
     * @return array{memory: int, cpu: int, disk: int}
     */
    private function positiveDelta(array $source, array $target): array
    {
        return [
            'memory' => max(0, (int) $target['memory'] - (int) $source['memory']),
            'cpu' => max(0, (int) $target['cpu'] - (int) $source['cpu']),
            'disk' => max(0, (int) $target['disk'] - (int) $source['disk']),
        ];
    }

    private function wholeNumber(mixed $value, string $label): int
    {
        $numeric = StrictInteger::parse($value)
            ?? StrictInteger::parseStoredDecimal($value);
        if ($numeric === null || $numeric <= 0) {
            throw new InvalidStockConfigurationException(
                "The {$label} value must be a positive whole number."
            );
        }

        return $numeric;
    }

    private function metadataInteger(object $option, string $key): int
    {
        $value = $this->wholeNumber(
            $option->getMetadata($key),
            "{$option->name} {$key}"
        );

        return $value;
    }

    private function reservationFingerprint(
        ServiceUpgrade $upgrade,
        array $context
    ): string {
        return $this->integrity->fingerprint($upgrade, $context);
    }

    private function reservationIntegrityError(
        ServiceUpgrade $upgrade,
        UpgradeReservation $reservation,
        ?Invoice $paidCommitInvoice = null
    ): ?string {
        try {
            $upgrade->load([
                'service.product.server.settings',
                'service.product.settings',
                'service.configs.configOption',
                'service.configs.configValue',
                'product.server.settings',
                'product.settings',
                'configs.configOption',
                'configs.configValue',
            ]);
            $this->assertProductPanel($upgrade->service);
            if ($paidCommitInvoice !== null) {
                $this->integrity->verifiedSnapshotForPaidCommit(
                    $upgrade,
                    $reservation,
                    $paidCommitInvoice
                );
            } else {
                $this->integrity->verifiedSnapshot($upgrade, $reservation);
            }
        } catch (\Throwable) {
            return 'The paid upgrade reservation failed its immutable integrity check.';
        }

        return null;
    }

    private function usesDynamicCapacity(ServiceUpgrade $upgrade): bool
    {
        return app(CapacityUpgradeReservationIdentity::class)
            ->requiresCoordinator($upgrade);
    }

    /**
     * Freeze every remote build field that a RAM/CPU/disk-only upgrade must
     * preserve across queue retries.
     *
     * @param  array<string, mixed>  $server
     * @return array{
     *     swap: int,
     *     io: int,
     *     threads: string|null,
     *     databases: int,
     *     allocations: int,
     *     backups: int
     * }
     */
    private function preservedBuild(array $server): array
    {
        $threads = $server['threads'] ?? null;
        $values = [
            'swap' => StrictInteger::parse($server['swap'] ?? null),
            'io' => StrictInteger::parse($server['io'] ?? null),
            'databases' => StrictInteger::parse(
                $server['database_limit'] ?? null
            ),
            'allocations' => StrictInteger::parse(
                $server['allocation_limit'] ?? null
            ),
            'backups' => StrictInteger::parse(
                $server['backup_limit'] ?? null
            ),
        ];
        if (
            ($threads !== null && ! is_string($threads))
            || $values['swap'] === null
            || $values['swap'] < 0
            || $values['io'] === null
            || $values['io'] < 0
            || $values['databases'] === null
            || $values['databases'] < 0
            || $values['allocations'] !== 0
            || $values['backups'] === null
            || $values['backups'] < 0
        ) {
            throw new InvalidStockConfigurationException(
                'Resource-only upgrades require complete remote build limits and a zero client allocation limit.'
            );
        }

        return [
            'swap' => $values['swap'],
            'io' => $values['io'],
            'threads' => $threads,
            'databases' => $values['databases'],
            'allocations' => $values['allocations'],
            'backups' => $values['backups'],
        ];
    }

    /**
     * @param  array<string, mixed>  $server
     * @return list<int>
     */
    private function assignedAllocationIds(array $server): array
    {
        $ids = $server['assigned_allocation_ids'] ?? null;
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new InvalidStockConfigurationException(
                'Pterodactyl did not return the server allocation set.'
            );
        }

        $normalized = [];
        foreach ($ids as $id) {
            $parsed = StrictInteger::parse($id);
            if ($parsed === null || $parsed <= 0) {
                throw new InvalidStockConfigurationException(
                    'Pterodactyl returned an invalid server allocation set.'
                );
            }
            $normalized[] = $parsed;
        }
        sort($normalized, SORT_NUMERIC);
        if (
            $normalized === []
            || count(array_unique($normalized)) !== count($normalized)
            || ! in_array((int) ($server['allocation'] ?? 0), $normalized, true)
        ) {
            throw new InvalidStockConfigurationException(
                'Pterodactyl returned an inconsistent server allocation set.'
            );
        }

        return $normalized;
    }

    private function assertServiceAcceptsUpgrade(Service $service): void
    {
        if (! $this->serviceAcceptsUpgrade($service)) {
            throw new InvalidResourceSelectionException(
                'The service is not active or already has a cancellation request.'
            );
        }
    }

    private function serviceAcceptsUpgrade(Service $service): bool
    {
        return $service->status === Service::STATUS_ACTIVE
            && ! $service->cancellation()->exists();
    }

    private function invoiceMismatch(
        ServiceUpgrade $upgrade,
        Invoice $invoice
    ): ?string {
        $items = $invoice->items()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if (
            (int) $invoice->user_id !== (int) $upgrade->service->user_id
            || strtoupper((string) $invoice->currency_code)
                !== strtoupper((string) $upgrade->currency_code)
            || $items->count() !== 1
        ) {
            return 'The upgrade invoice identity no longer matches its immutable quote.';
        }

        $item = $items->first();
        $actualCents = $this->moneyCents($item->price);
        $expectedCents = $this->moneyCents($upgrade->quoted_amount);
        if (
            $item->reference_type !== ServiceUpgrade::class
            || (int) $item->reference_id !== (int) $upgrade->id
            || (int) $item->quantity !== 1
            || $expectedCents === null
            || $expectedCents <= 0
            || $actualCents !== $expectedCents
        ) {
            return 'The upgrade invoice line no longer matches its immutable quote.';
        }

        return null;
    }

    private function moneyCents(mixed $value): ?int
    {
        $text = is_int($value)
            ? (string) $value
            : (is_string($value) ? $value : '');
        if (
            preg_match('/^(-?)(0|[1-9]\d*)(?:\.(\d+))?$/D', $text, $matches)
                !== 1
        ) {
            return null;
        }

        $whole = StrictInteger::parse(
            ($matches[1] ?? '').$matches[2]
        );
        $fraction = $matches[3] ?? '';
        if (
            $whole === null
            || (strlen($fraction) > 2
                && trim(substr($fraction, 2), '0') !== '')
            || abs($whole) > intdiv(PHP_INT_MAX - 99, 100)
        ) {
            return null;
        }

        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $cents = abs($whole) * 100 + (int) $fraction;

        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }

    private function snapDown(int $value, int $minimum, int $step): int
    {
        return $value < $minimum
            ? $minimum - 1
            : $minimum + intdiv($value - $minimum, $step) * $step;
    }

    private function snapUp(int $value, int $minimum, int $step): int
    {
        if ($value <= $minimum) {
            return $minimum;
        }

        return $minimum + (int) ceil(($value - $minimum) / $step) * $step;
    }
}
