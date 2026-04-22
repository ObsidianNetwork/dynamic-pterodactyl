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
        'service_id',
        'user_id',
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
    ];

    protected $casts = [
        'pricing_breakdown' => 'array',
        'expires_at' => 'datetime',
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
