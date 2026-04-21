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
    // Does not use pterodactylGet() — admin-initiated diagnostic needs longer timeout and different error surfaces.
    public function testConnection(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
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
        $data = $this->pterodactylGet('/api/application/locations', ['per_page' => 100]);

        return collect($data['data'] ?? [])
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
        $data = $this->pterodactylGet('/api/application/nodes', [
            'filter[location_id]' => $locationId,
            'per_page' => 100,
        ]);

        return collect($data['data'] ?? [])
            ->map(fn ($node) => $node['attributes'])
            ->toArray();
    }

    private function fetchServersOnNode(int $nodeId): array
    {
        $data = $this->pterodactylGet("/api/application/nodes/{$nodeId}", ['include' => 'servers']);

        return collect($data['attributes']['relationships']['servers']['data'] ?? [])
            ->map(fn ($server) => $server['attributes'])
            ->toArray();
    }

    private function pterodactylGet(string $path, array $query = []): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(3)            // per-attempt; worst case: 2 × 3s + 250ms = ~6.25s
                ->connectTimeout(2)
                ->retry(2, 250, function ($exception) {
                    // Per-attempt timeout, not end-to-end. Retry on connection errors only.
                    // Do not retry 4xx (other than 429) or 5xx — Pterodactyl returns meaningful errors.
                    return $exception instanceof ConnectionException;
                }, throw: false)
                ->get($this->apiUrl . $path, $query);
        } catch (ConnectionException $exception) {
            // Full message may contain internal hostnames/ports; log for diagnostics, throw sanitized.
            report($exception);
            throw new \RuntimeException('Pterodactyl API connection failed.', previous: $exception);
        }

        if ($response->status() === 429) {
            throw new \RuntimeException('Pterodactyl rate limit exceeded. Retry in a few seconds.');
        }
        if ($response->failed()) {
            // Log full upstream body for diagnostics, but do NOT leak it to callers —
            // AvailabilityController surfaces exception messages to API clients.
            report(new \RuntimeException(sprintf(
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
