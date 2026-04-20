<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use App\Models\Extension;
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
    public function getLocationAvailability(int $locationId): array
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
            $nodeAvailability = $this->calculateNodeAvailability($node);
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

    /**
     * Calculate available resources for a specific node
     */
    public function calculateNodeAvailability(array $node): array
    {
        $servers = $this->fetchServersOnNode($node['id']);

        // Sum allocated resources from all servers
        $allocated = ['memory' => 0, 'cpu' => 0, 'disk' => 0];
        foreach ($servers as $server) {
            $allocated['memory'] += $server['limits']['memory'] ?? 0;
            $allocated['cpu'] += $server['limits']['cpu'] ?? 0;
            $allocated['disk'] += $server['limits']['disk'] ?? 0;
        }

        // Get pending reservations for this node
        $pendingReservations = $this->getPendingReservations($node['id']);

        // Calculate effective totals with overallocation
        // Formula: total * (1 + overallocate_percentage / 100)
        $effectiveMemory = $node['memory'] * (1 + ($node['memory_overallocate'] ?? 0) / 100);
        $effectiveDisk = $node['disk'] * (1 + ($node['disk_overallocate'] ?? 0) / 100);
        $effectiveCpu = ($node['cpu_threads'] ?? 4) * 100; // No overallocation for CPU

        // Calculate available (total - allocated - pending)
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

    /**
     * Test API connection
     */
    public function testConnection(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
            ])->timeout(10)->get("{$this->apiUrl}/api/application/nodes");

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'message' => 'Connection successful',
                    'node_count' => count($data['data'] ?? []),
                    'panel_version' => $response->header('X-Pterodactyl-Version'),
                ];
            }

            return [
                'success' => false,
                'message' => 'API returned error: ' . $response->status(),
                'details' => $response->json('errors', []),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Verify resources are still available (called at payment time)
     */
    public function verifyAvailability(int $nodeId, array $requirements): bool
    {
        $nodes = $this->fetchNodesInLocation($this->getNodeLocation($nodeId));
        $node = collect($nodes)->firstWhere('id', $nodeId);

        if (! $node) {
            return false;
        }

        $availability = $this->calculateNodeAvailability($node);

        return $availability['available']['memory'] >= $requirements['memory']
            && $availability['available']['cpu'] >= $requirements['cpu']
            && $availability['available']['disk'] >= $requirements['disk'];
    }

    /**
     * Get all locations from Pterodactyl
     */
    public function getLocations(): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ])->get("{$this->apiUrl}/api/application/locations", [
            'per_page' => 100,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to fetch locations from Pterodactyl');
        }

        return collect($response->json('data', []))
            ->map(fn ($loc) => [
                'id' => $loc['attributes']['id'],
                'short' => $loc['attributes']['short'],
                'long' => $loc['attributes']['long'],
            ])
            ->toArray();
    }

    // --- Private Methods ---

    private function fetchNodesInLocation(int $locationId): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->get("{$this->apiUrl}/api/application/nodes", [
            'filter[location_id]' => $locationId,
            'per_page' => 100,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to fetch nodes: ' . $response->body());
        }

        return collect($response->json('data', []))
            ->map(fn ($node) => $node['attributes'])
            ->toArray();
    }

    private function fetchServersOnNode(int $nodeId): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ])->get("{$this->apiUrl}/api/application/nodes/{$nodeId}", [
            'include' => 'servers',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to fetch node details');
        }

        return collect($response->json('attributes.relationships.servers.data', []))
            ->map(fn ($server) => $server['attributes'])
            ->toArray();
    }

    private function getPendingReservations(int $nodeId): array
    {
        $result = DB::table('ptero_resource_reservations')
            ->where('node_id', $nodeId)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
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
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ])->get("{$this->apiUrl}/api/application/nodes/{$nodeId}");

        return $response->json('attributes.location_id');
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
