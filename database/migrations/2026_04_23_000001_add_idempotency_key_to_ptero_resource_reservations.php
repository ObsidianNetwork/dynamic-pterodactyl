<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ptero_resource_reservations', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('token');
            $table->string('active_idempotency_key', 64)
                ->nullable()
                ->storedAs("CASE WHEN status IN ('pending','confirmed') THEN idempotency_key ELSE NULL END")
                ->after('idempotency_key');

            $table->index(['user_id', 'idempotency_key'], 'ptero_reservations_idempotency_lookup_idx');
            $table->unique(['user_id', 'active_idempotency_key'], 'ptero_reservations_active_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ptero_resource_reservations', function (Blueprint $table) {
            $table->dropUnique('ptero_reservations_active_idempotency_unique');
            $table->dropIndex('ptero_reservations_idempotency_lookup_idx');
            $table->dropColumn(['active_idempotency_key', 'idempotency_key']);
        });
    }
};
