<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'ptero_scheduler_heartbeats',
            function (Blueprint $table): void {
                $table->unsignedBigInteger('last_scanned_entity_id')
                    ->default(0)
                    ->after('last_alerted_at');
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'ptero_scheduler_heartbeats',
            fn (Blueprint $table) => $table->dropColumn('last_scanned_entity_id')
        );
    }
};
