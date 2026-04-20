<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The ptero_pricing_configs table is no longer needed because:
     * - Pricing configuration is now stored in native ConfigOption metadata
     * - The Setup Wizard creates dynamic_slider ConfigOptions directly
     * - Dashboard and services now read from ConfigOptions
     */
    public function up(): void
    {
        Schema::dropIfExists('ptero_pricing_configs');
    }

    /**
     * Reverse the migrations.
     *
     * Recreates the table structure for rollback purposes.
     * Note: Data will be lost on rollback - use Setup Wizard to reconfigure.
     */
    public function down(): void
    {
        Schema::create('ptero_pricing_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('pricing_model')->default('linear');
            $table->json('pricing_config')->nullable();

            // Memory slider config (stored in MB)
            $table->boolean('enable_memory_slider')->default(true);
            $table->unsignedInteger('min_memory')->default(1024);
            $table->unsignedInteger('max_memory')->default(65536);
            $table->unsignedInteger('memory_step')->default(1024);
            $table->unsignedInteger('default_memory')->default(4096);

            // CPU slider config (stored as percentage, 100 = 1 core)
            $table->boolean('enable_cpu_slider')->default(true);
            $table->unsignedInteger('min_cpu')->default(100);
            $table->unsignedInteger('max_cpu')->default(800);
            $table->unsignedInteger('cpu_step')->default(100);
            $table->unsignedInteger('default_cpu')->default(200);

            // Disk slider config (stored in MB)
            $table->boolean('enable_disk_slider')->default(true);
            $table->unsignedInteger('min_disk')->default(10240);
            $table->unsignedInteger('max_disk')->default(102400);
            $table->unsignedInteger('disk_step')->default(10240);
            $table->unsignedInteger('default_disk')->default(20480);

            // Display settings
            $table->json('display_config')->nullable();
            $table->json('allowed_locations')->nullable();

            $table->timestamps();

            $table->unique('product_id');
        });
    }
};
