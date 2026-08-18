<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::table('ptero_resource_reservations', function (Blueprint $table) use ($driver) {
            $table->string('purpose', 24)->default('checkout')->after('id');
            $table->unsignedInteger('reserved_memory')->default(0)->after('memory');
            $table->unsignedInteger('reserved_cpu')->default(0)->after('cpu');
            $table->unsignedBigInteger('reserved_disk')->default(0)->after('disk');
            $table->unsignedBigInteger('service_upgrade_id')->nullable()->after('service_id');
            // Keep the partial-unique source separate from the nullable FK.
            // MariaDB rejects generated columns derived from SET NULL FKs.
            $table->unsignedBigInteger('upgrade_guard_id')->nullable()->after('service_upgrade_id');
            if ($driver !== 'sqlite') {
                $table->unsignedBigInteger('active_upgrade_id')
                    ->nullable()
                    ->storedAs(
                        "CASE WHEN purpose = 'upgrade' AND "
                        ."(status = 'pending' OR status = 'paid_committed') "
                        .'THEN upgrade_guard_id ELSE NULL END'
                    )
                    ->after('upgrade_guard_id');
            }

            $table->foreign('service_upgrade_id')
                ->references('id')
                ->on('service_upgrades')
                ->restrictOnDelete();
            if ($driver !== 'sqlite') {
                $table->unique(
                    'active_upgrade_id',
                    'ptero_reservations_active_upgrade_unique'
                );
            }
            $table->index(
                ['purpose', 'status'],
                'ptero_reservations_purpose_status_idx'
            );
            $table->index(
                'service_upgrade_id',
                'ptero_reservations_service_upgrade_idx'
            );
        });

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX ptero_reservations_active_upgrade_unique '
                .'ON ptero_resource_reservations (upgrade_guard_id) '
                ."WHERE purpose = 'upgrade' "
                ."AND status IN ('pending', 'paid_committed') "
                .'AND upgrade_guard_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement(
                'DROP INDEX IF EXISTS ptero_reservations_active_upgrade_unique'
            );
        }

        Schema::table('ptero_resource_reservations', function (Blueprint $table) use ($driver) {
            $table->dropForeign(['service_upgrade_id']);
            if ($driver !== 'sqlite') {
                $table->dropUnique(
                    'ptero_reservations_active_upgrade_unique'
                );
            }
            $table->dropIndex('ptero_reservations_purpose_status_idx');
            $table->dropIndex('ptero_reservations_service_upgrade_idx');
            $columns = [
                'upgrade_guard_id',
                'service_upgrade_id',
                'reserved_memory',
                'reserved_cpu',
                'reserved_disk',
                'purpose',
            ];
            if ($driver !== 'sqlite') {
                array_unshift($columns, 'active_upgrade_id');
            }
            $table->dropColumn($columns);
        });
    }
};
