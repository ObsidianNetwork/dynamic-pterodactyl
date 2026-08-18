<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Exceptions\InvalidStockConfigurationException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\NodeCapacityPolicy;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;

class ResourceCalculationService
{
    private ?PterodactylInventoryService $inventory;

    private ReservationConfigurationService $configuration;

    private UpgradeReservationIntegrityService $upgradeIntegrity;

    public function __construct(
        ?PterodactylInventoryService $inventory = null,
        ?ReservationConfigurationService $configuration = null,
        ?UpgradeReservationIntegrityService $upgradeIntegrity = null
    ) {
        $this->inventory = $inventory;
        $this->configuration = $configuration
            ?? new ReservationConfigurationService;
        $this->upgradeIntegrity = $upgradeIntegrity
            ?? new UpgradeReservationIntegrityService;
    }

    /**
     * Build current stock for every node in a location.
     *
     * Memory and disk allocation use the greater of NodeTransformer's
     * allocated_resources and the complete server index. This is deliberately
     * conservative while Pterodactyl's independently-read node and server
     * snapshots converge after a create or update. CPU comes from the server
     * index and a local, explicit per-node capacity policy. Any missing or
     * ambiguous inventory makes that node ineligible rather than optimistic.
     *
     * @return array<string, mixed>
     */
    public function getLocationAvailability(
        int $locationId,
        ?string $excludeReservationToken = null
    ): array {
        $nodes = $this->inventory()->nodesInLocation($locationId);

        return $this->buildLocationAvailability(
            $locationId,
            $nodes,
            $excludeReservationToken
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildClusterSnapshot(): array
    {
        $snapshot = $this->emptyClusterSnapshot();

        try {
            $locations = $this->inventory()->locations();
            $nodes = $this->inventory()->nodes();
        } catch (\RuntimeException $exception) {
            if ($this->shouldReturnDegradedSnapshot($exception)) {
                return $this->degradedClusterSnapshot();
            }

            throw $exception;
        }

        $snapshot['locations'] = $locations;
        $nodesByLocation = collect($nodes)->groupBy('location_id');

        foreach ($locations as $location) {
            $locationId = (int) $location['id'];
            $locationAvailability = $this->buildLocationAvailability(
                $locationId,
                $nodesByLocation->get($locationId, collect())->values()->all()
            );

            $snapshot['by_location'][$locationId] = [
                'nodes' => array_column($locationAvailability['nodes'], 'node_id'),
                'totals' => $locationAvailability['total_capacity'],
                'allocated' => $locationAvailability['total_allocated'],
                'available' => $locationAvailability['total_available'],
            ];

            foreach ($locationAvailability['nodes'] as $node) {
                $snapshot['nodes'][$node['node_id']] = [
                    'node' => [
                        'id' => $node['node_id'],
                        'uuid' => $node['node_uuid'],
                        'name' => $node['name'],
                        'fqdn' => $node['fqdn'],
                        'public' => $node['public'],
                        'maintenance_mode' => $node['maintenance_mode'],
                        'location_id' => $locationId,
                    ],
                    'location_id' => $locationId,
                    'servers' => $node['servers'],
                    'totals' => $node['total'],
                    'allocated' => $node['allocated'],
                    'available' => $node['available'],
                    'reserved' => $node['reserved'],
                    'server_count' => $node['server_count'],
                    'utilization' => $node['utilization'],
                    'node_availability' => $node,
                ];
            }
        }

        return $snapshot;
    }

    /**
     * Verify the complete resource vector against one currently eligible node.
     *
     * @param  array{memory: int, cpu: int, disk: int}  $requirements
     */
    public function verifyAvailability(
        int $nodeId,
        array $requirements,
        ?string $excludeReservationToken = null
    ): bool {
        return $this->verifyNodeCapacity(
            $nodeId,
            $requirements,
            1,
            $excludeReservationToken
        );
    }

    /**
     * Verify a capacity vector on a fixed node. Existing-server upgrades call
     * this with allocationCount=0 because their primary port remains assigned.
     *
     * @param  array{memory: int, cpu: int, disk: int}  $requirements
     */
    public function verifyNodeCapacity(
        int $nodeId,
        array $requirements,
        int $allocationCount = 1,
        ?string $excludeReservationToken = null
    ): bool {
        if ($allocationCount < 0) {
            throw new \InvalidArgumentException('Allocation count cannot be negative.');
        }

        foreach (['memory', 'cpu', 'disk'] as $resource) {
            if (
                ! array_key_exists($resource, $requirements)
                || ! is_int($requirements[$resource])
                || $requirements[$resource] < 0
            ) {
                throw new \InvalidArgumentException(
                    'Resource requirements must be non-negative integers.'
                );
            }
        }

        $availability = $this->getNodeAvailability(
            $nodeId,
            $excludeReservationToken
        );
        if ($availability === null) {
            return false;
        }

        $blockingReasons = array_values(array_filter(
            $availability['ineligible_reasons'],
            fn (string $reason): bool => $allocationCount > 0
                || $reason !== 'no_available_allocation'
        ));

        return $blockingReasons === []
            && $availability['available']['memory'] >= $requirements['memory']
            && $availability['available']['cpu'] >= $requirements['cpu']
            && $availability['available']['disk'] >= $requirements['disk']
            && count($availability['available_allocations']) >= $allocationCount;
    }

    /**
     * Internal stock detail for fixed-node fulfillment and upgrade decisions.
     *
     * @return array<string, mixed>|null
     */
    public function getNodeAvailability(
        int $nodeId,
        ?string $excludeReservationToken = null
    ): ?array {
        $node = collect($this->inventory()->nodes())->firstWhere('id', $nodeId);
        if (! is_array($node)) {
            return null;
        }

        $location = $this->buildLocationAvailability(
            (int) $node['location_id'],
            [$node],
            $excludeReservationToken
        );

        return $location['nodes'][0] ?? null;
    }

    /**
     * @return list<array{id: int, short: string, long: string}>
     */
    public function getLocations(): array
    {
        return $this->inventory()->locations();
    }

    public function testConnection(): array
    {
        return $this->inventory()->testConnection();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    private function buildLocationAvailability(
        int $locationId,
        array $nodes,
        ?string $excludeReservationToken = null
    ): array {
        $nodeIds = array_map(fn (array $node): int => (int) $node['id'], $nodes);
        $servers = $this->inventory()->serversForNodes($nodeIds);
        $reservations = $this->holdingReservations(
            $nodeIds,
            $servers,
            $excludeReservationToken
        );
        $reservedAllocationClaims = $this->reservedAllocationClaims(
            $nodeIds,
            $excludeReservationToken
        );
        $policies = $this->cpuPolicies($nodes);

        $location = [
            'location_id' => $locationId,
            'nodes' => [],
            'max_available' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            'total_capacity' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            'total_allocated' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            'total_available' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            'cpu_capacity_enforced' => true,
        ];

        foreach ($nodes as $node) {
            $nodeId = (int) $node['id'];
            $nodeServers = $servers[$nodeId] ?? [];
            $assignedServerAllocationIds = collect($nodeServers)
                ->flatMap(
                    fn (array $server): array => $server['assigned_allocation_ids'] ?? []
                )
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $inventoryAllocations =
                $this->inventory()->availableAllocationsForNode($nodeId);
            $assignedServerIps = collect($inventoryAllocations)
                ->filter(
                    fn (array $allocation): bool => in_array(
                        (int) $allocation['id'],
                        $assignedServerAllocationIds,
                        true
                    )
                )
                ->map(
                    fn (array $allocation): string => $this->canonicalIp((string) $allocation['ip'])
                )
                ->unique()
                ->values()
                ->all();
            $nodeClaims = $reservedAllocationClaims[$nodeId] ?? [
                'ids' => [],
                'ips' => [],
                'blocked_ips' => [],
            ];
            $blockedAllocationIds = [
                ...$nodeClaims['ids'],
                ...$assignedServerAllocationIds,
            ];
            $availableAllocations = array_values(array_map(
                fn (array $allocation): array => [
                    ...$allocation,
                    'ip_in_use' => (bool) (
                        $allocation['ip_in_use'] ?? false
                    ) || in_array(
                        $this->canonicalIp((string) $allocation['ip']),
                        $nodeClaims['ips'],
                        true
                    ) || in_array(
                        $this->canonicalIp((string) $allocation['ip']),
                        $assignedServerIps,
                        true
                    ),
                ],
                array_filter(
                    $inventoryAllocations,
                    fn (array $allocation): bool => ! in_array(
                        (int) $allocation['id'],
                        $blockedAllocationIds,
                        true
                    )
                        && ! in_array(
                            $this->canonicalIp((string) $allocation['ip']),
                            $nodeClaims['blocked_ips'],
                            true
                        )
                )
            ));
            $nodeAvailability = $this->buildNodeAvailability(
                $node,
                $nodeServers,
                $reservations[$nodeId] ?? $this->emptyResources(),
                $availableAllocations,
                $policies[(string) $node['uuid']] ?? null
            );

            $location['nodes'][] = $nodeAvailability;

            foreach (['memory', 'cpu', 'disk'] as $resource) {
                $location['total_capacity'][$resource] += $nodeAvailability['total'][$resource];
                $location['total_allocated'][$resource] += $nodeAvailability['allocated'][$resource];
                $location['total_available'][$resource] += $nodeAvailability['available'][$resource];

                if ($nodeAvailability['eligible']) {
                    $location['max_available'][$resource] = max(
                        $location['max_available'][$resource],
                        $nodeAvailability['available'][$resource]
                    );
                }
            }

            if (! $nodeAvailability['cpu_capacity_enforced']) {
                $location['cpu_capacity_enforced'] = false;
            }
        }

        return $location;
    }

    /**
     * @param  list<array{
     *     id: int,
     *     node: int,
     *     memory: int,
     *     cpu: int,
     *     disk: int,
     *     allocation_limit: int,
     *     assigned_allocation_ids: list<int>,
     *     allocation_headroom: int
     * }>  $servers
     * @param  array{memory: int, cpu: int, disk: int}  $reserved
     * @param  list<array{id: int, ip: string, port: int, ip_in_use?: bool}>  $availableAllocations
     * @return array<string, mixed>
     */
    private function buildNodeAvailability(
        array $node,
        array $servers,
        array $reserved,
        array $availableAllocations,
        ?NodeCapacityPolicy $cpuPolicy
    ): array {
        $memoryOverallocate = (int) $node['memory_overallocate'];
        $diskOverallocate = (int) $node['disk_overallocate'];
        $totalMemory = $this->effectiveCapacity(
            (int) $node['memory'],
            $memoryOverallocate
        );
        $totalDisk = $this->effectiveCapacity(
            (int) $node['disk'],
            $diskOverallocate
        );
        $cpuPolicyIdentityMatches = $cpuPolicy !== null
            && (int) $cpuPolicy->node_id === (int) $node['id']
            && (int) $cpuPolicy->location_id
                === (int) $node['location_id'];
        $totalCpu = $cpuPolicyIdentityMatches
            ? $cpuPolicy->effectiveCpuCapacity()
            : 0;
        $serverAllocatedMemory = $this->sumServerResource($servers, 'memory');
        $allocatedCpu = $this->sumServerResource($servers, 'cpu');
        $serverAllocatedDisk = $this->sumServerResource($servers, 'disk');

        $allocated = [
            'memory' => max(
                (int) $node['allocated_resources']['memory'],
                $serverAllocatedMemory
            ),
            'cpu' => $allocatedCpu,
            'disk' => max(
                (int) $node['allocated_resources']['disk'],
                $serverAllocatedDisk
            ),
        ];
        $available = [
            'memory' => max(0, $totalMemory - $allocated['memory'] - $reserved['memory']),
            'cpu' => max(0, $totalCpu - $allocated['cpu'] - $reserved['cpu']),
            'disk' => max(0, $totalDisk - $allocated['disk'] - $reserved['disk']),
        ];

        $reasons = [];
        if ($node['public'] !== true) {
            $reasons[] = 'private_node';
        }
        if ($node['maintenance_mode'] === true) {
            $reasons[] = 'maintenance_mode';
        }
        if ($memoryOverallocate < 0) {
            // Pterodactyl uses -1 to disable its memory allocation limit.
            // An unbounded panel cannot provide authoritative finite stock.
            $reasons[] = 'unbounded_memory_overallocation';
        }
        if ($diskOverallocate < 0) {
            // Pterodactyl uses -1 to disable its disk allocation limit.
            // An unbounded panel cannot provide authoritative finite stock.
            $reasons[] = 'unbounded_disk_overallocation';
        }
        if ($cpuPolicy !== null && ! $cpuPolicyIdentityMatches) {
            $reasons[] = 'cpu_policy_identity_mismatch';
        } elseif (
            $cpuPolicy === null
            || ! $cpuPolicy->enabled
            || $totalCpu <= 0
        ) {
            $reasons[] = 'cpu_policy_missing';
        }
        if (collect($servers)->contains(
            fn (array $server): bool => (int) $server['memory'] <= 0
                || (int) $server['cpu'] <= 0
                || (int) $server['disk'] <= 0
        )) {
            $reasons[] = 'unlimited_existing_resource';
        }
        if (collect($servers)->contains(
            fn (array $server): bool => (int) (
                $server['allocation_limit'] ?? 0
            ) > 0
        )) {
            // Exact port guarantees require Paymenter to be the only allocation
            // authority. Any nonzero client allocation limit enables the
            // customer-managed allocation workflow, even if the currently
            // assigned set has no immediate headroom.
            $reasons[] = 'customer_allocation_management';
        }
        if ($availableAllocations === []) {
            $reasons[] = 'no_available_allocation';
        }

        return [
            'node_id' => (int) $node['id'],
            'node_uuid' => (string) $node['uuid'],
            'name' => (string) $node['name'],
            'fqdn' => (string) $node['fqdn'],
            'public' => (bool) $node['public'],
            'maintenance_mode' => (bool) $node['maintenance_mode'],
            'eligible' => $reasons === [],
            'ineligible_reasons' => $reasons,
            'total' => [
                'memory' => $totalMemory,
                'cpu' => $totalCpu,
                'disk' => $totalDisk,
            ],
            'allocated' => $allocated,
            'reserved' => $reserved,
            'available' => $available,
            'available_allocations' => $availableAllocations,
            'server_count' => count($servers),
            'servers' => $servers,
            'cpu_capacity_enforced' => $cpuPolicy !== null
                && $cpuPolicyIdentityMatches
                && $cpuPolicy->enabled
                && $totalCpu > 0
                && ! collect($servers)->contains(
                    fn (array $server): bool => (int) $server['cpu'] <= 0
                ),
            'utilization' => [
                'memory' => $this->utilization(
                    $allocated['memory'] + $reserved['memory'],
                    $totalMemory
                ),
                'cpu' => $this->utilization(
                    $allocated['cpu'] + $reserved['cpu'],
                    $totalCpu
                ),
                'disk' => $this->utilization(
                    $allocated['disk'] + $reserved['disk'],
                    $totalDisk
                ),
            ],
        ];
    }

    private function effectiveCapacity(int $physical, int $overallocatePercent): int
    {
        if ($overallocatePercent < 0) {
            // Keep diagnostics finite while the ineligibility reason above
            // prevents this node from participating in quotes or placement.
            return max(0, $physical);
        }

        if ($overallocatePercent > PHP_INT_MAX - 100) {
            throw new \RuntimeException('Pterodactyl overallocation value is outside the supported range.');
        }

        $factor = max(0, 100 + $overallocatePercent);
        if ($factor > 0 && $physical > intdiv(PHP_INT_MAX, $factor)) {
            throw new \RuntimeException('Pterodactyl effective capacity exceeds the supported range.');
        }

        return max(0, intdiv($physical * $factor, 100));
    }

    private function utilization(int $used, int $total): float
    {
        return $total > 0 ? round($used / $total * 100, 1) : 100.0;
    }

    /**
     * @param  list<array<string, mixed>>  $servers
     */
    private function sumServerResource(array $servers, string $resource): int
    {
        $sum = 0;

        foreach ($servers as $server) {
            $value = (int) ($server[$resource] ?? 0);
            if ($value < 0 || $value > PHP_INT_MAX - $sum) {
                throw new \RuntimeException(
                    "Pterodactyl {$resource} allocation exceeds the supported range."
                );
            }
            $sum += $value;
        }

        return $sum;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, NodeCapacityPolicy>
     */
    private function cpuPolicies(array $nodes): array
    {
        $uuids = array_values(array_map(
            fn (array $node): string => (string) $node['uuid'],
            $nodes
        ));

        if ($uuids === []) {
            return [];
        }

        return NodeCapacityPolicy::query()
            ->forPanel($this->inventory()->panelIdentity())
            ->whereIn('node_uuid', $uuids)
            ->get()
            ->keyBy('node_uuid')
            ->all();
    }

    /**
     * @param  list<int>  $nodeIds
     *                              Confirmed commitments remain in the local overlay until the independently
     *                              read server snapshot proves the exact target vector. This closes the
     *                              handoff window where the local row is consumed before every Pterodactyl
     *                              inventory endpoint reflects the create or update.
     * @param  array<int, list<array<string, mixed>>>  $servers
     * @return array<int, array{memory: int, cpu: int, disk: int}>
     */
    private function holdingReservations(
        array $nodeIds,
        array $servers,
        ?string $excludeReservationToken
    ): array {
        if ($nodeIds === []) {
            return [];
        }

        $query = DB::table('ptero_resource_reservations as reservations')
            ->leftJoin(
                'services',
                'services.id',
                '=',
                'reservations.service_id'
            )
            ->where(
                'reservations.panel_identity',
                $this->inventory()->panelIdentity()
            )
            ->whereIn('reservations.node_id', $nodeIds)
            ->where(function ($query): void {
                // A pending row keeps holding stock until cleanup atomically
                // changes its status and releases its allocation claims.
                $query->where(
                    'reservations.status',
                    ResourceReservation::STATUS_PENDING
                )->orWhere(
                    'reservations.status',
                    ResourceReservation::STATUS_PAID_COMMITTED
                )->orWhere(function ($query): void {
                    $query->where(
                        'reservations.status',
                        ResourceReservation::STATUS_CONFIRMED
                    )->where(function ($query): void {
                        $query->whereNull('services.status')
                            ->orWhere('services.status', '!=', 'cancelled');
                    });
                });
            });

        if ($excludeReservationToken !== null) {
            $query->where(
                'reservations.token',
                '!=',
                $excludeReservationToken
            );
        }

        $rows = $query->get([
            'reservations.id',
            'reservations.node_id',
            'reservations.service_id',
            'reservations.service_upgrade_id',
            'reservations.purpose',
            'reservations.status',
            'reservations.external_server_id',
            'reservations.external_server_uuid',
            'reservations.external_server_identifier',
            'reservations.memory',
            'reservations.cpu',
            'reservations.disk',
            'reservations.reserved_memory',
            'reservations.reserved_cpu',
            'reservations.reserved_disk',
            'reservations.consumed_at',
            'reservations.created_at',
            'reservations.updated_at',
        ]);

        $totals = [];
        $confirmedByService = [];

        foreach ($rows as $reservation) {
            if ($reservation->status === ResourceReservation::STATUS_CONFIRMED) {
                $key = $reservation->service_id === null
                    ? 'reservation:'.(int) $reservation->id
                    : 'service:'.(int) $reservation->service_id;
                $confirmedByService[$key][] = $reservation;

                continue;
            }

            $nodeId = (int) $reservation->node_id;
            $isUpgrade = $reservation->purpose === 'upgrade';
            $this->addReservedResources($totals, $nodeId, [
                'memory' => (int) (
                    $isUpgrade
                        ? $reservation->reserved_memory
                        : $reservation->memory
                ),
                'cpu' => (int) (
                    $isUpgrade
                        ? $reservation->reserved_cpu
                        : $reservation->cpu
                ),
                'disk' => (int) (
                    $isUpgrade
                        ? $reservation->reserved_disk
                        : $reservation->disk
                ),
            ]);
        }

        foreach ($confirmedByService as $expectations) {
            $target = $this->latestConfirmedTarget($expectations);
            $nodeId = (int) $target->node_id;
            $this->addReservedResources(
                $totals,
                $nodeId,
                $this->confirmedExpectationOverlay(
                    $target,
                    $expectations,
                    $servers[$nodeId] ?? []
                )
            );
        }

        return $totals;
    }

    /**
     * A completed upgrade supersedes the checkout vector and every older
     * upgrade for the same service. Upgrades are created serially under the
     * one-active-upgrade guard, so the immutable ServiceUpgrade identity is
     * authoritative. Reservation timestamps are operational metadata and
     * must not be able to move an older target ahead of a newer one.
     *
     * @param  list<object>  $expectations
     */
    private function latestConfirmedTarget(array $expectations): object
    {
        $upgrades = array_values(array_filter(
            $expectations,
            fn (object $row): bool => $row->purpose === 'upgrade'
        ));
        $candidates = $upgrades === [] ? $expectations : $upgrades;

        usort(
            $candidates,
            fn (object $left, object $right): int => [
                (int) ($left->service_upgrade_id ?? 0),
                (int) $left->id,
            ] <=> [
                (int) ($right->service_upgrade_id ?? 0),
                (int) $right->id,
            ]
        );

        return $candidates[array_key_last($candidates)];
    }

    /**
     * The same server-list snapshot used by aggregate allocation must prove
     * the pinned identity, node, and complete target vector. If a resize is
     * visible but still converging, only the positive component deficit is
     * overlaid. If identity is absent or ambiguous, the full target stays held.
     *
     * @param  list<object>  $expectations
     * @param  list<array<string, mixed>>  $servers
     * @return array{memory: int, cpu: int, disk: int}
     */
    private function confirmedExpectationOverlay(
        object $target,
        array $expectations,
        array $servers
    ): array {
        $identity = $this->confirmedExternalIdentity($expectations);
        if ($identity['ambiguous'] || $identity['id'] <= 0) {
            return $this->targetResources($target);
        }

        $matches = array_values(array_filter(
            $servers,
            function (array $server) use ($identity, $target): bool {
                return (int) ($server['id'] ?? 0) === $identity['id']
                    && (
                        $identity['uuid'] === null
                        || ($server['uuid'] ?? null) === $identity['uuid']
                    )
                    && (
                        $identity['identifier'] === null
                        || ($server['identifier'] ?? null)
                            === $identity['identifier']
                    )
                    && (
                        array_key_exists('external_id', $server)
                        && (string) ($server['external_id'] ?? '')
                            === (string) ($target->service_id ?? '')
                    );
            }
        ));
        if (count($matches) !== 1) {
            return $this->targetResources($target);
        }

        $overlay = [];
        foreach (['memory', 'cpu', 'disk'] as $resource) {
            $overlay[$resource] = max(
                0,
                (int) $target->{$resource}
                    - (int) ($matches[0][$resource] ?? 0)
            );
        }

        return $overlay;
    }

    /**
     * @param  list<object>  $expectations
     * @return array{
     *     id: int,
     *     uuid: ?string,
     *     identifier: ?string,
     *     ambiguous: bool
     * }
     */
    private function confirmedExternalIdentity(
        array $expectations
    ): array {
        $ids = [];
        $uuids = [];
        $identifiers = [];

        foreach ($expectations as $expectation) {
            // External server identity is materialized only after the
            // Pterodactyl response is reconciled. Never infer it from mutable
            // or unsigned JSON when calculating confirmed stock overlays.
            $id = (int) ($expectation->external_server_id ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
            $uuid = $this->optionalIdentityString(
                $expectation->external_server_uuid ?? null
            );
            if ($uuid !== null) {
                $uuids[$uuid] = true;
            }
            $identifier = $this->optionalIdentityString(
                $expectation->external_server_identifier ?? null
            );
            if ($identifier !== null) {
                $identifiers[$identifier] = true;
            }
        }

        return [
            'id' => count($ids) === 1 ? (int) array_key_first($ids) : 0,
            'uuid' => count($uuids) === 1
                ? (string) array_key_first($uuids)
                : null,
            'identifier' => count($identifiers) === 1
                ? (string) array_key_first($identifiers)
                : null,
            'ambiguous' => count($ids) > 1
                || count($uuids) > 1
                || count($identifiers) > 1,
        ];
    }

    private function optionalIdentityString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    /**
     * @return array{memory: int, cpu: int, disk: int}
     */
    private function targetResources(object $target): array
    {
        return [
            'memory' => (int) $target->memory,
            'cpu' => (int) $target->cpu,
            'disk' => (int) $target->disk,
        ];
    }

    /**
     * @param  array<int, array{memory: int, cpu: int, disk: int}>  $totals
     * @param  array{memory: int, cpu: int, disk: int}  $resources
     */
    private function addReservedResources(
        array &$totals,
        int $nodeId,
        array $resources
    ): void {
        $totals[$nodeId] ??= $this->emptyResources();

        foreach ($resources as $resource => $value) {
            if (
                $value < 0
                || $value > PHP_INT_MAX - $totals[$nodeId][$resource]
            ) {
                throw new \RuntimeException(
                    "Reserved {$resource} exceeds the supported range."
                );
            }
            $totals[$nodeId][$resource] += $value;
        }
    }

    /**
     * @param  list<int>  $nodeIds
     * @return array<int, array{
     *     ids: list<int>,
     *     ips: list<string>,
     *     blocked_ips: list<string>
     * }>
     */
    private function reservedAllocationClaims(
        array $nodeIds,
        ?string $excludeReservationToken
    ): array {
        if ($nodeIds === []) {
            return [];
        }

        // Start from commitments and join every claim by reservation identity
        // only. Claim-side panel, node, and release fields are evidence to
        // validate, never query filters. Validate all active commitments before
        // narrowing to this panel/node so drift cannot move itself out of view.
        $rows = DB::table('ptero_resource_reservations as reservations')
            ->leftJoin(
                'services',
                'services.id',
                '=',
                'reservations.service_id'
            )
            ->leftJoin(
                'ptero_reservation_allocations as allocations',
                'allocations.reservation_id',
                '=',
                'reservations.id'
            )
            ->leftJoin(
                'service_upgrades as upgrades',
                'upgrades.id',
                '=',
                'reservations.service_upgrade_id'
            )
            ->leftJoin(
                'products as upgrade_products',
                'upgrade_products.id',
                '=',
                'upgrades.product_id'
            )
            ->leftJoin(
                'invoices as upgrade_invoices',
                'upgrade_invoices.id',
                '=',
                'upgrades.invoice_id'
            )
            ->where(function ($query): void {
                // TTL expiry alone is not a state transition. Keep the row
                // conservative until cleanup releases stock in one transaction.
                $query->where(
                    'reservations.status',
                    ResourceReservation::STATUS_PENDING
                )->orWhere(
                    'reservations.status',
                    ResourceReservation::STATUS_PAID_COMMITTED
                )->orWhere(function ($query): void {
                    $query->where(
                        'reservations.status',
                        ResourceReservation::STATUS_CONFIRMED
                    )->where(function ($query): void {
                        $query->whereNull('services.status')
                            ->orWhere(
                                'services.status',
                                '!=',
                                'cancelled'
                            );
                    });
                })->orWhere(function ($query): void {
                    // Terminal rows must not retain a database allocation
                    // claim. Include them solely to fail closed until cleanup.
                    $query->whereNotNull('allocations.id')
                        ->whereNull('allocations.released_at');
                });
            })
            ->get([
                'reservations.id as reservation_id',
                'reservations.token',
                'reservations.purpose',
                'reservations.panel_identity',
                'reservations.service_id',
                'reservations.service_upgrade_id',
                'reservations.upgrade_guard_id',
                'reservations.server_extension_id',
                'reservations.invoice_id',
                'reservations.user_id',
                'reservations.product_id',
                'reservations.plan_id',
                'reservations.quantity',
                'reservations.currency_code',
                'reservations.node_id',
                'reservations.location_id',
                'reservations.memory',
                'reservations.cpu',
                'reservations.disk',
                'reservations.reserved_memory',
                'reservations.reserved_cpu',
                'reservations.reserved_disk',
                'reservations.external_server_id',
                'reservations.external_user_id',
                'reservations.external_server_uuid',
                'reservations.external_server_identifier',
                'reservations.calculated_price',
                'reservations.pricing_version',
                'reservations.formula_version',
                'reservations.expires_at',
                'reservations.consumed_at',
                'reservations.status',
                'reservations.configuration_fingerprint',
                'reservations.configuration_payload',
                'services.status as service_status',
                'services.user_id as service_user_id',
                'services.product_id as service_product_id',
                'services.plan_id as service_plan_id',
                'services.quantity as service_quantity',
                'services.currency_code as service_currency_code',
                'allocations.id as claim_row_id',
                'allocations.panel_identity as claim_panel_identity',
                'allocations.node_id as claim_node_id',
                'allocations.allocation_id',
                'allocations.ip',
                'allocations.port',
                'allocations.environment_key',
                'allocations.is_primary',
                'allocations.released_at',
                'upgrades.id as upgrade_id',
                'upgrades.service_id as upgrade_service_id',
                'upgrades.product_id as upgrade_product_id',
                'upgrades.plan_id as upgrade_plan_id',
                'upgrades.invoice_id as upgrade_invoice_id',
                'upgrades.status as upgrade_status',
                'upgrades.active_service_guard_id as upgrade_active_service_guard_id',
                'upgrades.source_snapshot as upgrade_source_snapshot',
                'upgrades.target_snapshot as upgrade_target_snapshot',
                'upgrades.source_fingerprint as upgrade_source_fingerprint',
                'upgrades.target_fingerprint as upgrade_target_fingerprint',
                'upgrades.quoted_amount as upgrade_quoted_amount',
                'upgrades.currency_code as upgrade_currency_code',
                'upgrade_products.server_id as upgrade_product_server_id',
                'upgrade_invoices.status as upgrade_invoice_status',
                'upgrade_invoices.user_id as upgrade_invoice_user_id',
                'upgrade_invoices.currency_code as upgrade_invoice_currency_code',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $result = [];

        foreach ($rows->groupBy('reservation_id') as $reservationRows) {
            $reservation = $reservationRows->first();
            $claims = $reservationRows
                ->filter(
                    fn (object $row): bool => $row->claim_row_id !== null
                )
                ->map(fn (object $row): object => (object) [
                    'reservation_id' => (int) $row->reservation_id,
                    'panel_identity' => (string) $row->claim_panel_identity,
                    'node_id' => (int) $row->claim_node_id,
                    'allocation_id' => (int) $row->allocation_id,
                    'ip' => $row->ip,
                    'port' => (int) $row->port,
                    'environment_key' => $row->environment_key,
                    'is_primary' => (bool) $row->is_primary,
                    'released_at' => $row->released_at,
                ])
                ->values();
            $isActive = $reservation->status
                === ResourceReservation::STATUS_PENDING
                || $reservation->status
                === ResourceReservation::STATUS_PAID_COMMITTED
                || (
                    $reservation->status
                        === ResourceReservation::STATUS_CONFIRMED
                    && $reservation->service_status !== 'cancelled'
                );
            if (! $isActive) {
                throw new InvalidStockConfigurationException(
                    'A terminal capacity commitment still owns an unreleased allocation claim.'
                );
            }

            $payload = null;
            if ($reservation->purpose === 'checkout') {
                $payload = $this->configuration
                    ->verifiedAllocationSnapshot($reservation, $claims);
            } elseif ($reservation->purpose === 'upgrade') {
                if ($claims->isNotEmpty()) {
                    throw new InvalidStockConfigurationException(
                        'A resource upgrade unexpectedly owns checkout allocation claims.'
                    );
                }
                $this->upgradeIntegrity->verifiedSnapshot(
                    (object) [
                        'id' => $reservation->upgrade_id,
                        'service_id' => $reservation->upgrade_service_id,
                        'product_id' => $reservation->upgrade_product_id,
                        'plan_id' => $reservation->upgrade_plan_id,
                        'invoice_id' => $reservation->upgrade_invoice_id,
                        'status' => $reservation->upgrade_status,
                        'active_service_guard_id' => $reservation
                            ->upgrade_active_service_guard_id,
                        'source_snapshot' => $reservation->upgrade_source_snapshot,
                        'target_snapshot' => $reservation->upgrade_target_snapshot,
                        'source_fingerprint' => $reservation->upgrade_source_fingerprint,
                        'target_fingerprint' => $reservation->upgrade_target_fingerprint,
                        'quoted_amount' => $reservation->upgrade_quoted_amount,
                        'currency_code' => $reservation->upgrade_currency_code,
                        'service_user_id' => $reservation->service_user_id,
                        'service_product_id' => $reservation->service_product_id,
                        'service_plan_id' => $reservation->service_plan_id,
                        'service_quantity' => $reservation->service_quantity,
                        'service_currency_code' => $reservation->service_currency_code,
                        'product_server_id' => $reservation->upgrade_product_server_id,
                        'invoice_status' => $reservation->upgrade_invoice_status,
                        'invoice_user_id' => $reservation->upgrade_invoice_user_id,
                        'invoice_currency_code' => $reservation->upgrade_invoice_currency_code,
                    ],
                    $reservation
                );
            } else {
                throw new InvalidStockConfigurationException(
                    'An active capacity commitment has an unknown purpose.'
                );
            }

            if (
                ! hash_equals(
                    (string) $reservation->panel_identity,
                    $this->inventory()->panelIdentity()
                )
                || ! in_array(
                    (int) $reservation->node_id,
                    $nodeIds,
                    true
                )
            ) {
                continue;
            }

            // Self-exclusion changes arithmetic only. The excluded commitment
            // must still pass integrity checks before the UI may reuse its
            // capacity during a cart edit.
            if (
                $excludeReservationToken !== null
                && hash_equals(
                    (string) $reservation->token,
                    $excludeReservationToken
                )
            ) {
                continue;
            }
            if ($reservation->purpose !== 'checkout') {
                continue;
            }

            $nodeId = (int) $reservation->node_id;
            $result[$nodeId] ??= [
                'ids' => [],
                'ips' => [],
                'blocked_ips' => [],
            ];
            $dedicated = data_get(
                $payload,
                'allocation_requirements.dedicated_ip'
            ) === true;
            foreach ($claims as $claim) {
                $allocationId = (int) $claim->allocation_id;
                $ip = (string) ($claim->ip ?? '');
                $result[$nodeId]['ids'][$allocationId] = $allocationId;
                if ($ip === '') {
                    continue;
                }
                $canonicalIp = $this->canonicalIp($ip);
                $result[$nodeId]['ips'][$canonicalIp] = $canonicalIp;
                if ($dedicated) {
                    $result[$nodeId]['blocked_ips'][$canonicalIp]
                        = $canonicalIp;
                }
            }
        }

        foreach ($result as &$claims) {
            $claims['ids'] = array_values($claims['ids']);
            $claims['ips'] = array_values($claims['ips']);
            $claims['blocked_ips'] = array_values(
                $claims['blocked_ips']
            );
            sort($claims['ids'], SORT_NUMERIC);
            sort($claims['ips'], SORT_STRING);
            sort($claims['blocked_ips'], SORT_STRING);
        }
        unset($claims);

        return $result;
    }

    private function canonicalIp(string $ip): string
    {
        $packed = @inet_pton(trim($ip));

        return $packed === false
            ? strtolower(trim($ip))
            : bin2hex($packed);
    }

    /**
     * @return array{memory: int, cpu: int, disk: int}
     */
    private function emptyResources(): array
    {
        return ['memory' => 0, 'cpu' => 0, 'disk' => 0];
    }

    private function emptyClusterSnapshot(): array
    {
        return [
            'locations' => [],
            'nodes' => [],
            'by_location' => [],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function degradedClusterSnapshot(): array
    {
        return $this->emptyClusterSnapshot() + ['error' => 'Pterodactyl unavailable'];
    }

    private function shouldReturnDegradedSnapshot(\Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), 'connection failed')
            || preg_match('/inventory API error \\(5\\d\\d\\)/', $exception->getMessage()) === 1;
    }

    private function inventory(): PterodactylInventoryService
    {
        return $this->inventory ??= app(PterodactylInventoryService::class);
    }
}
