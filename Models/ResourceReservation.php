<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResourceReservation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID_COMMITTED = 'paid_committed';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'ptero_resource_reservations';

    protected $fillable = [
        'token',
        'purpose',
        'idempotency_key',
        'cart_item_id',
        'cart_item_guard_id',
        'cart_id',
        'server_extension_id',
        'panel_identity',
        'service_id',
        'service_guard_id',
        'invoice_id',
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
        'guaranteed_until',
        'paid_committed_at',
        'provisioning_started_at',
        'provisioning_attempts',
        'last_provisioning_attempt_at',
        'next_provisioning_attempt_at',
        'provisioning_lease_id',
        'consumed_at',
        'last_provisioning_error',
        'failure_alerted_at',
        'cancellation_requested_at',
        'last_cancellation_error',
        'cancellation_failure_alerted_at',
        'external_server_id',
        'external_user_id',
        'external_server_uuid',
        'external_server_identifier',
        'last_reconciled_at',
        'customer_notified_at',
        'product_stock_released_at',
    ];

    protected $casts = [
        'pricing_breakdown' => 'array',
        'configuration_payload' => 'array',
        'expires_at' => 'datetime',
        'guaranteed_until' => 'datetime',
        'paid_committed_at' => 'datetime',
        'provisioning_started_at' => 'datetime',
        'last_provisioning_attempt_at' => 'datetime',
        'next_provisioning_attempt_at' => 'datetime',
        'consumed_at' => 'datetime',
        'failure_alerted_at' => 'datetime',
        'cancellation_requested_at' => 'datetime',
        'cancellation_failure_alerted_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'customer_notified_at' => 'datetime',
        'product_stock_released_at' => 'datetime',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ReservationAllocation::class, 'reservation_id');
    }

    /**
     * Scope for pending reservations that haven't expired.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending')
                     ->where('expires_at', '>', now());
    }

    public function scopeHoldingCapacity($query)
    {
        return $query->where(function ($query) {
            $query->where(function ($query) {
                $query->where('status', self::STATUS_PENDING)
                    ->where('expires_at', '>', now());
            })->orWhere('status', self::STATUS_PAID_COMMITTED);
        });
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
