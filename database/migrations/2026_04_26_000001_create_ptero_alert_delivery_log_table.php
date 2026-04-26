<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptero_alert_delivery_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alert_config_id');
            $table->foreign('alert_config_id')
                ->references('id')->on('ptero_alert_configs')
                ->onDelete('cascade');
            $table->enum('trigger_type', ['capacity_breach', 'shortfall', 'state_drift', 'check_failure']);
            $table->timestamp('attempted_at');
            $table->json('channels_tried')->default('[]');
            $table->json('channels_ok')->default('[]');
            $table->json('channels_failed')->default('[]');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptero_alert_delivery_log');
    }
};
