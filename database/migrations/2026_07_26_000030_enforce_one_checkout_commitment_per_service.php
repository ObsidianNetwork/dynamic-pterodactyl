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

        // Never choose a winner between service-bound commitments. Two rows
        // can represent different paid invoices, signed configurations, or
        // external servers even when one appears more complete. Retiring one
        // here would erase a financial/fulfillment obligation before the
        // readiness gate can report it.
        $this->assertNoDuplicateCheckoutCommitments();

        Schema::table('ptero_resource_reservations', function (Blueprint $table) {
            // service_id is SET NULL, so MariaDB cannot use it as the source of
            // a generated partial-unique column. Preserve an immutable guard.
            $table->unsignedBigInteger('service_guard_id')->nullable()->after('service_id');
        });

        DB::table('ptero_resource_reservations')
            ->whereNotNull('service_id')
            ->whereNull('service_guard_id')
            ->update(['service_guard_id' => DB::raw('service_id')]);

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

    /**
     * Fail before adding/backfilling the guard column. This keeps a rejected
     * migration retryable on databases where DDL auto-commits.
     */
    private function assertNoDuplicateCheckoutCommitments(): void
    {
        $duplicateGroups = DB::table('ptero_resource_reservations')
            ->where('purpose', 'checkout')
            ->whereNotNull('service_id')
            ->whereIn('status', [
                'pending',
                'paid_committed',
                'confirmed',
            ])
            ->orderBy('service_id')
            ->orderBy('id')
            ->get([
                'id',
                'service_id',
                'status',
                'invoice_id',
                'configuration_fingerprint',
                'external_server_id',
                'external_server_uuid',
            ])
            ->groupBy('service_id')
            ->filter(fn ($rows) => $rows->count() > 1);
        if ($duplicateGroups->isEmpty()) {
            return;
        }

        $details = $duplicateGroups
            ->map(function ($rows, $serviceId): string {
                $commitments = $rows
                    ->map(fn ($row): string => sprintf(
                        '#%d(status=%s, invoice=%s, fingerprint=%s, '
                            .'external_server=%s, external_uuid=%s)',
                        (int) $row->id,
                        (string) $row->status,
                        $row->invoice_id !== null
                            ? (string) $row->invoice_id
                            : 'none',
                        is_string($row->configuration_fingerprint)
                            ? $row->configuration_fingerprint
                            : 'none',
                        $row->external_server_id !== null
                            ? (string) $row->external_server_id
                            : 'none',
                        is_string($row->external_server_uuid)
                            ? $row->external_server_uuid
                            : 'none'
                    ))
                    ->implode(', ');

                return "service {$serviceId}: {$commitments}";
            })
            ->implode('; ');

        throw new \RuntimeException(
            'Dynamic Pterodactyl found duplicate active checkout '
            .'commitments and refused to retire or release any obligation. '
            .'Reconcile every invoice, signed configuration, allocation, and '
            ."external server before rerunning the migration: {$details}"
        );
    }
};
