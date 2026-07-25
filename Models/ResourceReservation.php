<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceReservation extends Model
{
    protected $table = 'ptero_resource_reservations';

    protected $fillable = [
        'token',
        'idempotency_key',
        'cart_item_id',
        'cart_item_guard_id',
        'cart_id',
        'server_extension_id',
        'panel_identity',
        'service_id',
        'user_id',
        'product_id',
        'plan_id',
        'quantity',
        'currency_code',
        'configuration_fingerprint',
        'configuration_payload',
        'pricing_version',
        'formula_version',
        'node_id',
        'location_id',
        'memory',
        'cpu',
        'disk',
        'calculated_price',
        'pricing_breakdown',
        'status',
        'admin_notes',
        'expires_at',
        'provisioning_started_at',
        'provisioning_lease_id',
        'consumed_at',
        'last_provisioning_error',
    ];

    protected $casts = [
        'pricing_breakdown' => 'array',
        'configuration_payload' => 'array',
        'expires_at' => 'datetime',
        'provisioning_started_at' => 'datetime',
        'consumed_at' => 'datetime',
        'calculated_price' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Scope for pending reservations that haven't expired.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending')
                     ->where('expires_at', '>', now());
    }

    /**
     * Scope for expired reservations (pending but past TTL).
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'pending')
                     ->where('expires_at', '<=', now());
    }
}
