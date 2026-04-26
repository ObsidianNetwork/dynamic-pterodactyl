<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertDeliveryLog extends Model
{
    protected $table = 'ptero_alert_delivery_log';

    protected $fillable = [
        'alert_config_id',
        'trigger_type',
        'attempted_at',
        'channels_tried',
        'channels_ok',
        'channels_failed',
        'last_error',
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
        'channels_tried' => 'array',
        'channels_ok' => 'array',
        'channels_failed' => 'array',
    ];

    public function alertConfig(): BelongsTo
    {
        return $this->belongsTo(AlertConfig::class);
    }
}
