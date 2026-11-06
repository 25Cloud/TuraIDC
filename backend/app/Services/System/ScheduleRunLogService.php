<?php

namespace App\Services\System;

use App\Models\ScheduleRunLog;
use App\Services\Automation\ScheduleHookService;
use App\Support\CacheKey;
use App\Support\SqlLike;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 定时任务运行日志（`schedule_run_logs`）的读写入口。
 *
 * 三条口径：
 *
 * 1. **按指纹去重**：同一次「任务 + 时间片」只有一行日志。指纹是
 *    `sha1(task_key|slot)`，slot 优先取心跳槽位（tick id / 槽位起始时间），
 *    没有心跳上下文时退化为 15 分钟向下取整的时间片。队列重试、同槽重复心跳
 *    都是复用同一行（attempts 递增），不再每次插新行。
 * 2. **并发原子**：先 `INSERT IGNORE` 抢 `fingerprint` 唯一索引，抢不到再读回。
 *    同一指纹不可能出现两条「进行中」记录——这是库层约束，不是先查后写。
 * 3. **失败语义**：`failed` 只用于真的抛异常的执行，且必须带可诊断原因
 *    （异常类 + 消息 + 任务 + 槽位 + 尝试次数）；「上一轮还在跑」记 `skipped`，
 *    绝不记成失败。
 */
class ScheduleRunLogService
{
    /** 时间片长度：平台心跳是 15 分钟一槽 */
    private const SLOT_SECONDS = 900;

    /** 运行中 */
    public const STATUS_RUNNING = 'running';

    /** 跳过（同指纹已有进行中的执行、或重复投递被拒） */
    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /** 跳过原因码：上一轮仍在进行 */
    public const SKIP_PREVIOUS_RUN_IN_PROGRESS = 'previous_run_in_progress';

    /** 跳过原因码：重复投递/迟到 Job 被运行台账拒绝 */
    public const SKIP_RUN_NOT_ACTIVE = 'schedule_run_not_active';

    private const SKIP_REASON_TEXT = [
        self::SKIP_PREVIOUS_RUN_IN_PROGRESS => '同一任务在本时间片已有运行中的记录，本次触发未执行',
        self::SKIP_RUN_NOT_ACTIVE => '运行记录已收口或本次为重复投递，本次执行未进行',
    ];

    private ?bool $scheduleRunLogTableReady = null;

    private ?bool $fingerprintColumnsReady = null;

    private static bool $mutexDegradedWarningEmitted = false;

