<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NodeCapacityPolicy extends Model
{
    public const MAX_CPU_CAPACITY_PERCENT = 10000000;

    public const MAX_CPU_OVERCOMMIT_BPS = 100000;

    protected $table = 'ptero_node_capacity_policies';

    protected $fillable = [
        'panel_identity',
        'node_uuid',
        'node_id',
        'location_id',
        'cpu_capacity_percent',
        'cpu_overcommit_bps',
        'enabled',
    ];

    protected $casts = [
        'node_id' => 'integer',
        'location_id' => 'integer',
        'cpu_capacity_percent' => 'integer',
        'cpu_overcommit_bps' => 'integer',
        'enabled' => 'boolean',
    ];

    public function scopeForPanel(Builder $query, string $panelIdentity): Builder
    {
        return $query->where('panel_identity', $panelIdentity);
    }

    public function scopeForNode(Builder $query, string $nodeUuid): Builder
    {
        return $query->where('node_uuid', $nodeUuid);
    }

    public function effectiveCpuCapacity(): int
    {
        if (! $this->enabled) {
            return 0;
        }

        $capacity = (int) $this->cpu_capacity_percent;
        $overcommit = (int) $this->cpu_overcommit_bps;
        $this->assertPolicyRange($capacity, $overcommit);

        return intdiv(
            $capacity * $overcommit,
            10000
        );
    }

    protected static function booted(): void
    {
        static::saving(function (self $policy): void {
            $policy->assertPolicyRange(
                (int) $policy->cpu_capacity_percent,
                (int) $policy->cpu_overcommit_bps
            );
        });
    }

    public function save(array $options = [])
    {
        if (! $this->capacityPolicyIsChanging()) {
            return parent::save($options);
        }
        if (DB::transactionLevel() === 0) {
            return DB::transaction(
                fn () => $this->save($options),
                5
            );
        }

        $this->lockCapacityScopes();
        $this->assertNoLiveCommitment();

        return parent::save($options);
    }

    public function delete()
    {
        if (! $this->exists) {
            return parent::delete();
        }
        if (DB::transactionLevel() === 0) {
            return DB::transaction(
                fn () => $this->delete(),
                5
            );
        }

        $this->lockCapacityScopes();
        $this->assertNoLiveCommitment();

        return parent::delete();
    }

    private function assertPolicyRange(int $capacity, int $overcommit): void
    {
        if ($capacity < 1 || $capacity > self::MAX_CPU_CAPACITY_PERCENT) {
            throw new \InvalidArgumentException(sprintf(
                'CPU capacity must be between 1 and %d percent.',
                self::MAX_CPU_CAPACITY_PERCENT
            ));
        }

        if ($overcommit < 1 || $overcommit > self::MAX_CPU_OVERCOMMIT_BPS) {
            throw new \InvalidArgumentException(sprintf(
                'CPU overcommit must be between 1 and %d basis points.',
                self::MAX_CPU_OVERCOMMIT_BPS
            ));
        }

        if ($capacity > intdiv(PHP_INT_MAX, $overcommit)) {
            throw new \InvalidArgumentException(
                'The configured CPU policy exceeds the supported integer range.'
            );
        }
    }

    private function assertNoLiveCommitment(): bool
    {
        if (! Schema::hasTable('ptero_resource_reservations')) {
            return true;
        }

        $scopes = [[
            (string) $this->panel_identity,
            (int) $this->node_id,
        ]];
        if ($this->exists) {
            $scopes[] = [
                (string) $this->getOriginal('panel_identity'),
                (int) $this->getOriginal('node_id'),
            ];
        }

        foreach (array_unique($scopes, SORT_REGULAR) as [$panel, $node]) {
            if (! ResourceReservation::query()
                ->holdingCapacity()
                ->where('panel_identity', $panel)
                ->where('node_id', $node)
                ->exists()) {
                continue;
            }

            throw new \RuntimeException(
                'CPU capacity policy cannot change while this node has a live capacity commitment.'
            );
        }

        return true;
    }

    private function capacityPolicyIsChanging(): bool
    {
        return ! $this->exists || $this->isDirty([
            'panel_identity',
            'node_uuid',
            'node_id',
            'location_id',
            'cpu_capacity_percent',
            'cpu_overcommit_bps',
            'enabled',
        ]);
    }

    /**
     * Serialize the policy write with reservation creation for both the old
     * and new location. The lock remains held until the surrounding save or
     * delete transaction commits.
     */
    private function lockCapacityScopes(): void
    {
        if (! Schema::hasTable('ptero_resource_reservations')) {
            return;
        }
        if (! Schema::hasTable('ptero_capacity_scopes')) {
            throw new \RuntimeException(
                'CPU capacity policy changes require the capacity-scope lock table.'
            );
        }

        $scopes = [[
            'panel_identity' => (string) $this->panel_identity,
            'location_id' => (int) $this->location_id,
        ]];
        if ($this->exists) {
            $scopes[] = [
                'panel_identity' => (string) $this->getOriginal(
                    'panel_identity'
                ),
                'location_id' => (int) $this->getOriginal('location_id'),
            ];
        }
        $scopes = collect($scopes)
            ->unique(fn (array $scope): string => "{$scope['panel_identity']}:{$scope['location_id']}")
            ->sortBy(fn (array $scope): string => "{$scope['panel_identity']}:"
                .str_pad((string) $scope['location_id'], 10, '0', STR_PAD_LEFT))
            ->values();

        foreach ($scopes as $scope) {
            if (
                preg_match(
                    '/^[a-f0-9]{64}$/D',
                    $scope['panel_identity']
                ) !== 1
                || $scope['location_id'] <= 0
            ) {
                throw new \InvalidArgumentException(
                    'A CPU capacity policy requires a valid panel and location identity.'
                );
            }
            DB::table('ptero_capacity_scopes')->insertOrIgnore([
                ...$scope,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach ($scopes as $scope) {
            DB::table('ptero_capacity_scopes')
                ->where('panel_identity', $scope['panel_identity'])
                ->where('location_id', $scope['location_id'])
                ->lockForUpdate()
                ->firstOrFail();
        }
    }
}
