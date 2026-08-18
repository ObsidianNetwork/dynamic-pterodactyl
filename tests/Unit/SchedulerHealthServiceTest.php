<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig;
use Paymenter\Extensions\Others\DynamicPterodactyl\Notifications\SchedulerTaskFailureNotification;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AlertService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\SchedulerHealthService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\SchedulerOperatorAlertService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\LaravelTestCase;

class SchedulerHealthServiceTest extends LaravelTestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_bad_row_does_not_stop_later_rows(): void
    {
        $operatorAlerts = Mockery::mock(
            SchedulerOperatorAlertService::class
        );
        $health = Mockery::mock(
            SchedulerHealthService::class,
            [$operatorAlerts]
        )->makePartial();
        $failure = new \RuntimeException('corrupt signed row');
        $health->shouldReceive('recordRowFailure')
            ->once()
            ->with(
                SchedulerHealthService::TASK_EXPIRE_CHECKOUT,
                'resource_reservation',
                41,
                $failure
            );
        $visited = [];

        $processed = $health->processRows(
            SchedulerHealthService::TASK_EXPIRE_CHECKOUT,
            'resource_reservation',
            [41, 42, 43],
            function (int $reservationId) use (
                &$visited,
                $failure
            ): bool {
                $visited[] = $reservationId;
                if ($reservationId === 41) {
                    throw $failure;
                }

                return true;
            }
        );

        $this->assertSame([41, 42, 43], $visited);
        $this->assertSame(2, $processed);
    }

    public function test_cursor_reaches_row_after_a_full_failed_page(): void
    {
        $candidateIds = $this->insertSchedulerCandidates(101);
        $failedIds = array_fill_keys(
            array_slice($candidateIds, 0, 100),
            true
        );
        $operatorAlerts = Mockery::mock(
            SchedulerOperatorAlertService::class
        );
        $health = Mockery::mock(
            SchedulerHealthService::class,
            [$operatorAlerts]
        )->makePartial();
        $health->shouldReceive('recordRowFailure')->times(100);
        $task = SchedulerHealthService::TASK_EXPIRE_CHECKOUT;
        DB::table('ptero_scheduler_heartbeats')
            ->where('task_name', $task)
            ->update(['last_scanned_entity_id' => 0]);
        $eligible = fn () => DB::table('ptero_alert_configs')
            ->where('location_name', 'like', 'scheduler-cursor-%');
        $firstVisited = [];

        $this->assertSame(
            0,
            $health->processEligibleRows(
                $task,
                'cursor_fixture',
                100,
                $eligible,
                function (int $candidateId) use (
                    &$firstVisited,
                    $failedIds
                ): bool {
                    $firstVisited[] = $candidateId;
                    if (isset($failedIds[$candidateId])) {
                        throw new \RuntimeException(
                            'Deterministic corrupt row.'
                        );
                    }

                    return true;
                }
            )
        );
        $this->assertSame(
            array_slice($candidateIds, 0, 100),
            $firstVisited
        );
        $this->assertSame(
            $candidateIds[99],
            (int) DB::table('ptero_scheduler_heartbeats')
                ->where('task_name', $task)
                ->value('last_scanned_entity_id')
        );

        $secondVisited = [];
        $this->assertSame(
            1,
            $health->processEligibleRows(
                $task,
                'cursor_fixture',
                100,
                $eligible,
                function (int $candidateId) use (
                    &$secondVisited,
                    $candidateIds
                ): bool {
                    $secondVisited[] = $candidateId;

                    return $candidateId === $candidateIds[100];
                }
            )
        );
        $this->assertSame($candidateIds[100], $secondVisited[0]);
    }

    public function test_wrapped_scan_retries_a_repaired_early_row(): void
    {
        $candidateIds = $this->insertSchedulerCandidates(3);
        $operatorAlerts = Mockery::mock(
            SchedulerOperatorAlertService::class
        );
        $health = Mockery::mock(
            SchedulerHealthService::class,
            [$operatorAlerts]
        )->makePartial();
        $health->shouldReceive('recordRowFailure')->times(2);
        $task = SchedulerHealthService::TASK_EXPIRE_CHECKOUT;
        DB::table('ptero_scheduler_heartbeats')
            ->where('task_name', $task)
            ->update(['last_scanned_entity_id' => 0]);
        $eligible = fn () => DB::table('ptero_alert_configs')
            ->where('location_name', 'like', 'scheduler-cursor-%');
        $firstVisited = [];

        $this->assertSame(
            0,
            $health->processEligibleRows(
                $task,
                'cursor_fixture',
                2,
                $eligible,
                function (int $candidateId) use (
                    &$firstVisited
                ): bool {
                    $firstVisited[] = $candidateId;
                    throw new \RuntimeException(
                        'Temporarily corrupt row.'
                    );
                }
            )
        );
        $this->assertSame(
            array_slice($candidateIds, 0, 2),
            $firstVisited
        );

        $secondVisited = [];
        $this->assertSame(
            1,
            $health->processEligibleRows(
                $task,
                'cursor_fixture',
                2,
                $eligible,
                function (int $candidateId) use (
                    &$secondVisited,
                    $candidateIds
                ): bool {
                    $secondVisited[] = $candidateId;

                    return $candidateId === $candidateIds[0];
                }
            )
        );
        $this->assertSame(
            [$candidateIds[2], $candidateIds[0]],
            $secondVisited
        );
        $this->assertSame(
            $candidateIds[0],
            (int) DB::table('ptero_scheduler_heartbeats')
                ->where('task_name', $task)
                ->value('last_scanned_entity_id')
        );
    }

    public function test_migration_seeds_every_runtime_task_definition(): void
    {
        foreach (
            SchedulerHealthService::taskDefinitions() as $taskName => $definition
        ) {
            $this->assertDatabaseHas('ptero_scheduler_heartbeats', [
                'task_name' => $taskName,
                'expected_interval_seconds' => $definition[
                    'expected_interval_seconds'
                ],
                'lag_threshold_seconds' => $definition[
                    'lag_threshold_seconds'
                ],
                'last_scanned_entity_id' => 0,
            ]);
        }
    }

    public function test_partial_run_keeps_previous_success_and_row_identity(): void
    {
        Carbon::setTestNow('2026-07-27 12:00:00');
        $operatorAlerts = Mockery::mock(
            SchedulerOperatorAlertService::class
        );
        $operatorAlerts->shouldReceive('notify')
            ->once()
            ->with(Mockery::on(
                fn (array $context): bool => $context['task']
                    === SchedulerHealthService::TASK_EXPIRE_CHECKOUT
                    && $context['entity_type'] === 'resource_reservation'
                    && $context['entity_id'] === 501
                    && $context['error'] === 'invalid reservation'
            ));
        $health = new SchedulerHealthService($operatorAlerts);
        $task = SchedulerHealthService::TASK_EXPIRE_CHECKOUT;

        DB::table('ptero_scheduler_heartbeats')
            ->where('task_name', $task)
            ->delete();

        $this->assertSame(2, $health->run($task, fn (): int => 2));
        $healthy = DB::table('ptero_scheduler_heartbeats')
            ->where('task_name', $task)
            ->first();
        $this->assertSame(2, (int) $healthy->last_processed_count);
        $this->assertSame(0, (int) $healthy->last_failure_count);
        $this->assertSame(
            '2026-07-27 12:00:00',
            Carbon::parse($healthy->last_succeeded_at)->toDateTimeString()
        );

        Carbon::setTestNow('2026-07-27 12:01:00');
        $this->assertSame(
            1,
            $health->run(
                $task,
                fn (): int => $health->processRows(
                    $task,
                    'resource_reservation',
                    [501, 502],
                    function (int $reservationId): bool {
                        if ($reservationId === 501) {
                            throw new \RuntimeException(
                                'invalid reservation'
                            );
                        }

                        return true;
                    }
                )
            )
        );

        $partial = DB::table('ptero_scheduler_heartbeats')
            ->where('task_name', $task)
            ->first();
        $context = json_decode(
            (string) $partial->last_failure_context,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(1, (int) $partial->last_processed_count);
        $this->assertSame(1, (int) $partial->last_failure_count);
        $this->assertSame(1, (int) $partial->consecutive_failures);
        $this->assertSame('invalid reservation', $partial->last_error);
        $this->assertSame(501, $context['entity_id']);
        $this->assertSame('resource_reservation', $context['entity_type']);
        $this->assertSame(
            '2026-07-27 12:00:00',
            Carbon::parse($partial->last_succeeded_at)->toDateTimeString()
        );
        $this->assertSame(
            '2026-07-27 12:01:00',
            Carbon::parse($partial->last_completed_at)->toDateTimeString()
        );
    }

    public function test_lag_is_persisted_alerted_once_and_cleared_by_success(): void
    {
        Carbon::setTestNow('2026-07-27 12:00:00');
        $task = SchedulerHealthService::TASK_EXPIRE_CHECKOUT;
        $operatorAlerts = Mockery::mock(
            SchedulerOperatorAlertService::class
        );
        $operatorAlerts->shouldReceive('notify')
            ->once()
            ->with(Mockery::on(
                fn (array $context): bool => $context['kind']
                    === 'scheduler_lag'
                    && $context['task'] === $task
                    && $context['lag_seconds'] === 301
            ));
        $health = new SchedulerHealthService($operatorAlerts);

        foreach (
            SchedulerHealthService::taskDefinitions() as $taskName => $definition
        ) {
            DB::table('ptero_scheduler_heartbeats')->updateOrInsert(
                ['task_name' => $taskName],
                [
                    'expected_interval_seconds' => $definition[
                        'expected_interval_seconds'
                    ],
                    'lag_threshold_seconds' => $definition[
                        'lag_threshold_seconds'
                    ],
                    'last_succeeded_at' => $taskName === $task
                        ? now()->subSeconds(301)
                        : now(),
                    'last_alerted_at' => null,
                    'lag_detected_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->assertSame(1, $health->checkForLag());
        $this->assertSame(1, $health->checkForLag());
        $this->assertNotNull(
            DB::table('ptero_scheduler_heartbeats')
                ->where('task_name', $task)
                ->value('lag_detected_at')
        );

        $health->run($task, fn (): int => 0);

        $heartbeat = DB::table('ptero_scheduler_heartbeats')
            ->where('task_name', $task)
            ->first();
        $this->assertNull($heartbeat->lag_detected_at);
        $this->assertSame(
            '2026-07-27 12:00:00',
            Carbon::parse($heartbeat->last_succeeded_at)->toDateTimeString()
        );
    }

    public function test_bad_capacity_config_does_not_suppress_later_config(): void
    {
        DB::table('ptero_alert_configs')->delete();
        DB::table('ptero_scheduler_heartbeats')
            ->where(
                'task_name',
                SchedulerHealthService::TASK_CAPACITY_ALERTS
            )
            ->delete();
        $bad = AlertConfig::create([
            'location_id' => 71,
            'location_name' => 'Broken',
            'email_notifications' => false,
            'webhook_notifications' => false,
            'cooldown_minutes' => 60,
            'is_active' => true,
        ]);
        $good = AlertConfig::create([
            'location_id' => 72,
            'location_name' => 'Healthy',
            'email_notifications' => false,
            'webhook_notifications' => false,
            'cooldown_minutes' => 60,
            'is_active' => true,
        ]);
        $resources = Mockery::mock(ResourceCalculationService::class);
        $resources->shouldReceive('getLocationAvailability')
            ->once()
            ->with(71)
            ->andThrow(new \RuntimeException('panel payload invalid'));
        $resources->shouldReceive('getLocationAvailability')
            ->once()
            ->with(72)
            ->andReturn([
                'location_id' => 72,
                'location_name' => 'Healthy',
                'total_capacity' => [
                    'memory' => 100,
                    'cpu' => 100,
                    'disk' => 100,
                ],
                'total_available' => [
                    'memory' => 100,
                    'cpu' => 100,
                    'disk' => 100,
                ],
            ]);
        $operatorAlerts = Mockery::mock(
            SchedulerOperatorAlertService::class
        );
        $operatorAlerts->shouldReceive('notify')
            ->once()
            ->with(Mockery::on(
                fn (array $context): bool => $context['entity_type']
                    === 'alert_config_location'
                    && $context['entity_id'] === ($bad->id.':71')
            ));
        $this->app->instance(
            SchedulerHealthService::class,
            new SchedulerHealthService($operatorAlerts)
        );

        $processed = (new AlertService($resources))
            ->checkCapacityAlerts(chunkSize: 1);

        $this->assertSame(2, $processed);
        $this->assertDatabaseHas('ptero_alert_configs', [
            'id' => $good->id,
            'is_active' => true,
        ]);
        $heartbeat = DB::table('ptero_scheduler_heartbeats')
            ->where(
                'task_name',
                SchedulerHealthService::TASK_CAPACITY_ALERTS
            )
            ->first();
        $context = json_decode(
            (string) $heartbeat->last_failure_context,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame(($bad->id.':71'), $context['entity_id']);
        $this->assertSame('panel payload invalid', $heartbeat->last_error);
    }

    public function test_operator_alert_retains_failed_row_identity(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role_id' => 1]);
        $context = [
            'kind' => 'row_failure',
            'task' => SchedulerHealthService::TASK_RECONCILE_UPGRADES,
            'entity_type' => 'service_upgrade',
            'entity_id' => 991,
            'error' => 'upgrade snapshot drifted',
        ];

        (new SchedulerOperatorAlertService)->notify($context);

        Notification::assertSentTo(
            $admin,
            SchedulerTaskFailureNotification::class,
            fn (SchedulerTaskFailureNotification $notification): bool => $notification->context === $context
        );
    }

    /**
     * @return list<int>
     */
    private function insertSchedulerCandidates(int $count): array
    {
        DB::table('ptero_alert_configs')->delete();
        $rows = [];
        foreach (range(1, $count) as $index) {
            $rows[] = [
                'location_name' => "scheduler-cursor-{$index}",
                'email_notifications' => false,
                'webhook_notifications' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('ptero_alert_configs')->insert($rows);

        return DB::table('ptero_alert_configs')
            ->where('location_name', 'like', 'scheduler-cursor-%')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
