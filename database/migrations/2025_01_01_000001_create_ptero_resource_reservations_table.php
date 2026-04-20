<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptero_resource_reservations', function (Blueprint $table) {
            $table->id();

            // Unique token for tracking reservation
            $table->string('token', 64)->unique();

            // Link to cart (nullable - cleared after checkout)
            $table->unsignedBigInteger('cart_item_id')->nullable();

            // Link to service (set after provisioning)
            $table->unsignedBigInteger('service_id')->nullable();

            // Link to user for tracking
            $table->unsignedBigInteger('user_id')->nullable();

            // Pterodactyl references
            $table->unsignedInteger('node_id');
            $table->unsignedInteger('location_id');

            // Reserved resources (all in MB except CPU)
            $table->unsignedInteger('memory');        // MB
            $table->unsignedBigInteger('disk');       // MB
            $table->unsignedInteger('cpu');           // Percentage (100 = 1 core)

            // Pricing snapshot at reservation time
            $table->decimal('calculated_price', 10, 2);
            $table->json('pricing_breakdown');

            // Status tracking
            $table->enum('status', [
                'pending',      // Cart item exists, awaiting payment
                'confirmed',    // Payment received, server created
                'expired',      // TTL exceeded without payment
                'cancelled',    // User removed from cart
                'released'      // Resources released back to pool
            ])->default('pending');

            // Admin notes
            $table->text('admin_notes')->nullable();

            // Timestamps
            $table->timestamp('expires_at');
            $table->timestamps();

            // Indexes for efficient queries
            $table->index(['node_id', 'status', 'expires_at'], 'idx_node_pending');
            $table->index(['cart_item_id']);
            $table->index(['status', 'expires_at'], 'idx_cleanup');
            $table->index(['location_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['created_at']);

            // Foreign keys
            $table->foreign('cart_item_id')
                  ->references('id')
                  ->on('cart_items')
                  ->onDelete('set null');

            $table->foreign('service_id')
                  ->references('id')
                  ->on('services')
                  ->onDelete('set null');

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptero_resource_reservations');
    }
};
