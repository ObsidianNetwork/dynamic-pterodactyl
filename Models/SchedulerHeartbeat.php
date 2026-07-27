<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulerHeartbeat extends Model
{
    protected $table = 'ptero_scheduler_heartbeats';

    protected $fillable = [
        'task_name',
        'expected_interval_seconds',
        'lag_threshold_seconds',
        'last_started_at',
        'last_completed_at',
        'last_succeeded_at',
        'last_failed_at',
        'last_lag_checked_at',
        'lag_detected_at',
        'last_alerted_at',
        'last_scanned_entity_id',
        'last_processed_count',
        'last_failure_count',
        'consecutive_failures',
        'last_error',
        'last_failure_context',
    ];

    protected $casts = [
        'expected_interval_seconds' => 'integer',
        'lag_threshold_seconds' => 'integer',
        'last_started_at' => 'datetime',
        'last_completed_at' => 'datetime',
        'last_succeeded_at' => 'datetime',
        'last_failed_at' => 'datetime',
        'last_lag_checked_at' => 'datetime',
        'lag_detected_at' => 'datetime',
        'last_alerted_at' => 'datetime',
        'last_scanned_entity_id' => 'integer',
        'last_processed_count' => 'integer',
        'last_failure_count' => 'integer',
        'consecutive_failures' => 'integer',
        'last_failure_context' => 'array',
    ];
}
