<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\Extension;
use App\Support\PanelEndpointIdentity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PterodactylInventoryService
{
    private string $apiUrl;

    private string $apiKey;

    private bool $exclusiveProvisioningControl;

    /**
     * Request-local node snapshot. NodeTransformer can include every
     * allocation, so one paginated node read replaces an N+1 allocation scan
     * across locations while reservations are still overlaid from the local DB.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $nodeSnapshot = null;

    /**
     * The optional configuration argument keeps the production constructor
     * container-friendly while allowing the HTTP contract to be tested without
     * persisting extension settings.
     *
     * @param  array{
     *     pterodactyl_url?: string,
     *     pterodactyl_api_key?: string,
     *     exclusive_provisioning_control?: mixed
     * }|null  $config
     */
    public function __construct(?array $config = null)
    {
        $config ??= $this->extensionConfig();
        $this->apiUrl = $this->normalizePanelUrl((string) ($config['pterodactyl_url'] ?? ''));
        $this->apiKey = trim((string) ($config['pterodactyl_api_key'] ?? ''));
        $this->exclusiveProvisioningControl = filter_var(
            $config['exclusive_provisioning_control'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        if ($this->apiUrl === '' || $this->apiKey === '') {
            throw new \RuntimeException('Pterodactyl inventory credentials are not configured.');
        }
        if (filter_var($this->apiUrl, FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('The Pterodactyl inventory URL is invalid.');
        }
    }

    public function panelIdentity(): string
    {
        return PanelEndpointIdentity::hash($this->apiUrl);
    }

    public function hasExclusiveProvisioningControl(): bool
    {
        return $this->exclusiveProvisioningControl;
    }

    /**
     * A seven-day local hold is a real guarantee only if every server create,
     * move, resize, and allocation assignment on eligible nodes goes through
     * this Paymenter deployment. This is an explicit operational contract;
     * the Pterodactyl API cannot technically prevent a panel administrator.
     */
    public function assertExclusiveProvisioningControl(): void
    {
        if (! $this->exclusiveProvisioningControl) {
            throw new \RuntimeException(
                'Dynamic stock requires administrator-confirmed exclusive provisioning control '
                .'over every eligible Pterodactyl node.'
            );
        }
    }

    /**
     * @return list<array{id: int, short: string, long: string}>
     */
    public function locations(): array
    {
        return array_map(function (array $resource): array {
            $attributes = $this->attributes($resource, 'location');

            return [
                'id' => $this->positiveInteger($attributes['id'] ?? null, 'location.id'),
                'short' => $this->stringValue($attributes['short'] ?? null, 'location.short'),
                'long' => $this->stringValue($attributes['long'] ?? null, 'location.long', true),
            ];
        }, $this->paginated('/api/application/locations'));
    }

    /**
     * Pterodactyl 1.11 does not allow location_id as a NodeController filter.
     * Read every page and apply the location constraint locally.
     *
     * @return list<array<string, mixed>>
     */
    public function nodesInLocation(int $locationId): array
    {
        if ($locationId <= 0) {
            throw new \InvalidArgumentException('A positive Pterodactyl location ID is required.');
        }

        return array_values(array_filter(
            $this->nodes(),
            fn (array $node): bool => $node['location_id'] === $locationId
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function nodes(): array
    {
        if ($this->nodeSnapshot !== null) {
            return $this->nodeSnapshot;
        }

        $this->nodeSnapshot = array_map(function (array $resource): array {
            $attributes = $this->attributes($resource, 'node');
            $allocated = $attributes['allocated_resources'] ?? null;

            if (! is_array($allocated)) {
                throw new \RuntimeException(
                    'Pterodactyl node inventory is missing allocated_resources. '
                    .'Grant the application key node read permission and use Pterodactyl 1.11 or newer.'
                );
            }

            return [
                'id' => $this->positiveInteger($attributes['id'] ?? null, 'node.id'),
                'uuid' => $this->stringValue($attributes['uuid'] ?? null, 'node.uuid'),
                'name' => $this->stringValue($attributes['name'] ?? null, 'node.name'),
                'fqdn' => $this->stringValue($attributes['fqdn'] ?? null, 'node.fqdn'),
                'public' => $this->booleanValue($attributes['public'] ?? null, 'node.public'),
                'maintenance_mode' => $this->booleanValue(
                    $attributes['maintenance_mode'] ?? null,
                    'node.maintenance_mode'
                ),
                'location_id' => $this->positiveInteger(
                    $attributes['location_id'] ?? null,
                    'node.location_id'
                ),
                'memory' => $this->nonNegativeInteger($attributes['memory'] ?? null, 'node.memory'),
                'disk' => $this->nonNegativeInteger($attributes['disk'] ?? null, 'node.disk'),
                'memory_overallocate' => $this->integerValue(
                    $attributes['memory_overallocate'] ?? null,
                    'node.memory_overallocate'
                ),
                'disk_overallocate' => $this->integerValue(
                    $attributes['disk_overallocate'] ?? null,
                    'node.disk_overallocate'
                ),
                'allocated_resources' => [
                    'memory' => $this->nonNegativeInteger(
                        $allocated['memory'] ?? null,
                        'node.allocated_resources.memory'
                    ),
                    'disk' => $this->nonNegativeInteger(
                        $allocated['disk'] ?? null,
                        'node.allocated_resources.disk'
                    ),
                ],
                'available_allocations' => $this->availableNodeAllocations(
                    $resource
                ),
            ];
        }, $this->paginated(
            '/api/application/nodes',
            ['include' => 'allocations']
        ));

        return $this->nodeSnapshot;
    }

    /**
     * Server inventory is required for CPU accounting. A missing permission is
     * an upstream error, never an implicit empty-server result.
     *
     * @param  list<int>  $nodeIds
     * @return array<int, list<array{
     *     id: int,
     *     uuid: string,
     *     identifier: string,
     *     external_id: ?string,
     *     node: int,
     *     memory: int,
     *     cpu: int,
     *     disk: int,
     *     allocation_limit: int,
     *     assigned_allocation_ids: list<int>,
     *     allocation_headroom: int
     * }>>
     */
    public function serversForNodes(array $nodeIds): array
    {
        $nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));
        $grouped = array_fill_keys($nodeIds, []);

        if ($nodeIds === []) {
            return [];
        }

        foreach ($this->paginated(
            '/api/application/servers',
            ['include' => 'allocations']
        ) as $resource) {
            $attributes = $this->attributes($resource, 'server');
            $nodeId = $this->positiveInteger($attributes['node'] ?? null, 'server.node');

            if (! array_key_exists($nodeId, $grouped)) {
                continue;
            }

            $limits = $attributes['limits'] ?? null;
            if (! is_array($limits)) {
                throw new \RuntimeException('Pterodactyl server inventory is missing limits.');
            }
            $featureLimits = $attributes['feature_limits'] ?? null;
            if (! is_array($featureLimits)) {
                throw new \RuntimeException(
                    'Pterodactyl server inventory is missing feature_limits.'
                );
            }
            $allocationLimit = $this->nonNegativeInteger(
                $featureLimits['allocations'] ?? null,
                'server.feature_limits.allocations'
            );
            $allocationResources = $resource['relationships']['allocations']['data']
                ?? null;
            if (! is_array($allocationResources) || ! array_is_list($allocationResources)) {
                throw new \RuntimeException(
                    'Pterodactyl server inventory is missing the allocations relationship. '
                    .'Grant the application key allocation read permission.'
                );
            }
            $assignedAllocationIds = array_map(function (array $allocation): int {
                $allocationAttributes = $this->attributes($allocation, 'allocation');

                return $this->positiveInteger(
                    $allocationAttributes['id'] ?? null,
                    'server.allocations.id'
                );
            }, $allocationResources);
            if (
                $assignedAllocationIds === []
                || count(array_unique($assignedAllocationIds))
                    !== count($assignedAllocationIds)
                || ! in_array(
                    $this->positiveInteger(
                        $attributes['allocation'] ?? null,
                        'server.allocation'
                    ),
                    $assignedAllocationIds,
                    true
                )
            ) {
                throw new \RuntimeException(
                    'Pterodactyl server allocation inventory is incomplete or inconsistent.'
                );
            }
            $externalId = $attributes['external_id'] ?? null;
            if (
                $externalId !== null
                && (
                    ! is_string($externalId)
                    || trim($externalId) === ''
                )
            ) {
                throw new \RuntimeException(
                    'Pterodactyl field server.external_id must be null or a non-empty string.'
                );
            }

            $grouped[$nodeId][] = [
                'id' => $this->positiveInteger($attributes['id'] ?? null, 'server.id'),
                'uuid' => $this->stringValue(
                    $attributes['uuid'] ?? null,
                    'server.uuid'
                ),
                'identifier' => $this->stringValue(
                    $attributes['identifier'] ?? null,
                    'server.identifier'
                ),
                'external_id' => $externalId === null
                    ? null
                    : trim($externalId),
                'node' => $nodeId,
                'memory' => $this->nonNegativeInteger(
                    $limits['memory'] ?? null,
                    'server.limits.memory'
                ),
                'cpu' => $this->nonNegativeInteger($limits['cpu'] ?? null, 'server.limits.cpu'),
                'disk' => $this->nonNegativeInteger(
                    $limits['disk'] ?? null,
                    'server.limits.disk'
                ),
                'allocation_limit' => $allocationLimit,
                'assigned_allocation_ids' => $assignedAllocationIds,
                'allocation_headroom' => max(
                    0,
                    $allocationLimit - count($assignedAllocationIds)
                ),
            ];
        }

        return $grouped;
    }

    /**
     * @return list<array{id: int, ip: string, port: int, ip_in_use: bool}>
     */
    public function availableAllocationsForNode(int $nodeId): array
    {
        if ($this->nodeSnapshot !== null) {
            $node = collect($this->nodeSnapshot)->firstWhere('id', $nodeId);
            if (! is_array($node)) {
                throw new \RuntimeException(
                    "Pterodactyl node {$nodeId} is absent from the inventory snapshot."
                );
            }

            return $node['available_allocations'];
        }

        $parsed = [];
        foreach ($this->paginated("/api/application/nodes/{$nodeId}/allocations") as $resource) {
            $attributes = $this->attributes($resource, 'allocation');
            $assigned = $this->booleanValue($attributes['assigned'] ?? null, 'allocation.assigned');
            $port = $this->positiveInteger(
                $attributes['port'] ?? null,
                'allocation.port'
            );
            if ($port > 65535) {
                throw new \RuntimeException(
                    'Pterodactyl field allocation.port must not exceed 65535.'
                );
            }

            $parsed[] = [
                'id' => $this->positiveInteger($attributes['id'] ?? null, 'allocation.id'),
                'ip' => $this->stringValue($attributes['ip'] ?? null, 'allocation.ip'),
                'port' => $port,
                'assigned' => $assigned,
            ];
        }
        $allocations = $this->availableAllocations($parsed);

        usort($allocations, fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $allocations;
    }

    /**
     * Parse the NodeTransformer allocations include. A missing/null
     * relationship means the API key lacks allocation permission and must fail
     * stock closed rather than trigger per-node fallback requests.
     *
     * @return list<array{id: int, ip: string, port: int, ip_in_use: bool}>
     */
    private function availableNodeAllocations(array $nodeResource): array
    {
        $resources = $nodeResource['relationships']['allocations']['data']
            ?? null;
        if (! is_array($resources) || ! array_is_list($resources)) {
            throw new \RuntimeException(
                'Pterodactyl node inventory is missing the allocations relationship. '
                .'Grant the application key allocation read permission.'
            );
        }

        $parsed = [];
        foreach ($resources as $resource) {
            $attributes = $this->attributes($resource, 'allocation');
            $assigned = $this->booleanValue(
                $attributes['assigned'] ?? null,
                'allocation.assigned'
            );

            $port = $this->positiveInteger(
                $attributes['port'] ?? null,
                'allocation.port'
            );
            if ($port > 65535) {
                throw new \RuntimeException(
                    'Pterodactyl field allocation.port must not exceed 65535.'
                );
            }

            $parsed[] = [
                'id' => $this->positiveInteger(
                    $attributes['id'] ?? null,
                    'allocation.id'
                ),
                'ip' => $this->stringValue(
                    $attributes['ip'] ?? null,
                    'allocation.ip'
                ),
                'port' => $port,
                'assigned' => $assigned,
            ];
        }
        $allocations = $this->availableAllocations($parsed);

        usort(
            $allocations,
            fn (array $left, array $right): int => $left['id'] <=> $right['id']
        );

        return $allocations;
    }

    /**
     * @param  list<array{id: int, ip: string, port: int, assigned: bool}>  $allocations
     * @return list<array{id: int, ip: string, port: int, ip_in_use: bool}>
     */
    private function availableAllocations(array $allocations): array
    {
        $assignedIps = [];
        foreach ($allocations as $allocation) {
            if ($allocation['assigned']) {
                $assignedIps[$this->canonicalIp($allocation['ip'])] = true;
            }
        }

        return array_values(array_map(
            fn (array $allocation): array => [
                'id' => $allocation['id'],
                'ip' => $allocation['ip'],
                'port' => $allocation['port'],
                'ip_in_use' => isset(
                    $assignedIps[$this->canonicalIp($allocation['ip'])]
                ),
            ],
            array_filter(
                $allocations,
                fn (array $allocation): bool => ! $allocation['assigned']
            )
        ));
    }

    private function canonicalIp(string $ip): string
    {
        $packed = @inet_pton(trim($ip));

        return $packed === false
            ? strtolower(trim($ip))
            : bin2hex($packed);
    }

    /**
     * Resolve the server identity used by Paymenter without scanning or
     * trusting customer-provided node/resource data.
     *
     * @return array{
     *     id: int,
     *     uuid: string,
     *     identifier: string,
     *     external_id: string,
     *     user_id: int,
     *     user_external_id: string,
     *     user_email: string,
     *     nest_id: int,
     *     egg_id: int,
     *     node: int,
     *     memory: int,
     *     cpu: int,
     *     disk: int,
     *     swap: int,
     *     io: int,
     *     threads: string|null,
     *     database_limit: int,
     *     allocation_limit: int,
     *     backup_limit: int,
     *     allocation: int,
     *     assigned_allocation_ids: list<int>
     * }
     */
    public function serverByExternalId(int|string $externalId): array
    {
        $externalId = trim((string) $externalId);
        if ($externalId === '') {
            throw new \InvalidArgumentException('A Pterodactyl external server ID is required.');
        }

        $payload = $this->get(
            '/api/application/servers/external/'.rawurlencode($externalId),
            ['include' => 'allocations']
        );
        $attributes = $this->attributes($payload, 'server');
        $limits = $attributes['limits'] ?? null;

        if (! is_array($limits)) {
            throw new \RuntimeException('Pterodactyl server inventory is missing limits.');
        }
        $featureLimits = $attributes['feature_limits'] ?? null;
        $threads = $limits['threads'] ?? null;
        if (
            ! is_array($featureLimits)
            || ($threads !== null && ! is_string($threads))
        ) {
            throw new \RuntimeException(
                'Pterodactyl server inventory is missing build limits.'
            );
        }
        $primaryAllocation = $this->positiveInteger(
            $attributes['allocation'] ?? null,
            'server.allocation'
        );
        $allocationResources = $payload['relationships']['allocations']['data']
            ?? $attributes['relationships']['allocations']['data']
            ?? null;
        if (! is_array($allocationResources) || ! array_is_list($allocationResources)) {
            throw new \RuntimeException(
                'Pterodactyl server inventory is missing the allocations relationship.'
            );
        }
        $assignedAllocationIds = array_map(function (array $allocation): int {
            $allocationAttributes = $this->attributes($allocation, 'allocation');

            return $this->positiveInteger(
                $allocationAttributes['id'] ?? null,
                'server.allocations.id'
            );
        }, $allocationResources);
        sort($assignedAllocationIds, SORT_NUMERIC);
        if (
            $assignedAllocationIds === []
            || count(array_unique($assignedAllocationIds))
                !== count($assignedAllocationIds)
            || ! in_array($primaryAllocation, $assignedAllocationIds, true)
        ) {
            throw new \RuntimeException(
                'Pterodactyl server allocation inventory is incomplete or inconsistent.'
            );
        }

        $uuid = $this->stringValue(
            $attributes['uuid'] ?? null,
            'server.uuid'
        );
        if (! Str::isUuid($uuid)) {
            throw new \RuntimeException(
                'Pterodactyl field server.uuid must be a valid UUID.'
            );
        }
        $serverExternalId = $this->stringValue(
            $attributes['external_id'] ?? null,
            'server.external_id'
        );
        $userId = $this->positiveInteger(
            $attributes['user'] ?? null,
            'server.user'
        );
        $userPayload = $this->get(
            '/api/application/users/'.$userId,
            []
        );
        $userAttributes = $this->attributes($userPayload, 'user');
        if (
            $this->positiveInteger(
                $userAttributes['id'] ?? null,
                'user.id'
            ) !== $userId
        ) {
            throw new \RuntimeException(
                'Pterodactyl returned a different server owner identity.'
            );
        }

        return [
            'id' => $this->positiveInteger($attributes['id'] ?? null, 'server.id'),
            'uuid' => $uuid,
            'identifier' => $this->stringValue(
                $attributes['identifier'] ?? null,
                'server.identifier'
            ),
            'external_id' => $serverExternalId,
            'user_id' => $userId,
            'user_external_id' => $this->stringValue(
                $userAttributes['external_id'] ?? null,
                'user.external_id'
            ),
            'user_email' => strtolower(trim($this->stringValue(
                $userAttributes['email'] ?? null,
                'user.email'
            ))),
            'nest_id' => $this->positiveInteger(
                $attributes['nest'] ?? null,
                'server.nest'
            ),
            'egg_id' => $this->positiveInteger(
                $attributes['egg'] ?? null,
                'server.egg'
            ),
            'node' => $this->positiveInteger($attributes['node'] ?? null, 'server.node'),
            'memory' => $this->nonNegativeInteger(
                $limits['memory'] ?? null,
                'server.limits.memory'
            ),
            'cpu' => $this->nonNegativeInteger($limits['cpu'] ?? null, 'server.limits.cpu'),
            'disk' => $this->nonNegativeInteger(
                $limits['disk'] ?? null,
                'server.limits.disk'
            ),
            'swap' => $this->nonNegativeInteger(
                $limits['swap'] ?? null,
                'server.limits.swap'
            ),
            'io' => $this->nonNegativeInteger(
                $limits['io'] ?? null,
                'server.limits.io'
            ),
            'threads' => $threads,
            'database_limit' => $this->nonNegativeInteger(
                $featureLimits['databases'] ?? null,
                'server.feature_limits.databases'
            ),
            'allocation_limit' => $this->nonNegativeInteger(
                $featureLimits['allocations'] ?? null,
                'server.feature_limits.allocations'
            ),
            'backup_limit' => $this->nonNegativeInteger(
                $featureLimits['backups'] ?? null,
                'server.feature_limits.backups'
            ),
            'allocation' => $primaryAllocation,
            'assigned_allocation_ids' => $assignedAllocationIds,
        ];
    }

    public function testConnection(): array
    {
        try {
            $nodes = $this->nodes();
            if ($nodes === []) {
                throw new \RuntimeException(
                    'Pterodactyl returned no nodes, so inventory permissions cannot be verified.'
                );
            }

            $this->serversForNodes(array_column($nodes, 'id'));
            $this->availableAllocationsForNode((int) $nodes[0]['id']);

            return [
                'success' => true,
                'message' => 'Connection successful',
                'node_count' => count($nodes),
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paginated(string $path, array $query = []): array
    {
        $page = 1;
        $resources = [];

        while (true) {
            $payload = $this->get($path, [
                ...$query,
                'page' => $page,
                'per_page' => 100,
            ]);
            $data = $payload['data'] ?? null;

            if (! is_array($data) || ! array_is_list($data)) {
                throw new \RuntimeException('Pterodactyl returned an invalid paginated resource payload.');
            }

            $resources = array_merge($resources, $data);
            $pagination = $payload['meta']['pagination'] ?? null;

            // Fractal includes pagination metadata. Accepting a missing block as
            // one page also supports stock Pterodactyl's small relationship
            // responses without silently skipping an advertised next page.
            if ($pagination === null) {
                break;
            }
            if (! is_array($pagination)) {
                throw new \RuntimeException('Pterodactyl returned invalid pagination metadata.');
            }

            $currentPage = $this->positiveInteger(
                $pagination['current_page'] ?? null,
                'pagination.current_page'
            );
            $totalPages = $this->nonNegativeInteger(
                $pagination['total_pages'] ?? null,
                'pagination.total_pages'
            );

            if ($totalPages === 0 || $currentPage >= $totalPages) {
                break;
            }
            if ($currentPage < $page) {
                throw new \RuntimeException('Pterodactyl pagination did not advance.');
            }

            $page = $currentPage + 1;
        }

        return $resources;
    }

    private function get(string $path, array $query): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(3)
                ->connectTimeout(2)
                ->retry(
                    2,
                    250,
                    fn ($exception): bool => $exception instanceof ConnectionException,
                    throw: false
                )
                ->get($this->apiUrl.$path, $query);
        } catch (ConnectionException $exception) {
            \report($exception);

            throw new \RuntimeException('Pterodactyl API connection failed.', previous: $exception);
        }

        if ($response->status() === 429) {
            throw new \RuntimeException('Pterodactyl rate limit exceeded. Retry in a few seconds.');
        }

        if ($response->failed()) {
            \report(new \RuntimeException(sprintf(
                'Pterodactyl inventory API error (%d) body: %s',
                $response->status(),
                $response->body()
            )));

            throw new \RuntimeException(sprintf(
                'Pterodactyl inventory API error (%d). Verify the application API key permissions.',
                $response->status()
            ));
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new \RuntimeException('Pterodactyl returned invalid JSON inventory data.');
        }

        return $payload;
    }

    private function attributes(array $resource, string $expectedObject): array
    {
        $object = $resource['object'] ?? null;
        $attributes = $resource['attributes'] ?? null;

        if ($object !== $expectedObject || ! is_array($attributes)) {
            throw new \RuntimeException("Pterodactyl returned an invalid {$expectedObject} resource.");
        }

        return $attributes;
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        $value = $this->integerValue($value, $field);

        if ($value <= 0) {
            throw new \RuntimeException("Pterodactyl field {$field} must be positive.");
        }

        return $value;
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        $value = $this->integerValue($value, $field);

        if ($value < 0) {
            throw new \RuntimeException("Pterodactyl field {$field} must not be negative.");
        }

        return $value;
    }

    private function integerValue(mixed $value, string $field): int
    {
        if (! is_int($value) && ! (
            is_string($value)
            && preg_match('/^-?(0|[1-9]\d*)$/', $value) === 1
        )) {
            throw new \RuntimeException("Pterodactyl field {$field} must be an integer.");
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false) {
            throw new \RuntimeException(
                "Pterodactyl field {$field} is outside the supported integer range."
            );
        }

        return $validated;
    }

    private function booleanValue(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw new \RuntimeException("Pterodactyl field {$field} must be a boolean.");
        }

        return $value;
    }

    private function stringValue(mixed $value, string $field, bool $allowEmpty = false): string
    {
        if (! is_string($value) || (! $allowEmpty && trim($value) === '')) {
            throw new \RuntimeException("Pterodactyl field {$field} must be a string.");
        }

        return $value;
    }

    private function normalizePanelUrl(string $url): string
    {
        if (trim($url) === '') {
            return '';
        }

        try {
            return PanelEndpointIdentity::canonicalUrl($url);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException(
                'The Pterodactyl inventory URL is invalid.',
                previous: $exception
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function extensionConfig(): array
    {
        return Extension::query()
            ->where('extension', 'DynamicPterodactyl')
            ->where('enabled', true)
            ->first()
            ?->settings
            ->pluck('value', 'key')
            ->toArray() ?? [];
    }
}
