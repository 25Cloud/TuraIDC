<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Exceptions\BusinessException;
use App\Services\System\OperationLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * 管理端队列任务治理：只处理 database 队列连接下的 jobs 与 failed_jobs 两张表。
 *
 * 职责边界（刻意的）：
 * - pending（jobs 表）**只读**。删一条还在排队的任务等于静默丢弃业务工作
 *   （待发通知、待履约开通、待回推的续费），这不该是一个按钮能干的事；
 *   真正卡死的任务耗尽重试次数后本来就会落进 failed_jobs，在那里处置是安全的。
 * - failed（failed_jobs 表）支持单条删除与整表清空，均为破坏性操作，
 *   权限由路由中间件把关，这里负责表可用性守卫、payload 解析与审计双写。
 * - 队列连接可能被切到 redis 等非 database 驱动，此时本服务看不见真实积压，
 *   列表响应里带上连接信息，由前端提示这个覆盖缺口。
 */
class QueueJobService
{
    public const QUEUE_PENDING = 'pending';

    public const QUEUE_FAILED = 'failed';

    private const QUEUES = [self::QUEUE_PENDING, self::QUEUE_FAILED];

    public function __construct(
        private readonly OperationLogService $operationLogs,
    ) {}

    public function supports(string $queue): bool
    {
        return in_array(trim($queue), self::QUEUES, true);
    }

    /**
     * 队列任务分页。表尚未在当前环境落库时返回空分页，
     * 避免只读的管理端页面被基础设施状态拖垮。
     *
     * @return array<string, mixed>
     */
    public function paginateJobs(string $queue, int $page = 1, int $perPage = 20): array
    {
        $queue = $this->normalizeQueue($queue);
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);

        $table = $this->queueTable($queue);
        if ($table === null) {
            return $this->envelope($queue, [], 0, $page, $perPage);
        }

