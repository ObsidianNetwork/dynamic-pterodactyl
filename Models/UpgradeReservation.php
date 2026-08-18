<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use App\Models\ServiceUpgrade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpgradeReservation extends Model
{
    protected $table = 'ptero_resource_reservations';

    protected $guarded = [];

    protected $casts = [
        'configuration_payload' => 'array',
        'pricing_breakdown' => 'array',
        'expires_at' => 'datetime',
        'guaranteed_until' => 'datetime',
        'paid_committed_at' => 'datetime',
        'provisioning_started_at' => 'datetime',
        'consumed_at' => 'datetime',
        'calculated_price' => 'decimal:2',
    ];

    public function serviceUpgrade(): BelongsTo
    {
        return $this->belongsTo(ServiceUpgrade::class);
    }
}
