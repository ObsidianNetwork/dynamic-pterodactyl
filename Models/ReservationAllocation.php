<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationAllocation extends Model
{
    protected $table = 'ptero_reservation_allocations';

    protected $fillable = [
        'reservation_id',
        'panel_identity',
        'node_id',
        'allocation_id',
        'ip',
        'port',
        'environment_key',
        'is_primary',
        'released_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'released_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(ResourceReservation::class, 'reservation_id');
    }
}
