<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertConfig extends Model
{
    protected $table = 'ptero_alert_configs';

    protected $fillable = [
        'location_id',
        'location_name',
        'memory_warning_threshold',
        'memory_critical_threshold',
        'cpu_warning_threshold',
        'cpu_critical_threshold',
        'disk_warning_threshold',
        'disk_critical_threshold',
        'email_notifications',
        'notification_emails',
        'webhook_notifications',
        'webhook_url',
        'cooldown_minutes',
        'last_notification_at',
        'is_active',
    ];

    protected $casts = [
        'notification_emails' => 'array',
        'email_notifications' => 'boolean',
        'webhook_notifications' => 'boolean',
        'webhook_url' => 'encrypted',
        'is_active' => 'boolean',
        'last_notification_at' => 'datetime',
    ];

    /**
     * Scope for global alert configs (no specific location).
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('location_id');
    }

    /**
     * Scope for a specific location's alert config.
     */
    public function scopeForLocation($query, int $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function deliveryLogs(): HasMany
    {
        return $this->hasMany(AlertDeliveryLog::class);
    }
}
