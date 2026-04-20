<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptero_pricing_configs', function (Blueprint $table) {
            $table->id();

            // Link to Paymenter product
            $table->unsignedBigInteger('product_id')->unique();

            // Pricing model selection
            $table->enum('pricing_model', [
                'linear',
                'tiered',
                'base_plus_addon'
            ])->default('linear');

            // JSON configuration for pricing model
            // Structure varies by model - see 07-PRICING-MODELS.md
            $table->json('pricing_config');

            // Memory slider configuration (stored in MB)
            $table->unsignedInteger('min_memory')->default(1024);      // 1GB
            $table->unsignedInteger('max_memory')->default(65536);     // 64GB
            $table->unsignedInteger('memory_step')->default(1024);     // 1GB steps
            $table->unsignedInteger('default_memory')->default(4096);  // 4GB default

            // CPU slider configuration (percentage: 100 = 1 core)
            $table->unsignedInteger('min_cpu')->default(100);          // 1 core
            $table->unsignedInteger('max_cpu')->default(800);          // 8 cores
            $table->unsignedInteger('cpu_step')->default(100);         // 1 core steps
            $table->unsignedInteger('default_cpu')->default(200);      // 2 cores default

            // Disk slider configuration (stored in MB)
            $table->unsignedInteger('min_disk')->default(10240);       // 10GB
            $table->unsignedInteger('max_disk')->default(512000);      // 500GB
            $table->unsignedInteger('disk_step')->default(10240);      // 10GB steps
            $table->unsignedInteger('default_disk')->default(51200);   // 50GB default

            // Feature toggles
            $table->boolean('enable_memory_slider')->default(true);
            $table->boolean('enable_cpu_slider')->default(true);
            $table->boolean('enable_disk_slider')->default(true);
            $table->boolean('is_active')->default(true);

            // Customer display customization (labels, tooltips)
            $table->json('display_config')->nullable();

            // Location restrictions (null = all locations allowed)
            $table->json('allowed_locations')->nullable();

            $table->timestamps();

            // Foreign key
            $table->foreign('product_id')
                  ->references('id')
                  ->on('products')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptero_pricing_configs');
    }
};
