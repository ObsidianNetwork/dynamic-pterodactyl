<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AuditLogService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class AuditLogServiceTest extends LaravelTestCase
{
    use DatabaseTransactions;

    public function test_console_audit_entries_use_a_nullable_system_identity(): void
    {
        Auth::logout();

        $auditId = app(AuditLogService::class)->log(
            'scheduler_reconciled',
            'scheduler',
            1
        );

        $this->assertDatabaseHas('ptero_audit_logs', [
            'id' => $auditId,
            'user_id' => null,
            'user_name' => 'System',
            'user_email' => 'system@localhost',
        ]);
    }

    public function test_deleting_an_operator_retains_and_anonymizes_audit_history(): void
    {
        $operator = User::factory()->create();
        Auth::login($operator);
        $auditId = app(AuditLogService::class)->log(
            'configuration_updated',
            'product_config',
            42
        );
        Auth::logout();

        $operator->delete();

        $this->assertDatabaseMissing('users', ['id' => $operator->id]);
        $this->assertDatabaseHas('ptero_audit_logs', [
            'id' => $auditId,
            'user_id' => null,
            'user_name' => $operator->name,
            'user_email' => $operator->email,
        ]);
    }

    public function test_audit_user_foreign_key_sets_null_instead_of_cascading(): void
    {
        $rule = DB::getDriverName() === 'sqlite'
            ? collect(DB::select(
                "PRAGMA foreign_key_list('ptero_audit_logs')"
            ))
                ->first(
                    fn (object $foreignKey): bool => (string) $foreignKey->from === 'user_id'
                )
                ?->on_delete
            : DB::table(
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
                    'ptero_audit_logs'
                )
                ->where('columns.COLUMN_NAME', 'user_id')
                ->value('constraints.DELETE_RULE');

        $this->assertSame('SET NULL', strtoupper((string) $rule));
    }
}