    /**
     * 执行一次任务并写运行日志。
     *
     * @param  array<string, mixed>  $context
     */
    public function record(string $taskName, callable $callback, array $context = []): mixed
    {
        $this->warnIfMutexDegraded();

        $hookService = app(ScheduleHookService::class);

        $startedAt = Carbon::now();
        $startMs = (int) (microtime(true) * 1000);
        $normalizedContext = $this->normalizeContext($taskName, $context, $startedAt);

        $claim = $this->claim($taskName, $normalizedContext, $startedAt);

        if ($claim['overlap']) {
            // 「上一轮仍在跑」不是失败：不执行、不抛错，只留一条可解释的跳过痕迹。
            $this->appendOverlapHistory($claim['id'], $normalizedContext);

            return [
                'status' => self::STATUS_SKIPPED,
                'reason' => self::SKIP_PREVIOUS_RUN_IN_PROGRESS,
                'run_log_id' => $claim['id'],
                'fingerprint' => $claim['fingerprint'],
            ];
        }

        $hookContext = array_merge($context, [
            'task_name' => $taskName,
            'started_at' => $startedAt->toDateTimeString(),
        ]);

        $hookService->runMany([
            ScheduleHookService::HOOK_BEFORE_CRON,
            ScheduleHookService::HOOK_TASK_BEFORE,
        ], $hookContext);

        try {
            $result = $callback();
            $endMs = (int) (microtime(true) * 1000);
            $finishedAt = Carbon::now();
            $durationMs = $endMs - $startMs;
            $summary = is_array($result) ? $result : null;

            $this->finalize($claim, self::STATUS_SUCCESS, $normalizedContext, [
                'duration_ms' => $durationMs,
                'summary' => $summary,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);

            $this->storeCronActivityLog($taskName, 'success', $durationMs, $summary, null, $context);

            $hookService->runMany([
                ScheduleHookService::HOOK_TASK_AFTER,
                ScheduleHookService::HOOK_AFTER_CRON,
            ], array_merge($hookContext, [
                'duration_ms' => $durationMs,
                'finished_at' => $finishedAt->toDateTimeString(),
                'summary' => $summary,
            ]));

            return $result;
        } catch (Throwable $e) {
            $endMs = (int) (microtime(true) * 1000);
            $finishedAt = Carbon::now();
            $durationMs = $endMs - $startMs;

            $this->finalize($claim, self::STATUS_FAILED, $normalizedContext, [
                'duration_ms' => $durationMs,
                'error_msg' => $this->diagnosticMessage($e, $normalizedContext),
                'failure' => [
                    'exception' => $e::class,
                    'message' => mb_substr($e->getMessage(), 0, 1000),
                    'task_key' => $normalizedContext['task_key'],
                    'slot' => $normalizedContext['slot'],
                    'attempt' => $normalizedContext['attempt'],
                    'at' => $finishedAt->toDateTimeString(),
                ],
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);

            $this->storeCronActivityLog($taskName, 'failed', $durationMs, null, $e->getMessage(), $context);

            $hookService->runMany([
                ScheduleHookService::HOOK_TASK_FAILED,
                ScheduleHookService::HOOK_AFTER_CRON,
            ], array_merge($hookContext, [
                'duration_ms' => $durationMs,
                'finished_at' => $finishedAt->toDateTimeString(),
                'error_message' => $e->getMessage(),
                'exception_class' => $e::class,
            ]));

            throw $e;
        }
    }

    /**
     * 记录一次「没有执行」的运行：重复投递被拒、上一轮仍在跑等。
     *
     * 与失败严格区分：状态是 `skipped`，`error_msg` 里放可诊断的原因码与人话说明，
     * 不进失败计数。
     *
     * @param  array<string, mixed>  $context
     */
    public function recordSkipped(string $taskName, string $reason, array $context = []): void
    {
        if (! $this->scheduleRunLogTableIsReady()) {
            return;
        }

        $reason = trim($reason);
        $reason = $reason !== '' ? $reason : self::SKIP_RUN_NOT_ACTIVE;
        $normalizedContext = $this->normalizeContext($taskName, $context, Carbon::now());
        $message = $this->skipMessage($reason);

        if (! $this->fingerprintColumnsReady()) {
            // 未迁移的库：退化为一次独立写入（没有指纹可去重），语义不变。
            $this->storeRunLog([
                'task_name' => $taskName,
                'status' => self::STATUS_SKIPPED,
                'duration_ms' => 0,
                'error_msg' => $message,
                'started_at' => Carbon::now(),
                'finished_at' => Carbon::now(),
            ]);

            return;
        }

        $fingerprint = $this->fingerprint($normalizedContext);

        try {
            $inserted = DB::table((new ScheduleRunLog)->getTable())->insertOrIgnore([
                'task_name' => $taskName,
                'task_key' => $normalizedContext['task_key'],
                'fingerprint' => $fingerprint,
                'status' => self::STATUS_SKIPPED,
                'duration_ms' => 0,
                'summary' => $this->encodeSummary(array_merge($this->baseSummary($normalizedContext), [
                    'skip_reason' => $reason,
                    'skipped_at' => Carbon::now()->toDateTimeString(),
                    // 第一次跳过本身就进历史，后续追加时计数才对得上（skips 保留最近 5 条）
                    'skips' => [[
                        'reason' => $reason,
                        'message' => $message,
                        'at' => Carbon::now()->toDateTimeString(),
                    ]],
                ])),
                'error_msg' => $message,
                'started_at' => Carbon::now()->toDateTimeString(),
                'finished_at' => Carbon::now()->toDateTimeString(),
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);

            if ($inserted === 1) {
                return;
            }

            // 同指纹已有行：不新增、不覆盖既有状态，只把「又跳过一次」追加进摘要。
            $this->appendSkipHistory($fingerprint, $reason, $message);
        } catch (Throwable $exception) {
            Log::warning('[定时任务] 跳过日志写入失败，已忽略', [
                'task_name' => $taskName,
                'reason' => $reason,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * 抢占本指纹的运行行。
     *
     * @param  array<string, mixed>  $context
     * @return array{fingerprint: string, id: int|null, overlap: bool}
     */
    private function claim(string $taskName, array $context, Carbon $startedAt): array
    {
        $fingerprint = $this->fingerprint($context);

        if (! $this->fingerprintColumnsReady()) {
            return ['fingerprint' => $fingerprint, 'id' => null, 'overlap' => false];
        }

        $table = (new ScheduleRunLog)->getTable();
        $summary = array_merge($this->baseSummary($context), ['attempts' => 1]);

        try {
            $inserted = DB::table($table)->insertOrIgnore([
                'task_name' => $taskName,
                'task_key' => $context['task_key'],
                'fingerprint' => $fingerprint,
                'status' => self::STATUS_RUNNING,
                'duration_ms' => 0,
                'summary' => $this->encodeSummary($summary),
                'error_msg' => null,
                'started_at' => $startedAt->toDateTimeString(),
                'finished_at' => null,
                'created_at' => $startedAt->toDateTimeString(),
                'updated_at' => $startedAt->toDateTimeString(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('[定时任务] 运行日志抢占失败，本次按无台账执行', [
                'task_name' => $taskName,
                'fingerprint' => $fingerprint,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return ['fingerprint' => $fingerprint, 'id' => null, 'overlap' => false];
        }

        $row = DB::table($table)
            ->where('fingerprint', $fingerprint)
            ->first(['id', 'status', 'summary']);

        if ($row === null) {
            // 极罕见：抢到了但读不回来（被并发清理）。按无台账执行，别把任务拦死。
            return ['fingerprint' => $fingerprint, 'id' => null, 'overlap' => false];
        }

        $id = (int) $row->id;

        if ($inserted === 1) {
            return ['fingerprint' => $fingerprint, 'id' => $id, 'overlap' => false];
        }

        if ((string) $row->status === self::STATUS_RUNNING) {
            return ['fingerprint' => $fingerprint, 'id' => $id, 'overlap' => true];
        }

        // 同指纹上一轮已终态：这是重试/补跑，复用同一行重新进入 running（不新增行）。
        $attempts = $this->attemptsOf($row->summary) + 1;
        $reopened = DB::table($table)
            ->where('id', $id)
            ->where('status', (string) $row->status)
            ->update([
                'status' => self::STATUS_RUNNING,
                'duration_ms' => 0,
                'summary' => $this->encodeSummary(array_merge($this->baseSummary($context), ['attempts' => $attempts])),
                'error_msg' => null,
                'started_at' => $startedAt->toDateTimeString(),
                'finished_at' => null,
                'updated_at' => $startedAt->toDateTimeString(),
            ]);

        if ($reopened !== 1) {
            // CAS 落空：另一路同时把这一行开了起来，按重叠处理（不并行执行业务）。
            return ['fingerprint' => $fingerprint, 'id' => $id, 'overlap' => true];
        }

        return ['fingerprint' => $fingerprint, 'id' => $id, 'overlap' => false];
    }

    /**
     * 收口本次运行：成功 / 失败都写回同一指纹行。
     *
     * @param  array{fingerprint: string, id: int|null, overlap: bool}  $claim
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    private function finalize(array $claim, string $status, array $context, array $payload): void
    {
        $id = $claim['id'];

        if ($id === null) {
            // 无指纹行（未迁移的库或抢占失败）：保持旧的独立写入行为。
            $this->storeRunLog([
                'task_name' => $context['task_name'],
                'status' => $status,
                'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
                'summary' => is_array($payload['summary'] ?? null) ? $payload['summary'] : null,
                'error_msg' => isset($payload['error_msg']) ? (string) $payload['error_msg'] : null,
                'started_at' => $payload['started_at'] ?? null,
                'finished_at' => $payload['finished_at'] ?? null,
            ]);

            return;
        }

        if (! $this->scheduleRunLogTableIsReady()) {
            return;
        }

        $table = (new ScheduleRunLog)->getTable();

        try {
            $current = DB::table($table)->where('id', $id)->first(['summary', 'status']);
            $merged = $this->baseSummary($context);

            if ($current !== null) {
                $previous = $this->decodeSummary($current->summary);
                $merged = array_merge($merged, $previous);

                if (is_array($payload['summary'] ?? null)) {
                    $merged['result'] = $payload['summary'];
                }

                if (is_array($payload['failure'] ?? null)) {
                    $failureHistory = is_array($merged['failure_history'] ?? null) ? $merged['failure_history'] : [];
                    $failureHistory[] = $payload['failure'];
                    $merged['failure_history'] = array_slice($failureHistory, -5);
                }
            }

            $attributes = [
                'status' => $status,
                'duration_ms' => max(0, (int) ($payload['duration_ms'] ?? 0)),
                'summary' => $this->encodeSummary($merged),
                'error_msg' => isset($payload['error_msg']) ? mb_substr((string) $payload['error_msg'], 0, 2000) : null,
                'finished_at' => $this->toSqlTime($payload['finished_at'] ?? null),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ];

            if (isset($payload['started_at'])) {
                $attributes['started_at'] = $this->toSqlTime($payload['started_at']);
            }

            // 只在仍处于 running 时收口：被别的执行改写过的行不覆盖。
            $updated = DB::table($table)
                ->where('id', $id)
                ->where('status', self::STATUS_RUNNING)
                ->update($attributes);

            if ($updated !== 1) {
                Log::warning('[定时任务] 运行日志收口时状态已被改写，跳过覆盖', [
                    'run_log_id' => $id,
                    'task_key' => $context['task_key'],
                    'fingerprint' => $claim['fingerprint'],
                    'expected_status' => self::STATUS_RUNNING,
                    'target_status' => $status,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('[定时任务] 运行日志收口失败，已忽略', [
                'run_log_id' => $id,
                'task_key' => $context['task_key'],
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * 同指纹已有运行中的记录：把「本次没跑」追加进摘要，不改状态、不记失败。
     */
    private function appendOverlapHistory(?int $id, array $context): void
    {
        if ($id === null || ! $this->scheduleRunLogTableIsReady()) {
            return;
        }

        $this->mutateSummary($id, function (array $summary) use ($context): array {
            $history = is_array($summary['overlaps'] ?? null) ? $summary['overlaps'] : [];
            $history[] = [
                'task_key' => $context['task_key'],
                'slot' => $context['slot'],
                'reason' => self::SKIP_PREVIOUS_RUN_IN_PROGRESS,
                'at' => Carbon::now()->toDateTimeString(),
            ];
            $summary['overlaps'] = array_slice($history, -5);

            return $summary;
        });
    }

    private function appendSkipHistory(string $fingerprint, string $reason, string $message): void
    {
        if (! $this->scheduleRunLogTableIsReady()) {
            return;
        }

        $table = (new ScheduleRunLog)->getTable();
        $id = (int) (DB::table($table)->where('fingerprint', $fingerprint)->value('id') ?? 0);

        if ($id <= 0) {
            return;
        }

        $this->mutateSummary($id, function (array $summary) use ($reason, $message): array {
            $history = is_array($summary['skips'] ?? null) ? $summary['skips'] : [];
            $history[] = [
                'reason' => $reason,
                'message' => $message,
                'at' => Carbon::now()->toDateTimeString(),
            ];
            $summary['skips'] = array_slice($history, -5);

            return $summary;
        });
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    private function mutateSummary(int $id, callable $mutator): void
    {
        try {
            $table = (new ScheduleRunLog)->getTable();
            $current = DB::table($table)->where('id', $id)->first(['summary']);

            if ($current === null) {
                return;
            }

            $summary = $mutator($this->decodeSummary($current->summary));

            DB::table($table)->where('id', $id)->update([
                'summary' => $this->encodeSummary($summary),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('[定时任务] 运行日志摘要追加失败，已忽略', [
                'run_log_id' => $id,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{task_key: string, task_name: string, slot: string, attempt: int}
     */
    private function normalizeContext(string $taskName, array $context, Carbon $now): array
    {
        $taskKey = trim((string) ($context['task_key'] ?? ''));
        $taskKey = $taskKey !== '' ? $taskKey : $taskName;

        return [
            'task_key' => $taskKey,
            'task_name' => $taskName,
            'slot' => $this->resolveSlot($context, $now),
            'attempt' => max(1, (int) ($context['attempt'] ?? 1)),
        ];
    }

    /**
     * 时间片标识：心跳槽位优先，没有就按 15 分钟向下取整。
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveSlot(array $context, Carbon $now): string
    {
        $tickId = (int) ($context['tick_id'] ?? 0);
        if ($tickId > 0) {
            return 'tick:'.$tickId;
        }

        $tickSlot = trim((string) ($context['tick_slot'] ?? ''));
        if ($tickSlot !== '') {
            return 'slot:'.$tickSlot;
        }

        return 'slice:'.(int) floor($now->getTimestamp() / self::SLOT_SECONDS);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fingerprint(array $context): string
    {
        return sha1($context['task_key'].'|'.$context['slot']);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function baseSummary(array $context): array
    {
        return array_filter([
            'task_key' => $context['task_key'],
            'slot' => $context['slot'],
            'attempt' => max(1, (int) ($context['attempt'] ?? 1)),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * 失败必须可诊断：异常类 + 消息 + 任务 + 槽位 + 第几次尝试。
     *
     * @param  array<string, mixed>  $context
     */
    private function diagnosticMessage(Throwable $e, array $context): string
    {
        return mb_substr(sprintf(
            '%s: %s（任务 %s，时间片 %s，第 %d 次尝试）',
            $e::class,
            $e->getMessage(),
            (string) $context['task_key'],
            (string) $context['slot'],
            max(1, (int) $context['attempt']),
        ), 0, 2000);
    }

    private function skipMessage(string $reason): string
    {
        $text = self::SKIP_REASON_TEXT[$reason] ?? '本次触发未执行';

        return mb_substr('跳过执行（'.$reason.'）：'.$text, 0, 2000);
    }

    private function attemptsOf(mixed $summary): int
    {
        $decoded = $this->decodeSummary($summary);

        return max(1, (int) ($decoded['attempts'] ?? 1));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSummary(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function encodeSummary(array $summary): string
    {
        return (string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function toSqlTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateTimeString();
        }

        return null;
    }

    private function storeCronActivityLog(
        string $taskName,
        string $status,
        int $durationMs,
        ?array $summary,
        ?string $errorMessage,
        array $context
    ): void {
        if (! $this->activityLogTableIsReady()) {
            return;
        }

        try {
            $description = $status === 'success'
                ? "Cron_{$taskName}执行完成"
                : "Cron_{$taskName}执行失败：".mb_substr((string) $errorMessage, 0, 500);

            app(ActivityLogService::class)->logSystem(
                'cron',
                $status,
                $description,
                'schedule_task',
                null,
                array_filter([
                    'task_key' => $context['task_key'] ?? null,
                    'source' => $context['source'] ?? null,
                    'duration_ms' => $durationMs,
                    'summary' => $summary,
                    'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 2000) : null,
                ], static fn ($value): bool => $value !== null)
            );
        } catch (Throwable $exception) {
            Log::warning('[定时任务] Cron 活动日志写入失败，已跳过本次日志记录', [
                'task_name' => $taskName,
                'status' => $status,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * 无指纹路径的兜底写入（库未迁移、或抢占失败）。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function storeRunLog(array $attributes): void
    {
        if (! $this->scheduleRunLogTableIsReady()) {
            return;
        }

        try {
            ScheduleRunLog::query()->create($attributes);
        } catch (Throwable $exception) {
            Log::warning('[定时任务] 运行日志写入失败，已跳过本次日志记录', [
                'task_name' => $attributes['task_name'] ?? null,
                'status' => $attributes['status'] ?? null,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    private function scheduleRunLogTableIsReady(): bool
    {
        if ($this->scheduleRunLogTableReady !== null) {
            return $this->scheduleRunLogTableReady;
        }

        try {
            $this->scheduleRunLogTableReady = Schema::hasTable((new ScheduleRunLog)->getTable());
        } catch (Throwable $exception) {
            Log::warning('[定时任务] 运行日志表检查失败，已跳过本次日志记录', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            $this->scheduleRunLogTableReady = false;
        }

        return $this->scheduleRunLogTableReady;
    }

    /**
     * 指纹列是否就绪。只缓存 true：false 是暂态（迁移中），缓存会让本进程永久退化为
     * 不去重的老行为。
     */
    public function fingerprintColumnsReady(): bool
    {
        if ($this->fingerprintColumnsReady === true) {
            return true;
        }

        if (! $this->scheduleRunLogTableIsReady()) {
            return false;
        }

        try {
            $ready = Schema::hasColumn('schedule_run_logs', 'fingerprint')
                && Schema::hasColumn('schedule_run_logs', 'task_key');
        } catch (Throwable) {
            return false;
        }

        if ($ready) {
            $this->fingerprintColumnsReady = true;
        }

        return $ready;
    }

    private function activityLogTableIsReady(): bool
    {
        try {
            return Schema::hasTable('activity_logs');
        } catch (Throwable $exception) {
            Log::warning('[定时任务] 活动日志表检查失败，已跳过本次日志记录', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * 调度互斥处于降级模式（Windows + file 缓存等场景）时，每个 schedule:run
     * 进程首次进入 record() 写一次 warning，便于在 laravel.log 中察觉潜在重入风险。
     * 静态标志位保证单进程内只告警一次，避免每分钟刷屏。
     */
    private function warnIfMutexDegraded(): void
    {
        if (self::$mutexDegradedWarningEmitted) {
            return;
        }

        $mutex = (array) config('idc.schedule_runtime.mutex', []);
        if (! (bool) ($mutex['degraded'] ?? false)) {
            return;
        }

        self::$mutexDegradedWarningEmitted = true;

        Log::warning('[定时任务] 调度互斥处于降级模式，withoutOverlapping 已跳过，存在重入风险', [
            'reason' => $mutex['reason'] ?? '',
            'cache_store' => $mutex['cache_store'] ?? '',
            'os_family' => $mutex['os_family'] ?? '',
            'mode' => $mutex['mode'] ?? '',
            'fingerprint_lock_key' => CacheKey::scheduleRunFingerprint('*', '*'),
        ]);
    }

    public function getScheduleStatus(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $query = ScheduleRunLog::query()->orderByDesc('id');

        if (! empty($filters['task_name'])) {
            $query->where('task_name', $filters['task_name']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('task_name', 'like', SqlLike::contains($keyword))
                    ->orWhere('error_msg', 'like', SqlLike::contains($keyword));
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            // 返回值必须带 summary：AdminLogV2QueryService::resolveSummary 在 summary 为空时
            // 会再调一次 getScheduleStatus 只为拿这个 total，等于把整条列表查询和 count(*)
            // 在 149k 行的 schedule_run_logs 上跑第二遍。paginator 这里已经算出来了。
            'summary' => ['total' => $paginator->total()],
        ];
    }

    public function getHealthOverview(): array
    {
        $tasks = ScheduleRunLog::query()
            ->selectRaw('task_name, MAX(finished_at) as last_run_at, COUNT(*) as total_runs')
            ->selectRaw('SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) as failed_count')
            ->selectRaw('SUM(CASE WHEN status = \'skipped\' THEN 1 ELSE 0 END) as skipped_count')
            ->selectRaw('AVG(duration_ms) as avg_duration_ms')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('task_name')
            ->get();

        return $tasks->map(function ($task) {
            $lastRun = $task->last_run_at ? Carbon::parse($task->last_run_at) : null;
            $minutesSinceLastRun = $lastRun ? (int) $lastRun->diffInMinutes(now()) : null;

            return [
                'task_name' => $task->task_name,
                'last_run_at' => $lastRun?->toDateTimeString(),
                'minutes_since_last_run' => $minutesSinceLastRun,
                'health' => $this->evaluateHealth($minutesSinceLastRun),
                'total_runs_24h' => (int) $task->total_runs,
                'failed_count_24h' => (int) $task->failed_count,
                'skipped_count_24h' => (int) ($task->skipped_count ?? 0),
                'avg_duration_ms' => (int) round($task->avg_duration_ms),
            ];
        })->all();
    }

    private function evaluateHealth(?int $minutesSinceLastRun): string
    {
        if ($minutesSinceLastRun === null) {
            return 'unknown';
        }

        if ($minutesSinceLastRun <= 30) {
            return 'healthy';
        }

        if ($minutesSinceLastRun <= 120) {
            return 'warning';
        }

        return 'critical';
    }
}
