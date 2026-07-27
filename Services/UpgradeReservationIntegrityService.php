<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\Invoice;
use App\Models\ServiceUpgrade;
use App\Support\StrictDecimal;
use App\Support\StrictInteger;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;

/**
 * Shared immutable proof for capacity-aware upgrade commitments.
 *
 * Upgrade fingerprints deliberately include the separately persisted billing
 * record, so they cannot use the checkout snapshot fingerprint algorithm.
 */
class UpgradeReservationIntegrityService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function fingerprint(object $upgrade, array $context): string
    {
        return hash('sha256', json_encode(
            $this->canonicalizeSnapshot([
                'upgrade_id' => (int) $upgrade->id,
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
                'quoted_amount' => $this->normalizedMoney(
                    $upgrade->quoted_amount
                ),
                'currency_code' => strtoupper((string) $upgrade->currency_code),
            ]),
            JSON_THROW_ON_ERROR
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_SLASHES
        ));
    }

    public function pricingVersion(object $upgrade): string
    {
        return hash('sha256', json_encode([
            'quoted_amount' => $this->normalizedMoney(
                $upgrade->quoted_amount
            ),
            'currency_code' => strtoupper((string) $upgrade->currency_code),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    public function verifiedSnapshot(
        object $upgrade,
        object $reservation
    ): array {
        return $this->verifySnapshot($upgrade, $reservation, null);
    }

    /**
     * Verify the one transient lifecycle pair that exists while the locked
     * paid-invoice transaction atomically commits its capacity reservation.
     *
     * @return array<string, mixed>
     */
    public function verifiedSnapshotForPaidCommit(
        object $upgrade,
        object $reservation,
        Invoice $paidInvoice
    ): array {
        $invoiceId = StrictInteger::parse($paidInvoice->id ?? null);
        if (
            $invoiceId === null
            || $invoiceId <= 0
            || $paidInvoice->status !== Invoice::STATUS_PAID
            || StrictInteger::parse($upgrade->invoice_id ?? null)
                !== $invoiceId
            || StrictInteger::parse($reservation->invoice_id ?? null)
                !== $invoiceId
        ) {
            throw new InvalidStockConfigurationException(
                'The paid upgrade invoice failed its atomic transition proof.'
            );
        }

        return $this->verifySnapshot(
            $upgrade,
            $reservation,
            $invoiceId
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function verifySnapshot(
        object $upgrade,
        object $reservation,
        ?int $atomicPaidInvoiceId
    ): array {
        $payload = $reservation->configuration_payload;
        if (is_string($payload)) {
            try {
                $payload = json_decode(
                    $payload,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException $exception) {
                throw new InvalidStockConfigurationException(
                    'The upgrade capacity snapshot is unreadable.',
                    previous: $exception
                );
            }
        }
        if (! is_array($payload)) {
            throw new InvalidStockConfigurationException(
                'The upgrade capacity snapshot is unreadable.'
            );
        }

        try {
            $fingerprint = $this->fingerprint($upgrade, $payload);
            $pricingVersion = $this->pricingVersion($upgrade);
            $quotedAmount = $this->normalizedMoney(
                $upgrade->quoted_amount ?? null
            );
            $reservedAmount = $this->normalizedMoney(
                $reservation->calculated_price ?? null
            );
        } catch (\Throwable $exception) {
            throw new InvalidStockConfigurationException(
                'The upgrade capacity snapshot failed its immutable integrity check.',
                previous: $exception
            );
        }

        if ($upgrade instanceof ServiceUpgrade) {
            $this->assertLiveTargetMatches($upgrade);
        }

        $source = $this->resourceVector($payload['source'] ?? null);
        $target = $this->resourceVector($payload['target'] ?? null);
        $delta = $this->resourceVector($payload['delta'] ?? null);
        $rowTarget = $this->resourceVector([
            'memory' => $reservation->memory ?? null,
            'cpu' => $reservation->cpu ?? null,
            'disk' => $reservation->disk ?? null,
        ]);
        $rowDelta = $this->resourceVector([
            'memory' => $reservation->reserved_memory ?? null,
            'cpu' => $reservation->reserved_cpu ?? null,
            'disk' => $reservation->reserved_disk ?? null,
        ]);
        $upgradeId = $this->positiveInteger($upgrade->id ?? null);
        $upgradeServiceId = $this->positiveInteger(
            $upgrade->service_id ?? null
        );
        $reservationServiceId = $this->positiveInteger(
            $reservation->service_id ?? null
        );
        $reservationUpgradeId = $this->positiveInteger(
            $reservation->service_upgrade_id ?? null
        );
        $payloadUpgradeId = $this->positiveInteger(
            $payload['service_upgrade_id'] ?? null
        );
        $nodeId = $this->positiveInteger($reservation->node_id ?? null);
        $payloadNodeId = $this->positiveInteger(
            $payload['node_id'] ?? null
        );
        $locationId = $this->positiveInteger(
            $reservation->location_id ?? null
        );
        $payloadLocationId = $this->positiveInteger(
            $payload['location_id'] ?? null
        );
        $externalServerId = $this->positiveInteger(
            $reservation->external_server_id ?? null
        );
        $payloadExternalServerId = $this->positiveInteger(
            $payload['external_server_id'] ?? null
        );
        $externalUserId = $this->positiveInteger(
            $reservation->external_user_id ?? null
        );
        $payloadExternalUserId = $this->positiveInteger(
            $payload['external_user_id'] ?? null
        );
        $nestId = $this->positiveInteger($payload['nest_id'] ?? null);
        $eggId = $this->positiveInteger($payload['egg_id'] ?? null);
        $allocationId = $this->positiveInteger(
            $payload['allocation_id'] ?? null
        );
        $assignedAllocationIds = $this->positiveIntegerList(
            $payload['assigned_allocation_ids'] ?? null
        );
        $preservedBuild = $this->preservedBuild(
            $payload['preserved_build'] ?? null
        );
        $panelIdentity = $payload['panel_identity'] ?? null;
        $externalServerUuid = $payload['external_server_uuid'] ?? null;
        $externalServerIdentifier =
            $payload['external_server_identifier'] ?? null;
        $externalServerExternalId =
            $payload['external_server_external_id'] ?? null;
        $userExternalId = $payload['user_external_id'] ?? null;
        $userEmail = $payload['user_email'] ?? null;
        $sourceSnapshot = $this->decodedArray(
            $upgrade->source_snapshot ?? null
        );
        $targetSnapshot = $this->decodedArray(
            $upgrade->target_snapshot ?? null
        );
        $sourceProperties = $this->snapshotProperties($sourceSnapshot);
        $targetProperties = $this->snapshotProperties($targetSnapshot);
        $sourceSnapshotHash = $this->snapshotFingerprint(
            $sourceSnapshot
        );
        $targetSnapshotHash = $this->snapshotFingerprint(
            $targetSnapshot
        );
        $snapshotSource = $this->resourceVectorFromProperties(
            $sourceProperties
        );
        $snapshotTarget = $this->resourceVectorFromProperties(
            $targetProperties
        );
        $snapshotLocation = $this->locationFromProperties(
            $targetProperties
        );
        $targetRecurring = StrictDecimal::parseNonNegative(
            $targetSnapshot['recurring_price'] ?? null
        );
        $sourceFingerprint = $upgrade->source_fingerprint ?? null;
        $targetFingerprint = $upgrade->target_fingerprint ?? null;
        $service = $upgrade->service ?? null;
        $product = $upgrade->product ?? null;
        $serviceUserId = $this->positiveInteger(
            $upgrade->service_user_id
                ?? ($service?->user_id ?? null)
        );
        $serviceProductId = $this->positiveInteger(
            $upgrade->service_product_id
                ?? ($service?->product_id ?? null)
        );
        $servicePlanId = $this->positiveInteger(
            $upgrade->service_plan_id
                ?? ($service?->plan_id ?? null)
        );
        $serviceQuantity = StrictInteger::parse(
            $upgrade->service_quantity
                ?? ($service?->quantity ?? null)
        );
        $serviceCurrency = strtoupper((string) (
            $upgrade->service_currency_code
                ?? ($service?->currency_code ?? '')
        ));
        $upgradeProductId = $this->positiveInteger(
            $upgrade->product_id ?? null
        );
        $upgradePlanId = $this->positiveInteger(
            $upgrade->plan_id ?? null
        );
        $upgradeInvoiceId = $this->positiveInteger(
            $upgrade->invoice_id ?? null
        );
        $invoice = $upgrade->invoice ?? null;
        $invoiceStatus = (string) (
            $upgrade->invoice_status
                ?? ($invoice?->status ?? '')
        );
        $invoiceUserId = $this->positiveInteger(
            $upgrade->invoice_user_id
                ?? ($invoice?->user_id ?? null)
        );
        $invoiceCurrency = strtoupper((string) (
            $upgrade->invoice_currency_code
                ?? ($invoice?->currency_code ?? '')
        ));
        $productServerId = $this->positiveInteger(
            $upgrade->product_server_id
                ?? ($product?->server_id ?? null)
        );
        $reservationUserId = $this->positiveInteger(
            $reservation->user_id ?? null
        );
        $reservationProductId = $this->positiveInteger(
            $reservation->product_id ?? null
        );
        $reservationPlanId = $this->positiveInteger(
            $reservation->plan_id ?? null
        );
        $reservationInvoiceId = $this->positiveInteger(
            $reservation->invoice_id ?? null
        );
        $reservationServerId = $this->positiveInteger(
            $reservation->server_extension_id ?? null
        );
        $reservationQuantity = StrictInteger::parse(
            $reservation->quantity ?? null
        );
        $upgradeCurrency = strtoupper(
            (string) ($upgrade->currency_code ?? '')
        );
        $reservationCurrency = strtoupper(
            (string) ($reservation->currency_code ?? '')
        );
        $reservationGuard = $this->positiveInteger(
            $reservation->upgrade_guard_id ?? null
        );
        $upgradeGuard = $this->positiveInteger(
            $upgrade->active_service_guard_id ?? null
        );
        $reservationStatus = (string) (
            $reservation->status ?? ''
        );
        $upgradeStatus = (string) ($upgrade->status ?? '');
        if (
            $source === null
            || $target === null
            || $delta === null
            || $rowTarget === null
            || $rowDelta === null
            || $sourceSnapshot === null
            || $targetSnapshot === null
            || $sourceProperties === null
            || $targetProperties === null
            || $snapshotSource === null
            || $snapshotTarget === null
            || $snapshotLocation === null
            || $targetRecurring === null
            || $sourceSnapshotHash === null
            || $targetSnapshotHash === null
            || ! is_string($sourceFingerprint)
            || preg_match(
                '/^[a-f0-9]{64}$/D',
                $sourceFingerprint
            ) !== 1
            || ! is_string($targetFingerprint)
            || preg_match(
                '/^[a-f0-9]{64}$/D',
                $targetFingerprint
            ) !== 1
            || ! hash_equals(
                $sourceFingerprint,
                $sourceSnapshotHash
            )
            || ! hash_equals(
                $targetFingerprint,
                $targetSnapshotHash
            )
            || $source !== $snapshotSource
            || $target !== $snapshotTarget
            || $source === $target
            || collect($target)->contains(
                fn (int $value): bool => $value <= 0
            )
            || $target['disk'] < $source['disk']
            || ! $this->onlyResourcesChanged(
                $sourceProperties,
                $targetProperties
            )
            || $delta !== [
                'memory' => max(0, $target['memory'] - $source['memory']),
                'cpu' => max(0, $target['cpu'] - $source['cpu']),
                'disk' => max(0, $target['disk'] - $source['disk']),
            ]
            || ($reservation->purpose ?? null) !== 'upgrade'
            || $upgradeId === null
            || $upgradeServiceId === null
            || $reservationServiceId !== $upgradeServiceId
            || $reservationUpgradeId !== $upgradeId
            || $payloadUpgradeId !== $upgradeId
            || $serviceUserId === null
            || $reservationUserId !== $serviceUserId
            || $serviceProductId === null
            || $upgradeProductId !== $serviceProductId
            || $reservationProductId !== $serviceProductId
            || $servicePlanId === null
            || $upgradePlanId !== $servicePlanId
            || $reservationPlanId !== $servicePlanId
            || $serviceQuantity !== 1
            || $reservationQuantity !== 1
            || $serviceCurrency === ''
            || $upgradeCurrency !== $serviceCurrency
            || $reservationCurrency !== $serviceCurrency
            || ! $this->snapshotIdentityMatches(
                $sourceSnapshot,
                $targetSnapshot,
                $upgradeServiceId,
                $serviceProductId,
                $servicePlanId,
                $serviceCurrency
            )
            || (
                ($upgrade->invoice_id ?? null) !== null
                && $upgradeInvoiceId === null
            )
            || (
                ($reservation->invoice_id ?? null) !== null
                && $reservationInvoiceId === null
            )
            || $reservationInvoiceId !== $upgradeInvoiceId
            || (
                $this->moneyIsPositive($quotedAmount)
                    ? $upgradeInvoiceId === null
                    : $upgradeInvoiceId !== null
            )
            || ! $this->invoiceLifecycleMatches(
                $reservationStatus,
                $this->moneyIsPositive($quotedAmount),
                $upgradeInvoiceId,
                $invoiceStatus,
                $invoiceUserId,
                $invoiceCurrency,
                $serviceUserId,
                $serviceCurrency,
                $atomicPaidInvoiceId
            )
            || $productServerId === null
            || $reservationServerId !== $productServerId
            || (
                ($reservation->upgrade_guard_id ?? null) !== null
                && $reservationGuard === null
            )
            || (
                ($upgrade->active_service_guard_id ?? null) !== null
                && $upgradeGuard === null
            )
            || $quotedAmount !== $reservedAmount
            || (string) ($reservation->pricing_version ?? '')
                !== $pricingVersion
            || (string) ($reservation->formula_version ?? '')
                !== 'dynamic-upgrade-v1'
            || ! $this->lifecycleMatches(
                $reservationStatus,
                $upgradeStatus,
                $reservationGuard,
                $upgradeGuard,
                $upgradeId,
                $upgradeServiceId,
                $reservation->consumed_at ?? null
            )
            || ! is_string($reservation->configuration_fingerprint)
            || preg_match(
                '/^[a-f0-9]{64}$/D',
                $reservation->configuration_fingerprint
            ) !== 1
            || ! hash_equals(
                $reservation->configuration_fingerprint,
                $fingerprint
            )
            || (string) ($payload['source_fingerprint'] ?? '')
                !== (string) $upgrade->source_fingerprint
            || (string) ($payload['target_fingerprint'] ?? '')
                !== (string) $upgrade->target_fingerprint
            || ! is_string($panelIdentity)
            || preg_match('/^[a-f0-9]{64}$/D', $panelIdentity) !== 1
            || ! is_string($reservation->panel_identity ?? null)
            || ! hash_equals(
                (string) $reservation->panel_identity,
                $panelIdentity
            )
            || $nodeId === null
            || $payloadNodeId !== $nodeId
            || $locationId === null
            || $payloadLocationId !== $locationId
            || $payloadLocationId !== $snapshotLocation
            || $externalServerId === null
            || $payloadExternalServerId !== $externalServerId
            || $externalUserId === null
            || $payloadExternalUserId !== $externalUserId
            || ! is_string($externalServerUuid)
            || ! Str::isUuid($externalServerUuid)
            || ! is_string($reservation->external_server_uuid ?? null)
            || ! hash_equals(
                (string) $reservation->external_server_uuid,
                $externalServerUuid
            )
            || ! is_string($externalServerIdentifier)
            || trim($externalServerIdentifier) === ''
            || ! is_string(
                $reservation->external_server_identifier ?? null
            )
            || ! hash_equals(
                (string) $reservation->external_server_identifier,
                $externalServerIdentifier
            )
            || ! is_string($externalServerExternalId)
            || ! hash_equals(
                (string) $upgradeServiceId,
                $externalServerExternalId
            )
            || ! is_string($userExternalId)
            || ! hash_equals(
                "paymenter-user-{$serviceUserId}",
                $userExternalId
            )
            || ! is_string($userEmail)
            || trim($userEmail) === ''
            || $nestId === null
            || $eggId === null
            || $allocationId === null
            || $assignedAllocationIds === null
            || ! in_array(
                $allocationId,
                $assignedAllocationIds,
                true
            )
            || $preservedBuild === null
            || $target !== $rowTarget
            || $delta !== $rowDelta
        ) {
            throw new InvalidStockConfigurationException(
                'The upgrade capacity snapshot failed its immutable integrity check.'
            );
        }

        return $payload;
    }

    /**
     * The signed target snapshot is provisioning authority, while core applies
     * ServiceUpgrade::configs after the remote resize. Prove those mutable rows
     * still materialize the exact signed target so the two systems cannot
     * diverge after quote creation.
     */
    public function assertLiveTargetMatches(ServiceUpgrade $upgrade): void
    {
        try {
            $upgrade->load([
                'service.product.settings',
                'service.configs.configOption',
                'service.configs.configValue',
                'product.settings',
                'configs.configOption',
                'configs.configValue',
            ]);
            $targetSnapshot = $this->decodedArray(
                $upgrade->target_snapshot
            );
            $signedProperties = $this->snapshotProperties(
                $targetSnapshot
            );
            $liveProperties = $this->snapshotProperties([
                'properties' => $upgrade->targetProperties(),
            ]);
        } catch (\Throwable $exception) {
            throw new InvalidStockConfigurationException(
                'The live upgrade target cannot be reconstructed.',
                previous: $exception
            );
        }

        if (
            $targetSnapshot === null
            || $signedProperties === null
            || $liveProperties === null
            || $signedProperties !== $liveProperties
            || ! array_key_exists('billing_anchor', $targetSnapshot)
        ) {
            throw new InvalidStockConfigurationException(
                'The live upgrade target no longer matches its signed snapshot.'
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodedArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $decoded = json_decode(
                $value,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function snapshotFingerprint(?array $snapshot): ?string
    {
        if ($snapshot === null) {
            return null;
        }

        try {
            return hash('sha256', json_encode(
                $this->canonicalizeSnapshot($snapshot),
                JSON_THROW_ON_ERROR
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_UNESCAPED_SLASHES
            ));
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * ServiceUpgrade uses a distinct canonicalizer that preserves numeric
     * representation. Do not reuse the checkout canonicalizer here.
     *
     * @param  array<string|int, mixed>  $value
     * @return array<string|int, mixed>
     */
    private function canonicalizeSnapshot(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalizeSnapshot($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    private function snapshotProperties(?array $snapshot): ?array
    {
        $properties = $snapshot['properties'] ?? null;
        if (! is_array($properties) || array_is_list($properties)) {
            return null;
        }

        $normalized = [];
        foreach ($properties as $key => $value) {
            $key = strtolower((string) $key);
            if ($key === '' || array_key_exists($key, $normalized)) {
                return null;
            }
            $normalized[$key] = $value;
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>|null  $properties
     * @return array{memory: int, cpu: int, disk: int}|null
     */
    private function resourceVectorFromProperties(
        ?array $properties
    ): ?array {
        if ($properties === null) {
            return null;
        }

        $vector = [];
        foreach (['memory', 'cpu', 'disk'] as $resource) {
            $parsed = StrictInteger::parse(
                $properties[$resource] ?? null
            ) ?? StrictInteger::parseStoredDecimal(
                $properties[$resource] ?? null
            );
            if ($parsed === null || $parsed < 0) {
                return null;
            }
            $vector[$resource] = $parsed;
        }

        return $vector;
    }

    /**
     * @param  array<string, mixed>|null  $properties
     */
    private function locationFromProperties(
        ?array $properties
    ): ?int {
        if ($properties === null) {
            return null;
        }

        $location = StrictInteger::parse(
            $properties['location'] ?? null
        ) ?? StrictInteger::parseStoredDecimal(
            $properties['location'] ?? null
        );
        if ($location !== null) {
            return $location > 0 ? $location : null;
        }

        $locationIds = $properties['location_ids'] ?? null;
        if (is_string($locationIds)) {
            $decoded = json_decode($locationIds, true);
            $locationIds = json_last_error() === JSON_ERROR_NONE
                && is_array($decoded)
                    ? $decoded
                    : [$locationIds];
        } elseif (! is_array($locationIds)) {
            $locationIds = [$locationIds];
        }
        if (! array_is_list($locationIds) || count($locationIds) !== 1) {
            return null;
        }

        $location = StrictInteger::parse($locationIds[0])
            ?? StrictInteger::parseStoredDecimal($locationIds[0]);

        return $location !== null && $location > 0
            ? $location
            : null;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $target
     */
    private function onlyResourcesChanged(
        array $source,
        array $target
    ): bool {
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
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $target
     */
    private function snapshotIdentityMatches(
        array $source,
        array $target,
        int $serviceId,
        int $productId,
        int $planId,
        string $currencyCode
    ): bool {
        return StrictInteger::parse($source['service_id'] ?? null)
                === $serviceId
            && StrictInteger::parse($target['service_id'] ?? null)
                === $serviceId
            && StrictInteger::parse($source['product_id'] ?? null)
                === $productId
            && StrictInteger::parse($target['product_id'] ?? null)
                === $productId
            && StrictInteger::parse($source['plan_id'] ?? null)
                === $planId
            && StrictInteger::parse($target['plan_id'] ?? null)
                === $planId
            && StrictInteger::parse($source['quantity'] ?? null) === 1
            && StrictInteger::parse($target['quantity'] ?? null) === 1
            && strtoupper((string) ($source['currency_code'] ?? ''))
                === $currencyCode
            && strtoupper((string) ($target['currency_code'] ?? ''))
                === $currencyCode
            && array_key_exists('billing_anchor', $source)
            && array_key_exists('billing_anchor', $target)
            && $source['billing_anchor'] === $target['billing_anchor'];
    }

    private function lifecycleMatches(
        string $reservationStatus,
        string $upgradeStatus,
        ?int $reservationGuard,
        ?int $upgradeGuard,
        int $upgradeId,
        int $serviceId,
        mixed $consumedAt
    ): bool {
        if ($reservationStatus === 'pending') {
            return in_array(
                $upgradeStatus,
                ['pending', 'awaiting_payment'],
                true
            )
                && $reservationGuard === $upgradeId
                && $upgradeGuard === $serviceId
                && $consumedAt === null;
        }
        if ($reservationStatus === 'paid_committed') {
            return in_array(
                $upgradeStatus,
                [
                    'paid_committed',
                    'provisioning',
                    'retryable_failed',
                    'needs_attention',
                ],
                true
            )
                && $reservationGuard === $upgradeId
                && $upgradeGuard === $serviceId
                && $consumedAt === null;
        }
        if ($reservationStatus !== 'confirmed' || $consumedAt === null) {
            return false;
        }

        return $upgradeStatus === 'completed'
            && $reservationGuard === null
            && $upgradeGuard === null;
    }

    /**
     * @return array{memory: int, cpu: int, disk: int}|null
     */
    private function resourceVector(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $resources = [];
        foreach (['memory', 'cpu', 'disk'] as $resource) {
            $parsed = StrictInteger::parse($value[$resource] ?? null);
            if ($parsed === null || $parsed < 0) {
                return null;
            }
            $resources[$resource] = $parsed;
        }

        return $resources;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $parsed = StrictInteger::parse($value);

        return $parsed !== null && $parsed > 0 ? $parsed : null;
    }

    /**
     * @return list<int>|null
     */
    private function positiveIntegerList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $normalized = [];
        foreach ($value as $item) {
            $parsed = $this->positiveInteger($item);
            if ($parsed === null) {
                return null;
            }
            $normalized[] = $parsed;
        }
        sort($normalized, SORT_NUMERIC);

        return $normalized !== []
            && count(array_unique($normalized)) === count($normalized)
                ? $normalized
                : null;
    }

    /**
     * @return array{
     *     swap: int,
     *     io: int,
     *     threads: string|null,
     *     databases: int,
     *     allocations: int,
     *     backups: int
     * }|null
     */
    private function preservedBuild(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $threads = $value['threads'] ?? null;
        $swap = StrictInteger::parse($value['swap'] ?? null);
        $io = StrictInteger::parse($value['io'] ?? null);
        $databases = StrictInteger::parse(
            $value['databases'] ?? null
        );
        $allocations = StrictInteger::parse(
            $value['allocations'] ?? null
        );
        $backups = StrictInteger::parse($value['backups'] ?? null);
        if (
            ! array_key_exists('threads', $value)
            || ($threads !== null && ! is_string($threads))
            || $swap === null
            || $swap < 0
            || $io === null
            || $io < 0
            || $databases === null
            || $databases < 0
            || $allocations !== 0
            || $backups === null
            || $backups < 0
        ) {
            return null;
        }

        return [
            'swap' => $swap,
            'io' => $io,
            'threads' => $threads,
            'databases' => $databases,
            'allocations' => $allocations,
            'backups' => $backups,
        ];
    }

    /**
     * Database drivers do not agree on how to materialize DECIMAL(17, 2):
     * the same value can arrive as "100.00", 100, or 9.9. Fingerprints must
     * use one exact representation without accepting precision beyond cents.
     */
    private function normalizedMoney(mixed $value): string
    {
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $text = number_format($value, 2, '.', '');
        } elseif (is_string($value)) {
            $text = $value;
        } else {
            throw new InvalidStockConfigurationException(
                'The upgrade quote amount is invalid.'
            );
        }

        if (
            preg_match(
                '/^(-?)(0|[1-9]\d*)(?:\.(\d+))?$/D',
                $text,
                $matches
            ) !== 1
        ) {
            throw new InvalidStockConfigurationException(
                'The upgrade quote amount is invalid.'
            );
        }

        $fraction = $matches[3] ?? '';
        if (
            strlen($fraction) > 2
            && trim(substr($fraction, 2), '0') !== ''
        ) {
            throw new InvalidStockConfigurationException(
                'The upgrade quote amount exceeds cent precision.'
            );
        }

        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $sign = ($matches[1] ?? '') === '-'
            && ($matches[2] !== '0' || $fraction !== '00')
                ? '-'
                : '';

        return $sign.$matches[2].'.'.$fraction;
    }

    private function moneyIsPositive(string $amount): bool
    {
        return $amount[0] !== '-'
            && $amount !== '0.00';
    }

    private function invoiceLifecycleMatches(
        string $reservationStatus,
        bool $positiveQuote,
        ?int $invoiceId,
        string $invoiceStatus,
        ?int $invoiceUserId,
        string $invoiceCurrency,
        ?int $serviceUserId,
        string $serviceCurrency,
        ?int $atomicPaidInvoiceId
    ): bool {
        if (! $positiveQuote) {
            return $invoiceId === null;
        }
        if (
            $invoiceId === null
            || $invoiceUserId === null
            || $serviceUserId === null
            || $invoiceUserId !== $serviceUserId
            || $invoiceCurrency !== $serviceCurrency
        ) {
            return false;
        }

        if ($reservationStatus === 'pending') {
            return $invoiceStatus === 'pending'
                || (
                    $invoiceStatus === 'paid'
                    && $atomicPaidInvoiceId !== null
                    && $invoiceId === $atomicPaidInvoiceId
                );
        }

        return $invoiceStatus === 'paid';
    }
}
