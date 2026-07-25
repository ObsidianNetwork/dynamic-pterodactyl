<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CapacityScope extends Model
{
    protected $table = 'ptero_capacity_scopes';

    protected $fillable = [
        'panel_identity',
        'location_id',
    ];

    protected $casts = [
        'location_id' => 'integer',
    ];

    public function scopeForPanel(Builder $query, string $panelIdentity): Builder
    {
        return $query->where('panel_identity', $panelIdentity);
    }

    public function scopeForLocation(Builder $query, int $locationId): Builder
    {
        return $query->where('location_id', $locationId);
    }
}
