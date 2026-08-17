# Core Services

> **Related docs**: [01-DATABASE.md](01-DATABASE.md) (tables used), [07-PRICING-MODELS.md](07-PRICING-MODELS.md) (pricing details), [08-ALGORITHMS.md](08-ALGORITHMS.md) (node selection)

---

## Service Overview

| Service | Responsibility |
|---------|----------------|
| `ResourceCalculationService` | Real-time Pterodactyl API queries |
| `NodeSelectionService` | Best-fit node allocation algorithm |
| `ReservationService` | Resource holds during checkout |
| `SliderConfigReaderService` | Reads dynamic-slider config payloads for API/frontend use |
| `AuditLogService` | Track admin actions |
| `AlertService` | Capacity notifications |

---

## ResourceCalculationService

Implements **real-time API approach** (no caching) proven by PteroSync.

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class ResourceCalculationService
{
    private string $apiUrl;
    private string $apiKey;
    
    public function __construct()
    {
        $config = $this->getExtensionConfig();
        $this->apiUrl = rtrim($config['pterodactyl_url'], '/');
        $this->apiKey = $config['pterodactyl_api_key'];
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
        
        foreach ($nodes as $nodeWithServers) {
            $nodeAvailability = $this->calculateNodeAvailability($nodeWithServers);
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
    public function calculateNodeAvailability(array $nodeWithServers): array
    {
        $node = $nodeWithServers['node'];
        $servers = $nodeWithServers['servers'];
        
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
            'memory' => max(0, (int)$effectiveMemory - $allocated['memory'] - $pendingReservations['memory']),
            'cpu' => max(0, (int)$effectiveCpu - $allocated['cpu'] - $pendingReservations['cpu']),
            'disk' => max(0, (int)$effectiveDisk - $allocated['disk'] - $pendingReservations['disk']),
        ];
        
        return [
            'node_id' => $node['id'],
            'name' => $node['name'],
            'fqdn' => $node['fqdn'],
            'maintenance_mode' => $node['maintenance_mode'] ?? false,
            'total' => [
                'memory' => (int)$effectiveMemory,
                'cpu' => (int)$effectiveCpu,
                'disk' => (int)$effectiveDisk,
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
        
        if (!$node) return false;
        
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
        
        if (!$response->successful()) {
            throw new \RuntimeException('Failed to fetch locations from Pterodactyl');
        }
        
        return collect($response->json('data', []))
            ->map(fn($loc) => [
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
        ])->get("{$this->apiUrl}/api/application/locations/{$locationId}", [
            'include' => 'nodes,servers',
        ]);
        
        if (!$response->successful()) {
            throw new \RuntimeException('Failed to fetch location: ' . $response->body());
        }

        $attributes = $response->json('attributes', []);
        $serversByNode = collect(data_get($attributes, 'relationships.servers.data', []))
            ->map(fn($server) => $server['attributes'])
            ->groupBy('node');

        return collect(data_get($attributes, 'relationships.nodes.data', []))
            ->map(fn($node) => [
                'node' => $node['attributes'],
                'servers' => $serversByNode->get($node['attributes']['id'], collect())->values()->all(),
            ])
            ->all();
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
            'memory' => (int)$result->memory,
            'cpu' => (int)$result->cpu,
            'disk' => (int)$result->disk,
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
        return \App\Helpers\ExtensionHelper::getConfig('Others', 'DynamicPterodactyl');
    }
}
```

---

## NodeSelectionService

Implements **best-fit algorithm with headroom weighting**. See [08-ALGORITHMS.md](08-ALGORITHMS.md) for detailed explanation.

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

class NodeSelectionService
{
    private ResourceCalculationService $resourceService;
    
    public function __construct(ResourceCalculationService $resourceService)
    {
        $this->resourceService = $resourceService;
    }
    
    /**
     * Select the best node for given resource requirements
     * 
     * Algorithm: Best-fit with headroom weighting
     * - Memory: 50% weight (most commonly upgraded)
     * - Disk: 35% weight (harder to migrate)
     * - CPU: 15% weight (often unlimited/shared)
     */
    public function selectBestNode(int $locationId, array $requirements): ?array
    {
        $locationData = $this->resourceService->getLocationAvailability($locationId);
        
        $candidates = [];
        
        foreach ($locationData['nodes'] as $node) {
            // Skip nodes in maintenance mode
            if ($node['maintenance_mode'] ?? false) continue;
            
            // Check if node can accommodate requirements
            if ($node['available']['memory'] < $requirements['memory']) continue;
            if ($node['available']['cpu'] < $requirements['cpu']) continue;
            if ($node['available']['disk'] < $requirements['disk']) continue;
            
            // Calculate remaining headroom after allocation
            $remainingMemory = $node['available']['memory'] - $requirements['memory'];
            $remainingCpu = $node['available']['cpu'] - $requirements['cpu'];
            $remainingDisk = $node['available']['disk'] - $requirements['disk'];
            
            // Weighted score: prioritize memory headroom, then disk, then CPU
            $memoryScore = ($remainingMemory / max(1, $node['total']['memory'])) * 0.50;
            $diskScore = ($remainingDisk / max(1, $node['total']['disk'])) * 0.35;
            $cpuScore = ($remainingCpu / max(1, $node['total']['cpu'])) * 0.15;
            
            $score = $memoryScore + $diskScore + $cpuScore;
            
            $candidates[] = [
                'node' => $node,
                'score' => $score,
                'remaining' => [
                    'memory' => $remainingMemory,
                    'cpu' => $remainingCpu,
                    'disk' => $remainingDisk,
                ],
            ];
        }
        
        if (empty($candidates)) {
            return null;
        }
        
        // Sort by score descending, return highest
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        
        return $candidates[0]['node'];
    }
    
    /**
     * Get maximum allocatable resources across a location
     */
    public function getMaxAvailable(int $locationId): array
    {
        $locationData = $this->resourceService->getLocationAvailability($locationId);
        return $locationData['max_available'];
    }
}
```

---

## SliderConfigReaderService

This service is now intentionally thin: it reads `dynamic_slider` ConfigOption metadata and returns the slider/config payload used by the extension API. Pricing math no longer lives in the extension.

`POST /pricing/calculate` delegates directly to Paymenter core via `Plan::dynamicSliderBasePrice()` and `ConfigOption::calculateDynamicPriceDelta()` inside `PricingController`.

---

## ReservationService

Handles temporary resource holds with **pessimistic locking** to prevent overselling.

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationService
{
    private NodeSelectionService $nodeService;
    private AuditLogService $auditService;
    private int $ttlMinutes;
    
    public function __construct(
        NodeSelectionService $nodeService,
        AuditLogService $auditService
    ) {
        $this->nodeService = $nodeService;
        $this->auditService = $auditService;
        $this->ttlMinutes = config('dynamic-pterodactyl.reservation_ttl', 15);
    }
    
    /**
     * Create a resource reservation
     * 
     * Uses database transaction with pessimistic locking
     * Retries up to 5 times on deadlock
     */
    public function create(
        int $productId, 
        int $locationId, 
        array $resources, 
        ?int $cartItemId = null, 
        ?int $userId = null
    ): array {
        return DB::transaction(function () use ($productId, $locationId, $resources, $cartItemId, $userId) {
            // Lock pending reservations for this location
            DB::table('ptero_resource_reservations')
                ->where('location_id', $locationId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();
            
            // Find best node
            $node = $this->nodeService->selectBestNode($locationId, $resources);
            
            if (!$node) {
                throw new \RuntimeException('No node with sufficient resources available');
            }
            
            // Create reservation
            // Create reservation
            $token = Str::random(64);
            $expiresAt = now()->addMinutes($this->ttlMinutes);
            
            $id = DB::table('ptero_resource_reservations')->insertGetId([
                'token' => $token,
                'cart_item_id' => $cartItemId,
                'user_id' => $userId,
                'node_id' => $node['node_id'],
                'location_id' => $locationId,
                'memory' => $resources['memory'],
                'cpu' => $resources['cpu'],
                'disk' => $resources['disk'],
                'calculated_price' => 0,
                'pricing_breakdown' => json_encode([]),
                'status' => 'pending',
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            return [
                'id' => $id,
                'token' => $token,
                'node_id' => $node['node_id'],
                'node_name' => $node['name'],
                'expires_at' => $expiresAt->toIso8601String(),
                'ttl_minutes' => $this->ttlMinutes,
                'pricing' => $pricing,
            ];
        }, 5); // 5 retry attempts for deadlock
    }
    
    /**
     * Confirm a reservation (after successful payment)
     */
    public function confirm(string $token, int $serviceId): bool
    {
        return DB::table('ptero_resource_reservations')
            ->where('token', $token)
            ->where('status', 'pending')
            ->update([
                'status' => 'confirmed',
                'service_id' => $serviceId,
                'updated_at' => now(),
            ]) > 0;
    }
    
    /**
     * Cancel a reservation
     */
    public function cancel(string $token, ?string $reason = null, bool $isAdminAction = false): bool
    {
        $reservation = $this->getByToken($token);
        
        if (!$reservation) return false;
        
        $result = DB::table('ptero_resource_reservations')
            ->where('token', $token)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancelled',
                'admin_notes' => $reason,
                'updated_at' => now(),
            ]) > 0;
        
        if ($result && $isAdminAction) {
            $this->auditService->log('cancelled', 'reservation', $reservation->id, [
                'reason' => $reason,
                'resources' => [
                    'memory' => $reservation->memory,
                    'cpu' => $reservation->cpu,
                    'disk' => $reservation->disk,
                ],
            ]);
        }
        
        return $result;
    }
    
    /**
     * Extend reservation TTL
     */
    public function extend(string $token, int $additionalMinutes = 15): bool
    {
        return DB::table('ptero_resource_reservations')
            ->where('token', $token)
            ->where('status', 'pending')
            ->update([
                'expires_at' => DB::raw("DATE_ADD(expires_at, INTERVAL {$additionalMinutes} MINUTE)"),
                'updated_at' => now(),
            ]) > 0;
    }
    
    /**
     * Get reservation by token
     */
    public function getByToken(string $token): ?object
    {
        return DB::table('ptero_resource_reservations')
            ->where('token', $token)
            ->first();
    }
    
    /**
     * Get reservation by cart item
     */
    public function getByCartItem(int $cartItemId): ?object
    {
        return DB::table('ptero_resource_reservations')
            ->where('cart_item_id', $cartItemId)
            ->where('status', 'pending')
            ->first();
    }
    
    /**
     * Get all reservations with filters (for admin)
     */
    public function getAll(array $filters = []): \Illuminate\Support\Collection
    {
        $query = DB::table('ptero_resource_reservations')
            ->leftJoin('users', 'ptero_resource_reservations.user_id', '=', 'users.id')
            ->select([
                'ptero_resource_reservations.*',
                'users.name as user_name',
                'users.email as user_email',
            ]);
        
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }
        if (!empty($filters['node_id'])) {
            $query->where('node_id', $filters['node_id']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('ptero_resource_reservations.user_id', $filters['user_id']);
        }
        
        return $query->orderBy('created_at', 'desc')->get();
    }
    
    /**
     * Get reservation statistics
     */
    public function getStatistics(string $period = '30d'): array
    {
        $startDate = match($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => now()->subDays(30),
        };
        
        $stats = DB::table('ptero_resource_reservations')
            ->where('created_at', '>=', $startDate)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
        
        $revenue = DB::table('ptero_resource_reservations')
            ->where('created_at', '>=', $startDate)
            ->where('status', 'confirmed')
            ->sum('calculated_price');
        
        $avgResources = DB::table('ptero_resource_reservations')
            ->where('created_at', '>=', $startDate)
            ->where('status', 'confirmed')
            ->selectRaw('AVG(memory) as avg_memory, AVG(cpu) as avg_cpu, AVG(disk) as avg_disk')
            ->first();
        
        $total = array_sum($stats);
        $confirmed = $stats['confirmed'] ?? 0;
        $expired = $stats['expired'] ?? 0;
        $cancelled = $stats['cancelled'] ?? 0;
        
        return [
            'period' => $period,
            'total' => $total,
            'by_status' => $stats,
            'confirmed_revenue' => $revenue,
            'conversion_rate' => ($confirmed + $expired + $cancelled) > 0
                ? round($confirmed / ($confirmed + $expired + $cancelled) * 100, 1)
                : 0,
            'average_resources' => [
                'memory' => round($avgResources->avg_memory ?? 0),
                'cpu' => round($avgResources->avg_cpu ?? 0),
                'disk' => round($avgResources->avg_disk ?? 0),
            ],
        ];
    }
    
    /**
     * Cleanup expired reservations (called by scheduled job)
     */
    public function cleanupExpired(): int
    {
        return DB::table('ptero_resource_reservations')
            ->where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);
    }
}
```

---

## AuditLogService

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    /**
     * Log an action
     */
    public function log(
        string $action,
        string $entityType,
        int $entityId,
        ?array $newValues = null,
        ?array $oldValues = null,
        ?string $description = null,
        ?string $entityName = null
    ): int {
        $user = Auth::user();
        
        return DB::table('ptero_audit_logs')->insertGetId([
            'user_id' => $user?->id ?? 0,
            'user_name' => $user?->name ?? 'System',
            'user_email' => $user?->email ?? 'system@localhost',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'old_values' => $oldValues ? json_encode($oldValues) : null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'description' => $description,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }
    
    /**
     * Get audit logs with filters
     */
    public function getLogs(array $filters = [], int $limit = 50): \Illuminate\Support\Collection
    {
        $query = DB::table('ptero_audit_logs');
        
        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }
        if (!empty($filters['entity_id'])) {
            $query->where('entity_id', $filters['entity_id']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
        
        return $query->orderBy('created_at', 'desc')->limit($limit)->get();
    }
    
    /**
     * Get logs for a specific entity
     */
    public function getEntityHistory(string $entityType, int $entityId): \Illuminate\Support\Collection
    {
        return $this->getLogs([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ], 100);
    }
}
```

---

## AlertService

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

class AlertService
{
    private ResourceCalculationService $resourceService;
    
    public function __construct(ResourceCalculationService $resourceService)
    {
        $this->resourceService = $resourceService;
    }
    
    /**
     * Check all locations for capacity alerts
     */
    public function checkCapacityAlerts(): void
    {
        $alertConfigs = DB::table('ptero_alert_configs')
            ->where('is_active', true)
            ->get();
        
        foreach ($alertConfigs as $config) {
            $this->checkAlertConfig($config);
        }
    }
    
    private function checkAlertConfig(object $config): void
    {
        // Skip if in cooldown
        if ($config->last_notification_at && 
            now()->diffInMinutes($config->last_notification_at) < $config->cooldown_minutes) {
            return;
        }
        
        try {
            if ($config->location_id) {
                $locations = [$config->location_id];
            } else {
                $locations = collect($this->resourceService->getLocations())->pluck('id');
            }
            
            foreach ($locations as $locationId) {
                $availability = $this->resourceService->getLocationAvailability($locationId);
                $alerts = $this->checkThresholds($availability, $config);
                
                if (!empty($alerts)) {
                    $this->sendNotifications($config, $availability, $alerts);
                    
                    DB::table('ptero_alert_configs')
                        ->where('id', $config->id)
                        ->update(['last_notification_at' => now()]);
                }
            }
        } catch (\Exception $e) {
            \Log::error('Alert check failed', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    private function checkThresholds(array $availability, object $config): array
    {
        $alerts = [];
        
        $memoryUtilization = $availability['total_capacity']['memory'] > 0
            ? ($availability['total_allocated']['memory'] / $availability['total_capacity']['memory']) * 100
            : 0;
        
        $diskUtilization = $availability['total_capacity']['disk'] > 0
            ? ($availability['total_allocated']['disk'] / $availability['total_capacity']['disk']) * 100
            : 0;
        
        if ($memoryUtilization >= $config->memory_critical_threshold) {
            $alerts[] = ['type' => 'critical', 'resource' => 'memory', 'utilization' => $memoryUtilization];
        } elseif ($memoryUtilization >= $config->memory_warning_threshold) {
            $alerts[] = ['type' => 'warning', 'resource' => 'memory', 'utilization' => $memoryUtilization];
        }
        
        if ($diskUtilization >= $config->disk_critical_threshold) {
            $alerts[] = ['type' => 'critical', 'resource' => 'disk', 'utilization' => $diskUtilization];
        } elseif ($diskUtilization >= $config->disk_warning_threshold) {
            $alerts[] = ['type' => 'warning', 'resource' => 'disk', 'utilization' => $diskUtilization];
        }
        
        return $alerts;
    }
    
    private function sendNotifications(object $config, array $availability, array $alerts): void
    {
        $locationName = $config->location_name ?? 'All Locations';
        
        if ($config->email_notifications && !empty($config->notification_emails)) {
            $emails = json_decode($config->notification_emails, true);
            // Send email notification
            // Implementation depends on your mail setup
        }
        
        if ($config->webhook_notifications && $config->webhook_url) {
            Http::post($config->webhook_url, [
                'location' => $locationName,
                'alerts' => $alerts,
                'availability' => $availability,
                'timestamp' => now()->toIso8601String(),
            ]);
        }
    }
    
    /**
     * Send test notification
     */
    public function sendTestNotification(object $config): void
    {
        $testAlerts = [
            ['type' => 'test', 'resource' => 'memory', 'utilization' => 85],
        ];
        
        $testAvailability = [
            'location_id' => $config->location_id ?? 0,
            'total_capacity' => ['memory' => 65536, 'disk' => 512000],
            'total_allocated' => ['memory' => 55705, 'disk' => 409600],
        ];
        
        $this->sendNotifications($config, $testAvailability, $testAlerts);
    }
}
```

---

## Scheduled Jobs

### CleanupExpiredReservations

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class CleanupExpiredReservations implements ShouldQueue
{
    use Dispatchable, Queueable;
    
    public function handle(ReservationService $reservationService): void
    {
        $count = $reservationService->cleanupExpired();
        
        if ($count > 0) {
            \Log::info("Cleaned up {$count} expired reservations");
        }
    }
}
```

Register in scheduler:
```php
// In App\Console\Kernel or extension boot
$schedule->job(new CleanupExpiredReservations)->everyMinute();
$schedule->job(new CheckCapacityAlerts)->everyFiveMinutes();
```
