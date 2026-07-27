<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SchedulerHealthService
{
    public const TASK_EXPIRE_CHECKOUT = 'expire_checkout_reservations';

    public const TASK_EXPIRE_UPGRADES = 'expire_unpaid_upgrades';

    public const TASK_RECONCILE_CHECKOUT = 'reconcile_paid_checkout_commitments';

    public const TASK_RECONCILE_UPGRADES = 'reconcile_stalled_upgrades';

    public const TASK_CAPACITY_ALERTS = 'check_capacity_alerts';

    private const ALERT_COOLDOWN_MINUTES = 60;

    public function __construct(
        private SchedulerOperatorAlertService $operatorAlerts
    ) {}

    /**
     * @return array<string, array{
     *     expected_interval_seconds: int,
     *     lag_threshold_seconds: int
     * }>
     */
    public static function taskDefinitions(): array
    {
        return [
            self::TASK_EXPIRE_CHECKOUT => [
                'expected_interval_seconds' => 60,
                'lag_threshold_seconds' => 300,
            ],
            self::TASK_EXPIRE_UPGRADES => [
                'expected_interval_seconds' => 60,
                'lag_threshold_seconds' => 300,
            ],
            self::TASK_RECONCILE_CHECKOUT => [
                'expected_interval_seconds' => 600,
                'lag_threshold_seconds' => 1800,
            ],
            self::TASK_RECONCILE_UPGRADES => [
                'expected_interval_seconds' => 600,
                'lag_threshold_seconds' => 1800,
            ],
            self::TASK_CAPACITY_ALERTS => [
                'expected_interval_seconds' => 300,
                'lag_threshold_seconds' => 900,
            ],
        ];
    }

    /**
     * Run one independently scheduled task and persist whether the entire
     * invocation was healthy. A partial run never refreshes last_succeeded_at.
     */
    public function run(string $taskName, callable $task): int
    {
        $this->startRun($taskName);
        $processed = 0;

        try {
            $result = $task();
            $processed = is_int($result) ? $result : 0;
        } catch (\Throwable $exception) {
            $this->recordFailure($taskName, [
                'kind' => 'task_failure',
                'task' => $taskName,
                'entity_type' => 'scheduler_task',
                'entity_id' => $taskName,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ], $exception);
            $this->finishRun($taskName, $processed);

            throw $exception;
        }

        $this->finishRun($taskName, $processed);

        return $processed;
    }

    /**
     * Process a bounded set of database identities without allowing one bad
     * row to suppress later work.
     */
    public function processRows(
        string $taskName,
        string $entityType,
        iterable $entityIds,
        callable $processor,
    ): int {
        $processed = 0;

        foreach ($entityIds as $entityId) {
            try {
                if ($processor($entityId)) {
                    $processed++;
                }
            } catch (\Throwable $exception) {
                $this->recordRowFailure(
                    $taskName,
                    $entityType,
                    is_numeric($entityId) ? (int) $entityId : (string) $entityId,
                    $exception
                );
            }
        }

        return $processed;
    }

    /**
     * Select and process one fair, bounded cycle segment. The persisted cursor
     * advances before every row attempt, so a deterministic failure cannot pin
     * the first page forever. A short forward page wraps to the lowest eligible
     * identities and eventually retries repaired rows.
     *
     * @param  callable(): (EloquentBuilder|QueryBuilder)  $eligibleQuery
     */
    public function processEligibleRows(
        string $taskName,
        string $entityType,
        int $limit,
        callable $eligibleQuery,
        callable $processor,
    ): int {
        $limit = max(1, $limit);
        $this->ensureTask($taskName);
        $cursor = (int) DB::table('ptero_scheduler_heartbeats')
            ->where('task_name', $taskName)
            ->value('last_scanned_entity_id');
        $baseQuery = $eligibleQuery();
        if (
            ! $baseQuery instanceof EloquentBuilder
            && ! $baseQuery instanceof QueryBuilder
        ) {
            throw new \InvalidArgumentException(
                'The scheduler eligible-query factory must return a database query builder.'
            );
        }

        $entityIds = (clone $baseQuery)
            ->where('id', '>', $cursor)
            ->reorder()
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($entityId): int => (int) $entityId)
            ->values();
        $remaining = $limit - $entityIds->count();
        if ($remaining > 0 && $cursor > 0) {
            $wrappedIds = (clone $baseQuery)
                ->where('id', '<=', $cursor)
                ->reorder()
                ->orderBy('id')
                ->limit($remaining)
                ->pluck('id')
                ->map(fn ($entityId): int => (int) $entityId);
            $entityIds = $entityIds
                ->concat($wrappedIds)
                ->values();
        }

        return $this->processRows(
            $taskName,
            $entityType,
            $entityIds,
            function (int $entityId) use (
                $taskName,
                $processor
            ): bool {
                DB::table('ptero_scheduler_heartbeats')
                    ->where('task_name', $taskName)
                    ->update([
                        'last_scanned_entity_id' => $entityId,
                        'updated_at' => now(),
                    ]);

                return $processor($entityId);
            }
        );
    }

    public function recordRowFailure(
        string $taskName,
        string $entityType,
        int|string $entityId,
        \Throwable $exception,
    ): void {
        $this->recordFailure($taskName, [
            'kind' => 'row_failure',
            'task' => $taskName,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
            'failed_at' => now()->toIso8601String(),
        ], $exception);
    }

    /**
     * Persist and alert for tasks that have not completed one fully healthy
     * run within their configured threshold.
     */
    public function checkForLag(): int
    {
        $lagging = 0;

        foreach (self::taskDefinitions() as $taskName => $definition) {
            $context = null;
            try {
                $this->ensureTask($taskName);
                $context = DB::transaction(function () use (
                    $taskName,
                    $definition
                ): ?array {
                    $heartbeat = DB::table('ptero_scheduler_heartbeats')
                        ->where('task_name', $taskName)
                        ->lockForUpdate()
                        ->first();
                    if ($heartbeat === null) {
                        return null;
                    }

                    $reference = $heartbeat->last_succeeded_at
                        ?? $heartbeat->created_at;
                    $lagSeconds = max(
                        0,
                        now()->getTimestamp()
                            - Carbon::parse($reference)->getTimestamp()
                    );
                    $isLagging = $lagSeconds
                        > (int) $definition['lag_threshold_seconds'];
                    $shouldAlert = $isLagging
                        && (
                            $heartbeat->last_alerted_at === null
                            || Carbon::parse($heartbeat->last_alerted_at)
                                ->lte(now()->subMinutes(
                                    self::ALERT_COOLDOWN_MINUTES
                                ))
                        );

                    DB::table('ptero_scheduler_heartbeats')
                        ->where('id', $heartbeat->id)
                        ->update([
                            'last_lag_checked_at' => now(),
                            'lag_detected_at' => $isLagging
                                ? ($heartbeat->lag_detected_at ?? now())
                                : null,
                            'last_alerted_at' => $shouldAlert
                                ? now()
                                : $heartbeat->last_alerted_at,
                            'updated_at' => now(),
                        ]);

                    if (! $isLagging) {
                        return null;
                    }

                    return [
                        'kind' => 'scheduler_lag',
                        'task' => $taskName,
                        'lag_seconds' => $lagSeconds,
                        'lag_threshold_seconds' => (int) $definition[
                            'lag_threshold_seconds'
                        ],
                        'last_succeeded_at' => $heartbeat->last_succeeded_at,
                        'error' => 'No fully successful run completed within the scheduler lag threshold.',
                        'should_alert' => $shouldAlert,
                    ];
                }, 5);
            } catch (\Throwable $exception) {
                $this->safeLog('error', 'Scheduler heartbeat lag check failed', [
                    'task' => $taskName,
                    'error' => $exception->getMessage(),
                ]);
                $this->reportThrowable($exception);
            }

            if ($context === null) {
                continue;
            }

            $lagging++;
            if ($context['should_alert']) {
                unset($context['should_alert']);
                $this->safeLog(
                    'warning',
                    'Dynamic Pterodactyl scheduled task is lagging',
                    $context
                );
                $this->notifyOperators($context);
            }
        }

        return $lagging;
    }

    private function startRun(string $taskName): void
    {
        try {
            $this->ensureTask($taskName);
            DB::table('ptero_scheduler_heartbeats')
                ->where('task_name', $taskName)
                ->update([
                    'last_started_at' => now(),
                    'last_failure_count' => 0,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $exception) {
            $this->safeLog('error', 'Scheduler heartbeat start write failed', [
                'task' => $taskName,
                'error' => $exception->getMessage(),
            ]);
            $this->reportThrowable($exception);
        }
    }

    private function finishRun(string $taskName, int $processed): void
    {
        try {
            DB::transaction(function () use ($taskName, $processed): void {
                $heartbeat = DB::table('ptero_scheduler_heartbeats')
                    ->where('task_name', $taskName)
                    ->lockForUpdate()
                    ->first();
                if ($heartbeat === null) {
                    return;
                }

                $healthy = (int) $heartbeat->last_failure_count === 0;
                $updates = [
                    'last_completed_at' => now(),
                    'last_processed_count' => max(0, $processed),
                    'consecutive_failures' => $healthy
                        ? 0
                        : ((int) $heartbeat->consecutive_failures + 1),
                    'updated_at' => now(),
                ];
                if ($healthy) {
                    $updates += [
                        'last_succeeded_at' => now(),
                        'lag_detected_at' => null,
                        'last_error' => null,
                        'last_failure_context' => null,
                    ];
                }

                DB::table('ptero_scheduler_heartbeats')
                    ->where('id', $heartbeat->id)
                    ->update($updates);
            }, 5);
        } catch (\Throwable $exception) {
            $this->safeLog('error', 'Scheduler heartbeat completion write failed', [
                'task' => $taskName,
                'error' => $exception->getMessage(),
            ]);
            $this->reportThrowable($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordFailure(
        string $taskName,
        array $context,
        \Throwable $exception,
    ): void {
        $this->safeLog(
            'error',
            'Dynamic Pterodactyl scheduled work failed',
            $context
        );
        $this->reportThrowable($exception);

        $shouldAlert = false;
        try {
            $this->ensureTask($taskName);
            $shouldAlert = DB::transaction(function () use (
                $taskName,
                $context
            ): bool {
                $heartbeat = DB::table('ptero_scheduler_heartbeats')
                    ->where('task_name', $taskName)
                    ->lockForUpdate()
                    ->first();
                if ($heartbeat === null) {
                    return false;
                }

                $shouldAlert = $heartbeat->last_alerted_at === null
                    || Carbon::parse($heartbeat->last_alerted_at)
                        ->lte(now()->subMinutes(
                            self::ALERT_COOLDOWN_MINUTES
                        ));

                DB::table('ptero_scheduler_heartbeats')
                    ->where('id', $heartbeat->id)
                    ->update([
                        'last_failed_at' => now(),
                        'last_failure_count' => DB::raw(
                            'last_failure_count + 1'
                        ),
                        'last_error' => substr(
                            (string) ($context['error'] ?? ''),
                            0,
                            4000
                        ),
                        'last_failure_context' => json_encode(
                            $context,
                            JSON_THROW_ON_ERROR
                                | JSON_INVALID_UTF8_SUBSTITUTE
                        ),
                        'last_alerted_at' => $shouldAlert
                            ? now()
                            : $heartbeat->last_alerted_at,
                        'updated_at' => now(),
                    ]);

                return $shouldAlert;
            }, 5);
        } catch (\Throwable $heartbeatException) {
            $this->safeLog('error', 'Scheduler failure heartbeat write failed', [
                'task' => $taskName,
                'entity_type' => $context['entity_type'] ?? null,
                'entity_id' => $context['entity_id'] ?? null,
                'error' => $heartbeatException->getMessage(),
            ]);
            $this->reportThrowable($heartbeatException);
        }

        if ($shouldAlert) {
            $this->notifyOperators($context);
        }
    }

    private function ensureTask(string $taskName): void
    {
        $definition = self::taskDefinitions()[$taskName] ?? [
            'expected_interval_seconds' => 300,
            'lag_threshold_seconds' => 900,
        ];
        $now = now();

        DB::table('ptero_scheduler_heartbeats')->upsert([[
            'task_name' => $taskName,
            'expected_interval_seconds' => $definition[
                'expected_interval_seconds'
            ],
            'lag_threshold_seconds' => $definition[
                'lag_threshold_seconds'
            ],
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['task_name'], [
            'expected_interval_seconds',
            'lag_threshold_seconds',
            'updated_at',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function notifyOperators(array $context): void
    {
        try {
            $this->operatorAlerts->notify($context);
        } catch (\Throwable $exception) {
            $this->safeLog(
                'critical',
                'Scheduler failure operator alert could not be delivered',
                [
                    'task' => $context['task'] ?? null,
                    'entity_type' => $context['entity_type'] ?? null,
                    'entity_id' => $context['entity_id'] ?? null,
                    'error' => $exception->getMessage(),
                ]
            );
            $this->reportThrowable($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function safeLog(
        string $level,
        string $message,
        array $context,
    ): void {
        try {
            Log::{$level}($message, $context);
        } catch (\Throwable) {
            // Plain unit tests may not boot Laravel's logging facade.
        }
    }

    private function reportThrowable(\Throwable $throwable): void
    {
        try {
            app(ExceptionHandler::class)->report($throwable);
        } catch (\Throwable) {
            // Heartbeat reporting must never suppress later lifecycle rows.
        }
    }
}
