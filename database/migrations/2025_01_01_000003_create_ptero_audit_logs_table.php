<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptero_audit_logs', function (Blueprint $table) {
            $table->id();

            // Who made the change
            $table->unsignedBigInteger('user_id');
            $table->string('user_name');
            $table->string('user_email');

            // What was changed
            $table->string('action'); // created, updated, deleted, cancelled
            $table->string('entity_type'); // pricing_config, reservation, alert_config
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_name')->nullable(); // Product name, etc.

            // Change details
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('description')->nullable();

            // Request context
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('created_at');

            // Indexes
            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id']);
            $table->index(['created_at']);
            $table->index(['action']);

            // Foreign key
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptero_audit_logs');
    }
};
