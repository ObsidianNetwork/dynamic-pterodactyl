<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class DurableReservationForeignKeyTest extends LaravelTestCase
{
    public function test_durable_parents_are_restricted_while_cart_cleanup_sets_null(): void
    {
        $deleteRules = $this->reservationDeleteRules();

        $this->assertContains(
            $deleteRules['service_id'] ?? null,
            ['RESTRICT', 'NO ACTION']
        );
        $this->assertContains(
            $deleteRules['user_id'] ?? null,
            ['RESTRICT', 'NO ACTION']
        );
        $this->assertSame(
            'SET NULL',
            $deleteRules['cart_item_id'] ?? null
        );
    }

    public function test_sqlite_foreign_key_rebuild_preserves_partial_uniqueness_guards(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped(
                'MariaDB uses generated-column uniqueness guards.'
            );
        }

        $indexes = DB::table('sqlite_master')
            ->where('type', 'index')
            ->whereIn('name', [
                'ptero_reservations_active_upgrade_unique',
                'ptero_reservations_active_checkout_service_unique',
            ])
            ->pluck('sql', 'name')
            ->map(fn (?string $sql): string => strtolower((string) $sql));

        $this->assertStringContainsString(
            "where purpose = 'upgrade'",
            $indexes->get(
                'ptero_reservations_active_upgrade_unique',
                ''
            )
        );
        $this->assertStringContainsString(
            "status in ('pending', 'paid_committed')",
            $indexes->get(
                'ptero_reservations_active_upgrade_unique',
                ''
            )
        );
        $this->assertStringContainsString(
            "where purpose = 'checkout'",
            $indexes->get(
                'ptero_reservations_active_checkout_service_unique',
                ''
            )
        );
        $this->assertStringContainsString(
            "status in ('pending', 'paid_committed', 'confirmed')",
            $indexes->get(
                'ptero_reservations_active_checkout_service_unique',
                ''
            )
        );
    }

    /**
     * @return array<string, string>
     */
    private function reservationDeleteRules(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select(
                "PRAGMA foreign_key_list('ptero_resource_reservations')"
            ))->mapWithKeys(
                fn (object $foreignKey): array => [
                    (string) $foreignKey->from => strtoupper((string) $foreignKey->on_delete),
                ]
            )->all();
        }

        return DB::table(
            'information_schema.REFERENTIAL_CONSTRAINTS as constraints'
        )
            ->join(
                'information_schema.KEY_COLUMN_USAGE as columns',
                function ($join): void {
                    $join->on(
                        'columns.CONSTRAINT_SCHEMA',
                        '=',
                        'constraints.CONSTRAINT_SCHEMA'
                    )->on(
                        'columns.CONSTRAINT_NAME',
                        '=',
                        'constraints.CONSTRAINT_NAME'
                    )->on(
                        'columns.TABLE_NAME',
                        '=',
                        'constraints.TABLE_NAME'
                    );
                }
            )
            ->where(
                'constraints.CONSTRAINT_SCHEMA',
                DB::getDatabaseName()
            )
            ->where(
                'constraints.TABLE_NAME',
                'ptero_resource_reservations'
            )
            ->whereIn('columns.COLUMN_NAME', [
                'cart_item_id',
                'service_id',
                'user_id',
            ])
            ->get([
                'columns.COLUMN_NAME as column_name',
                'constraints.DELETE_RULE as delete_rule',
            ])
            ->mapWithKeys(
                fn (object $foreignKey): array => [
                    (string) $foreignKey->column_name => strtoupper((string) $foreignKey->delete_rule),
                ]
            )
            ->all();
    }
}
