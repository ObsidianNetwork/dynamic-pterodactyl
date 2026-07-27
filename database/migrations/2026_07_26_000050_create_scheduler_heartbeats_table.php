<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptero_scheduler_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('task_name')->unique();
            $table->unsignedInteger('expected_interval_seconds');
            $table->unsignedInteger('lag_threshold_seconds');
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('last_lag_checked_at')->nullable();
            $table->timestamp('lag_detected_at')->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->unsignedInteger('last_processed_count')->default(0);
            $table->unsignedInteger('last_failure_count')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->json('last_failure_context')->nullable();
            $table->timestamps();

            $table->index('last_succeeded_at');
            $table->index('lag_detected_at');
        });

        $now = now();
        DB::table('ptero_scheduler_heartbeats')->insert([
            [
                'task_name' => 'expire_checkout_reservations',
                'expected_interval_seconds' => 60,
                'lag_threshold_seconds' => 300,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'task_name' => 'expire_unpaid_upgrades',
                'expected_interval_seconds' => 60,
                'lag_threshold_seconds' => 300,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'task_name' => 'reconcile_paid_checkout_commitments',
                'expected_interval_seconds' => 600,
                'lag_threshold_seconds' => 1800,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'task_name' => 'reconcile_stalled_upgrades',
                'expected_interval_seconds' => 600,
                'lag_threshold_seconds' => 1800,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'task_name' => 'check_capacity_alerts',
                'expected_interval_seconds' => 300,
                'lag_threshold_seconds' => 900,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ptero_scheduler_heartbeats');
    }
};
