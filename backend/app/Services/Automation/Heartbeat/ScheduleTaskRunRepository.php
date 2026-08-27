<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Models\ScheduleTaskRun;
use App\Models\ScheduleTick;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\TriggerRule;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScheduleTaskRunRepository
{
    /**
     * 表/列存在性的实例级缓存，只缓存 true。
     *
     * 只缓存 true 的理由：本仓「数据库只增不删」是铁律，表一旦建成不会再消失，
     * 因此 true 是永久结论；而 false 是暂态——安装或迁移中途随时会翻转，把它缓存下来
     * 会让同进程内后续调用永远读到过期结论（运行台账被静默停用）。
     * 缓存挂在实例上而非静态：调度器与队列任务每次都从容器新建实例，作用域不跨请求。
     */
    private ?bool $tableReady = null;

    private ?bool $retryLineageReady = null;

    /**
     * 登记本槽位的运行记录。
     *
     * 返回值携带「是否需要派发」与「同槽已有记录的状态」两件事：调用方原先在拿到 null
     * 后还要再查一次 existingRunForTick 才能区分「本槽已终态处理」与「真正的重复投递」，
     * 而那一行就是这里 firstOrCreate 刚读出来的同一行——(schedule_tick_id, task_key,
     * source) 上有唯一索引，至多一行，两次查询必然同源。
     */
    public function markQueued(ScheduleTick $tick, ScheduledTask $task, TriggerRule $rule): QueuedRunOutcome
    {
        $run = ScheduleTaskRun::query()->firstOrCreate(
            [
                'schedule_tick_id' => (int) $tick->id,
                'task_key' => $task->key(),
                'source' => 'heartbeat',
            ],
            [
                'task_name' => $task->title(),
                'rule_description' => $rule->describe(),
                'queue' => $task->queue(),
                'status' => ScheduleTaskRun::STATUS_QUEUED,
                'queued_at' => now(),
            ],
        );

        if ($run->wasRecentlyCreated) {
            return new QueuedRunOutcome($run, null);
        }

        // 派发阶段失败不等于任务执行失败；同一 15 分钟槽位的下一次心跳应复用该行重派。
        if ($run->status === ScheduleTaskRun::STATUS_DISPATCH_FAILED) {
            $now = now();
            $dispatchError = trim((string) $run->error_msg);
            $summary = $this->appendDispatchFailure(
                $run->getAttribute('summary'),
                $dispatchError,
                $now->toDateTimeString(),
            );

            $updated = ScheduleTaskRun::query()
                ->whereKey((int) $run->id)
                ->where('status', ScheduleTaskRun::STATUS_DISPATCH_FAILED)
                ->update([
                    'status' => ScheduleTaskRun::STATUS_QUEUED,
                    'queue' => $task->queue(),
                    'rule_description' => $rule->describe(),
                    'queued_at' => $now,
                    'started_at' => null,
                    'finished_at' => null,
                    'duration_ms' => null,
                    'error_msg' => null,
                    // 重派等同新的队列生命周期，attempt 与 Job 尝试次数同口径。
                    'attempt' => 1,
                    'summary' => $summary,
                    'updated_at' => $now,
                ]);

            if ($updated === 1) {
                return new QueuedRunOutcome($run->fresh(), ScheduleTaskRun::STATUS_DISPATCH_FAILED);
            }

            // CAS 落空说明另一路已经把这行重派走了；此时内存里的状态已过期，
            // 必须重读一次才能给出与原实现一致的判定依据。这是罕见分支，多一次查询可接受。
            return new QueuedRunOutcome(null, $run->fresh()?->status);
        }

        // 本槽已有记录且无需重派：status 直接取自上面 firstOrCreate 读到的同一行，
        // 无需再查库。
        return new QueuedRunOutcome(null, $run->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function appendDispatchFailure(mixed $summaryValue, string $message, string $at): array
    {
        $summary = is_array($summaryValue) ? $summaryValue : [];
        if ($message === '') {
            return $summary;
        }

        $historyValue = $summary['dispatch_failures'] ?? [];
        $history = is_array($historyValue) ? $historyValue : [];
        $history[] = [
            'message' => mb_substr($message, 0, 2000),
            'at' => $at,
        ];
        $summary['dispatch_failures'] = array_slice($history, -5);

        return $summary;
    }

    public function markManualQueued(ScheduledTask $task, ?int $adminUserId = null): ?ScheduleTaskRun
    {
        if (! $this->tableReady()) {
            return null;
        }

        $attributes = [
            'task_key' => $task->key(),
            'task_name' => $task->title(),
            'rule_description' => '手动触发',
            'source' => 'manual_trigger',
            'queue' => $task->queue(),
            'status' => ScheduleTaskRun::STATUS_QUEUED,
            'summary' => array_filter([
                'admin_user_id' => $adminUserId,
            ], static fn (mixed $value): bool => $value !== null),
            'queued_at' => now(),
        ];

        if ($this->retryLineageReady()) {
            $attributes['attempt'] = 1;
        }

        return ScheduleTaskRun::query()->create($attributes);
    }

    public function findRun(int $runId): ?ScheduleTaskRun
    {
        if (! $this->tableReady() || $runId <= 0) {
            return null;
        }

        $run = ScheduleTaskRun::query()->find($runId);

        return $run instanceof ScheduleTaskRun ? $run : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateRuns(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        if (! $this->tableReady()) {
            return new ConcreteLengthAwarePaginator([], 0, $perPage, $page);
        }

        $query = ScheduleTaskRun::query();
        $this->applyRunFilters($query, $filters);

        $sort = trim((string) ($filters['sort'] ?? 'id'));
        $direction = strtolower(trim((string) ($filters['direction'] ?? 'desc')));
        $allowedSorts = ['id', 'task_key', 'status', 'source', 'created_at', 'queued_at', 'started_at', 'finished_at'];
        $sort = in_array($sort, $allowedSorts, true) ? $sort : 'id';
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 调度总览用的轻量运行统计；全部走索引列，禁止扫描全表。
     *
     * @return array<string, int>
     */
    public function runStatsSummary(): array
    {
        if (! $this->tableReady()) {
            return [
                'active' => 0,
                'stale' => 0,
                'failed_24h' => 0,
                'success_24h' => 0,
                'manual_retry_24h' => 0,
            ];
        }

        $since = now()->subHours(24);
        $leaseSeconds = max(
            60,
            (int) config('queue.turaidc_worker_max_timeout', 3600) + 60,
            (int) config('queue.connections.database.retry_after', 3900) + 60,
        );
        $cutoff = now()->subSeconds($leaseSeconds);
        $activeStatuses = [
            ScheduleTaskRun::STATUS_QUEUED,
            ScheduleTaskRun::STATUS_RUNNING,
            ScheduleTaskRun::STATUS_RETRYING,
        ];

        // stale 的 COALESCE 表达式无法走单列索引；外层 status 索引已把扫描基数
        // 压到活跃记录数，适合总览统计，不用于大范围筛选。
        return [
            'active' => ScheduleTaskRun::query()->whereIn('status', $activeStatuses)->count(),
            'stale' => ScheduleTaskRun::query()
                ->whereIn('status', $activeStatuses)
                ->where(function ($query) use ($cutoff): void {
                    $query
                        ->whereRaw('COALESCE(updated_at, queued_at, created_at) <= ?', [$cutoff])
                        ->orWhere(function ($nested): void {
                            $nested
                                ->whereNull('updated_at')
                                ->whereNull('queued_at')
                                ->whereNull('created_at');
                        });
                })
                ->count(),
            'failed_24h' => ScheduleTaskRun::query()
                ->where('status', ScheduleTaskRun::STATUS_FAILED)
                ->where('updated_at', '>=', $since)
                ->count(),
            'success_24h' => ScheduleTaskRun::query()
                ->where('status', ScheduleTaskRun::STATUS_SUCCESS)
                ->where('updated_at', '>=', $since)
                ->count(),
            'manual_retry_24h' => ScheduleTaskRun::query()
                ->where('source', 'manual_retry')
                ->where('created_at', '>=', $since)
                ->count(),
        ];
    }

    /**
     * 创建一次人工重跑运行，并将原失败记录标记为已发起人工重跑。
     * 两步必须在同一事务内完成，避免并发请求生成多个子运行。
     */
    public function markManualRetryQueued(
        ScheduleTaskRun $parent,
        ScheduledTask $task,
        ?int $adminUserId,
        string $reason,
    ): ?ScheduleTaskRun {
        if (! $this->retryLineageReady()) {
            return null;
        }

        return DB::transaction(function () use ($parent, $task, $adminUserId, $reason): ?ScheduleTaskRun {
            $lockedParent = ScheduleTaskRun::query()
                ->lockForUpdate()
                ->find((int) $parent->id);

            if (! $lockedParent instanceof ScheduleTaskRun
                || $lockedParent->status !== ScheduleTaskRun::STATUS_FAILED
                || $lockedParent->manual_retry_at !== null
            ) {
                return null;
            }

            $now = now();
            $updated = ScheduleTaskRun::query()
                ->whereKey((int) $lockedParent->id)
                ->where('status', ScheduleTaskRun::STATUS_FAILED)
                ->whereNull('manual_retry_at')
                ->update([
                    'manual_retry_at' => $now,
                    'manual_retry_by' => $adminUserId,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                return null;
            }

            return ScheduleTaskRun::query()->create([
                'parent_run_id' => (int) $lockedParent->id,
                'task_key' => $task->key(),
                'task_name' => $task->title(),
                'rule_description' => '失败任务人工重跑',
                'source' => 'manual_retry',
                'queue' => $task->queue(),
                'status' => ScheduleTaskRun::STATUS_QUEUED,
                'attempt' => 1,
                'summary' => [
                    'manual_retry' => [
                        'parent_run_id' => (int) $lockedParent->id,
                        'admin_user_id' => $adminUserId,
                        'reason' => $reason,
                    ],
                ],
                'queued_at' => $now,
            ]);
        });
    }

    public function activeRunForTask(string $taskKey): ?ScheduleTaskRun
    {
        if (! $this->tableReady()) {
            return null;
        }

        $taskKey = trim($taskKey);
        if ($taskKey === '') {
            return null;
        }

        return ScheduleTaskRun::query()
            ->where('task_key', $taskKey)
            ->whereIn('status', [
                ScheduleTaskRun::STATUS_QUEUED,
                ScheduleTaskRun::STATUS_RUNNING,
                ScheduleTaskRun::STATUS_RETRYING,
            ])
            ->oldest('queued_at')
            ->first();
    }

    /**
     * 只有 queued/retrying 可以进入 running；迟到或重复投递的 Job 会被拒绝执行。
     */
    public function markRunning(?int $runId, ?int $attempt = null): bool
    {
        if ($runId === null || $runId <= 0) {
            // 没有运行记录时仍允许执行，兼容调度表尚未创建的只读/诊断场景。
            return true;
        }

        $attributes = [
            'status' => ScheduleTaskRun::STATUS_RUNNING,
            'finished_at' => null,
            'duration_ms' => null,
            'error_msg' => null,
            'updated_at' => now(),
        ];
        if ($attempt !== null && $this->retryLineageReady()) {
            $attributes['attempt'] = max(1, $attempt);
        }

        $updated = ScheduleTaskRun::query()
            ->whereKey($runId)
            ->whereIn('status', [
                ScheduleTaskRun::STATUS_QUEUED,
                ScheduleTaskRun::STATUS_RETRYING,
            ])
            ->update($attributes);

        if ($updated !== 1) {
            return false;
        }

        ScheduleTaskRun::query()
            ->whereKey($runId)
            ->whereNull('started_at')
            ->update([
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        return true;
    }

    /**
     * 迟到或重复投递的 Job 被 CAS 拒绝后，在台账摘要追加拒绝痕迹，限量保留最近五次。
     * 只追加审计字段，不改变运行状态；运行随后进入 success 时成功摘要会覆盖该痕迹。
     */
    public function recordLateJobRejection(int $runId, int $attempt, string $reason): bool
    {
        if ($runId <= 0 || ! $this->tableReady()) {
            return false;
        }

        $run = ScheduleTaskRun::query()->find($runId);
        if ($run === null) {
            return false;
        }

        $summaryValue = $run->getAttribute('summary');
        $summary = is_array($summaryValue) ? $summaryValue : [];

        $historyValue = $summary['late_job_rejections'] ?? [];
        $history = is_array($historyValue) ? $historyValue : [];
        $history[] = [
            'attempt' => max(1, $attempt),
            'reason' => mb_substr($reason, 0, 500),
            'at' => now()->toDateTimeString(),
        ];
        $summary['late_job_rejections'] = array_slice($history, -5);

        return ScheduleTaskRun::query()
            ->whereKey($runId)
            ->update([
                'summary' => $summary,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function markSucceeded(?int $runId, array $summary, int $durationMs): bool
    {
        if ($runId === null || $runId <= 0) {
            return true;
        }

        return ScheduleTaskRun::query()
            ->whereKey($runId)
            ->where('status', ScheduleTaskRun::STATUS_RUNNING)
            ->update([
                'status' => ScheduleTaskRun::STATUS_SUCCESS,
                'duration_ms' => $durationMs,
                'summary' => $summary,
                'finished_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    public function markRetrying(?int $runId, string $message, int $durationMs): bool
    {
        if ($runId === null || $runId <= 0) {
            return true;
        }

        return ScheduleTaskRun::query()
            ->whereKey($runId)
            ->where('status', ScheduleTaskRun::STATUS_RUNNING)
            ->update([
                'status' => ScheduleTaskRun::STATUS_RETRYING,
                'duration_ms' => $durationMs,
                'error_msg' => mb_substr($message, 0, 2000),
                'finished_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * 兼容旧调用方：未明确声明最终失败时，运行中的记录进入 retrying。
     * 新的 Job/调度链路应使用 markTerminalFailed()。
     */
    public function markFailed(?int $runId, string $message, ?int $durationMs, bool $terminal = false): bool
    {
        if (! $terminal && $runId !== null && $runId > 0) {
            $status = ScheduleTaskRun::query()->whereKey($runId)->value('status');
            if ($status === ScheduleTaskRun::STATUS_RUNNING) {
                return $this->markRetrying($runId, $message, $durationMs ?? 0);
            }
        }

        return $this->markTerminalFailed($runId, $message, $durationMs);
    }

    public function markTerminalFailed(?int $runId, string $message, ?int $durationMs): bool
    {
        if ($runId === null || $runId <= 0) {
            return true;
        }

        $attributes = [
            'status' => ScheduleTaskRun::STATUS_FAILED,
            'error_msg' => mb_substr($message, 0, 2000),
            'finished_at' => now(),
            'updated_at' => now(),
        ];
        if ($durationMs !== null) {
            $attributes['duration_ms'] = $durationMs;
        }

        return ScheduleTaskRun::query()
            ->whereKey($runId)
            ->whereIn('status', [
                ScheduleTaskRun::STATUS_QUEUED,
                ScheduleTaskRun::STATUS_RUNNING,
                ScheduleTaskRun::STATUS_RETRYING,
                ScheduleTaskRun::STATUS_DISPATCH_FAILED,
            ])
            ->update($attributes) === 1;
    }

    public function markDispatchFailed(?int $runId, string $message): bool
    {
        if ($runId === null || $runId <= 0) {
            return false;
        }

        return ScheduleTaskRun::query()
            ->whereKey($runId)
            ->where('status', ScheduleTaskRun::STATUS_QUEUED)
            ->update([
                'status' => ScheduleTaskRun::STATUS_DISPATCH_FAILED,
                'duration_ms' => 0,
                'error_msg' => mb_substr($message, 0, 2000),
                'finished_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * 将一次「队列派发失败」的人工重跑运行重置回 queued 并复用同一记录重新派发，
     * 保留失败溯源（parent_run_id 与 manual_retry 摘要），避免重跑链路因派发失败形成死胡同。
     * 通过 CAS 条件更新，避免与并发的心跳/重派相互覆盖。
     */
    public function requeueDispatchFailedRun(int $runId): ?ScheduleTaskRun
    {
        if ($runId <= 0 || ! $this->tableReady()) {
            return null;
        }

        $now = now();
        $updated = ScheduleTaskRun::query()
            ->whereKey($runId)
            ->where('status', ScheduleTaskRun::STATUS_DISPATCH_FAILED)
            ->update([
                'status' => ScheduleTaskRun::STATUS_QUEUED,
                'queued_at' => $now,
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => null,
                'error_msg' => null,
                'attempt' => 1,
                'updated_at' => $now,
            ]);

        if ($updated !== 1) {
            return null;
        }

        $run = ScheduleTaskRun::query()->find($runId);

        return $run instanceof ScheduleTaskRun ? $run : null;
    }

    /**
     * 将明确超出安全租约的运行标记为失败，避免一条陈旧记录永久阻塞后续调度。
     * 这里不自动重跑，因为进程是否已经产生业务副作用无法仅凭数据库判断。
     */
    public function reclaimStaleRunsForTask(string $taskKey, int $leaseSeconds): int
    {
        if (! $this->tableReady()) {
            return 0;
        }

        $taskKey = trim($taskKey);
        if ($taskKey === '') {
            return 0;
        }

        $cutoff = now()->subSeconds(max(60, $leaseSeconds));

        // dispatch_failed 也在回收范围：同槽复用只覆盖下一次心跳，队列故障跨槽位后
        // 遗留的派发失败记录超出租约即收敛为终态 failed（可人工重跑），避免台账滞留孤儿。
        return ScheduleTaskRun::query()
            ->where('task_key', $taskKey)
            ->whereIn('status', [
                ScheduleTaskRun::STATUS_QUEUED,
                ScheduleTaskRun::STATUS_RUNNING,
                ScheduleTaskRun::STATUS_RETRYING,
                ScheduleTaskRun::STATUS_DISPATCH_FAILED,
            ])
            ->where(function ($query) use ($cutoff): void {
                // 旧版本可能没有写入 updated_at/queued_at，回退到 created_at；三者都为空
                // 时无法证明仍在租约内，也应回收，避免永久阻塞同一任务。
                $query
                    ->whereRaw('COALESCE(updated_at, queued_at, created_at) <= ?', [$cutoff])
                    ->orWhere(function ($nested): void {
                        $nested
                            ->whereNull('updated_at')
                            ->whereNull('queued_at')
                            ->whereNull('created_at');
                    });
            })
            ->update([
                'status' => ScheduleTaskRun::STATUS_FAILED,
                'error_msg' => '运行租约超时，未自动重跑；请确认业务副作用后再触发',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function latestRunForTask(string $taskKey): ?array
    {
        if (! $this->tableReady()) {
            return null;
        }

        $run = ScheduleTaskRun::query()
            ->where('task_key', $taskKey)
            ->latest('id')
            ->first();

        return $run instanceof ScheduleTaskRun ? $this->serializeRun($run) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentRuns(int $limit = 24): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        return ScheduleTaskRun::query()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ScheduleTaskRun $run): array => $this->serializeRun($run))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(ScheduleTaskRun $run): array
    {
        $time = $run->finished_at ?? $run->started_at ?? $run->queued_at ?? $run->created_at;

        return [
            'id' => (int) $run->id,
            'time' => $time instanceof CarbonInterface ? $time->toDateTimeString() : null,
            'level' => strtoupper((string) $run->status),
            'message' => (string) $run->task_name,
            'task_key' => (string) $run->task_key,
            'status' => (string) $run->status,
            'source' => (string) $run->source,
            'attempt' => max(1, (int) ($run->attempt ?? 1)),
            'parent_run_id' => $run->parent_run_id !== null ? (int) $run->parent_run_id : null,
            'duration_ms' => $run->duration_ms,
            'summary' => $run->summary,
            'error_msg' => $run->error_msg,
            'queued_at' => $this->formatDate($run->queued_at),
            'started_at' => $this->formatDate($run->started_at),
            'finished_at' => $this->formatDate($run->finished_at),
            'manual_retry_at' => $this->formatDate($run->manual_retry_at),
            'manual_retry_by' => $run->manual_retry_by !== null ? (int) $run->manual_retry_by : null,
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : null;
    }

    private function applyRunFilters(Builder $query, array $filters): void
    {
        if (($taskKey = trim((string) ($filters['task_key'] ?? ''))) !== '') {
            $query->where('task_key', $taskKey);
        }

        if (($keyword = trim((string) ($filters['keyword'] ?? ''))) !== '') {
            $query->where(function (Builder $nested) use ($keyword): void {
                $nested
                    ->where('task_key', 'like', '%'.$keyword.'%')
                    ->orWhere('task_name', 'like', '%'.$keyword.'%')
                    ->orWhere('error_msg', 'like', '%'.$keyword.'%');
            });
        }

        if (is_array($filters['status'] ?? null) && $filters['status'] !== []) {
            $query->whereIn('status', $filters['status']);
        } elseif (($status = trim((string) ($filters['status'] ?? ''))) !== '') {
            $query->where('status', $status);
        }

        if (($source = trim((string) ($filters['source'] ?? ''))) !== '') {
            $query->where('source', $source);
        }

        if (($startDate = trim((string) ($filters['start_date'] ?? ''))) !== '') {
            $query->where('created_at', '>=', $startDate.' 00:00:00');
        }

        if (($endDate = trim((string) ($filters['end_date'] ?? ''))) !== '') {
            $query->where('created_at', '<=', $endDate.' 23:59:59');
        }
    }

    public function retryLineageReady(): bool
    {
        if ($this->retryLineageReady === true) {
            return true;
        }

        try {
            $ready = $this->tableReady()
                && Schema::hasColumn('schedule_task_runs', 'parent_run_id')
                && Schema::hasColumn('schedule_task_runs', 'attempt')
                && Schema::hasColumn('schedule_task_runs', 'manual_retry_at')
                && Schema::hasColumn('schedule_task_runs', 'manual_retry_by');
        } catch (\Throwable) {
            return false;
        }

        if ($ready) {
            $this->retryLineageReady = true;
        }

        return $ready;
    }

    public function tableReady(): bool
    {
        if ($this->tableReady === true) {
            return true;
        }

        try {
            $ready = Schema::hasTable('schedule_task_runs');
        } catch (\Throwable) {
            return false;
        }

        if ($ready) {
            $this->tableReady = true;
        }

        return $ready;
    }
}
