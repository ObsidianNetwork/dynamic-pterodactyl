# Algorithms

> **Related docs**: [02-SERVICES.md](02-SERVICES.md) (service implementations)

---

## Node Selection Algorithm

### Goal

Select the best node that can accommodate requested resources while maintaining balanced utilization across the cluster.

### Approach: Best-Fit with Headroom Weighting

Not just "first available" or "most available" — we use **weighted headroom scoring** to:
1. Ensure the node CAN fit the request
2. Prefer nodes with better capacity distribution
3. Weight resources by importance (memory > disk > CPU)

### Why These Weights?

| Resource | Weight | Rationale |
|----------|--------|-----------|
| Memory | 50% | Most commonly upgraded; hard to migrate |
| Disk | 35% | Harder to expand; data migration is slow |
| CPU | 15% | Often shared/burstable; easier to oversell |

### Algorithm Steps

```
1. FILTER: Remove nodes that cannot fit the request
   - Skip nodes in maintenance mode
   - Skip nodes with insufficient memory
   - Skip nodes with insufficient CPU
   - Skip nodes with insufficient disk

2. SCORE: Calculate weighted headroom for each candidate
   For each node:
     remaining_memory = available_memory - requested_memory
     remaining_cpu = available_cpu - requested_cpu
     remaining_disk = available_disk - requested_disk
     
     memory_score = (remaining_memory / total_memory) × 0.50
     disk_score = (remaining_disk / total_disk) × 0.35
     cpu_score = (remaining_cpu / total_cpu) × 0.15
     
     total_score = memory_score + disk_score + cpu_score

3. SELECT: Choose node with highest score
```

### Visual Example

```
Request: 8GB RAM, 2 cores, 50GB disk

Node A: 32GB total, 16GB available → fits, remaining 8GB
Node B: 64GB total, 20GB available → fits, remaining 12GB
Node C: 32GB total, 6GB available  → SKIP (can't fit 8GB)

Scoring (memory only for simplicity):
Node A: 8/32 = 0.25 × 0.50 = 0.125
Node B: 12/64 = 0.1875 × 0.50 = 0.094

Winner: Node A (better relative headroom despite smaller total)
```

### Pseudocode

```php
function selectBestNode(locationId, requirements):
    nodes = getNodesInLocation(locationId)
    candidates = []
    
    for node in nodes:
        if node.maintenance_mode:
            continue
        
        available = calculateAvailable(node)
        
        if available.memory < requirements.memory:
            continue
        if available.cpu < requirements.cpu:
            continue
        if available.disk < requirements.disk:
            continue
        
        remaining = {
            memory: available.memory - requirements.memory,
            cpu: available.cpu - requirements.cpu,
            disk: available.disk - requirements.disk,
        }
        
        score = (remaining.memory / node.total.memory) * 0.50
              + (remaining.disk / node.total.disk) * 0.35
              + (remaining.cpu / node.total.cpu) * 0.15
        
        candidates.append({ node, score, remaining })
    
    if candidates.empty:
        return null
    
    return candidates.sortByScoreDesc().first().node
```

### Edge Cases

| Scenario | Handling |
|----------|----------|
| No nodes in location | Return `null`, let caller handle |
| All nodes in maintenance | Return `null` |
| Exact fit (0 remaining) | Score = 0, still valid candidate |
| Single node location | That node wins if it fits |

---

## Availability Calculation

### Components of "Available" Resources

```
Available = Total Capacity
          - Already Allocated (existing servers)
          - Pending Reservations (checkout in progress)
```

### Pterodactyl Overallocation

Pterodactyl allows overallocation for memory and disk:

```
Effective Total = Base Capacity × (1 + Overallocation% / 100)

Example:
  Base memory: 64GB
  Overallocation: 50%
  Effective: 64 × 1.5 = 96GB allocatable
```

CPU is NOT overallocated (uses thread count × 100).

### Calculation Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                     Pterodactyl API                              │
├─────────────────────────────────────────────────────────────────┤
│  GET /api/application/nodes/{id}?include=servers                │
│                                                                  │
│  Returns:                                                        │
│  - node.memory (base capacity)                                  │
│  - node.memory_overallocate (percentage)                        │
│  - node.disk (base capacity)                                    │
│  - node.disk_overallocate (percentage)                          │
│  - servers[].limits.memory (allocated per server)               │
│  - servers[].limits.cpu                                         │
│  - servers[].limits.disk                                        │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                   Local Database Query                           │
├─────────────────────────────────────────────────────────────────┤
│  SELECT SUM(memory), SUM(cpu), SUM(disk)                        │
│  FROM ptero_resource_reservations                               │
│  WHERE node_id = ? AND status = 'pending'                       │
│    AND expires_at > NOW()                                       │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Final Calculation                           │
├─────────────────────────────────────────────────────────────────┤
│  effective_total = base × (1 + overallocate/100)                │
│  allocated = SUM(server.limits)                                 │
│  reserved = SUM(pending_reservations)                           │
│  available = effective_total - allocated - reserved             │
└─────────────────────────────────────────────────────────────────┘
```

---

## Concurrency Control

### The Problem

Two customers selecting resources simultaneously could both be promised the same capacity, leading to overselling.

```
Timeline:
  T0: Customer A checks availability → 8GB available
  T1: Customer B checks availability → 8GB available
  T2: Customer A reserves 8GB → succeeds
  T3: Customer B reserves 8GB → should fail, but might succeed without locking
