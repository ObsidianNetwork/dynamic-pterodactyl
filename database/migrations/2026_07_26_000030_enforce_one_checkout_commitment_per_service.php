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

        Schema::table('ptero_resource_reservations', function (Blueprint $table) {
            // service_id is SET NULL, so MariaDB cannot use it as the source of
            // a generated partial-unique column. Preserve an immutable guard.
            $table->unsignedBigInteger('service_guard_id')->nullable()->after('service_id');
        });

        DB::table('ptero_resource_reservations')
            ->whereNotNull('service_id')
            ->whereNull('service_guard_id')
            ->update(['service_guard_id' => DB::raw('service_id')]);

        $activeStatuses = ['pending', 'paid_committed', 'confirmed'];
        $duplicateGroups = DB::table('ptero_resource_reservations')
            ->where('purpose', 'checkout')
            ->whereNotNull('service_id')
            ->whereIn('status', $activeStatuses)
            ->get()
            ->groupBy('service_id')
            ->filter(fn ($rows) => $rows->count() > 1);

        foreach ($duplicateGroups as $serviceId => $rows) {
            $externalIdentities = $rows
                ->where('status', 'confirmed')
                ->filter(fn ($row) => $row->external_server_id !== null || $row->external_server_uuid !== null)
                ->map(fn ($row) => ($row->external_server_id ?? 'unknown')
                    .':' . ($row->external_server_uuid ?? 'unknown'))
                ->unique()
                ->values();
            if ($externalIdentities->count() > 1) {
                throw new \RuntimeException(
                    "Service {$serviceId} has conflicting confirmed Pterodactyl identities; "
                    .'resolve them manually before running the durable-fulfillment migration.'
                );
            }

            $ranked = $rows->sort(function ($left, $right): int {
                $rank = static function ($row): int {
                    if (
                        $row->status === 'confirmed'
                        && $row->external_server_id !== null
                        && $row->external_server_uuid !== null
                    ) {
                        return 4;
                    }

                    return match ($row->status) {
                        'confirmed' => 3,
                        'paid_committed' => 2,
                        'pending' => 1,
                        default => 0,
                    };
                };
                $status = $rank($right) <=> $rank($left);
                if ($status !== 0) {
                    return $status;
                }

                $leftFingerprint = $left->configuration_fingerprint !== null ? 1 : 0;
                $rightFingerprint = $right->configuration_fingerprint !== null ? 1 : 0;
                if ($leftFingerprint !== $rightFingerprint) {
                    return $rightFingerprint <=> $leftFingerprint;
                }

                return (int) $right->id <=> (int) $left->id;
            })->values();
            $retiredIds = $ranked->slice(1)->pluck('id');
            if ($retiredIds->isEmpty()) {
                continue;
            }

            DB::table('ptero_resource_reservations')
                ->whereIn('id', $retiredIds)
                ->update([
                    'status' => 'cancelled',
                    'admin_notes' => 'Retired duplicate service commitment during durable-fulfillment migration.',
                    'provisioning_started_at' => null,
                    'provisioning_lease_id' => null,
                    'updated_at' => now(),
                ]);
            DB::table('ptero_reservation_allocations')
                ->whereIn('reservation_id', $retiredIds)
                ->whereNull('released_at')
                ->update([
                    'released_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX ptero_reservations_active_checkout_service_unique '
                .'ON ptero_resource_reservations (service_guard_id) '
                ."WHERE purpose = 'checkout' "
                ."AND status IN ('pending', 'paid_committed', 'confirmed') "
                .'AND service_guard_id IS NOT NULL'
            );
        } else {
            Schema::table('ptero_resource_reservations', function (Blueprint $table) {
                $table
                    ->unsignedBigInteger('active_checkout_service_id')
                    ->nullable()
                    ->storedAs(
                        "CASE WHEN purpose = 'checkout' AND "
                        ."(status = 'pending' OR status = 'paid_committed' OR status = 'confirmed') "
                        .'THEN service_guard_id ELSE NULL END'
                    )
                    ->after('service_guard_id');
                $table->unique(
                    'active_checkout_service_id',
                    'ptero_reservations_active_checkout_service_unique'
                );
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement(
                'DROP INDEX IF EXISTS ptero_reservations_active_checkout_service_unique'
            );
            Schema::table(
                'ptero_resource_reservations',
                fn (Blueprint $table) =>
                    $table->dropColumn('service_guard_id')
            );
        } else {
            Schema::table('ptero_resource_reservations', function (Blueprint $table) {
                $table->dropUnique('ptero_reservations_active_checkout_service_unique');
                $table->dropColumn([
                    'active_checkout_service_id',
                    'service_guard_id',
                ]);
            });
        }
    }
};
