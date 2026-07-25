<?php

use App\Models\Service;
use App\Support\StrictInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fail-gated audit for reservations created before immutable remote identity
 * was captured. This service is deliberately diagnostic only: reconstructing
 * identity from mutable product settings or an external-ID lookup would turn a
 * lookup key into authority over a possibly replaced server.
 */
return new class
{
    /**
     * @return list<array{
     *     reservation_id: int,
     *     service_id: int|null,
     *     purpose: string,
     *     missing: list<string>
     * }>
     */
    public function blockers(): array
    {
        if (! Schema::hasTable('ptero_resource_reservations')) {
            return [];
        }

        $requiredColumns = [
            'purpose',
            'configuration_fingerprint',
            'configuration_payload',
            'external_server_id',
            'external_user_id',
            'external_server_uuid',
            'external_server_identifier',
        ];
        foreach ($requiredColumns as $column) {
            if (! Schema::hasColumn('ptero_resource_reservations', $column)) {
                return [[
                    'reservation_id' => 0,
                    'service_id' => null,
                    'purpose' => 'schema',
                    'missing' => ["column:{$column}"],
                ]];
            }
        }

        return DB::table('ptero_resource_reservations')
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('purpose', 'checkout')
                        ->whereIn('status', [
                            'pending',
                            'paid_committed',
                            'confirmed',
                        ])
                        ->whereNotNull('service_id');
                })->orWhere(function ($query): void {
                    $query->where('purpose', 'upgrade')
                        ->whereIn('status', ['pending', 'paid_committed']);
                });
            })
            ->orderBy('id')
            ->get()
            ->map(function (object $reservation): array {
                $missing = $reservation->purpose === 'upgrade'
                    ? $this->missingUpgradeIdentity($reservation)
                    : $this->missingCheckoutIdentity($reservation);

                return [
                    'reservation_id' => (int) $reservation->id,
                    'service_id' => $reservation->service_id !== null
                        ? (int) $reservation->service_id
                        : null,
                    'purpose' => (string) $reservation->purpose,
                    'missing' => $missing,
                ];
            })
            ->filter(fn (array $row): bool => $row['missing'] !== [])
            ->values()
            ->all();
    }

    public function assertReady(): void
    {
        $blockers = $this->blockers();
        if ($blockers === []) {
            return;
        }

        $shown = array_slice($blockers, 0, 25);
        $details = array_map(
            fn (array $row): string => sprintf(
                'reservation #%d%s [%s]: %s',
                $row['reservation_id'],
                $row['service_id'] !== null
                    ? " / service #{$row['service_id']}"
                    : '',
                $row['purpose'],
                implode(', ', $row['missing'])
            ),
            $shown
        );
        if (count($blockers) > count($shown)) {
            $details[] = sprintf(
                'and %d more unresolved row(s)',
                count($blockers) - count($shown)
            );
        }

        throw new \RuntimeException(
            'Dynamic Pterodactyl cannot finish upgrading because legacy '
            .'reservations lack immutable fulfillment identity: '
            .implode('; ', $details)
            .'. Reconstruct the exact signed order configuration for every '
            .'bound unpaid commitment. For provisioned services, also verify '
            .'and import the exact numeric server/user IDs, UUID, identifier, '
            .'panel, node, nest, and egg from Pterodactyl and auditable order '
            .'records; never infer authority from an external-ID lookup or '
            .'current product settings. Then rerun '
            .'php artisan app:extension:migrate other DynamicPterodactyl --force.'
        );
    }

    /**
     * @return list<string>
     */
    private function missingCheckoutIdentity(object $reservation): array
    {
        $missing = $reservation->status === 'confirmed'
            ? $this->missingCommonRemoteIdentity($reservation)
            : [];
        foreach ([
            'server_extension_id',
            'service_id',
            'user_id',
            'product_id',
            'plan_id',
        ] as $field) {
            $this->requirePositive(
                $reservation->{$field},
                $field,
                $missing
            );
        }
        $payload = $this->decodedPayload($reservation, $missing);
        if ($payload === null) {
            return array_values(array_unique($missing));
        }

        $fingerprint = $reservation->configuration_fingerprint;
        if (
            ! is_string($fingerprint)
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || ! hash_equals(
                $fingerprint,
                $this->fingerprint($payload)
            )
        ) {
            $missing[] = 'valid configuration_fingerprint';
        }
        $service = Service::query()->find($reservation->service_id);
        if ($service === null) {
            $missing[] = 'service';
        } elseif (! $this->checkoutPayloadMatchesService(
            $payload,
            $reservation,
            $service
        )) {
            $missing[] = 'signed checkout/service identity agreement';
        }

        $provisioning = $payload['provisioning_identity'] ?? null;
        if (! is_array($provisioning)) {
            $missing[] = 'provisioning_identity';

            return array_values(array_unique($missing));
        }
        $this->requirePositive(
            $provisioning['nest_id'] ?? null,
            'provisioning_identity.nest_id',
            $missing
        );
        $this->requirePositive(
            $provisioning['egg_id'] ?? null,
            'provisioning_identity.egg_id',
            $missing
        );
        $expectedUserExternalId = $reservation->user_id !== null
            ? "paymenter-user-{$reservation->user_id}"
            : null;
        if (
            $expectedUserExternalId === null
            || ! is_string($provisioning['user_external_id'] ?? null)
            || ! hash_equals(
                $expectedUserExternalId,
                $provisioning['user_external_id']
            )
        ) {
            $missing[] = 'provisioning_identity.user_external_id';
        }
        foreach ([
            'panel_identity' => $reservation->panel_identity,
            'node_id' => $reservation->node_id,
            'customer_id' => $reservation->user_id,
            'product_id' => $reservation->product_id,
            'plan_id' => $reservation->plan_id,
        ] as $field => $expected) {
            $actual = data_get($payload, $field);
            if (
                $field === 'panel_identity'
                    ? ! is_string($actual)
                        || ! is_string($expected)
                        || ! hash_equals($expected, $actual)
                    : StrictInteger::parse($actual) === null
                        || (int) $actual !== (int) $expected
            ) {
                $missing[] = "configuration_payload.{$field}";
            }
        }
        if ($reservation->status !== 'confirmed') {
            $this->requireActiveAllocationClaims(
                $reservation,
                $payload,
                $missing
            );
        }

        return array_values(array_unique($missing));
    }

    /**
     * A bound checkout is not migratable unless the allocation rows still
     * materialize the exact signed port claims. Otherwise a paid invoice could
     * become durable before provisioning discovers the drift.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $missing
     */
    private function requireActiveAllocationClaims(
        object $reservation,
        array $payload,
        array &$missing
    ): void {
        if (! Schema::hasTable('ptero_reservation_allocations')) {
            $missing[] = 'active signed allocation claims';

            return;
        }

        $allocations = $payload['allocations'] ?? null;
        if (! is_array($allocations) || $allocations === []) {
            $missing[] = 'active signed allocation claims';

            return;
        }

        $expected = [];
        foreach ($allocations as $allocation) {
            if (! is_array($allocation)) {
                $missing[] = 'active signed allocation claims';

                return;
            }
            $allocationId = StrictInteger::parse(
                $allocation['allocation_id'] ?? null
            );
            $port = StrictInteger::parse($allocation['port'] ?? null);
            if (
                $allocationId === null
                || $allocationId <= 0
                || $port === null
                || $port <= 0
                || $port > 65535
            ) {
                $missing[] = 'active signed allocation claims';

                return;
            }
            $expected[] = [
                'panel_identity' => (string) $reservation->panel_identity,
                'node_id' => (int) $reservation->node_id,
                'allocation_id' => $allocationId,
                'ip' => (string) ($allocation['ip'] ?? ''),
                'port' => $port,
                'environment_key' =>
                    $allocation['environment_key'] ?? null,
                'is_primary' =>
                    (bool) ($allocation['is_primary'] ?? false),
            ];
        }
        usort(
            $expected,
            fn (array $left, array $right): int =>
                $left['allocation_id'] <=> $right['allocation_id']
        );

        $claims = DB::table('ptero_reservation_allocations')
            ->where('reservation_id', $reservation->id)
            ->get();
        $actual = $claims
            ->map(fn (object $allocation): array => [
                'panel_identity' => (string) $allocation->panel_identity,
                'node_id' => (int) $allocation->node_id,
                'allocation_id' => (int) $allocation->allocation_id,
                'ip' => (string) ($allocation->ip ?? ''),
                'port' => (int) $allocation->port,
                'environment_key' => $allocation->environment_key,
                'is_primary' => (bool) $allocation->is_primary,
            ])
            ->sortBy('allocation_id')
            ->values()
            ->all();

        if (
            $expected !== $actual
            || collect($actual)->where('is_primary', true)->count() !== 1
            || $claims->contains(
                fn (object $allocation): bool =>
                    $allocation->released_at !== null
            )
        ) {
            $missing[] = 'active signed allocation claims';
        }
    }

    /**
     * Reproduce the checkout identity proof locally so an upload never resolves
     * a readiness service class that may already be loaded from the old tree.
     *
     * @param  array<string, mixed>  $payload
     */
    private function checkoutPayloadMatchesService(
        array $payload,
        object $reservation,
        Service $service
    ): bool {
        $provisioning = $payload['provisioning_identity'] ?? null;
        $nestId = is_array($provisioning)
            ? StrictInteger::parse($provisioning['nest_id'] ?? null)
            : null;
        $eggId = is_array($provisioning)
            ? StrictInteger::parse($provisioning['egg_id'] ?? null)
            : null;

        return (int) $reservation->service_id === (int) $service->id
            && (int) $reservation->user_id === (int) $service->user_id
            && (int) $reservation->product_id === (int) $service->product_id
            && (int) $reservation->plan_id === (int) $service->plan_id
            && (int) $reservation->quantity === (int) $service->quantity
            && strtoupper((string) $reservation->currency_code)
                === strtoupper((string) $service->currency_code)
            && (int) ($payload['customer_id'] ?? 0)
                === (int) $reservation->user_id
            && (int) ($payload['server_extension_id'] ?? 0)
                === (int) $reservation->server_extension_id
            && (string) ($payload['panel_identity'] ?? '')
                === (string) $reservation->panel_identity
            && (int) ($payload['product_id'] ?? 0)
                === (int) $reservation->product_id
            && (int) ($payload['plan_id'] ?? 0)
                === (int) $reservation->plan_id
            && (int) ($payload['quantity'] ?? 0)
                === (int) $reservation->quantity
            && strtoupper((string) ($payload['currency_code'] ?? ''))
                === strtoupper((string) $reservation->currency_code)
            && (int) data_get($payload, 'resources.memory', 0)
                === (int) $reservation->memory
            && (int) data_get($payload, 'resources.cpu', 0)
                === (int) $reservation->cpu
            && (int) data_get($payload, 'resources.disk', 0)
                === (int) $reservation->disk
            && (int) ($payload['location_id'] ?? 0)
                === (int) $reservation->location_id
            && (int) ($payload['node_id'] ?? 0)
                === (int) $reservation->node_id
            && $nestId !== null
            && $nestId > 0
            && $eggId !== null
            && $eggId > 0
            && is_string($provisioning['user_external_id'] ?? null)
            && hash_equals(
                "paymenter-user-{$service->user_id}",
                $provisioning['user_external_id']
            )
            && is_string($provisioning['user_email'] ?? null)
            && trim($provisioning['user_email']) !== '';
    }

    /**
     * @param  array<string|int, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * @param  array<string|int, mixed>  $value
     * @return array<string|int, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function missingUpgradeIdentity(object $reservation): array
    {
        $missing = $this->missingCommonRemoteIdentity($reservation);
        $payload = $this->decodedPayload($reservation, $missing);
        if ($payload === null) {
            return array_values(array_unique($missing));
        }

        foreach ([
            'service_upgrade_id',
            'node_id',
            'location_id',
            'external_server_id',
            'external_user_id',
            'nest_id',
            'egg_id',
            'allocation_id',
        ] as $field) {
            $this->requirePositive(
                $payload[$field] ?? null,
                "configuration_payload.{$field}",
                $missing
            );
        }
        foreach ([
            'external_server_uuid',
            'external_server_identifier',
            'external_server_external_id',
            'user_external_id',
        ] as $field) {
            if (
                ! is_string($payload[$field] ?? null)
                || trim($payload[$field]) === ''
            ) {
                $missing[] = "configuration_payload.{$field}";
            }
        }
        if (
            is_string($payload['external_server_uuid'] ?? null)
            && ! Str::isUuid($payload['external_server_uuid'])
        ) {
            $missing[] = 'configuration_payload.external_server_uuid';
        }
        foreach (['source', 'target', 'delta', 'preserved_build'] as $field) {
            if (! is_array($payload[$field] ?? null)) {
                $missing[] = "configuration_payload.{$field}";
            }
        }
        $allocationIds = $payload['assigned_allocation_ids'] ?? null;
        if (
            ! is_array($allocationIds)
            || $allocationIds === []
            || collect($allocationIds)->contains(
                fn ($id): bool => StrictInteger::parse($id) === null
                    || (int) $id <= 0
            )
        ) {
            $missing[] = 'configuration_payload.assigned_allocation_ids';
        }
        if (
            (string) ($payload['panel_identity'] ?? '')
                !== (string) $reservation->panel_identity
            || (int) ($payload['node_id'] ?? 0)
                !== (int) $reservation->node_id
            || (int) ($payload['external_server_id'] ?? 0)
                !== (int) $reservation->external_server_id
            || (int) ($payload['external_user_id'] ?? 0)
                !== (int) $reservation->external_user_id
            || (string) ($payload['external_server_uuid'] ?? '')
                !== (string) $reservation->external_server_uuid
            || (string) ($payload['external_server_identifier'] ?? '')
                !== (string) $reservation->external_server_identifier
        ) {
            $missing[] = 'payload/row identity agreement';
        }
        if (
            ! is_string($reservation->configuration_fingerprint)
            || preg_match(
                '/^[a-f0-9]{64}$/D',
                $reservation->configuration_fingerprint
            ) !== 1
        ) {
            $missing[] = 'configuration_fingerprint';
        } elseif (! $this->upgradeFingerprintMatches(
            $reservation,
            $payload
        )) {
            $missing[] = 'valid configuration_fingerprint';
        }

        return array_values(array_unique($missing));
    }

    /**
     * Prove the active upgrade payload against the billing and before/after
     * fingerprints captured on its ServiceUpgrade row.
     *
     * @param  array<string, mixed>  $payload
     */
    private function upgradeFingerprintMatches(
        object $reservation,
        array $payload
    ): bool {
        $upgradeId = StrictInteger::parse(
            $reservation->service_upgrade_id
        );
        if ($upgradeId === null || $upgradeId <= 0) {
            return false;
        }
        $upgrade = DB::table('service_upgrades')->find($upgradeId);
        if ($upgrade === null) {
            return false;
        }

        try {
            $expected = hash('sha256', json_encode([
                'upgrade_id' => $upgradeId,
                'source_fingerprint' => (string) $upgrade->source_fingerprint,
                'target_fingerprint' => (string) $upgrade->target_fingerprint,
                'panel_identity' => $payload['panel_identity'] ?? null,
                'node_id' => $payload['node_id'] ?? null,
                'location_id' => $payload['location_id'] ?? null,
                'external_server_id' =>
                    $payload['external_server_id'] ?? null,
                'external_server_uuid' =>
                    $payload['external_server_uuid'] ?? null,
                'external_server_identifier' =>
                    $payload['external_server_identifier'] ?? null,
                'external_server_external_id' =>
                    $payload['external_server_external_id'] ?? null,
                'external_user_id' =>
                    $payload['external_user_id'] ?? null,
                'user_external_id' =>
                    $payload['user_external_id'] ?? null,
                'user_email' => $payload['user_email'] ?? null,
                'nest_id' => $payload['nest_id'] ?? null,
                'egg_id' => $payload['egg_id'] ?? null,
                'preserved_build' =>
                    $payload['preserved_build'] ?? null,
                'allocation_id' => $payload['allocation_id'] ?? null,
                'assigned_allocation_ids' =>
                    $payload['assigned_allocation_ids'] ?? null,
                'source' => $payload['source'] ?? null,
                'target' => $payload['target'] ?? null,
                'delta' => $payload['delta'] ?? null,
                'quoted_amount' => (string) $upgrade->quoted_amount,
                'currency_code' => strtoupper(
                    (string) $upgrade->currency_code
                ),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (\JsonException) {
            return false;
        }

        return hash_equals(
            (string) $reservation->configuration_fingerprint,
            $expected
        );
    }

    /**
     * @param  list<string>  $missing
     * @return array<string, mixed>|null
     */
    private function decodedPayload(
        object $reservation,
        array &$missing
    ): ?array {
        try {
            $payload = json_decode(
                (string) $reservation->configuration_payload,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            $payload = null;
        }
        if (! is_array($payload)) {
            $missing[] = 'configuration_payload';

            return null;
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function missingCommonRemoteIdentity(object $reservation): array
    {
        $missing = [];
        $this->requirePositive(
            $reservation->external_server_id,
            'external_server_id',
            $missing
        );
        $this->requirePositive(
            $reservation->external_user_id,
            'external_user_id',
            $missing
        );
        if (
            ! is_string($reservation->external_server_uuid)
            || ! Str::isUuid($reservation->external_server_uuid)
        ) {
            $missing[] = 'external_server_uuid';
        }
        if (
            ! is_string($reservation->external_server_identifier)
            || trim($reservation->external_server_identifier) === ''
        ) {
            $missing[] = 'external_server_identifier';
        }
        if (
            ! is_string($reservation->panel_identity)
            || preg_match('/^[a-f0-9]{64}$/D', $reservation->panel_identity)
                !== 1
        ) {
            $missing[] = 'panel_identity';
        }
        $this->requirePositive(
            $reservation->node_id,
            'node_id',
            $missing
        );

        return $missing;
    }

    /**
     * @param  list<string>  $missing
     */
    private function requirePositive(
        mixed $value,
        string $field,
        array &$missing
    ): void {
        $parsed = StrictInteger::parse($value);
        if ($parsed === null || $parsed <= 0) {
            $missing[] = $field;
        }
    }
}
