<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'ptero_audit_logs';

    /**
     * Disable automatic timestamps - we only use created_at manually.
     */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'user_name',
        'user_email',
        'action',
        'entity_type',
        'entity_id',
        'entity_name',
        'old_values',
        'new_values',
        'description',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];
}
