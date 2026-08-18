<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptero_node_capacity_policies', function (Blueprint $table) {
            $table->id();
            $table->string('panel_identity', 64);
            $table->string('node_uuid', 36);
            $table->unsignedBigInteger('node_id');
            $table->unsignedInteger('location_id');
            $table->unsignedInteger('cpu_capacity_percent');
            $table->unsignedInteger('cpu_overcommit_bps')->default(10000);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(
                ['panel_identity', 'node_uuid'],
                'ptero_node_capacity_panel_uuid_unique'
            );
            $table->index(
                ['panel_identity', 'node_id'],
                'ptero_node_capacity_panel_id_index'
            );
            $table->index(
                ['panel_identity', 'location_id'],
                'ptero_node_capacity_panel_location_index'
            );
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('ptero_node_capacity_policies');
    }
};
