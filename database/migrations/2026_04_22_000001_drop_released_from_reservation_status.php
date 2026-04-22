<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Data migration: change any 'released' to 'cancelled'
        DB::table('ptero_resource_reservations')
            ->where('status', 'released')
            ->update(['status' => 'cancelled']);

        // Schema migration
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE ptero_resource_reservations MODIFY status ENUM('pending','confirmed','expired','cancelled') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE ptero_resource_reservations MODIFY status ENUM('pending','confirmed','expired','cancelled','released') NOT NULL DEFAULT 'pending'");
        }
    }
};
