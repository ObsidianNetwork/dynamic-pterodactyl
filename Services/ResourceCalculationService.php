<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\Extension;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ResourceCalculationService
{
    private string $apiUrl;

    private string $apiKey;

    public function __construct()
    {
        $config = $this->getExtensionConfig();
        $this->apiUrl = rtrim($config['pterodactyl_url'] ?? '', '/');
        $this->apiKey = $config['pterodactyl_api_key'] ?? '';
    }

    /**
     * Get available resources for a location (real-time from Pterodactyl API)
     */
    public function getLocationAvailability(int $locationId, ?string $excludeReservationToken = null): array
    {
        $nodes = $this->fetchNodesInLocation($locationId);

        $locationData = [
            'location_id' => $locationId,
            'nodes' => [],
            'max_available' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            'total_capacity' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            'total_allocated' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
        ];

        foreach ($nodes as $node) {
            $nodeAvailability = $this->calculateNodeAvailability($node, $excludeReservationToken);
            $locationData['nodes'][] = $nodeAvailability;

            // Track maximum available across all nodes
            $locationData['max_available']['memory'] = max(
                $locationData['max_available']['memory'],
                $nodeAvailability['available']['memory']
            );
            $locationData['max_available']['cpu'] = max(
                $locationData['max_available']['cpu'],
                $nodeAvailability['available']['cpu']
            );
            $locationData['max_available']['disk'] = max(
                $locationData['max_available']['disk'],
                $nodeAvailability['available']['disk']
            );

            // Aggregate totals
            $locationData['total_capacity']['memory'] += $nodeAvailability['total']['memory'];
            $locationData['total_capacity']['cpu'] += $nodeAvailability['total']['cpu'];
            $locationData['total_capacity']['disk'] += $nodeAvailability['total']['disk'];

            $locationData['total_allocated']['memory'] += $nodeAvailability['allocated']['memory'];
            $locationData['total_allocated']['cpu'] += $nodeAvailability['allocated']['cpu'];
            $locationData['total_allocated']['disk'] += $nodeAvailability['allocated']['disk'];
        }

        return $locationData;
    }

    public function buildClusterSnapshot(): array
    {
        $snapshot = $this->emptyClusterSnapshot();

        try {
            $locations = $this->fetchAllLocations();
            $nodes = $this->fetchClusterNodes();
        } catch (\RuntimeException $exception) {
            if ($this->shouldReturnDegradedSnapshot($exception)) {
                return $this->degradedClusterSnapshot();
            }

            throw $exception;
        }

        $snapshot['locations'] = $locations;

        foreach ($locations as $location) {
            $snapshot['by_location'][$location['id']] = [
                'nodes' => [],
                'totals' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
                'allocated' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
                'available' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            ];
        }

        $pendingReservations = $this->getPendingReservationsForNodes(array_keys($nodes));

        foreach ($nodes as $nodeId => $nodeData) {
            $node = $nodeData['node'];
            $locationId = $nodeData['location_id'];
            $availability = $this->buildNodeAvailabilityFromServers(
                $node,
                $nodeData['servers'],
                $pendingReservations[$nodeId] ?? ['memory' => 0, 'cpu' => 0, 'disk' => 0],
            );

            $snapshot['nodes'][$nodeId] = [
                'node' => $node,
                'location_id' => $locationId,
                'servers' => $nodeData['servers'],
                'totals' => $availability['total'],
                'allocated' => $availability['allocated'],
                'available' => $availability['available'],
                'reserved' => $availability['reserved'],
                'server_count' => $availability['server_count'],
                'utilization' => $availability['utilization'],
                'node_availability' => $availability,
            ];

            if (! array_key_exists($locationId, $snapshot['by_location'])) {
                $snapshot['by_location'][$locationId] = [
                    'nodes' => [],
                    'totals' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
                    'allocated' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
                    'available' => ['memory' => 0, 'cpu' => 0, 'disk' => 0],
                ];
            }

            $snapshot['by_location'][$locationId]['nodes'][] = $nodeId;
            $snapshot['by_location'][$locationId]['totals']['memory'] += $availability['total']['memory'];
            $snapshot['by_location'][$locationId]['totals']['cpu'] += $availability['total']['cpu'];
            $snapshot['by_location'][$locationId]['totals']['disk'] += $availability['total']['disk'];
            $snapshot['by_location'][$locationId]['allocated']['memory'] += $availability['allocated']['memory'];
            $snapshot['by_location'][$locationId]['allocated']['cpu'] += $availability['allocated']['cpu'];
            $snapshot['by_location'][$locationId]['allocated']['disk'] += $availability['allocated']['disk'];
            $snapshot['by_location'][$locationId]['available']['memory'] += $availability['available']['memory'];
            $snapshot['by_location'][$locationId]['available']['cpu'] += $availability['available']['cpu'];
            $snapshot['by_location'][$locationId]['available']['disk'] += $availability['available']['disk'];
        }

        return $snapshot;
    }

    /**
     * Calculate available resources for a specific node
     */
    private function calculateNodeAvailability(array $nodeWithServers, ?string $excludeReservationToken = null): array
    {
        $node = $nodeWithServers['node'];
        $servers = $nodeWithServers['servers'];
        $pendingReservations = $this->getPendingReservations($node['id'], $excludeReservationToken);

        return $this->buildNodeAvailabilityFromServers($node, $servers, $pendingReservations);
    }

    /**
     * Test API connection
     */
    // Does not use pterodactylGet() — admin-initiated diagnostic needs longer timeout and different error surfaces.
    public function testConnection(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Accept' => 'application/json',
            ])->timeout(10)->get("{$this->apiUrl}/api/application/nodes");

            if ($response->successful()) {
                $data = $response->json();

                if (! is_array($data) || ! is_array($data['data'] ?? null)) {
                    return [
                        'success' => false,
                        'message' => 'Connection succeeded but response body was not a valid Pterodactyl nodes payload.',
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'Connection successful',
                    'node_count' => count($data['data']),
                    'panel_version' => $response->header('X-Pterodactyl-Version'),
                ];
            }

            return [
                'success' => false,
                'message' => 'API returned error: '.$response->status(),
                'details' => $response->json('errors', []),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Verify resources are still available (called at payment time)
     */
    public function verifyAvailability(int $nodeId, array $requirements, ?string $excludeReservationToken = null): bool
    {
        $nodes = $this->fetchNodesInLocation($this->getNodeLocation($nodeId));
        $nodeWithServers = \collect($nodes)->first(fn ($n) => ($n['node']['id'] ?? null) === $nodeId);

        if (! $nodeWithServers) {
            return false;
        }

        $availability = $this->calculateNodeAvailability($nodeWithServers, $excludeReservationToken);

        return $availability['available']['memory'] >= $requirements['memory']
            && $availability['available']['cpu'] >= $requirements['cpu']
            && $availability['available']['disk'] >= $requirements['disk'];
    }

    /**
     * Get all locations from Pterodactyl
     */
    public function getLocations(): array
    {
        return $this->fetchAllLocations();
    }

    // --- Private Methods ---

    private function fetchNodesInLocation(int $locationId): array
    {
        $response = $this->pterodactylGet(
            "/api/application/locations/{$locationId}",
            ['include' => 'nodes,servers']
        );

        $nodesData = $this->requireRelationshipData($response, 'nodes');
        $serversData = $this->requireRelationshipData($response, 'servers');

        // Group servers by node_id for per-node allocation accounting
        $serversByNode = [];
        foreach ($serversData as $server) {
            $attributes = is_array($server) && is_array($server['attributes'] ?? null)
                ? $server['attributes']
                : null;
            $nodeId = $attributes['node'] ?? null;
            if ($nodeId === null || ! is_array($attributes['limits'] ?? null)) {
                throw new \RuntimeException('Pterodactyl API returned an invalid included server payload.');
            }
            $serversByNode[$nodeId][] = $attributes;
        }

        $nodes = [];
        foreach ($nodesData as $node) {
            $attrs = is_array($node) && is_array($node['attributes'] ?? null)
                ? $node['attributes']
                : null;
            if ($attrs === null || ! isset($attrs['id'], $attrs['name'], $attrs['fqdn'], $attrs['memory'], $attrs['disk'])) {
                throw new \RuntimeException('Pterodactyl API returned an invalid included node payload.');
            }
            $nodeId = $attrs['id'];
            $nodes[] = [
                'node' => $attrs,
                'servers' => $serversByNode[$nodeId] ?? [],
            ];
        }

        return $nodes;
    }

    private function buildNodeAvailabilityFromServers(array $node, array $servers, array $pendingReservations): array
    {
        $allocated = ['memory' => 0, 'cpu' => 0, 'disk' => 0];
        foreach ($servers as $server) {
            $limits = is_array($server['limits'] ?? null) ? $server['limits'] : [];
            $allocated['memory'] += $limits['memory'] ?? 0;
            $allocated['cpu'] += $limits['cpu'] ?? 0;
            $allocated['disk'] += $limits['disk'] ?? 0;
        }

        $effectiveMemory = $node['memory'] * (1 + ($node['memory_overallocate'] ?? 0) / 100);
        $effectiveDisk = $node['disk'] * (1 + ($node['disk_overallocate'] ?? 0) / 100);
        $effectiveCpu = ($node['cpu_threads'] ?? 4) * 100;

        $available = [
            'memory' => max(0, (int) $effectiveMemory - $allocated['memory'] - $pendingReservations['memory']),
            'cpu' => max(0, (int) $effectiveCpu - $allocated['cpu'] - $pendingReservations['cpu']),
            'disk' => max(0, (int) $effectiveDisk - $allocated['disk'] - $pendingReservations['disk']),
        ];

        return [
            'node_id' => $node['id'],
            'name' => $node['name'],
            'fqdn' => $node['fqdn'],
            'maintenance_mode' => $node['maintenance_mode'] ?? false,
            'total' => [
                'memory' => (int) $effectiveMemory,
                'cpu' => (int) $effectiveCpu,
                'disk' => (int) $effectiveDisk,
            ],
            'allocated' => $allocated,
            'reserved' => $pendingReservations,
            'available' => $available,
            'server_count' => count($servers),
            'utilization' => [
                'memory' => $effectiveMemory > 0
                    ? round(($allocated['memory'] + $pendingReservations['memory']) / $effectiveMemory * 100, 1)
                    : 100,
                'disk' => $effectiveDisk > 0
                    ? round(($allocated['disk'] + $pendingReservations['disk']) / $effectiveDisk * 100, 1)
                    : 100,
            ],
        ];
    }

    private function fetchAllLocations(): array
    {
        return \collect($this->pterodactylGetPaginatedData('/api/application/locations', ['per_page' => 100]))
            ->map(fn ($location) => [
                'id' => $location['attributes']['id'],
                'short' => $location['attributes']['short'],
                'long' => $location['attributes']['long'],
            ])
            ->toArray();
    }

    private function fetchClusterNodes(): array
    {
        try {
            return $this->fetchClusterNodesWithIncludedServers();
        } catch (\RuntimeException $exception) {
            if ($this->shouldReturnDegradedSnapshot($exception)) {
                throw $exception;
            }

            return $this->fetchClusterNodesFromServerIndex();
        }
    }

    private function fetchClusterNodesWithIncludedServers(): array
    {
        return \collect($this->pterodactylGetPaginatedData('/api/application/nodes', [
            'include' => 'servers',
            'per_page' => 100,
        ]))->mapWithKeys(function ($node) {
            $attributes = $node['attributes'] ?? [];

            return [
                $attributes['id'] => [
                    'node' => $attributes,
                    'location_id' => $attributes['location_id'],
                    'servers' => \collect($this->extractRelationshipData($node, 'servers'))
                        ->map(fn ($server) => $server['attributes'])
                        ->toArray(),
                ],
            ];
        })->toArray();
    }

    private function fetchClusterNodesFromServerIndex(): array
    {
        $nodes = \collect($this->pterodactylGetPaginatedData('/api/application/nodes', ['per_page' => 100]))
            ->mapWithKeys(fn ($node) => [
                $node['attributes']['id'] => [
                    'node' => $node['attributes'],
                    'location_id' => $node['attributes']['location_id'],
                    'servers' => [],
                ],
            ])
            ->toArray();

        foreach ($this->pterodactylGetPaginatedData('/api/application/servers', ['per_page' => 100]) as $server) {
            $attributes = $server['attributes'] ?? [];
            $nodeId = $attributes['node'] ?? null;

            if ($nodeId !== null && array_key_exists($nodeId, $nodes)) {
                $nodes[$nodeId]['servers'][] = $attributes;
            }
        }

        return $nodes;
    }

    private function pterodactylGetPaginatedData(string $path, array $query = []): array
    {
        $page = 1;
        $data = [];

        while (true) {
            $payload = $this->pterodactylGet($path, array_merge($query, ['page' => $page]));
            $data = array_merge($data, $payload['data'] ?? []);

            $pagination = $payload['meta']['pagination'] ?? null;
            if (! is_array($pagination)) {
                break;
            }

            $currentPage = (int) ($pagination['current_page'] ?? $page);
            $totalPages = (int) ($pagination['total_pages'] ?? $currentPage);

            if ($currentPage >= $totalPages || $totalPages === 0) {
                break;
            }

            $page = $currentPage + 1;
        }

        return $data;
    }

    private function extractRelationshipData(array $payload, string $relationship): array
    {
        $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $relationshipSets = [
            is_array($attributes['relationships'] ?? null) ? $attributes['relationships'] : [],
            is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [],
        ];

        foreach ($relationshipSets as $relationships) {
            $entry = is_array($relationships[$relationship] ?? null)
                ? $relationships[$relationship]
                : [];
            if (is_array($entry['data'] ?? null)) {
                return $entry['data'];
            }
        }

        return [];
    }

    private function requireRelationshipData(array $payload, string $relationship): array
    {
        $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $relationships = is_array($attributes['relationships'] ?? null)
            ? $attributes['relationships']
            : [];
        $entry = is_array($relationships[$relationship] ?? null)
            ? $relationships[$relationship]
            : null;

        if ($entry === null || ! is_array($entry['data'] ?? null)) {
            throw new \RuntimeException(sprintf(
                'Pterodactyl API returned an invalid %s relationship payload.',
                $relationship
            ));
        }

        return $entry['data'];
    }

    private function emptyClusterSnapshot(): array
    {
        return [
            'locations' => [],
            'nodes' => [],
            'by_location' => [],
            'generated_at' => \now()->toIso8601String(),
        ];
    }

    private function degradedClusterSnapshot(): array
    {
        return $this->emptyClusterSnapshot() + [
            'error' => 'Pterodactyl unavailable',
        ];
    }

    private function shouldReturnDegradedSnapshot(\Throwable $exception): bool
    {
        if ($this->extractStatusCode($exception) >= 500) {
            return true;
        }

        return str_contains($exception->getMessage(), 'Pterodactyl API connection failed');
    }

    private function extractStatusCode(\Throwable $exception): int
    {
        preg_match('/\((\d{3})\)/', $exception->getMessage(), $matches);

        return (int) ($matches[1] ?? 0);
    }

    private function getPendingReservationsForNodes(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        return DB::table('ptero_resource_reservations')
            ->whereIn('node_id', $nodeIds)
            ->where('status', 'pending')
            ->where('expires_at', '>', \now())
            ->select('node_id')
            ->selectRaw('COALESCE(SUM(memory), 0) as memory')
            ->selectRaw('COALESCE(SUM(cpu), 0) as cpu')
            ->selectRaw('COALESCE(SUM(disk), 0) as disk')
            ->groupBy('node_id')
            ->get()
            ->mapWithKeys(fn ($reservation) => [
                $reservation->node_id => [
                    'memory' => (int) $reservation->memory,
                    'cpu' => (int) $reservation->cpu,
                    'disk' => (int) $reservation->disk,
                ],
            ])
            ->toArray();
    }

    private function pterodactylGet(string $path, array $query = []): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(3)            // per-attempt; worst case: 2 × 3s + 250ms = ~6.25s
                ->connectTimeout(2)
                ->retry(2, 250, function ($exception) {
                    // Per-attempt timeout, not end-to-end. Retry on connection errors only.
                    // Do not retry 4xx (other than 429) or 5xx — Pterodactyl returns meaningful errors.
                    return $exception instanceof ConnectionException;
                }, throw: false)
                ->get($this->apiUrl.$path, $query);
        } catch (ConnectionException $exception) {
            // Full message may contain internal hostnames/ports; log for diagnostics, throw sanitized.
            \report($exception);
            throw new \RuntimeException('Pterodactyl API connection failed.', previous: $exception);
        }

        if ($response->status() === 429) {
            throw new \RuntimeException('Pterodactyl rate limit exceeded. Retry in a few seconds.');
        }
        if ($response->failed()) {
            // Log full upstream body for diagnostics, but do NOT leak it to callers —
            // AvailabilityController surfaces exception messages to API clients.
            \report(new \RuntimeException(sprintf(
                'Pterodactyl API error (%d) body: %s',
                $response->status(),
                $response->body()
            )));

            throw new \RuntimeException(sprintf('Pterodactyl API error (%d).', $response->status()));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new \RuntimeException(sprintf(
                'Pterodactyl API returned an invalid JSON payload (status %d).',
                $response->status()
            ));
        }

        return $payload;
    }

    private function getPendingReservations(int $nodeId, ?string $excludeReservationToken = null): array
    {
        $query = DB::table('ptero_resource_reservations')
            ->where('node_id', $nodeId)
            ->where('status', 'pending')
            ->where('expires_at', '>', \now());

        if ($excludeReservationToken !== null) {
            $query->where('token', '!=', $excludeReservationToken);
        }

        $result = $query
            ->selectRaw('COALESCE(SUM(memory), 0) as memory')
            ->selectRaw('COALESCE(SUM(cpu), 0) as cpu')
            ->selectRaw('COALESCE(SUM(disk), 0) as disk')
            ->first();

        return [
            'memory' => (int) $result->memory,
            'cpu' => (int) $result->cpu,
            'disk' => (int) $result->disk,
        ];
    }

    private function getNodeLocation(int $nodeId): int
    {
        $data = $this->pterodactylGet("/api/application/nodes/{$nodeId}");

        $locationId = $data['attributes']['location_id'] ?? null;
        if (! is_int($locationId)) {
            throw new \RuntimeException("Pterodactyl node {$nodeId} response is missing location_id.");
        }

        return $locationId;
    }

    private function getExtensionConfig(): array
    {
        return Extension::where('extension', 'DynamicPterodactyl')
            ->first()
            ?->settings
            ->pluck('value', 'key')
            ->toArray() ?? [];
    }
}