        [$connection, $name] = $table;
        $query = DB::connection($connection)->table($name);
        $total = (int) $query->count();
        $rows = $query
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return $this->envelope(
            $queue,
            $rows->map(fn (object $row): array => $this->serializeJob($queue, (array) $row))->all(),
            $total,
            $page,
            $perPage,
        );
    }

    /**
     * 删除单条「已失败」任务，返回被删任务摘要（供审计与结果展示）。
     *
     * @return array<string, mixed>
     */
    public function deleteJob(string $queue, int $jobId, ?int $adminUserId, ?string $ipAddress = null): array
    {
        $queue = $this->normalizeQueue($queue);
        $this->assertWritable($queue);

        if ($jobId <= 0) {
            throw new InvalidArgumentException('队列任务不存在或已被处理');
        }

        [$connection, $name] = $this->requireQueueTable($queue);

        $row = DB::connection($connection)->table($name)->where('id', $jobId)->first();
        if ($row === null) {
            throw new InvalidArgumentException('队列任务不存在或已被处理');
        }

        $job = $this->serializeJob($queue, (array) $row);

        if (DB::connection($connection)->table($name)->where('id', $jobId)->delete() === 0) {
            throw new RuntimeException('队列任务删除失败，请稍后重试');
        }

        $this->writeAudit(
            adminUserId: $adminUserId,
            action: 'schedule.queue.job_delete',
            targetId: $jobId,
            detail: [
                'queue' => $queue,
                'display_name' => (string) ($job['display_name'] ?? ''),
                'job_queue' => (string) ($job['queue'] ?? ''),
            ],
            ipAddress: $ipAddress,
        );

        return $job;
    }

    /**
     * 清空「已失败」任务表，返回实际删除条数。
     */
    public function clearJobs(string $queue, ?int $adminUserId, ?string $ipAddress = null): int
    {
        $queue = $this->normalizeQueue($queue);
        $this->assertWritable($queue);

        [$connection, $name] = $this->requireQueueTable($queue);

        $deleted = (int) DB::connection($connection)->table($name)->delete();
        if ($deleted > 0) {
            $this->writeAudit(
                adminUserId: $adminUserId,
                action: 'schedule.queue.clear',
                targetId: null,
                detail: [
                    'queue' => $queue,
                    'deleted_count' => $deleted,
                ],
                ipAddress: $ipAddress,
            );
        }

        return $deleted;
    }

    private function normalizeQueue(string $queue): string
    {
        $queue = trim($queue);
        if (! $this->supports($queue)) {
            throw new InvalidArgumentException('不支持的队列类型：'.$queue);
        }

        return $queue;
    }

    /**
     * pending 表只读：删/清一律拒绝。错误信息要讲清楚「为什么」，
     * 否则管理员会当成 bug 去找绕过办法。
     */
    private function assertWritable(string $queue): void
    {
        if ($queue === self::QUEUE_PENDING) {
            throw new BusinessException('待执行队列只读：其中是尚未运行的业务任务，删除即静默丢弃；请改在「已失败」页签处置');
        }
    }

    /**
     * @return array{0: string, 1: string}|null 连接名与表名；表未落库返回 null
     */
    private function queueTable(string $queue): ?array
    {
        $isPending = $queue === self::QUEUE_PENDING;
        $configKey = $isPending ? 'queue.connections.database' : 'queue.failed';
        $connectionKey = $isPending ? 'connection' : 'database';
        $defaultTable = $isPending ? 'jobs' : 'failed_jobs';

        $config = (array) config($configKey, []);
        $connection = trim((string) ($config[$connectionKey] ?? '')) ?: (string) config('database.default');
        $table = trim((string) ($config['table'] ?? $defaultTable));

        if ($connection === '' || $table === '') {
            return null;
        }

        try {
            return Schema::connection($connection)->hasTable($table) ? [$connection, $table] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function requireQueueTable(string $queue): array
    {
        $table = $this->queueTable($queue);
        if ($table === null) {
            throw new RuntimeException('队列表不可用，已拒绝执行');
        }

        return $table;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function envelope(string $queue, array $rows, int $total, int $page, int $perPage): array
    {
        return [
            'list' => $rows,
            'total' => $total,
            'page' => $page,
            'page_size' => $perPage,
            // 队列运行时连接：与 inspected 不一致时前端必须提示「本页看不见真实积压」
            'connection' => [
                'runtime' => (string) config('queue.default'),
                'inspected' => $queue === self::QUEUE_PENDING
                    ? (string) config('queue.connections.database.connection', config('database.default'))
                    : (string) config('queue.failed.database', config('database.default')),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function serializeJob(string $queue, array $row): array
    {
        $payload = $this->decodePayload($row['payload'] ?? null);
        $displayName = trim((string) ($payload['displayName'] ?? '')) ?: trim((string) ($payload['job'] ?? ''));

        $job = [
            'id' => (int) ($row['id'] ?? 0),
            'kind' => $queue,
            'queue' => (string) ($row['queue'] ?? ''),
            'display_name' => $displayName !== '' ? $displayName : '未知任务',
            'attempts' => (int) ($row['attempts'] ?? 0),
            'max_tries' => isset($payload['maxTries']) ? (int) $payload['maxTries'] : null,
        ];

        if ($queue === self::QUEUE_PENDING) {
            $reservedAt = $row['reserved_at'] ?? null;

            return array_merge($job, [
                'state' => $reservedAt !== null ? 'reserved' : 'pending',
                'available_at' => $this->toDateTimeString($row['available_at'] ?? null),
                'reserved_at' => $this->toDateTimeString($reservedAt),
                'created_at' => $this->toDateTimeString($row['created_at'] ?? null),
                'uuid' => (string) ($payload['uuid'] ?? ''),
                'failed_at' => null,
                'exception' => null,
            ]);
        }

        return array_merge($job, [
            'state' => 'failed',
            'uuid' => (string) ($row['uuid'] ?? ($payload['uuid'] ?? '')),
            'failed_at' => $this->toDateTimeString($row['failed_at'] ?? null),
            'created_at' => null,
            'available_at' => null,
            'reserved_at' => null,
            'exception' => (string) ($row['exception'] ?? ''),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function toDateTimeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        return (string) $value;
    }

    private function writeAudit(
        ?int $adminUserId,
        string $action,
        ?int $targetId,
        array $detail,
        ?string $ipAddress,
    ): void {
        try {
            $this->operationLogs->write(
                userId: $adminUserId,
                userType: 'admin',
                action: $action,
                module: 'schedule',
                targetId: $targetId,
                detail: $detail,
                ipAddress: $ipAddress !== null && trim($ipAddress) !== '' ? trim($ipAddress) : null,
            );
        } catch (Throwable $exception) {
            // 审计写入失败不应阻断已完成的破坏性操作；保留告警供运维补偿。
            Log::warning('[队列任务] 管理端队列操作审计写入失败', [
                'action' => $action,
                'detail' => $detail,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }
}