```

### Solution: Pessimistic Locking

Lock pending reservations for the location before checking availability and creating new reservation.

```php
DB::transaction(function () use ($locationId, $requirements) {
    // Lock all pending reservations for this location
    // This prevents concurrent transactions from reading stale data
    DB::table('ptero_resource_reservations')
        ->where('location_id', $locationId)
        ->where('status', 'pending')
        ->lockForUpdate()  // ← Key: exclusive lock
        ->get();
    
    // Now calculate availability (includes locked rows)
    $node = $this->nodeService->selectBestNode($locationId, $requirements);
    
    if (!$node) {
        throw new \RuntimeException('No capacity available');
    }
    
    // Create reservation (within same transaction)
    return $this->createReservation($node, $requirements);
}, 5);  // ← Retry up to 5 times on deadlock
```

### Why `lockForUpdate()`?

| Lock Type | Behavior |
|-----------|----------|
| Shared (`sharedLock()`) | Multiple readers allowed, blocks writes |
| Exclusive (`lockForUpdate()`) | Blocks all other access until commit |

We need exclusive because we're reading AND writing based on the read.

### Deadlock Handling

With pessimistic locking, deadlocks can occur if two transactions lock in different orders. Laravel's transaction retry handles this:

```php
DB::transaction(function () {
    // ... locking logic ...
}, 5);  // Retry 5 times with exponential backoff
```

### Race Condition Timeline (With Locking)

```
Timeline:
  T0: Customer A begins transaction, locks location 1
  T1: Customer B begins transaction, waits for lock...
  T2: Customer A calculates availability, creates reservation
  T3: Customer A commits, releases lock
  T4: Customer B acquires lock, calculates (sees A's reservation)
  T5: Customer B sees reduced availability, acts accordingly
```

---

## Reservation Lifecycle

### State Machine

```
                    ┌──────────────┐
                    │   (start)    │
                    └──────┬───────┘
                           │ add to cart
                           ▼
                    ┌──────────────┐
            ┌───────│   pending    │───────┐
            │       └──────┬───────┘       │
            │              │               │
       TTL expires    payment succeeds   user cancels
            │              │               │
            ▼              ▼               ▼
     ┌──────────┐   ┌───────────┐   ┌───────────┐
     │ expired  │   │ confirmed │   │ cancelled │
     └──────────┘   └───────────┘   └───────────┘
            │              │               │
            └──────────────┴───────────────┘
                           │
                           ▼
                    ┌──────────────┐
                    │  (released)  │  ← resources back in pool
                    └──────────────┘
```

### TTL Management

**Default TTL**: 15 minutes (configurable)

**Extension**: Customers can extend once when they reach checkout, adding another 15 minutes.

**Cleanup Job**: Runs every minute to mark expired reservations.

```php
// Cleanup query
UPDATE ptero_resource_reservations
SET status = 'expired', updated_at = NOW()
WHERE status = 'pending' AND expires_at < NOW()
```

### Final Verification

Even with reservations, we do a **final check** at payment time:

```php
// In invoice payment handler
$stillAvailable = $resourceService->verifyAvailability(
    $reservation->node_id,
    [
        'memory' => $reservation->memory,
        'cpu' => $reservation->cpu,
        'disk' => $reservation->disk,
    ]
);

if (!$stillAvailable) {
    // Edge case: node capacity changed (admin removed node, etc.)
    // Log error, notify admin, but don't block payment
    // Server creation will fail at Pterodactyl level
}
```

This catches edge cases like:
- Admin removing a node
- Another admin manually creating servers
- Pterodactyl capacity changes

---

## Performance Considerations

### API Call Frequency

| Scenario | API Calls |
|----------|-----------|
| Location dropdown change | 1 (nodes in location) |
| Create reservation | 1 (verify node capacity) |
| Price calculation | 0 (local only) |
| Cleanup job | 0 (local only) |

Pterodactyl rate limit: **240 requests/minute** — we stay well under this.

### Database Query Optimization

Key indexes on `ptero_resource_reservations`:

```sql
-- Fast pending reservation lookup by node
INDEX idx_node_pending (node_id, status, expires_at)

-- Fast cleanup of expired
INDEX idx_cleanup (status, expires_at)

-- Location availability calculation
INDEX (location_id, status)
```

### Caching (Intentionally Avoided)

We chose **real-time API** over caching because:
1. Stale cache = overselling risk
2. Cache invalidation complexity
3. Proven by PteroSync in production
4. Pterodactyl API is fast enough

If latency becomes an issue, consider:
- Edge caching with 30-second TTL (accept slight staleness)
- WebSocket for real-time updates
- Background refresh with optimistic UI
