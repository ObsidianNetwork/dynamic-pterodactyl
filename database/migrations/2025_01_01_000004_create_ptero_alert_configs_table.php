<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptero_alert_configs', function (Blueprint $table) {
            $table->id();

            // Scope: global (null) or per-location
            $table->unsignedInteger('location_id')->nullable();
            $table->string('location_name')->nullable();

            // Capacity thresholds (percentage)
            $table->unsignedTinyInteger('memory_warning_threshold')->default(80);
            $table->unsignedTinyInteger('memory_critical_threshold')->default(95);
            $table->unsignedTinyInteger('disk_warning_threshold')->default(80);
            $table->unsignedTinyInteger('disk_critical_threshold')->default(95);

            // Notification settings
            $table->boolean('email_notifications')->default(true);
            $table->json('notification_emails')->nullable(); // Array of emails
            $table->boolean('webhook_notifications')->default(false);
            $table->string('webhook_url')->nullable();

            // Cooldown to prevent spam
            $table->unsignedInteger('cooldown_minutes')->default(60);
            $table->timestamp('last_notification_at')->nullable();

            // Status
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Indexes
            $table->index(['location_id']);
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptero_alert_configs');
    }
};
