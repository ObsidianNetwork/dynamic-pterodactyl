<?php

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Support\PanelEndpointIdentity;
use App\Support\StrictDecimal;
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
    private const LEGACY_RETIREMENT_NOTES = [
        'Retired during migration to server-owned reservations.',
        'Retired duplicate service commitment during durable-fulfillment migration.',
    ];

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
            return [[
                'reservation_id' => 0,
                'service_id' => null,
                'purpose' => 'schema',
                'missing' => ['table:ptero_resource_reservations'],
            ]];
        }

        $requiredColumns = [
            'token',
            'idempotency_key',
            'purpose',
            'status',
            'admin_notes',
            'cart_item_id',
            'cart_item_guard_id',
            'cart_id',
            'configuration_fingerprint',
            'configuration_payload',
            'service_id',
            'service_guard_id',
            'service_upgrade_id',
            'upgrade_guard_id',
            'server_extension_id',
            'panel_identity',
            'invoice_id',
            'user_id',
            'product_id',
            'plan_id',
            'quantity',
            'currency_code',
            'node_id',
            'location_id',
            'memory',
            'cpu',
            'disk',
            'reserved_memory',
            'reserved_cpu',
            'reserved_disk',
            'calculated_price',
            'pricing_breakdown',
            'pricing_version',
            'formula_version',
            'expires_at',
            'guaranteed_until',
            'paid_committed_at',
            'provisioning_started_at',
            'provisioning_lease_id',
            'provisioning_attempts',
            'last_provisioning_attempt_at',
            'next_provisioning_attempt_at',
            'last_provisioning_error',
            'consumed_at',
            'failure_alerted_at',
            'cancellation_requested_at',
            'last_cancellation_error',
            'cancellation_failure_alerted_at',
            'external_server_id',
            'external_user_id',
            'external_server_uuid',
            'external_server_identifier',
            'last_reconciled_at',
            'customer_notified_at',
            'product_stock_released_at',
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

        $requiredTableColumns = [
            'ptero_capacity_scopes' => [
                'panel_identity',
                'location_id',
            ],
            'ptero_reservation_allocations' => [
                'reservation_id',
                'panel_identity',
                'node_id',
                'allocation_id',
                'ip',
                'port',
                'environment_key',
                'is_primary',
                'released_at',
            ],
            'ptero_node_capacity_policies' => [
                'panel_identity',
                'node_uuid',
                'node_id',
                'location_id',
                'cpu_capacity_percent',
                'cpu_overcommit_bps',
                'enabled',
            ],
            'services' => [
                'status',
                'product_id',
                'plan_id',
                'user_id',
                'quantity',
                'currency_code',
                'price',
                'product_stock_released_at',
            ],
            'invoices' => [
                'status',
                'user_id',
                'currency_code',
            ],
            'invoice_items' => [
                'invoice_id',
                'price',
                'quantity',
                'reference_type',
                'reference_id',
            ],
            'service_configs' => [
                'configurable_type',
                'configurable_id',
                'config_option_id',
                'config_value_id',
                'slider_value',
            ],
            'properties' => [
                'model_type',
                'model_id',
                'key',
                'value',
            ],
            'settings' => [
                'settingable_type',
                'settingable_id',
                'key',
                'value',
                'type',
            ],
            'extensions' => [
                'id',
                'type',
                'extension',
            ],
            'products' => [
                'id',
                'server_id',
            ],
            'config_options' => [
                'id',
                'parent_id',
                'type',
                'env_variable',
                'metadata',
            ],
            'config_option_products' => [
                'product_id',
                'config_option_id',
            ],
        ];
        foreach ($requiredTableColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                return [[
                    'reservation_id' => 0,
                    'service_id' => null,
                    'purpose' => 'schema',
                    'missing' => ["table:{$table}"],
                ]];
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return [[
                        'reservation_id' => 0,
                        'service_id' => null,
                        'purpose' => 'schema',
                        'missing' => ["column:{$table}.{$column}"],
                    ]];
                }
            }
        }

        if (! Schema::hasTable('service_upgrades')) {
            return [[
                'reservation_id' => 0,
                'service_id' => null,
                'purpose' => 'schema',
                'missing' => ['table:service_upgrades'],
            ]];
        }
        foreach ([
            'service_id',
            'product_id',
            'plan_id',
            'invoice_id',
            'status',
            'active_service_guard_id',
            'source_snapshot',
            'target_snapshot',
            'source_fingerprint',
            'target_fingerprint',
            'quoted_amount',
            'currency_code',
            'completed_at',
        ] as $column) {
            if (! Schema::hasColumn('service_upgrades', $column)) {
                return [[
                    'reservation_id' => 0,
                    'service_id' => null,
                    'purpose' => 'schema',
                    'missing' => ["column:service_upgrades.{$column}"],
                ]];
            }
        }

        $blockers = DB::table('ptero_resource_reservations')
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('purpose', 'checkout')
                        ->where(function ($query): void {
                            $query->where(function ($query): void {
                                $query->where('status', 'pending')
                                    ->where(function ($query): void {
                                        $query
                                            ->whereNotNull('service_id')
                                            ->orWhereNotNull(
                                                'service_guard_id'
                                            );
                                    });
                            })->orWhereIn('status', [
                                'paid_committed',
                                'confirmed',
                            ]);
                        });
                })->orWhere(function ($query): void {
                    $query->where('purpose', 'upgrade')
                        ->whereIn('status', [
                            'pending',
                            'paid_committed',
                            'confirmed',
                        ]);
                })->orWhere(function ($query): void {
                    // Older checkout-identity and durable-fulfillment
                    // migrations could silently cancel a service-bound
                    // commitment before this gate ran.
                    $query->where('purpose', 'checkout')
                        ->where('status', 'cancelled')
                        ->where(function ($query): void {
                            $query->whereNotNull('service_id')
                                ->orWhereNotNull('service_guard_id');
                        })
                        ->whereIn(
                            'admin_notes',
                            self::LEGACY_RETIREMENT_NOTES
                        );
                });
            })
            ->orderBy('id')
            ->get()
            ->map(function (object $reservation): array {
                $missing = $reservation->purpose === 'upgrade'
                    ? $this->missingUpgradeIdentity($reservation)
                    : $this->missingCheckoutIdentity($reservation);
                if ($this->wasBoundCommitmentRetired($reservation)) {
                    $missing[] =
                        'legacy bound commitment was retired before readiness';
                }

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

        return array_values(array_merge(
            $blockers,
            $this->missingDynamicServiceReservations(),
            $this->missingActiveUpgradeReservations()
        ));
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

        throw new RuntimeException(
            'Dynamic Pterodactyl cannot finish upgrading because its dynamic '
            .'stock schema or fulfillment proofs are incomplete: '
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
            'service_guard_id',
            'cart_item_guard_id',
            'cart_id',
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
        } else {
            if (! $this->checkoutPayloadMatchesService(
                $payload,
                $reservation,
                $service
            )) {
                $missing[] = 'signed checkout/service identity agreement';
            }
            if (! $this->checkoutLifecycleMatches(
                $reservation,
                $service
            )) {
                $missing[] = 'checkout service guard/lifecycle agreement';
            }
            if (! $this->checkoutServiceResourcesMatch(
                $payload,
                $reservation,
                $service
            )) {
                $missing[] = 'delivered service resource agreement';
            }
            if (! $this->checkoutPricingMatches(
                $payload,
                $reservation
            )) {
                $missing[] = 'signed checkout pricing agreement';
            }
            if (! $this->checkoutInvoiceMatches(
                $reservation,
                $service
            )) {
                $missing[] = 'checkout invoice/service billing agreement';
            }
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
            'cart_id' => $reservation->cart_id,
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
        $this->requireSignedAllocationClaims(
            $reservation,
            $payload,
            $missing
        );

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
    private function requireSignedAllocationClaims(
        object $reservation,
        array $payload,
        array &$missing
    ): void {
        $claimRequirement = $reservation->status === 'confirmed'
            ? 'released signed allocation claims'
            : 'active signed allocation claims';
        if (! Schema::hasTable('ptero_reservation_allocations')) {
            $missing[] = $claimRequirement;

            return;
        }

        $allocations = $payload['allocations'] ?? null;
        $requiredCount = StrictInteger::parse(
            data_get(
                $payload,
                'allocation_requirements.required_count'
            )
        );
        if (
            ! is_array($allocations)
            || $allocations === []
            || $requiredCount === null
            || $requiredCount <= 0
            || count($allocations) !== $requiredCount
        ) {
            $missing[] = $claimRequirement;

            return;
        }

        $expected = [];
        foreach ($allocations as $allocation) {
            if (! is_array($allocation)) {
                $missing[] = $claimRequirement;

                return;
            }
            $allocationId = StrictInteger::parse(
                $allocation['allocation_id'] ?? null
            );
            $port = StrictInteger::parse($allocation['port'] ?? null);
            $ip = $allocation['ip'] ?? null;
            $environmentKey = $allocation['environment_key'] ?? null;
            $isPrimary = $allocation['is_primary'] ?? null;
            if (
                $allocationId === null
                || $allocationId <= 0
                || $port === null
                || $port <= 0
                || $port > 65535
                || ! is_string($ip)
                || trim($ip) === ''
                || (
                    $environmentKey !== null
                    && ! is_string($environmentKey)
                )
                || ! is_bool($isPrimary)
            ) {
                $missing[] = $claimRequirement;

                return;
            }
            $expected[] = [
                'panel_identity' => (string) $reservation->panel_identity,
                'node_id' => (int) $reservation->node_id,
                'allocation_id' => $allocationId,
                'ip' => $ip,
                'port' => $port,
                'environment_key' => $environmentKey,
                'is_primary' => $isPrimary,
            ];
        }
        usort(
            $expected,
            fn (array $left, array $right): int => $left['allocation_id'] <=> $right['allocation_id']
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
            || count(array_unique(
                array_column($actual, 'allocation_id')
            )) !== count($actual)
            || collect($actual)->where('is_primary', true)->count() !== 1
            || (
                $reservation->status === 'confirmed'
                    ? $claims->contains(
                        fn (object $allocation): bool => $allocation->released_at === null
                    )
                    : $claims->contains(
                        fn (object $allocation): bool => $allocation->released_at !== null
                    )
            )
        ) {
            $missing[] = $claimRequirement;
        }
    }

    private function wasBoundCommitmentRetired(
        object $reservation
    ): bool {
        return $reservation->purpose === 'checkout'
            && $reservation->status === 'cancelled'
            && (
                $reservation->service_id !== null
                || $reservation->service_guard_id !== null
            )
            && in_array(
                $reservation->admin_notes,
                self::LEGACY_RETIREMENT_NOTES,
                true
            );
    }

    /**
     * A reservation-first scan cannot detect a dynamic service whose
     * commitment was deleted, detached, or duplicated with a null guard.
     * Inspect every non-cancelled capacity-backed service in reverse and
     * require one lifecycle-coherent checkout commitment.
     *
     * @return list<array{
     *     reservation_id: int,
     *     service_id: int|null,
     *     purpose: string,
     *     missing: list<string>
     * }>
     */
    private function missingDynamicServiceReservations(): array
    {
        $historyServiceIds = DB::table('ptero_resource_reservations')
            ->where('purpose', 'checkout')
            ->whereNotNull('service_id')
            ->distinct()
            ->pluck('service_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $dynamicProductIds = array_keys($this->rawDynamicProductIds());
        $configuredServiceIds =
            $this->rawDynamicConfiguredServiceIds();
        if (
            $historyServiceIds === []
            && $dynamicProductIds === []
            && $configuredServiceIds === []
        ) {
            return [];
        }

        $services = Service::query()
            ->where('status', '!=', Service::STATUS_CANCELLED)
            ->where(function ($query) use (
                $historyServiceIds,
                $dynamicProductIds,
                $configuredServiceIds
            ): void {
                if ($historyServiceIds !== []) {
                    $query->whereIn('id', $historyServiceIds);
                }
                if ($configuredServiceIds !== []) {
                    $method = $historyServiceIds === []
                        ? 'whereIn'
                        : 'orWhereIn';
                    $query->{$method}('id', $configuredServiceIds);
                }
                if ($dynamicProductIds !== []) {
                    $method = $historyServiceIds === []
                        && $configuredServiceIds === []
                        ? 'whereIn'
                        : 'orWhereIn';
                    $query->{$method}('product_id', $dynamicProductIds);
                }
            })
            ->orderBy('id')
            ->get();
        if ($services->isEmpty()) {
            return [];
        }

        $history = DB::table('ptero_resource_reservations')
            ->where('purpose', 'checkout')
            ->whereIn('service_id', $services->modelKeys())
            ->orderBy('id')
            ->get()
            ->groupBy('service_id');
        $blockers = [];

        foreach ($services as $service) {
            $reservations = $history
                ->get($service->id, collect())
                ->whereIn('status', [
                    'pending',
                    'paid_committed',
                    'confirmed',
                ])
                ->values();
            $expectedStatuses = $this->checkoutStatusesForService(
                (string) $service->status
            );
            $coherent = $expectedStatuses !== []
                && $reservations->count() === 1
                && in_array(
                    (string) $reservations->first()->status,
                    $expectedStatuses,
                    true
                )
                && $this->checkoutLifecycleMatches(
                    $reservations->first(),
                    $service
                );
            if ($coherent) {
                continue;
            }

            $blockers[] = [
                'reservation_id' => $reservations->count() === 1
                    ? (int) $reservations->first()->id
                    : 0,
                'service_id' => (int) $service->id,
                'purpose' => 'checkout',
                'missing' => [
                    "dynamic service #{$service->id} requires exactly one "
                        .'lifecycle-coherent checkout capacity reservation',
                ],
            ];
        }

        return $blockers;
    }

    /**
     * A product or pivot may have been removed after a service was created.
     * Its persisted dynamic ServiceConfig rows remain enough evidence that the
     * service requires a capacity commitment.
     *
     * @return list<int>
     */
    private function rawDynamicConfiguredServiceIds(): array
    {
        $rows = DB::table('service_configs')
            ->join(
                'config_options',
                'config_options.id',
                '=',
                'service_configs.config_option_id'
            )
            ->where(
                'service_configs.configurable_type',
                Service::class
            )
            ->where('config_options.type', 'dynamic_slider')
            ->get([
                'service_configs.configurable_id',
                'config_options.env_variable',
                'config_options.metadata',
            ]);
        $serviceIds = [];
        foreach ($rows as $row) {
            $metadata = is_string($row->metadata)
                ? json_decode($row->metadata, true)
                : (array) $row->metadata;
            $resource = strtolower((string) (
                (is_array($metadata)
                    ? ($metadata['resource_type'] ?? null)
                    : null)
                ?? $row->env_variable
                ?? ''
            ));
            if (in_array($resource, ['memory', 'cpu', 'disk'], true)) {
                $serviceIds[(int) $row->configurable_id] = true;
            }
        }

        return array_keys($serviceIds);
    }

    /**
     * Reservation-first scans cannot see an active dynamic ServiceUpgrade
     * whose capacity row is missing, terminal, or duplicated. Inspect the
     * core lifecycle in the reverse direction and require its one authoritative
     * reservation before an extension upgrade may complete.
     *
     * @return list<array{
     *     reservation_id: int,
     *     service_id: int|null,
     *     purpose: string,
     *     missing: list<string>
     * }>
     */
    private function missingActiveUpgradeReservations(): array
    {
        $upgrades = ServiceUpgrade::query()
            ->with([
                'service.product',
                'product',
            ])
            ->where(function ($query): void {
                $query->whereIn('status', [
                    ...ServiceUpgrade::activeStatuses(),
                    ServiceUpgrade::STATUS_COMPLETED,
                ])
                    ->orWhereNotNull('active_service_guard_id');
            })
            ->orderBy('id')
            ->get();
        if ($upgrades->isEmpty()) {
            return [];
        }

        $history = DB::table('ptero_resource_reservations')
            ->where('purpose', 'upgrade')
            ->whereIn('service_upgrade_id', $upgrades->modelKeys())
            ->orderBy('id')
            ->get()
            ->groupBy('service_upgrade_id');
        $rawDynamicProducts = $this->rawDynamicProductIds();
        $rawPterodactylProducts = $this->rawPterodactylProductIds();
        $capacityBackedServices = DB::table(
            'ptero_resource_reservations'
        )
            ->where('purpose', 'checkout')
            ->whereNotNull('service_id')
            ->pluck('service_id')
            ->mapWithKeys(
                fn ($id): array => [(int) $id => true]
            )
            ->all();
        $dynamicCache = [];
        $blockers = [];

        foreach ($upgrades as $upgrade) {
            $rows = $history->get($upgrade->id, collect());
            $classificationFailed = false;
            try {
                $currentProduct = $upgrade->service?->product;
                $targetProduct = $upgrade->product;
                if ($currentProduct === null || $targetProduct === null) {
                    throw new RuntimeException(
                        'The active upgrade product identity is missing.'
                    );
                }
                foreach ([$currentProduct, $targetProduct] as $product) {
                    $productId = (int) $product->id;
                    $dynamicCache[$productId] ??=
                        $product->usesDynamicResources();
                }
                $dynamic = $rows->isNotEmpty()
                    || isset($capacityBackedServices[
                        (int) $upgrade->service_id
                    ])
                    || ($dynamicCache[(int) $currentProduct->id] ?? false)
                    || ($dynamicCache[(int) $targetProduct->id] ?? false)
                    || isset($rawDynamicProducts[(int) $currentProduct->id])
                    || isset($rawDynamicProducts[(int) $targetProduct->id])
                    || (
                        (
                            isset($rawPterodactylProducts[
                                (int) $currentProduct->id
                            ])
                            || isset($rawPterodactylProducts[
                                (int) $targetProduct->id
                            ])
                        )
                        && $this->upgradeSnapshotsChangeOnlyResources(
                            $upgrade
                        )
                    );
            } catch (Throwable) {
                $dynamic = true;
                $classificationFailed = true;
            }
            if (! $dynamic) {
                continue;
            }

            $reservations = $rows->whereIn('status', [
                'pending',
                'paid_committed',
                'confirmed',
            ])->values();
            $expectedStatus = match ((string) $upgrade->status) {
                ServiceUpgrade::STATUS_PENDING,
                ServiceUpgrade::STATUS_AWAITING_PAYMENT => 'pending',
                ServiceUpgrade::STATUS_PAID_COMMITTED,
                ServiceUpgrade::STATUS_PROVISIONING,
                ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                ServiceUpgrade::STATUS_NEEDS_ATTENTION => 'paid_committed',
                ServiceUpgrade::STATUS_COMPLETED => 'confirmed',
                default => null,
            };
            $reservation = $reservations->count() === 1
                ? $reservations->first()
                : null;
            $reservationGuard = $reservation !== null
                ? $this->positiveInteger(
                    $reservation->upgrade_guard_id ?? null
                )
                : null;
            $upgradeGuard = $this->positiveInteger(
                $upgrade->active_service_guard_id
            );
            if (
                ! $classificationFailed
                && $expectedStatus !== null
                && $reservations->count() === 1
                && $reservation?->status === $expectedStatus
                && $this->upgradeLifecycleMatches(
                    (string) $reservation->status,
                    (string) $upgrade->status,
                    $reservationGuard,
                    $upgradeGuard,
                    (int) $upgrade->id,
                    (int) $upgrade->service_id,
                    $reservation->consumed_at ?? null,
                    $upgrade->completed_at
                )
            ) {
                continue;
            }

            $blockers[] = [
                'reservation_id' => $reservations->count() === 1
                    ? (int) $reservations->first()->id
                    : 0,
                'service_id' => $upgrade->service_id !== null
                    ? (int) $upgrade->service_id
                    : null,
                'purpose' => 'upgrade',
                'missing' => [
                    "dynamic service upgrade #{$upgrade->id} "
                    .'requires exactly one coherent capacity reservation',
                ],
            ];
        }

        return $blockers;
    }

    /**
     * Detect capacity-backed products without Eloquent soft-delete or current
     * visibility scopes. A host or slider hidden after quote creation must not
     * make an active dynamic upgrade disappear from readiness.
     *
     * @return array<int, true>
     */
    private function rawDynamicProductIds(): array
    {
        $rows = DB::table('config_options')
            ->join(
                'config_option_products',
                'config_option_products.config_option_id',
                '=',
                'config_options.id'
            )
            ->join(
                'products',
                'products.id',
                '=',
                'config_option_products.product_id'
            )
            ->join(
                'extensions as server_extensions',
                'server_extensions.id',
                '=',
                'products.server_id'
            )
            ->where('config_options.type', 'dynamic_slider')
            ->whereNull('config_options.parent_id')
            ->where('server_extensions.type', 'server')
            ->where('server_extensions.extension', 'Pterodactyl')
            ->get([
                'config_option_products.product_id',
                'config_options.env_variable',
                'config_options.metadata',
            ]);
        $products = [];

        foreach ($rows as $row) {
            $metadata = is_string($row->metadata)
                ? json_decode($row->metadata, true)
                : (array) $row->metadata;
            $resource = strtolower((string) (
                (is_array($metadata)
                    ? ($metadata['resource_type'] ?? null)
                    : null)
                ?? $row->env_variable
                ?? ''
            ));
            if (in_array($resource, ['memory', 'cpu', 'disk'], true)) {
                $products[(int) $row->product_id] = true;
            }
        }

        return $products;
    }

    /**
     * @return array<int, true>
     */
    private function rawPterodactylProductIds(): array
    {
        return DB::table('products')
            ->join(
                'extensions as server_extensions',
                'server_extensions.id',
                '=',
                'products.server_id'
            )
            ->where('server_extensions.type', 'server')
            ->where('server_extensions.extension', 'Pterodactyl')
            ->pluck('products.id')
            ->mapWithKeys(
                fn ($id): array => [(int) $id => true]
            )
            ->all();
    }

    private function upgradeSnapshotsChangeOnlyResources(
        ServiceUpgrade $upgrade
    ): bool {
        $source = $this->snapshotProperties(
            $this->decodedArray($upgrade->source_snapshot)
        );
        $target = $this->snapshotProperties(
            $this->decodedArray($upgrade->target_snapshot)
        );
        if ($source === null || $target === null) {
            return false;
        }

        $sourceResources = $this->resourceVectorFromProperties($source);
        $targetResources = $this->resourceVectorFromProperties($target);

        return $sourceResources !== null
            && $targetResources !== null
            && $sourceResources !== $targetResources
            && $this->onlyResourcesChanged($source, $target);
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
        $customerId = StrictInteger::parse(
            $payload['customer_id'] ?? null
        );
        $serverExtensionId = StrictInteger::parse(
            $payload['server_extension_id'] ?? null
        );
        $productId = StrictInteger::parse(
            $payload['product_id'] ?? null
        );
        $planId = StrictInteger::parse($payload['plan_id'] ?? null);
        $quantity = StrictInteger::parse($payload['quantity'] ?? null);
        $memory = StrictInteger::parse(
            data_get($payload, 'resources.memory')
        );
        $cpu = StrictInteger::parse(
            data_get($payload, 'resources.cpu')
        );
        $disk = StrictInteger::parse(
            data_get($payload, 'resources.disk')
        );
        $locationId = StrictInteger::parse(
            $payload['location_id'] ?? null
        );
        $nodeId = StrictInteger::parse($payload['node_id'] ?? null);

        return (int) $reservation->service_id === (int) $service->id
            && (int) $reservation->user_id === (int) $service->user_id
            && (int) $reservation->product_id === (int) $service->product_id
            && (int) $reservation->plan_id === (int) $service->plan_id
            && (int) $reservation->quantity === (int) $service->quantity
            && (int) $reservation->quantity === 1
            && strtoupper((string) $reservation->currency_code)
                === strtoupper((string) $service->currency_code)
            && $customerId !== null
            && $customerId
                === (int) $reservation->user_id
            && $serverExtensionId !== null
            && $serverExtensionId
                === (int) $reservation->server_extension_id
            && (string) ($payload['panel_identity'] ?? '')
                === (string) $reservation->panel_identity
            && $productId !== null
            && $productId
                === (int) $reservation->product_id
            && $planId !== null
            && $planId
                === (int) $reservation->plan_id
            && $quantity === 1
            && $quantity
                === (int) $reservation->quantity
            && strtoupper((string) ($payload['currency_code'] ?? ''))
                === strtoupper((string) $reservation->currency_code)
            && $memory !== null
            && $memory
                === (int) $reservation->memory
            && $cpu !== null
            && $cpu
                === (int) $reservation->cpu
            && $disk !== null
            && $disk
                === (int) $reservation->disk
            && $locationId !== null
            && $locationId
                === (int) $reservation->location_id
            && $nodeId !== null
            && $nodeId
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
            && trim($provisioning['user_email']) !== ''
            && $this->productPanelMatchesCheckout(
                $reservation
            );
    }

    private function productPanelMatchesCheckout(
        object $reservation
    ): bool {
        try {
            $serverId = $this->positiveInteger(
                $reservation->server_extension_id ?? null
            );
            $server = $serverId !== null
                ? Server::query()
                    ->with('settings')
                    ->find($serverId)
                : null;
            if (
                $server === null
                || $server->extension !== 'Pterodactyl'
                || ! is_string($reservation->panel_identity ?? null)
            ) {
                return false;
            }
            $settings = ExtensionHelper::settingsToArray(
                $server->settings
            );
            $panelIdentity = PanelEndpointIdentity::hash(
                trim((string) ($settings['host'] ?? ''))
            );
        } catch (Throwable) {
            return false;
        }

        return hash_equals(
            (string) $reservation->panel_identity,
            $panelIdentity
        );
    }

    /**
     * @return list<string>
     */
    private function checkoutStatusesForService(string $status): array
    {
        return match ($status) {
            Service::STATUS_PENDING => ['pending'],
            Service::STATUS_PROVISIONING,
            Service::STATUS_PROVISIONING_FAILED => ['paid_committed'],
            Service::STATUS_ACTIVE,
            Service::STATUS_SUSPENDED => ['confirmed'],
            Service::STATUS_CANCELLATION_PENDING => [
                'paid_committed',
                'confirmed',
            ],
            Service::STATUS_CANCELLED => ['confirmed'],
            default => [],
        };
    }

    private function checkoutLifecycleMatches(
        object $reservation,
        Service $service
    ): bool {
        $guard = $this->positiveInteger(
            $reservation->service_guard_id ?? null
        );
        $status = (string) ($reservation->status ?? '');
        $expectedStatuses = $this->checkoutStatusesForService(
            (string) $service->status
        );

        $cancelledConfirmed = (string) $service->status
                === Service::STATUS_CANCELLED
            && $status === 'confirmed';
        $cancellationPending = (string) $service->status
            === Service::STATUS_CANCELLATION_PENDING;
        $stockReleaseMatches = $cancelledConfirmed
            ? ($reservation->product_stock_released_at ?? null) !== null
                && $service->product_stock_released_at !== null
            : ($reservation->product_stock_released_at ?? null) === null
                && $service->product_stock_released_at === null;

        return (int) ($reservation->service_id ?? 0) === (int) $service->id
            && $guard === (int) $service->id
            && in_array($status, $expectedStatuses, true)
            && $stockReleaseMatches
            && (
                ! $cancellationPending
                || ($reservation->cancellation_requested_at ?? null)
                    !== null
            )
            && (
                ! $cancelledConfirmed
                || (
                    ($reservation->cancellation_requested_at ?? null)
                        !== null
                )
            )
            && (
                $status === 'confirmed'
                    ? ($reservation->consumed_at ?? null) !== null
                    : in_array(
                        $status,
                        ['pending', 'paid_committed'],
                        true
                    )
                        && ($reservation->consumed_at ?? null) === null
            );
    }

    /**
     * The signed resources must also be the values Paymenter's provisioner
     * reads from the Service. A self-consistent reservation cannot authorize
     * different mutable ServiceConfig values.
     *
     * @param  array<string, mixed>  $payload
     */
    private function checkoutServiceResourcesMatch(
        array $payload,
        object $reservation,
        Service $service
    ): bool {
        $properties = $this->currentServiceProperties($service);
        $serviceResources = $this->resourceVectorFromProperties(
            $properties
        );
        $serviceLocation = $this->locationFromProperties($properties);
        $payloadResources = $this->resourceVector(
            $payload['resources'] ?? null
        );
        $payloadLocation = StrictInteger::parse(
            $payload['location_id'] ?? null
        );
        $rowResources = $this->resourceVector([
            'memory' => $reservation->memory ?? null,
            'cpu' => $reservation->cpu ?? null,
            'disk' => $reservation->disk ?? null,
        ]);
        $rowLocation = StrictInteger::parse(
            $reservation->location_id ?? null
        );

        $immutableReservationMatches = $payloadResources !== null
            && $payloadLocation !== null
            && $rowResources !== null
            && $rowLocation !== null
            && $payloadResources === $rowResources
            && $payloadLocation === $rowLocation;
        if (
            ! $immutableReservationMatches
            || $this->serviceHasCompletedUpgrade((int) $service->id)
        ) {
            return $immutableReservationMatches;
        }

        return $serviceResources !== null
            && $serviceLocation !== null
            && $serviceResources === $rowResources
            && $serviceLocation === $rowLocation;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checkoutPricingMatches(
        array $payload,
        object $reservation
    ): bool {
        $configOptions = $payload['config_options'] ?? null;
        if (! is_array($configOptions) || ! array_is_list($configOptions)) {
            return false;
        }
        if (! $this->checkoutResourceOptionsMatch(
            $payload,
            $configOptions
        )) {
            return false;
        }

        try {
            $payloadAmount = $this->normalizedMoney(
                $payload['calculated_price'] ?? null
            );
            $reservationAmount = $this->normalizedMoney(
                $reservation->calculated_price ?? null
            );
            $pricingVersion = $this->fingerprint([
                'product_id' => (int) $reservation->product_id,
                'plan_id' => (int) $reservation->plan_id,
                'currency_code' => strtoupper(
                    (string) $reservation->currency_code
                ),
                'calculated_price' => $reservationAmount,
                'config_options' => $configOptions,
            ]);
        } catch (Throwable) {
            return false;
        }

        return $reservationAmount[0] !== '-'
            && $payloadAmount === $reservationAmount
            && is_string($payload['pricing_version'] ?? null)
            && hash_equals(
                $pricingVersion,
                $payload['pricing_version']
            )
            && is_string($reservation->pricing_version ?? null)
            && hash_equals(
                $pricingVersion,
                (string) $reservation->pricing_version
            )
            && ($payload['formula_version'] ?? null)
                === 'dynamic-pterodactyl-v1'
            && ($reservation->formula_version ?? null)
                === 'dynamic-pterodactyl-v1';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<mixed>  $configOptions
     */
    private function checkoutResourceOptionsMatch(
        array $payload,
        array $configOptions
    ): bool {
        $payloadResources = $this->resourceVector(
            $payload['resources'] ?? null
        );
        if ($payloadResources === null) {
            return false;
        }

        $selected = [];
        $optionIds = [];
        foreach ($configOptions as $configOption) {
            if (! is_array($configOption)) {
                return false;
            }
            $resource = strtolower((string) (
                $configOption['resource_type'] ?? ''
            ));
            if (! in_array($resource, ['memory', 'cpu', 'disk'], true)) {
                continue;
            }
            $optionId = $this->positiveInteger(
                $configOption['id'] ?? null
            );
            $value = StrictInteger::parse(
                $configOption['value'] ?? null
            );
            if (
                $optionId === null
                || $value === null
                || ($configOption['type'] ?? null) !== 'dynamic_slider'
                || array_key_exists($resource, $selected)
                || array_key_exists($optionId, $optionIds)
            ) {
                return false;
            }
            $selected[$resource] = $value;
            $optionIds[$optionId] = $resource;
        }

        return ! (
            $selected === []
            || collect($selected)->contains(
                fn (int $value, string $resource): bool => $value !== $payloadResources[$resource]
            )
        );
    }

    private function checkoutInvoiceMatches(
        object $reservation,
        Service $service
    ): bool {
        try {
            $amount = $this->normalizedMoney(
                $reservation->calculated_price ?? null
            );
        } catch (Throwable) {
            return false;
        }
        if ($amount[0] === '-') {
            return false;
        }
        if (! $this->moneyIsPositive($amount)) {
            return ($reservation->invoice_id ?? null) === null;
        }

        $invoiceId = $this->positiveInteger(
            $reservation->invoice_id ?? null
        );
        if ($invoiceId === null) {
            return false;
        }
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        $reservationStatus = (string) ($reservation->status ?? '');
        if (
            $invoice === null
            || (int) $invoice->user_id !== (int) $service->user_id
            || strtoupper((string) $invoice->currency_code)
                !== strtoupper((string) $service->currency_code)
            || (
                $reservationStatus === 'pending'
                    ? (string) $invoice->status !== 'pending'
                    : (string) $invoice->status !== 'paid'
            )
        ) {
            return false;
        }

        $items = DB::table('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->where('reference_type', $service->getMorphClass())
            ->where('reference_id', $service->id)
            ->orderBy('id')
            ->get();
        if ($items->count() !== 1) {
            return false;
        }
        $item = $items->first();

        try {
            $lineAmount = $this->normalizedMoney($item->price);
        } catch (Throwable) {
            return false;
        }

        return (int) $item->quantity === 1
            && (int) $reservation->quantity === 1
            && $lineAmount === $amount;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentServiceProperties(Service $service): ?array
    {
        try {
            $service->loadMissing([
                'product.settings',
                'properties',
                'configs.configOption',
                'configs.configValue',
            ]);
            if ($service->product === null) {
                return null;
            }
            $properties = array_merge(
                ExtensionHelper::settingsToArray(
                    $service->product->settings
                ),
                ExtensionHelper::getServiceProperties($service)
            );
            // MariaDB hydrates DECIMAL slider values as strings while SQLite
            // commonly returns integers. Restore their exact stored integer
            // representation before comparing the live service with its
            // signed target. Do not apply today's mutable min/max/step rules
            // to a historical upgrade that has already completed.
            foreach ($service->configs as $config) {
                $option = $config->configOption;
                if (
                    $option === null
                    || ! $option->isDynamicSlider()
                    || $config->slider_value === null
                ) {
                    continue;
                }
                $key = strtolower((string) (
                    $option->env_variable ?: $option->name
                ));
                if (! in_array($key, ['memory', 'cpu', 'disk'], true)) {
                    continue;
                }
                $numericValue = StrictInteger::parseStoredDecimal(
                    $config->slider_value
                );
                if ($numericValue === null) {
                    return null;
                }
                $properties[$key] = $numericValue;
            }
        } catch (Throwable) {
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

    private function serviceHasCompletedUpgrade(int $serviceId): bool
    {
        return ServiceUpgrade::query()
            ->where('service_id', $serviceId)
            ->where('status', ServiceUpgrade::STATUS_COMPLETED)
            ->exists();
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
            } elseif (
                is_float($item)
                && is_finite($item)
                && floor($item) === $item
                && $item >= PHP_INT_MIN
                && $item <= PHP_INT_MAX
            ) {
                $value[$key] = (int) $item;
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
        if (
            Schema::hasTable('ptero_reservation_allocations')
            && DB::table('ptero_reservation_allocations')
                ->where('reservation_id', $reservation->id)
                ->exists()
        ) {
            $missing[] = 'unexpected checkout allocation claims';
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
        $upgradeId = $this->positiveInteger(
            $reservation->service_upgrade_id ?? null
        );
        if ($upgradeId === null) {
            return false;
        }

        try {
            $upgrade = ServiceUpgrade::query()
                ->with([
                    'service.product.server.settings',
                    'service.product.settings',
                    'service.plan',
                    'service.configs.configOption',
                    'service.configs.configValue',
                    'service.user',
                    'product.server.settings',
                    'product.settings',
                    'plan',
                    'configs.configOption',
                    'configs.configValue',
                    'invoice',
                ])
                ->find($upgradeId);
        } catch (Throwable) {
            return false;
        }
        if ($upgrade === null || $upgrade->service === null) {
            return false;
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
        $sourceSnapshot = $this->decodedArray(
            $upgrade->source_snapshot
        );
        $targetSnapshot = $this->decodedArray(
            $upgrade->target_snapshot
        );
        $sourceProperties = $this->snapshotProperties($sourceSnapshot);
        $targetProperties = $this->snapshotProperties($targetSnapshot);
        $snapshotSource = $this->resourceVectorFromProperties(
            $sourceProperties
        );
        $snapshotTarget = $this->resourceVectorFromProperties(
            $targetProperties
        );
        $snapshotLocation = $this->locationFromProperties(
            $targetProperties
        );
        $sourceSnapshotHash = $this->snapshotFingerprint($sourceSnapshot);
        $targetSnapshotHash = $this->snapshotFingerprint($targetSnapshot);
        $sourceFingerprint = $upgrade->source_fingerprint;
        $targetFingerprint = $upgrade->target_fingerprint;
        $upgradeServiceId = $this->positiveInteger($upgrade->service_id);
        $upgradeProductId = $this->positiveInteger($upgrade->product_id);
        $upgradePlanId = $this->positiveInteger($upgrade->plan_id);
        $upgradeInvoiceId = $this->positiveInteger($upgrade->invoice_id);
        $serviceUserId = $this->positiveInteger($upgrade->service->user_id);
        $serviceProductId = $this->positiveInteger(
            $upgrade->service->product_id
        );
        $servicePlanId = $this->positiveInteger(
            $upgrade->service->plan_id
        );
        $serviceQuantity = StrictInteger::parse(
            $upgrade->service->quantity
        );
        $serviceCurrency = strtoupper(
            (string) $upgrade->service->currency_code
        );
        $productServerId = $this->positiveInteger(
            $upgrade->product?->server_id
        );
        $reservationServiceId = $this->positiveInteger(
            $reservation->service_id ?? null
        );
        $reservationUpgradeId = $this->positiveInteger(
            $reservation->service_upgrade_id ?? null
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
        $reservationGuard = $this->positiveInteger(
            $reservation->upgrade_guard_id ?? null
        );
        $upgradeGuard = $this->positiveInteger(
            $upgrade->active_service_guard_id
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
        $reservationStatus = (string) (
            $reservation->status ?? ''
        );

        try {
            $targetRecurring = StrictDecimal::parseNonNegative(
                $targetSnapshot['recurring_price'] ?? null
            );
            $targetRecurringAmount = $this->normalizedMoney(
                $targetSnapshot['recurring_price'] ?? null
            );
            $quotedAmount = $this->normalizedMoney(
                $upgrade->quoted_amount
            );
            $reservedAmount = $this->normalizedMoney(
                $reservation->calculated_price ?? null
            );
            $expectedFingerprint = $this->upgradeFingerprint(
                $upgrade,
                $payload
            );
            $pricingVersion = $this->upgradePricingVersion($upgrade);
            $invoiceMatches = $this->upgradeInvoiceMatches(
                $upgrade,
                (string) $reservation->status,
                $quotedAmount,
                $serviceUserId,
                $serviceCurrency
            );
            $upgrade->load([
                'service.product.settings',
                'service.configs.configOption',
                'service.configs.configValue',
                'product.settings',
                'configs.configOption',
                'configs.configValue',
            ]);
            if ($reservationStatus === 'confirmed') {
                if ($this->isLatestCompletedUpgrade($upgrade)) {
                    $liveTargetProperties =
                        $this->currentServiceProperties(
                            $upgrade->service
                        );
                    $liveRecurringAmount = $this->normalizedMoney(
                        $upgrade->service->price
                    );
                } else {
                    // Historical completed upgrades are superseded by the
                    // next signed target. Only the latest target can equal
                    // the Service's current mutable state.
                    $liveTargetProperties = $targetProperties;
                    $liveRecurringAmount = $targetRecurringAmount;
                }
            } else {
                $liveTargetProperties = $this->snapshotProperties([
                    'properties' => $upgrade->targetProperties(),
                ]);
                $liveRecurringAmount = $targetRecurringAmount;
            }
            $sourceStillMatches = ! in_array(
                $reservationStatus,
                ['pending', 'paid_committed'],
                true
            ) || $upgrade->sourceStillMatches();
        } catch (Throwable) {
            return false;
        }

        return $source !== null
            && $target !== null
            && $delta !== null
            && $rowTarget !== null
            && $rowDelta !== null
            && $sourceSnapshot !== null
            && $targetSnapshot !== null
            && $sourceProperties !== null
            && $targetProperties !== null
            && $liveTargetProperties === $targetProperties
            && $snapshotSource !== null
            && $snapshotTarget !== null
            && $snapshotLocation !== null
            && $targetRecurring !== null
            && $liveRecurringAmount === $targetRecurringAmount
            && $sourceSnapshotHash !== null
            && $targetSnapshotHash !== null
            && is_string($sourceFingerprint)
            && preg_match('/^[a-f0-9]{64}$/D', $sourceFingerprint) === 1
            && is_string($targetFingerprint)
            && preg_match('/^[a-f0-9]{64}$/D', $targetFingerprint) === 1
            && hash_equals($sourceFingerprint, $sourceSnapshotHash)
            && hash_equals($targetFingerprint, $targetSnapshotHash)
            && $source === $snapshotSource
            && $target === $snapshotTarget
            && $source !== $target
            && ! collect($target)->contains(
                fn (int $value): bool => $value <= 0
            )
            && $target['disk'] >= $source['disk']
            && $this->onlyResourcesChanged(
                $sourceProperties,
                $targetProperties
            )
            && $delta === [
                'memory' => max(0, $target['memory'] - $source['memory']),
                'cpu' => max(0, $target['cpu'] - $source['cpu']),
                'disk' => max(0, $target['disk'] - $source['disk']),
            ]
            && ($reservation->purpose ?? null) === 'upgrade'
            && $upgradeServiceId !== null
            && $reservationServiceId === $upgradeServiceId
            && $reservationUpgradeId === $upgradeId
            && $payloadUpgradeId === $upgradeId
            && $serviceUserId !== null
            && $reservationUserId === $serviceUserId
            && $serviceProductId !== null
            && $upgradeProductId === $serviceProductId
            && $reservationProductId === $serviceProductId
            && $servicePlanId !== null
            && $upgradePlanId === $servicePlanId
            && $reservationPlanId === $servicePlanId
            && $serviceQuantity === 1
            && $reservationQuantity === 1
            && $serviceCurrency !== ''
            && strtoupper((string) $upgrade->currency_code)
                === $serviceCurrency
            && strtoupper((string) $reservation->currency_code)
                === $serviceCurrency
            && $this->snapshotIdentityMatches(
                $sourceSnapshot,
                $targetSnapshot,
                $upgradeServiceId,
                $serviceProductId,
                $servicePlanId,
                $serviceCurrency
            )
            && (
                $upgrade->invoice_id === null
                || $upgradeInvoiceId !== null
            )
            && (
                ($reservation->invoice_id ?? null) === null
                || $reservationInvoiceId !== null
            )
            && $reservationInvoiceId === $upgradeInvoiceId
            && (
                $this->moneyIsPositive($quotedAmount)
                    ? $upgradeInvoiceId !== null
                    : $upgradeInvoiceId === null
            )
            && $invoiceMatches
            && $productServerId !== null
            && $reservationServerId === $productServerId
            && $this->productPanelMatchesUpgrade(
                $upgrade,
                $reservation
            )
            && (
                ($reservation->upgrade_guard_id ?? null) === null
                || $reservationGuard !== null
            )
            && (
                $upgrade->active_service_guard_id === null
                || $upgradeGuard !== null
            )
            && $quotedAmount === $reservedAmount
            && (string) ($reservation->pricing_version ?? '')
                === $pricingVersion
            && (string) ($reservation->formula_version ?? '')
                === 'dynamic-upgrade-v1'
            && $this->upgradeLifecycleMatches(
                (string) ($reservation->status ?? ''),
                (string) $upgrade->status,
                $reservationGuard,
                $upgradeGuard,
                $upgradeId,
                $upgradeServiceId,
                $reservation->consumed_at ?? null,
                $upgrade->completed_at
            )
            && $sourceStillMatches
            && is_string($reservation->configuration_fingerprint)
            && preg_match(
                '/^[a-f0-9]{64}$/D',
                $reservation->configuration_fingerprint
            ) === 1
            && hash_equals(
                $reservation->configuration_fingerprint,
                $expectedFingerprint
            )
            && (string) ($payload['source_fingerprint'] ?? '')
                === $sourceFingerprint
            && (string) ($payload['target_fingerprint'] ?? '')
                === $targetFingerprint
            && is_string($panelIdentity)
            && preg_match('/^[a-f0-9]{64}$/D', $panelIdentity) === 1
            && is_string($reservation->panel_identity ?? null)
            && hash_equals(
                (string) $reservation->panel_identity,
                $panelIdentity
            )
            && $nodeId !== null
            && $payloadNodeId === $nodeId
            && $locationId !== null
            && $payloadLocationId === $locationId
            && $payloadLocationId === $snapshotLocation
            && $externalServerId !== null
            && $payloadExternalServerId === $externalServerId
            && $externalUserId !== null
            && $payloadExternalUserId === $externalUserId
            && is_string($externalServerUuid)
            && Str::isUuid($externalServerUuid)
            && is_string($reservation->external_server_uuid ?? null)
            && hash_equals(
                (string) $reservation->external_server_uuid,
                $externalServerUuid
            )
            && is_string($externalServerIdentifier)
            && trim($externalServerIdentifier) !== ''
            && is_string(
                $reservation->external_server_identifier ?? null
            )
            && hash_equals(
                (string) $reservation->external_server_identifier,
                $externalServerIdentifier
            )
            && is_string($externalServerExternalId)
            && hash_equals(
                (string) $upgradeServiceId,
                $externalServerExternalId
            )
            && is_string($userExternalId)
            && hash_equals(
                "paymenter-user-{$serviceUserId}",
                $userExternalId
            )
            && is_string($userEmail)
            && trim($userEmail) !== ''
            && $nestId !== null
            && $eggId !== null
            && $allocationId !== null
            && $assignedAllocationIds !== null
            && in_array($allocationId, $assignedAllocationIds, true)
            && $preservedBuild !== null
            && $target === $rowTarget
            && $delta === $rowDelta
            && $this->checkoutIdentityMatchesUpgrade(
                $reservation,
                $payload,
                $upgradeServiceId
            );
    }

    private function isLatestCompletedUpgrade(
        ServiceUpgrade $upgrade
    ): bool {
        return (string) $upgrade->status === ServiceUpgrade::STATUS_COMPLETED
            && ! ServiceUpgrade::query()
                ->where('service_id', $upgrade->service_id)
                ->where('status', ServiceUpgrade::STATUS_COMPLETED)
                ->where('id', '>', $upgrade->id)
                ->exists();
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
        } catch (JsonException) {
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
                $this->canonicalizeUpgradeSnapshot($snapshot),
                JSON_THROW_ON_ERROR
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * ServiceUpgrade deliberately preserves numeric representation while
     * recursively sorting associative maps. Checkout fingerprints use the
     * separate canonicalizer above, which normalizes integral JSON floats.
     *
     * @param  array<string|int, mixed>  $value
     * @return array<string|int, mixed>
     */
    private function canonicalizeUpgradeSnapshot(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalizeUpgradeSnapshot($item);
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

    private function upgradeLifecycleMatches(
        string $reservationStatus,
        string $upgradeStatus,
        ?int $reservationGuard,
        ?int $upgradeGuard,
        int $upgradeId,
        int $serviceId,
        mixed $consumedAt,
        mixed $completedAt
    ): bool {
        if ($reservationStatus === 'pending') {
            return in_array(
                $upgradeStatus,
                [
                    ServiceUpgrade::STATUS_PENDING,
                    ServiceUpgrade::STATUS_AWAITING_PAYMENT,
                ],
                true
            )
                && $reservationGuard === $upgradeId
                && $upgradeGuard === $serviceId
                && $consumedAt === null
                && $completedAt === null;
        }
        if ($reservationStatus === 'paid_committed') {
            return in_array(
                $upgradeStatus,
                [
                    ServiceUpgrade::STATUS_PAID_COMMITTED,
                    ServiceUpgrade::STATUS_PROVISIONING,
                    ServiceUpgrade::STATUS_RETRYABLE_FAILED,
                    ServiceUpgrade::STATUS_NEEDS_ATTENTION,
                ],
                true
            )
                && $reservationGuard === $upgradeId
                && $upgradeGuard === $serviceId
                && $consumedAt === null
                && $completedAt === null;
        }

        return $reservationStatus === 'confirmed'
            && $upgradeStatus === ServiceUpgrade::STATUS_COMPLETED
            && $reservationGuard === null
            && $upgradeGuard === null
            && $consumedAt !== null
            && $completedAt !== null;
    }

    private function upgradeFingerprint(
        ServiceUpgrade $upgrade,
        array $payload
    ): string {
        return hash('sha256', json_encode(
            $this->canonicalizeUpgradeSnapshot([
                'upgrade_id' => (int) $upgrade->id,
                'source_fingerprint' => (string) $upgrade->source_fingerprint,
                'target_fingerprint' => (string) $upgrade->target_fingerprint,
                'panel_identity' => $payload['panel_identity'],
                'node_id' => $payload['node_id'],
                'location_id' => $payload['location_id'],
                'external_server_id' => $payload['external_server_id'],
                'external_server_uuid' => $payload['external_server_uuid'],
                'external_server_identifier' => $payload['external_server_identifier'],
                'external_server_external_id' => $payload['external_server_external_id'],
                'external_user_id' => $payload['external_user_id'],
                'user_external_id' => $payload['user_external_id'],
                'user_email' => $payload['user_email'],
                'nest_id' => $payload['nest_id'],
                'egg_id' => $payload['egg_id'],
                'preserved_build' => $payload['preserved_build'],
                'allocation_id' => $payload['allocation_id'],
                'assigned_allocation_ids' => $payload['assigned_allocation_ids'],
                'source' => $payload['source'],
                'target' => $payload['target'],
                'delta' => $payload['delta'],
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

    private function upgradePricingVersion(
        ServiceUpgrade $upgrade
    ): string {
        return hash('sha256', json_encode([
            'quoted_amount' => $this->normalizedMoney(
                $upgrade->quoted_amount
            ),
            'currency_code' => strtoupper((string) $upgrade->currency_code),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function upgradeInvoiceMatches(
        ServiceUpgrade $upgrade,
        string $reservationStatus,
        string $quotedAmount,
        ?int $serviceUserId,
        string $serviceCurrency
    ): bool {
        if (! $this->moneyIsPositive($quotedAmount)) {
            return $upgrade->invoice_id === null;
        }

        $invoice = $upgrade->invoice;
        if (
            $invoice === null
            || $serviceUserId === null
            || (int) $invoice->user_id !== $serviceUserId
            || strtoupper((string) $invoice->currency_code)
                !== $serviceCurrency
            || (
                $reservationStatus === 'pending'
                    ? $invoice->status !== 'pending'
                    : $invoice->status !== 'paid'
            )
        ) {
            return false;
        }

        $items = $invoice->items()
            ->orderBy('id')
            ->get();
        if ($items->count() !== 1) {
            return false;
        }
        $item = $items->first();

        try {
            $lineAmount = $this->normalizedMoney($item->price);
        } catch (Throwable) {
            return false;
        }

        return $item->reference_type === ServiceUpgrade::class
            && (int) $item->reference_id === (int) $upgrade->id
            && (int) $item->quantity === 1
            && $lineAmount === $quotedAmount;
    }

    /**
     * The upgrade is an in-place mutation of a server created by checkout.
     * Re-prove the immutable server, panel, user, nest, and egg identity rather
     * than trusting a self-consistent upgrade payload in isolation.
     *
     * @param  array<string, mixed>  $payload
     */
    private function checkoutIdentityMatchesUpgrade(
        object $reservation,
        array $payload,
        int $serviceId
    ): bool {
        $checkout = DB::table('ptero_resource_reservations')
            ->where('purpose', 'checkout')
            ->where('service_id', $serviceId)
            ->where('status', 'confirmed')
            ->orderByDesc('id')
            ->first();
        if ($checkout === null) {
            return false;
        }

        try {
            $checkoutPayload = json_decode(
                (string) $checkout->configuration_payload,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return false;
        }
        if (! is_array($checkoutPayload)) {
            return false;
        }
        $identity = $checkoutPayload['provisioning_identity'] ?? null;
        if (! is_array($identity)) {
            return false;
        }

        $checkoutNest = $this->positiveInteger(
            $identity['nest_id'] ?? null
        );
        $checkoutEgg = $this->positiveInteger(
            $identity['egg_id'] ?? null
        );
        $checkoutUserExternalId =
            $identity['user_external_id'] ?? null;
        $checkoutUserEmail = $identity['user_email'] ?? null;

        return is_string($checkout->configuration_fingerprint)
            && preg_match(
                '/^[a-f0-9]{64}$/D',
                $checkout->configuration_fingerprint
            ) === 1
            && hash_equals(
                $checkout->configuration_fingerprint,
                $this->fingerprint($checkoutPayload)
            )
            && (int) $checkout->service_id === $serviceId
            && (int) $checkout->user_id === (int) $reservation->user_id
            && (int) $checkout->product_id
                === (int) $reservation->product_id
            && (int) $checkout->plan_id === (int) $reservation->plan_id
            && (int) $checkout->server_extension_id
                === (int) $reservation->server_extension_id
            && is_string($checkout->panel_identity)
            && is_string($reservation->panel_identity ?? null)
            && hash_equals(
                $checkout->panel_identity,
                (string) $reservation->panel_identity
            )
            && (int) $checkout->external_server_id
                === (int) $reservation->external_server_id
            && (int) $checkout->external_user_id
                === (int) $reservation->external_user_id
            && is_string($checkout->external_server_uuid)
            && is_string($reservation->external_server_uuid ?? null)
            && hash_equals(
                $checkout->external_server_uuid,
                (string) $reservation->external_server_uuid
            )
            && is_string($checkout->external_server_identifier)
            && is_string(
                $reservation->external_server_identifier ?? null
            )
            && hash_equals(
                $checkout->external_server_identifier,
                (string) $reservation->external_server_identifier
            )
            && $checkoutNest !== null
            && $checkoutNest === $this->positiveInteger(
                $payload['nest_id'] ?? null
            )
            && $checkoutEgg !== null
            && $checkoutEgg === $this->positiveInteger(
                $payload['egg_id'] ?? null
            )
            && is_string($checkoutUserExternalId)
            && is_string($payload['user_external_id'] ?? null)
            && hash_equals(
                $checkoutUserExternalId,
                $payload['user_external_id']
            )
            && is_string($checkoutUserEmail)
            && is_string($payload['user_email'] ?? null)
            && hash_equals(
                strtolower(trim($checkoutUserEmail)),
                strtolower(trim($payload['user_email']))
            );
    }

    private function productPanelMatchesUpgrade(
        ServiceUpgrade $upgrade,
        object $reservation
    ): bool {
        $server = $upgrade->product?->server;
        if (
            $server === null
            || $server->extension !== 'Pterodactyl'
            || ! is_string($reservation->panel_identity ?? null)
        ) {
            return false;
        }

        try {
            $settings = ExtensionHelper::settingsToArray(
                $server->settings
            );
            $panelIdentity = PanelEndpointIdentity::hash(
                trim((string) ($settings['host'] ?? ''))
            );
        } catch (Throwable) {
            return false;
        }

        return hash_equals(
            (string) $reservation->panel_identity,
            $panelIdentity
        );
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
        } catch (JsonException) {
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

    private function normalizedMoney(mixed $value): string
    {
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $text = number_format($value, 2, '.', '');
        } elseif (is_string($value)) {
            $text = $value;
        } else {
            throw new UnexpectedValueException(
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
            throw new UnexpectedValueException(
                'The upgrade quote amount is invalid.'
            );
        }

        $fraction = $matches[3] ?? '';
        if (
            strlen($fraction) > 2
            && trim(substr($fraction, 2), '0') !== ''
        ) {
            throw new UnexpectedValueException(
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
};
