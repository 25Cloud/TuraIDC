<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Constants\PaymentGatewayCode;
use App\Models\ActivityLog;
use App\Models\AdminUser;
use App\Models\GatewayLog;
use App\Models\IntegrationPluginRuntimeLog;
use App\Models\MessageLog;
use App\Models\OperationLog;
use App\Models\Role;
use App\Models\ScheduleRunLog;
use App\Models\TicketUpstreamDeliveryLog;
use App\Models\User;
use App\Services\System\Concerns\HandlesAdminLogCleanup;
use App\Support\AdminPrivacy;
use App\Support\SensitiveDataSanitizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AdminLogService
{
    use HandlesAdminLogCleanup;

    private const HTTP_ACTION_REGEXP = '^(GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD) ';

    private const LIST_SUMMARY_CACHE_TTL_SECONDS = 30;

    private const FILE_LOG_CACHE_TTL_SECONDS = 60;

    private const CLEANUP_OVERVIEW_CACHE_TTL_SECONDS = 60;

    private const CLEANUP_OVERVIEW_CACHE_VERSION_KEY = 'admin_logs:cleanup_overview:version';

    private const FILE_LOG_SUMMARY_CACHE_TTL_SECONDS = 60;

    private const TASK_META = [
        'refresh-hosting-panel-auth' => [
            'title' => '接口认证刷新',
            'log_keywords' => ['JWT刷新', '接口认证刷新', 'refresh-hosting-panel-auth'],
        ],
        'refresh-zjmf-finance-auth' => [
            'title' => 'ZJMF 财务认证刷新',
            'log_keywords' => ['ZJMF 财务认证刷新', 'refresh-zjmf-finance-auth'],
        ],
        'service-auto-renew' => [
            'title' => '服务自动续费',
            'log_keywords' => ['自动续费执行完成', '[自动续费]', 'service-auto-renew'],
        ],
        'referral-release-rewards' => [
            'title' => '推荐奖励释放',
            'log_keywords' => ['推荐奖励释放执行完成', '推荐奖励', 'referral-release-rewards'],
        ],
        'service-lifecycle-maintenance' => [
            'title' => '服务生命周期维护',
            'log_keywords' => ['服务生命周期维护执行完成', 'service-lifecycle-maintenance'],
        ],
        'service-status-sync' => [
            'title' => '用户产品状态同步',
            'log_keywords' => ['用户产品状态同步执行完成', 'service-status-sync'],
        ],
        'billing-maintenance' => [
            'title' => '账单自动化维护',
            'log_keywords' => ['账单自动化维护执行完成', 'billing-maintenance'],
        ],
        'product-upstream-config-sync' => [
            'title' => '上游产品配置同步',
            'log_keywords' => ['上游产品配置同步执行完成', 'product-upstream-config-sync'],
        ],
        'coupon-campaign-dispatch' => [
            'title' => '优惠券活动发放',
            'log_keywords' => ['优惠券活动自动发放执行完成', 'coupon-campaign-dispatch'],
        ],
        'ticket-auto-close' => [
            'title' => '工单自动关闭',
            'log_keywords' => ['工单自动关闭执行完成', 'ticket-auto-close'],
        ],
        'order-cleanup' => [
            'title' => '账单与充值清理',
            'log_keywords' => ['账单与充值清理执行完成', '订单与充值清理执行完成', 'order-cleanup'],
        ],
    ];

    public function getSmsLogs(array $filters, int $perPage): array
    {
        $query = $this->buildSmsLogQuery($filters);
        if ($query === null) {
            return $this->buildPaginatorPayload($this->emptyPaginator($perPage));
        }

        $logs = (clone $query)->orderByDesc('created_at')->paginate($perPage);
        $logs->setCollection($logs->getCollection()->map(function ($log) {
            $item = $log->toArray();
            $item['params_json'] = $this->normalizeNotificationParams($item['params_json'] ?? []);
            $item['params'] = $item['params_json'] ?? [];
            unset($item['params_json']);

            return $item;
        }));

        return $this->buildPaginatorPayload($logs);
    }

    public function getSmsLogsSummary(array $filters): array
    {
        return Cache::remember(
            $this->buildListSummaryCacheKey('sms', $filters),
            now()->addSeconds(self::LIST_SUMMARY_CACHE_TTL_SECONDS),
            function () use ($filters) {
                $query = $this->buildNotificationSummaryQuery('sms', $filters);
                if ($query === null) {
                    return [
                        'total' => 0,
                        'success' => 0,
                        'failed' => 0,
                        'pending' => 0,
                    ];
                }

                $summary = $query
                    ->selectRaw('COUNT(*) as total')
                    ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END), 0) as success")
                    ->selectRaw("COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) as failed")
                    ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending")
                    ->first();

                return [
                    'total' => (int) ($summary?->total ?? 0),
                    'success' => (int) ($summary?->success ?? 0),
                    'failed' => (int) ($summary?->failed ?? 0),
                    'pending' => (int) ($summary?->pending ?? 0),
                ];
            }
        );
    }

    public function getEmailLogs(array $filters, int $perPage): array
    {
        $query = $this->buildEmailLogQuery($filters);
        if ($query === null) {
            return $this->buildPaginatorPayload($this->emptyPaginator($perPage));
        }

        $logs = (clone $query)->orderByDesc('created_at')->paginate($perPage);
        $logs->setCollection($logs->getCollection()->map(function ($log) {
            return $log->toArray();
        }));

        return $this->buildPaginatorPayload($logs);
    }

    public function getEmailLogsSummary(array $filters): array
    {
        return Cache::remember(
            $this->buildListSummaryCacheKey('email', $filters),
            now()->addSeconds(self::LIST_SUMMARY_CACHE_TTL_SECONDS),
            function () use ($filters) {
                $query = $this->buildNotificationSummaryQuery('email', $filters);
                if ($query === null) {
                    return [
                        'total' => 0,
                        'success' => 0,
                        'failed' => 0,
                        'pending' => 0,
                    ];
                }

                $summary = $query
                    ->selectRaw('COUNT(*) as total')
                    ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END), 0) as success")
                    ->selectRaw("COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) as failed")
                    ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending")
                    ->first();

                return [
                    'total' => (int) ($summary?->total ?? 0),
                    'success' => (int) ($summary?->success ?? 0),
                    'failed' => (int) ($summary?->failed ?? 0),
                    'pending' => (int) ($summary?->pending ?? 0),
                ];
            }
        );
    }

    public function getApiLogs(array $filters, int $page, int $perPage, bool $withSummary = true): array
    {
        $query = OperationLog::query()
            ->whereRaw('action REGEXP ?', [self::HTTP_ACTION_REGEXP]);

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($builder) use ($keyword) {
                $builder->where('action', 'like', "%{$keyword}%")
                    ->orWhere('module', 'like', "%{$keyword}%")
                    ->orWhere('ip_address', 'like', "%{$keyword}%")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.request_id')) like ?", ["%{$keyword}%"]);
            });
        }

        if (! empty($filters['module'])) {
            $query->where('module', trim((string) $filters['module']));
        }

        if (! empty($filters['method'])) {
            $query->where('action', 'like', trim((string) $filters['method']).' %');
        }

        if (! empty($filters['status'])) {
            $query->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(context, '$.status')) AS UNSIGNED) = ?", [(int) $filters['status']]);
        }

        if (! empty($filters['user_type'])) {
            $query->where('user_type', trim((string) $filters['user_type']));
        }

        $this->applyDateFilter($query, $filters);

        $logs = (clone $query)->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);
        $rows = $this->mapOperationLogs($logs->getCollection(), false)->map(function (array $item) {
            [$method, $path] = $this->splitHttpAction((string) ($item['action'] ?? ''));
            $detail = SensitiveDataSanitizer::sanitize(
                is_array($item['detail'] ?? null) ? $item['detail'] : []
            );

            $item['method'] = $method;
            $item['path'] = $path;
            $item['status'] = isset($detail['status']) ? (int) $detail['status'] : null;
            $item['request_id'] = trim((string) ($detail['request_id'] ?? ''));
            $item['params'] = $detail['params'] ?? [];
            $item['user_agent'] = trim((string) ($detail['user_agent'] ?? ''));

            return $item;
        });
        $logs->setCollection($rows);

        if (! $withSummary) {
            return $this->buildPaginatorPayload($logs);
        }

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(context, '$.status')) AS UNSIGNED) >= 500 THEN 1 ELSE 0 END), 0) as errors")
            ->selectRaw("COALESCE(SUM(CASE WHEN user_type = 'admin' THEN 1 ELSE 0 END), 0) as admin_count")
            ->first();

        return $this->buildPaginatorPayload($logs, [
            'total' => (int) ($summary?->total ?? 0),
            'errors' => (int) ($summary?->errors ?? 0),
            'admin_count' => (int) ($summary?->admin_count ?? 0),
        ]);
    }

    public function getTaskLogs(array $filters, int $page, int $perPage): array
    {
        $entries = $this->buildTaskLogEntries($filters);

        $paginator = $this->paginateCollection($entries, $page, $perPage);

        return $this->buildPaginatorPayload($paginator);
    }

    public function getTaskLogsSummary(array $filters): array
    {
        return Cache::remember(
            $this->buildFileLogSummaryCacheKey('task', $filters),
            now()->addSeconds(self::FILE_LOG_SUMMARY_CACHE_TTL_SECONDS),
            function () use ($filters) {
                $entries = $this->buildTaskLogEntries($filters);

                return [
                    'total' => $entries->count(),
                    'tasks' => $entries->pluck('task_key')->filter()->unique()->count(),
                    'errors' => $entries->whereIn('level', ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'])->count(),
                ];
            }
        );
    }

    public function getSystemLogs(array $filters, int $page, int $perPage, bool $withSummary = true): array
    {
        return $this->getActivityLogs($filters, $page, $perPage, $withSummary);
    }

    public function getSystemLogsSummary(array $filters): array
    {
        return $this->getActivityLogsSummary($filters);
    }

    public function getRuntimeLogs(array $filters, int $page, int $perPage): array
    {
        $entries = $this->buildRuntimeLogEntries($filters);

        $paginator = $this->paginateCollection($entries, $page, $perPage);

        return $this->buildPaginatorPayload($paginator);
    }

    /**
     * Locate a runtime entry originating from the Laravel log file.
     *
     * @return array<string, mixed>|null
     */
    public function findRuntimeFileLog(string $id): ?array
    {
        foreach ($this->readLaravelLogEntries() as $entry) {
            if ((string) ($entry['id'] ?? '') === $id && empty($entry['task_key'])) {
                return $entry;
            }
        }

        return null;
    }

    public function getRuntimeLogsSummary(array $filters): array
    {
        return Cache::remember(
            $this->buildFileLogSummaryCacheKey('runtime', $filters),
            now()->addSeconds(self::FILE_LOG_SUMMARY_CACHE_TTL_SECONDS),
            function () use ($filters) {
                $entries = $this->buildRuntimeLogEntries($filters);

                return [
                    'total' => $entries->count(),
                    'errors' => $entries->whereIn('level', ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'])->count(),
                    'warnings' => $entries->where('level', 'WARNING')->count(),
                    'infos' => $entries->where('level', 'INFO')->count(),
                ];
            }
        );
    }

    public function getAdminLoginLogs(array $filters, int $page, int $perPage, bool $withSummary = true): array
    {
        // 仅当 operation_logs 表中从未有过任何 admin.login 记录（全新部署）时，才降级到
        // admin_users 快照。若表内有历史记录但当前过滤条件下为空，应返回空页而非降级，
        // 否则前端会看到与实际日志不一致的"最后一次登录"快照数据。
        $hasAnyLoginRecord = OperationLog::query()
            ->where('module', 'auth')
            ->where('action', 'admin.login')
            ->exists();

        if (! $hasAnyLoginRecord) {
            return $this->getAdminLoginLogsFromSnapshot($filters, $page, $perPage, $withSummary);
        }

        $query = OperationLog::query()
            ->where('module', 'auth')
            ->where('action', 'admin.login');

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($builder) use ($keyword) {
                $builder->where('ip_address', 'like', "%{$keyword}%")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.admin_username')) like ?", ["%{$keyword}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.admin_nickname')) like ?", ["%{$keyword}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.role_name')) like ?", ["%{$keyword}%"]);
            });
        }

        $this->applyDateFilter($query, $filters);

        $logs = (clone $query)->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);

        $rows = $this->mapOperationLogs($logs->getCollection(), false)->map(function (array $item) {
            $detail = is_array($item['detail'] ?? null) ? $item['detail'] : [];
            $item['admin_username'] = trim((string) ($detail['admin_username'] ?? $item['actor_name'] ?? ''));
            $item['admin_nickname'] = trim((string) ($detail['admin_nickname'] ?? ''));
            $item['role_name'] = trim((string) ($detail['role_name'] ?? ''));
            $item['source'] = 'operation_log';

            return $item;
        });
        $logs->setCollection($rows);

        return $this->buildPaginatorPayload(
            $logs,
            $withSummary
                ? [
                    'total' => $logs->total(),
                    'mode' => 'operation_log',
                ]
                : []
        );
    }

    private function getAdminLoginLogsFromSnapshot(array $filters, int $page, int $perPage, bool $withSummary = true): array
    {
        $query = AdminUser::query()
            ->with('role:id,name,label')
            ->whereNotNull('last_login_at')
            ->when(! empty($filters['keyword']), function ($builder) use ($filters) {
                $keyword = trim((string) $filters['keyword']);
                $builder->where(function ($q) use ($keyword) {
                    $q->where('username', 'like', "%{$keyword}%")
                        ->orWhere('nickname', 'like', "%{$keyword}%")
                        ->orWhere('last_login_ip', 'like', "%{$keyword}%");
                });
            });

        if (! empty($filters['start_date'])) {
            $query->where('last_login_at', '>=', Carbon::parse((string) $filters['start_date'])->startOfDay());
        }

        if (! empty($filters['end_date'])) {
            $query->where('last_login_at', '<=', Carbon::parse((string) $filters['end_date'])->endOfDay());
        }

        $admins = $query->orderByDesc('last_login_at')->paginate($perPage, ['*'], 'page', $page);
        $privacy = AdminPrivacy::current();
        $admins->setCollection($admins->getCollection()->map(function (AdminUser $admin) use ($privacy) {
            return [
                'id' => (int) $admin->id,
                'user_id' => (int) $admin->id,
                'user_type' => 'admin',
                'actor_name' => trim((string) ($admin->nickname ?: $admin->username)),
                'admin_username' => trim((string) $admin->username),
                'admin_nickname' => trim((string) ($admin->nickname ?? '')),
                'role_name' => trim((string) ($admin->role?->label ?: $admin->role?->name ?: '')),
                'ip_address' => $privacy->ip($admin->last_login_ip ?? ''),
                'created_at' => $admin->last_login_at?->format('Y-m-d H:i:s'),
                'detail' => ['source' => 'admin_users.last_login_at'],
                'source' => 'admin_snapshot',
            ];
        }));

        return $this->buildPaginatorPayload(
            $admins,
            $withSummary
                ? [
                    'total' => $admins->total(),
                    'mode' => 'admin_snapshot',
                ]
                : []
        );
    }

    private function baseApiLogQuery()
    {
        return OperationLog::query()->whereRaw('action REGEXP ?', [self::HTTP_ACTION_REGEXP]);
    }

    private function baseAdminLoginLogQuery()
    {
        return OperationLog::query()
            ->where('module', 'auth')
            ->where('action', 'admin.login');
    }

    private function buildPaginatorPayload(LengthAwarePaginator $paginator, array $summary = []): array
    {
        return array_merge($paginator->toArray(), [
            'summary' => $summary,
        ]);
    }

    // 管理端短信/邮件日志查询返回完整 content、params 与收件人，不做脱敏（项目红线：管理员需真实审计信息）
    private function buildSmsLogQuery(array $filters): ?Builder
    {
        if (! Schema::hasTable('message_logs')) {
            return null;
        }

        $tableName = 'message_logs';
        $query = MessageLog::query()
            ->where('channel', 'sms')
            ->selectRaw('id, recipient as phone, template_code, content, params_json, status, provider, request_id, error_msg, sent_at, created_at, updated_at, origin_type');
        $recipientColumn = 'recipient';

        $this->addOptionalSelectColumns($query, $tableName, ['plugin_id', 'driver_key', 'trace_id']);
        $this->applyPluginLogFilters($query, $tableName, $filters, 'driver_key');

        if (! empty($filters['phone'])) {
            $query->where($recipientColumn, 'like', '%'.trim((string) $filters['phone']).'%');
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($builder) use ($keyword, $tableName) {
                $builder->where('template_code', 'like', "%{$keyword}%")
                    ->orWhere('request_id', 'like', "%{$keyword}%");

                $this->applyOptionalKeywordColumns($builder, $tableName, $keyword, ['driver_key', 'trace_id']);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query;
    }

    private function buildEmailLogQuery(array $filters): ?Builder
    {
        if (! Schema::hasTable('message_logs')) {
            return null;
        }

        $tableName = 'message_logs';
        $query = MessageLog::query()
            ->where('channel', 'email')
            ->selectRaw('id, template_code, recipient as to_email, subject, content, status, error_msg, sent_at, created_at, updated_at');
        $recipientColumn = 'recipient';

        $this->addOptionalSelectColumns($query, $tableName, ['plugin_id', 'driver_key', 'trace_id']);
        $this->applyPluginLogFilters($query, $tableName, $filters, 'driver_key');

        if (! empty($filters['email'])) {
            $query->where($recipientColumn, 'like', '%'.trim((string) $filters['email']).'%');
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) ($filters['keyword'] ?? ''));
            $query->where(function ($builder) use ($keyword, $tableName) {
                $builder->where('template_code', 'like', "%{$keyword}%")
                    ->orWhere('subject', 'like', "%{$keyword}%")
                    ->orWhere('content', 'like', "%{$keyword}%");

                $this->applyOptionalKeywordColumns($builder, $tableName, $keyword, ['driver_key', 'trace_id']);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query;
    }

    private function buildNotificationSummaryQuery(string $channel, array $filters): ?Builder
    {
        if (! Schema::hasTable('message_logs')) {
            return null;
        }

        $tableName = 'message_logs';
        $query = MessageLog::query()->where('channel', $channel);
        $recipientColumn = 'recipient';
        $hasRequestId = true;

        $this->applyPluginLogFilters($query, $tableName, $filters, 'driver_key');

        if ($channel === 'sms' && ! empty($filters['phone'])) {
            $query->where($recipientColumn, 'like', '%'.trim((string) $filters['phone']).'%');
        }

        if ($channel === 'email' && ! empty($filters['email'])) {
            $query->where($recipientColumn, 'like', '%'.trim((string) $filters['email']).'%');
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($builder) use ($channel, $hasRequestId, $keyword, $tableName) {
                if ($channel === 'sms') {
                    $builder->where('template_code', 'like', "%{$keyword}%");

                    if ($hasRequestId) {
                        $builder->orWhere('request_id', 'like', "%{$keyword}%");
                    }

                    $this->applyOptionalKeywordColumns($builder, $tableName, $keyword, ['driver_key', 'trace_id']);

                    return;
                }

                $builder->where('content', 'like', "%{$keyword}%")
                    ->orWhere('template_code', 'like', "%{$keyword}%")
                    ->orWhere('subject', 'like', "%{$keyword}%");

                if ($hasRequestId) {
                    $builder->orWhere('request_id', 'like', "%{$keyword}%");
                }

                $this->applyOptionalKeywordColumns($builder, $tableName, $keyword, ['driver_key', 'trace_id']);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query;
    }

    private function buildListSummaryCacheKey(string $type, array $filters): string
    {
        ksort($filters);

        return 'admin_logs:summary:'.$type.':'.md5(json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function emptyPaginator(int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, $perPage);
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addOptionalSelectColumns(Builder $query, string $tableName, array $columns): void
    {
        foreach ($columns as $column) {
            if ($this->tableHasColumn($tableName, $column)) {
                $query->addSelect($column);
            }
        }
    }

    private function applyPluginLogFilters(Builder $query, string $tableName, array $filters, ?string $businessKeyColumn = null): void
    {
        if (! empty($filters['plugin_id']) && $this->tableHasColumn($tableName, 'plugin_id')) {
            $query->where('plugin_id', (int) $filters['plugin_id']);
        }

        if ($businessKeyColumn !== null && ! empty($filters[$businessKeyColumn]) && $this->tableHasColumn($tableName, $businessKeyColumn)) {
            $query->where($businessKeyColumn, trim((string) $filters[$businessKeyColumn]));
        }

        if (! empty($filters['trace_id']) && $this->tableHasColumn($tableName, 'trace_id')) {
            $query->where('trace_id', 'like', '%'.trim((string) $filters['trace_id']).'%');
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function applyOptionalKeywordColumns(Builder $query, string $tableName, string $keyword, array $columns): void
    {
        foreach ($columns as $column) {
            if ($this->tableHasColumn($tableName, $column)) {
                $query->orWhere($column, 'like', "%{$keyword}%");
            }
        }
    }

    private function tableHasColumn(string $tableName, string $column): bool
    {
        return Schema::hasTable($tableName) && Schema::hasColumn($tableName, $column);
    }

    private function normalizeNotificationParams(mixed $value): array
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

    private function buildTaskLogEntries(array $filters): Collection
    {
        $scheduleEntries = $this->buildScheduleRunTaskLogEntries($filters);

        // 当 schedule_run_logs 表存在并已有记录时，ScheduleRunLogService 同时会往 activity_logs
        // (module=cron) 写一条镜像记录，二者代表同一次执行。此时跳过 activity_logs cron 源，
        // 避免同一任务运行在列表中出现两行。
        $cronActivityEntries = Schema::hasTable('schedule_run_logs') && ScheduleRunLog::query()->exists()
            ? collect()
            : $this->buildCronActivityTaskLogEntries($filters);

        return $scheduleEntries
            ->merge($cronActivityEntries)
            ->merge($this->buildFileTaskLogEntries($filters))
            ->sortByDesc(fn (array $item) => (string) ($item['time'] ?? $item['created_at'] ?? ''))
            ->values();
    }

    private function buildFileTaskLogEntries(array $filters): Collection
    {
        return collect($this->readLaravelLogEntries())
            ->filter(fn (array $item) => ! empty($item['task_key']))
            ->filter(function (array $item) use ($filters) {
                if (! empty($filters['task_key']) && (string) $item['task_key'] !== trim((string) $filters['task_key'])) {
                    return false;
                }

                if (! empty($filters['level']) && strtoupper((string) $item['level']) !== strtoupper((string) $filters['level'])) {
                    return false;
                }

                if (! empty($filters['status']) && $this->statusFromLogLevel((string) ($item['level'] ?? '')) !== trim((string) $filters['status'])) {
                    return false;
                }

                if (! empty($filters['keyword'])) {
                    $keyword = trim((string) $filters['keyword']);
                    if (! str_contains((string) $item['message'], $keyword) && ! str_contains((string) $item['task_title'], $keyword)) {
                        return false;
                    }
                }

                return $this->matchLogDateRange((string) $item['time'], $filters);
            })
            ->map(function (array $item) {
                $item['source'] = 'laravel_log';
                $item['status'] = $this->statusFromLogLevel((string) ($item['level'] ?? ''));
                $item['summary'] = null;
                $item['error_msg'] = in_array(strtoupper((string) ($item['level'] ?? '')), ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)
                    ? ($item['raw'] ?? $item['message'] ?? null)
                    : null;

                return $item;
            })
            ->values();
    }

    private function buildScheduleRunTaskLogEntries(array $filters): Collection
    {
        if (! Schema::hasTable('schedule_run_logs')) {
            return collect();
        }

        $query = ScheduleRunLog::query();

        if (! empty($filters['task_key'])) {
            $query->where('task_name', trim((string) $filters['task_key']));
        }

        if (! empty($filters['status'])) {
            $query->where('status', trim((string) $filters['status']));
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($builder) use ($keyword) {
                $builder->where('task_name', 'like', "%{$keyword}%")
                    ->orWhere('error_msg', 'like', "%{$keyword}%");
            });
        }

        if (! empty($filters['start_date'])) {
            $query->where('started_at', '>=', Carbon::parse((string) $filters['start_date'])->startOfDay());
        }

        if (! empty($filters['end_date'])) {
            $query->where('started_at', '<=', Carbon::parse((string) $filters['end_date'])->endOfDay());
        }

        return $query
            ->orderByDesc('started_at')
            ->limit(1000)
            ->get()
            ->map(function (ScheduleRunLog $log) {
                $taskKey = trim((string) $log->task_name);
                $summary = is_array($log->summary) ? SensitiveDataSanitizer::sanitize($log->summary) : null;
                $errorMessage = trim((string) ($log->error_msg ?? ''));
                $message = $errorMessage !== ''
                    ? $errorMessage
                    : $this->taskSummaryText($summary, trim((string) $log->status));

                return [
                    'id' => 'schedule-'.$log->id,
                    'source' => 'schedule_run_logs',
                    'time' => $log->started_at?->format('Y-m-d H:i:s') ?: $log->created_at?->format('Y-m-d H:i:s'),
                    'started_at' => $log->started_at?->format('Y-m-d H:i:s'),
                    'finished_at' => $log->finished_at?->format('Y-m-d H:i:s'),
                    'task_key' => $taskKey,
                    'task_name' => $taskKey,
                    'task_title' => self::TASK_META[$taskKey]['title'] ?? $taskKey,
                    'status' => trim((string) $log->status),
                    'level' => $this->levelFromTaskStatus((string) $log->status),
                    'duration_ms' => (int) $log->duration_ms,
                    'summary' => $summary,
                    'error_msg' => $errorMessage,
                    'message' => $message,
                    'raw' => $errorMessage,
                ];
            })
            ->values();
    }

    private function buildCronActivityTaskLogEntries(array $filters): Collection
    {
        if (! Schema::hasTable('activity_logs')) {
            return collect();
        }

        $query = ActivityLog::query()->where('module', 'cron');

        if (! empty($filters['task_key'])) {
            $taskKey = trim((string) $filters['task_key']);
            $query->where(function ($builder) use ($taskKey) {
                $builder->where('description', 'like', "%{$taskKey}%")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.task_key')) = ?", [$taskKey]);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('action', trim((string) $filters['status']));
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($builder) use ($keyword) {
                $builder->where('description', 'like', "%{$keyword}%")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.task_key')) like ?", ["%{$keyword}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.error_message')) like ?", ["%{$keyword}%"]);
            });
        }

        $this->applyDateFilter($query, $filters);

        return $query
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get()
            ->map(function (ActivityLog $log) {
                $context = is_array($log->context) ? SensitiveDataSanitizer::sanitize($log->context) : [];
                $taskKey = trim((string) ($context['task_key'] ?? ''));
                $status = trim((string) $log->action);

                return [
                    'id' => 'activity-'.$log->id,
                    'source' => 'activity_logs',
                    'time' => $log->created_at?->format('Y-m-d H:i:s'),
                    'started_at' => null,
                    'finished_at' => $log->created_at?->format('Y-m-d H:i:s'),
                    'task_key' => $taskKey,
                    'task_name' => $taskKey ?: trim((string) $log->subject_type),
                    'task_title' => $taskKey !== '' ? (self::TASK_META[$taskKey]['title'] ?? $taskKey) : trim((string) $log->subject_type),
                    'status' => $status,
                    'level' => $this->levelFromTaskStatus($status),
                    'duration_ms' => isset($context['duration_ms']) ? (int) $context['duration_ms'] : null,
                    'summary' => $context['summary'] ?? null,
                    'error_msg' => $context['error_message'] ?? null,
                    'message' => trim((string) $log->description),
                    'raw' => trim((string) $log->description),
                ];
            })
            ->values();
    }

    private function buildRuntimeLogEntries(array $filters): Collection
    {
        $fileEntries = $this->hasStructuredRuntimeFilter($filters)
            ? collect()
            : collect($this->readLaravelLogEntries())
                ->filter(fn (array $item) => empty($item['task_key']));

        return $fileEntries
            ->merge($this->buildPluginRuntimeLogEntries($filters))
            ->filter(function (array $item) use ($filters) {
                if (! empty($filters['level']) && strtoupper((string) $item['level']) !== strtoupper((string) $filters['level'])) {
                    return false;
                }

                if (! empty($filters['keyword'])) {
                    $keyword = trim((string) $filters['keyword']);
                    if (! str_contains((string) $item['message'], $keyword)) {
                        return false;
                    }
                }

                return $this->matchLogDateRange((string) $item['time'], $filters);
            })
            ->sortByDesc(fn (array $item) => strtotime((string) ($item['time'] ?? '')) ?: 0)
            ->values();
    }

    private function buildPluginRuntimeLogEntries(array $filters): Collection
    {
        if (! Schema::hasTable('integration_plugin_runtime_logs')) {
            return collect();
        }

        $query = IntegrationPluginRuntimeLog::query();

        if (! empty($filters['plugin_id'])) {
            $query->where('plugin_id', (int) $filters['plugin_id']);
        }

        if (! empty($filters['gateway_key'])) {
            $query->where('plugin_key', trim((string) $filters['gateway_key']));
        }

        if (! empty($filters['driver_key'])) {
            $query->where('plugin_key', trim((string) $filters['driver_key']));
        }

        if (! empty($filters['trace_id'])) {
            $query->where('trace_id', 'like', '%'.trim((string) $filters['trace_id']).'%');
        }

        if (! empty($filters['status'])) {
            $query->where('status', trim((string) $filters['status']));
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function (Builder $builder) use ($keyword): void {
                $builder->where('plugin_key', 'like', "%{$keyword}%")
                    ->orWhere('slug', 'like', "%{$keyword}%")
                    ->orWhere('action', 'like', "%{$keyword}%")
                    ->orWhere('trace_id', 'like', "%{$keyword}%")
                    ->orWhere('error_message', 'like', "%{$keyword}%");
            });
        }

        $this->applyDateFilter($query, $filters);

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1600)
            ->get()
            ->map(fn (IntegrationPluginRuntimeLog $log): array => $this->mapPluginRuntimeLogEntry($log));
    }

    private function mapPluginRuntimeLogEntry(IntegrationPluginRuntimeLog $log): array
    {
        $status = trim((string) ($log->status ?? ''));
        $pluginLabel = trim((string) ($log->plugin_key ?: $log->slug));
        $action = trim((string) ($log->action ?? ''));
        $error = trim((string) ($log->error_message ?? ''));
        $message = trim($pluginLabel.' '.$action);

        if ($error !== '') {
            $message = trim($message.' - '.$error);
        }

        return [
            'id' => 'plugin-runtime-'.$log->id,
            'source' => 'integration_plugin_runtime_logs',
            'time' => $log->created_at?->format('Y-m-d H:i:s'),
            'level' => strtolower($status) === 'failed' ? 'ERROR' : 'INFO',
            'message' => $message !== '' ? $message : 'plugin runtime',
            'raw' => $message,
            'status' => $status,
            'trace_id' => trim((string) ($log->trace_id ?? '')),
            'domain' => trim((string) ($log->domain ?? '')),
            'plugin_id' => $log->plugin_id,
            'plugin_key' => trim((string) ($log->plugin_key ?? '')),
            'slug' => trim((string) ($log->slug ?? '')),
            'action' => $action,
            'duration_ms' => $log->duration_ms,
            'error_msg' => $error,
            'request_meta' => $log->request_meta_json ?? [],
            'response_meta' => $log->response_meta_json ?? [],
        ];
    }

    private function hasStructuredRuntimeFilter(array $filters): bool
    {
        foreach (['plugin_id', 'gateway_key', 'driver_key', 'trace_id'] as $key) {
            if (! empty($filters[$key])) {
                return true;
            }
        }

        return false;
    }

    private function levelFromTaskStatus(string $status): string
    {
        return match (strtolower($status)) {
            'success' => 'SUCCESS',
            'skipped' => 'NOTICE',
            'failed' => 'ERROR',
            default => 'INFO',
        };
    }

    private function statusFromLogLevel(string $level): string
    {
        return in_array(strtoupper($level), ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)
            ? 'failed'
            : 'success';
    }

    private function taskSummaryText(?array $summary, string $status): string
    {
        if ($summary === null || $summary === []) {
            return $status === '' ? '任务执行完成' : '任务状态：'.$status;
        }

        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '任务执行完成' : $json;
    }

    private function buildFileLogSummaryCacheKey(string $type, array $filters): string
    {
        $logPath = storage_path('logs/laravel.log');
        $fileModifiedAt = is_file($logPath) ? (int) filemtime($logPath) : 0;
        $fileSize = is_file($logPath) ? (int) filesize($logPath) : 0;
        ksort($filters);

        return 'admin_logs:file_summary:'.$type.':'.$fileModifiedAt.':'.$fileSize.':'.md5(
            json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function paginateCollection(Collection $items, int $page, int $perPage): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $total = $items->count();
        $data = $items->forPage($page, $perPage)->values();

        return new LengthAwarePaginator($data, $total, $perPage, $page);
    }

    private function mapOperationLogs(Collection $logs, bool $includeMessageFields): Collection
    {
        $privacy = AdminPrivacy::current();
        $adminIds = $logs->where('user_type', 'admin')->pluck('user_id')->filter()->unique()->values();
        $clientIds = $logs->where('user_type', 'client')->pluck('user_id')->filter()->unique()->values();

        $admins = $adminIds->isEmpty()
            ? collect()
            : AdminUser::query()->whereIn('id', $adminIds)->get(['id', 'username', 'nickname', 'role_id'])->keyBy('id');

        $roleIds = $admins->pluck('role_id')->filter()->unique()->values();
        $roles = $roleIds->isEmpty()
            ? collect()
            : Role::query()->whereIn('id', $roleIds)->get(['id', 'name', 'label'])->keyBy('id');

        $clients = $clientIds->isEmpty()
            ? collect()
            : User::query()
                ->withReadAggregates()
                ->whereIn('id', $clientIds)
                ->get([
                    'id',
                    'email',
                    'phone',
                    'nickname',
                    'real_name',
                    'verification_status',
                    'is_verified',
                ])
                ->keyBy('id');

        return $logs->map(function (OperationLog $log) use ($admins, $roles, $clients, $includeMessageFields, $privacy) {
            $detail = is_array($log->detail) ? $log->detail : [];
            $actorName = '';
            $roleName = '';

            if ($log->user_type === 'admin') {
                $admin = $admins->get($log->user_id);
                if ($admin) {
                    $actorName = trim((string) ($admin->nickname ?: $admin->username));
                    $role = $roles->get($admin->role_id);
                    $roleName = trim((string) ($role?->label ?: $role?->name ?: ''));
                }
            } elseif ($log->user_type === 'client') {
                $client = $clients->get($log->user_id);
                if ($client instanceof User) {
                    $actorName = $privacy->displayName($client->display_name, $client->email, $client->phone, $client->real_name);
                }
            }

            $item = [
                'id' => (int) $log->id,
                'user_id' => $log->user_id ? (int) $log->user_id : null,
                'user_type' => trim((string) ($log->user_type ?? '')),
                'actor_name' => $actorName,
                'role_name' => $roleName,
                'action' => trim((string) $log->action),
                'module' => trim((string) ($log->module ?? '')),
                'target_id' => $log->target_id ? (int) $log->target_id : null,
                'detail' => $privacy->payload($detail),
                'ip_address' => $privacy->ip($log->ip_address ?? ''),
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ];

            if ($includeMessageFields) {
                $item['title'] = trim((string) ($detail['title'] ?? $detail['subject'] ?? ''));
                $item['content'] = trim((string) ($detail['content'] ?? $detail['message'] ?? ''));
                $item['target'] = trim((string) ($detail['target'] ?? $detail['to'] ?? ''));
                $item['status'] = trim((string) ($detail['status'] ?? 'success'));
            }

            return $item;
        });
    }

    private function applyDateFilter($query, array $filters): void
    {
        if (! empty($filters['start_date'])) {
            $query->where('created_at', '>=', Carbon::parse((string) $filters['start_date'])->startOfDay());
        }

        if (! empty($filters['end_date'])) {
            $query->where('created_at', '<=', Carbon::parse((string) $filters['end_date'])->endOfDay());
        }
    }

    private function splitHttpAction(string $action): array
    {
        if (! preg_match('/^(GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)\s+(.+)$/', trim($action), $matches)) {
            return ['', $action];
        }

        return [trim((string) $matches[1]), trim((string) $matches[2])];
    }

    private function readLaravelLogEntries(int $lineLimit = 1600): array
    {
        $logPath = storage_path('logs/laravel.log');

        if (! is_file($logPath)) {
            return [];
        }

        $fileModifiedAt = (int) filemtime($logPath);
        $fileSize = (int) filesize($logPath);
        $cacheKey = "admin_logs:file_scan:{$lineLimit}:{$fileModifiedAt}:{$fileSize}";

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(self::FILE_LOG_CACHE_TTL_SECONDS),
            function () use ($logPath, $lineLimit) {
                return collect($this->readLastLines($logPath, $lineLimit))
                    ->reverse()
                    ->map(fn (array $item) => $this->parseLaravelLogLine((string) $item['content'], (int) $item['line_no']))
                    ->filter()
                    ->values()
                    ->all();
            }
        );
    }

    /**
     * 返回文件尾部窗口内最后 $limit 行非空行，元素为 `['line_no' => 物理行号, 'content' => 行内容]`。
     *
     * 物理行号用于生成日志 ID，保证文件持续追加时既有日志行的 ID 不因窗口滑动而漂移，
     * 否则列表返回的 ID 在详情请求时可能已无法复现（行序号随追加变化）。
     *
     * @return list<array{line_no: int, content: string}>
     */
    private function readLastLines(string $path, int $limit): array
    {
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $startLine = max($lastLine - $limit, 0);
        $lines = [];

        $file->seek($startLine);

        while (! $file->eof()) {
            $line = rtrim((string) $file->current(), "\r\n");
            $lineNo = (int) $file->key();
            if ($line !== '') {
                $lines[] = ['line_no' => $lineNo, 'content' => $line];
            }
            $file->next();
        }

        return $lines;
    }

    private function parseLaravelLogLine(string $line, int $lineNo): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        if (! preg_match('/^\[(?<time>[^\]]+)\]\s+\w+\.(?<level>[A-Z]+):\s+(?<message>.+)$/u', $line, $matches)) {
            return null;
        }

        $message = SensitiveDataSanitizer::sanitizeText(trim((string) $matches['message']));
        $taskKey = $this->resolveTaskKeyFromMessage($message);

        return [
            'id' => md5($line.'|'.$lineNo),
            'time' => trim((string) $matches['time']),
            'level' => trim((string) $matches['level']),
            'message' => preg_replace('/\s+\{.*$/u', '', $message) ?: $message,
            'raw' => $message,
            'task_key' => $taskKey,
            'task_title' => $taskKey ? (self::TASK_META[$taskKey]['title'] ?? $taskKey) : '',
        ];
    }

    private function resolveTaskKeyFromMessage(string $message): ?string
    {
        foreach (self::TASK_META as $taskKey => $meta) {
            foreach ((array) ($meta['log_keywords'] ?? []) as $keyword) {
                if ($keyword !== '' && str_contains($message, $keyword)) {
                    return $taskKey;
                }
            }
        }

        return null;
    }

    private function matchLogDateRange(string $time, array $filters): bool
    {
        try {
            $date = Carbon::parse($time);
        } catch (\Throwable) {
            return true;
        }

        if (! empty($filters['start_date']) && $date->lt(Carbon::parse((string) $filters['start_date'])->startOfDay())) {
            return false;
        }

        if (! empty($filters['end_date']) && $date->gt(Carbon::parse((string) $filters['end_date'])->endOfDay())) {
            return false;
        }

        return true;
    }

    public function getGatewayLogs(array $filters, int $page, int $perPage, bool $withSummary = true): array
    {
        if (! Schema::hasTable('gateway_logs')) {
            return $this->buildPaginatorPayload($this->emptyPaginator($perPage));
        }

        $query = GatewayLog::query();
        $this->applyPluginLogFilters($query, 'gateway_logs', $filters, 'gateway_key');

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($builder) use ($keyword) {
                $builder->where('out_trade_no', 'like', "%{$keyword}%")
                    ->orWhere('trade_no', 'like', "%{$keyword}%")
                    ->orWhere('gateway', 'like', "%{$keyword}%")
                    ->orWhere('error_msg', 'like', "%{$keyword}%");

                $this->applyOptionalKeywordColumns($builder, 'gateway_logs', $keyword, ['gateway_key', 'trace_id']);
            });
        }

        if (! empty($filters['gateway'])) {
            $query->where('gateway_key', PaymentGatewayCode::normalize((string) $filters['gateway']));
        }

        if (! empty($filters['action'])) {
            $query->where('action', trim((string) $filters['action']));
        }

        if (! empty($filters['result_status'])) {
            $query->where('result_status', trim((string) $filters['result_status']));
        }

        $this->applyDateFilter($query, $filters);

        $logs = (clone $query)->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);

        if (! $withSummary) {
            return $this->buildPaginatorPayload($logs);
        }

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN result_status = 'success' THEN 1 ELSE 0 END), 0) as success")
            ->selectRaw("COALESCE(SUM(CASE WHEN result_status = 'failed' THEN 1 ELSE 0 END), 0) as failed")
            ->first();

        return $this->buildPaginatorPayload($logs, [
            'total' => (int) ($summary?->total ?? 0),
            'success' => (int) ($summary?->success ?? 0),
            'failed' => (int) ($summary?->failed ?? 0),
        ]);
    }

    public function getUpstreamLogs(array $filters, int $page, int $perPage, bool $withSummary = true): array
    {
        $query = $this->buildUpstreamLogQuery($filters);
        if ($query === null) {
            return $this->buildPaginatorPayload($this->emptyPaginator($perPage));
        }

        $logs = (clone $query)->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        $logs->setCollection($logs->getCollection()->map(fn (TicketUpstreamDeliveryLog $log): array => $this->upstreamLogRow($log)));

        if (! $withSummary) {
            return $this->buildPaginatorPayload($logs);
        }

        return $this->buildPaginatorPayload($logs, $this->getUpstreamLogsSummary($filters));
    }

    public function getUpstreamLogsSummary(array $filters): array
    {
        $query = $this->buildUpstreamLogQuery($filters);
        if ($query === null) {
            return ['total' => 0, 'failed' => 0, 'delivered' => 0, 'skipped' => 0, 'pending' => 0, 'sending' => 0];
        }

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) as failed")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END), 0) as delivered")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END), 0) as skipped")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'sending' THEN 1 ELSE 0 END), 0) as sending")
            ->first();

        return [
            'total' => (int) ($summary?->total ?? 0),
            'failed' => (int) ($summary?->failed ?? 0),
            'delivered' => (int) ($summary?->delivered ?? 0),
            'skipped' => (int) ($summary?->skipped ?? 0),
            'pending' => (int) ($summary?->pending ?? 0),
            'sending' => (int) ($summary?->sending ?? 0),
        ];
    }

    /** @return array<string, mixed>|null */
    public function getUpstreamLog(int|string $id): ?array
    {
        if (! Schema::hasTable('ticket_upstream_delivery_logs')) {
            return null;
        }

        $log = TicketUpstreamDeliveryLog::query()->find($id);
        if (! $log instanceof TicketUpstreamDeliveryLog) {
            return null;
        }

        $row = $this->upstreamLogRow($log);

        return [
            'id' => (string) $log->id,
            'channel' => 'upstream',
            'source' => 'ticket_upstream_delivery_logs',
            'fields' => $row,
            'message' => (string) ($log->message ?? ''),
            'context' => [],
            'created_at' => $log->occurred_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function buildUpstreamLogQuery(array $filters): ?Builder
    {
        if (! Schema::hasTable('ticket_upstream_delivery_logs')) {
            return null;
        }

        $query = TicketUpstreamDeliveryLog::query();
        foreach (['ticket_id', 'ticket_reply_id', 'supplier_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, (int) $filters[$field]);
            }
        }
        foreach (['status', 'operation', 'event', 'reason_code', 'provider_key'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, trim((string) $filters[$field]));
            }
        }
        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function (Builder $builder) use ($keyword): void {
                foreach (['message', 'reason_code', 'provider_key', 'operation', 'event'] as $field) {
                    $builder->orWhere($field, 'like', "%{$keyword}%");
                }
                if (ctype_digit($keyword)) {
                    $builder->orWhere('ticket_id', (int) $keyword)
                        ->orWhere('ticket_reply_id', (int) $keyword)
                        ->orWhere('supplier_id', (int) $keyword);
                }
            });
        }
        if (! empty($filters['start_date'])) {
            $query->where('occurred_at', '>=', Carbon::parse((string) $filters['start_date'])->startOfDay());
        }
        if (! empty($filters['end_date'])) {
            $query->where('occurred_at', '<=', Carbon::parse((string) $filters['end_date'])->endOfDay());
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function upstreamLogRow(TicketUpstreamDeliveryLog $log): array
    {
        return [
            'id' => (int) $log->id,
            'ticket_id' => (int) $log->ticket_id,
            'ticket_reply_id' => $log->ticket_reply_id ? (int) $log->ticket_reply_id : null,
            'direction' => (string) $log->direction,
            'operation' => (string) $log->operation,
            'event' => (string) $log->event,
            'status' => (string) $log->status,
            'reason_code' => $log->reason_code,
            'provider_key' => $log->provider_key,
            'supplier_id' => $log->supplier_id ? (int) $log->supplier_id : null,
            'attempt' => $log->attempt ? (int) $log->attempt : null,
            'http_status' => $log->http_status ? (int) $log->http_status : null,
            'duration_ms' => $log->duration_ms ? (int) $log->duration_ms : null,
            'message' => (string) ($log->message ?? ''),
            'occurred_at' => $log->occurred_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function getActivityLogs(array $filters, int $page, int $perPage, bool $withSummary = true): array
    {
        // 仅在表不存在，或表完全没有任何数据（刚迁移、尚未写入）时才降级到 operation_logs。
        // 若表已存在并有历史数据，过滤条件导致的空结果不应降级，否则同一页面会混用两套数据源。
        if (! Schema::hasTable('activity_logs') || ActivityLog::query()->doesntExist()) {
            return $this->getBusinessOperationLogsAsActivityLogs($filters, $page, $perPage, $withSummary);
        }

        $query = ActivityLog::query();
        $this->applyActivityLogFilters($query, $filters);

        $logs = (clone $query)->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);

        $privacy = AdminPrivacy::current();
        $logs->setCollection($logs->getCollection()->map(function (ActivityLog $log) use ($privacy) {
            $item = $log->toArray();
            $item['source'] = 'activity_log';
            $item['context'] = $privacy->payload($item['context'] ?? []);
            $item['ip_address'] = $privacy->ip($item['ip_address'] ?? '');
            if (($item['actor_type'] ?? '') === 'client') {
                $item['actor_name'] = $privacy->displayName($item['actor_name'] ?? '');
            }

            return $item;
        }));

        if (! $withSummary) {
            return $this->buildPaginatorPayload($logs);
        }

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(DISTINCT module) as modules')
            ->first();

        return $this->buildPaginatorPayload($logs, [
            'total' => (int) ($summary?->total ?? 0),
            'modules' => (int) ($summary?->modules ?? 0),
            'source' => 'activity_logs',
        ]);
    }

    public function getActivityLogsSummary(array $filters): array
    {
        // 与 getActivityLogs 保持一致：仅当表不存在或完全无数据时才降级。
        // 过滤条件导致的空结果不触发降级，否则 summary 与 list 的 source 不一致。
        if (Schema::hasTable('activity_logs') && ActivityLog::query()->exists()) {
            $query = ActivityLog::query();
            $this->applyActivityLogFilters($query, $filters);

            $summary = (clone $query)
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('COUNT(DISTINCT module) as modules')
                ->first();

            return [
                'total' => (int) ($summary?->total ?? 0),
                'modules' => (int) ($summary?->modules ?? 0),
                'source' => 'activity_logs',
            ];
        }

        if (! Schema::hasTable('operation_logs')) {
            return [
                'total' => 0,
                'modules' => 0,
                'source' => 'none',
            ];
        }

        $query = OperationLog::query()
            ->whereRaw('action NOT REGEXP ?', [self::HTTP_ACTION_REGEXP])
            ->where('action', '<>', 'admin.login');
        $this->applyBusinessOperationActivityFilters($query, $filters);

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(DISTINCT module) as modules')
            ->first();

        return [
            'total' => (int) ($summary?->total ?? 0),
            'modules' => (int) ($summary?->modules ?? 0),
            'source' => 'operation_logs',
        ];
    }

    private function applyActivityLogFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($builder) use ($keyword) {
                $builder->where('description', 'like', "%{$keyword}%")
                    ->orWhere('actor_name', 'like', "%{$keyword}%")
                    ->orWhere('module', 'like', "%{$keyword}%")
                    ->orWhere('ip_address', 'like', "%{$keyword}%");

                if (ctype_digit($keyword)) {
                    $builder->orWhere('actor_id', (int) $keyword)
                        ->orWhere('subject_id', (int) $keyword);
                }
            });
        }

        if (! empty($filters['actor_keyword'])) {
            $keyword = trim((string) $filters['actor_keyword']);
            $query->where(function ($builder) use ($keyword) {
                $builder->where('actor_name', 'like', "%{$keyword}%");

                if (ctype_digit($keyword)) {
                    $builder->orWhere('actor_id', (int) $keyword);
                }
            });
        }

        if (! empty($filters['description_keyword'])) {
            $keyword = trim((string) $filters['description_keyword']);
            $query->where('description', 'like', "%{$keyword}%");
        }

        if (! empty($filters['ip_address'])) {
            $query->where('ip_address', 'like', '%'.trim((string) $filters['ip_address']).'%');
        }

        if (! empty($filters['module'])) {
            $query->where('module', trim((string) $filters['module']));
        }

        if (! empty($filters['actor_type'])) {
            $query->where('actor_type', trim((string) $filters['actor_type']));
        }

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', trim((string) $filters['subject_type']));
        }

        $this->applyDateFilter($query, $filters);
    }

    private function getBusinessOperationLogsAsActivityLogs(array $filters, int $page, int $perPage, bool $withSummary = true): array
    {
        if (! Schema::hasTable('operation_logs')) {
            return $this->buildPaginatorPayload($this->emptyPaginator($perPage));
        }

        $query = OperationLog::query()
            ->whereRaw('action NOT REGEXP ?', [self::HTTP_ACTION_REGEXP])
            ->where('action', '<>', 'admin.login');
        $this->applyBusinessOperationActivityFilters($query, $filters);

        $logs = (clone $query)->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);
        $logs->setCollection(
            $this->mapOperationLogs($logs->getCollection(), true)
                ->map(fn (array $item) => $this->mapOperationLogToActivityRow($item))
        );

        if (! $withSummary) {
            return $this->buildPaginatorPayload($logs);
        }

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(DISTINCT module) as modules')
            ->first();

        return $this->buildPaginatorPayload($logs, [
            'total' => (int) ($summary?->total ?? 0),
            'modules' => (int) ($summary?->modules ?? 0),
            'source' => 'operation_logs',
        ]);
    }

    private function applyBusinessOperationActivityFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $actorCandidates = $this->resolveActorKeywordCandidates($keyword);
            $subjectIdColumn = $this->operationLogSubjectIdColumn();
            $query->where(function ($builder) use ($keyword, $actorCandidates, $subjectIdColumn) {
                $builder->where('action', 'like', "%{$keyword}%")
                    ->orWhere('module', 'like', "%{$keyword}%")
                    ->orWhere('ip_address', 'like', "%{$keyword}%")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.title')) like ?", ["%{$keyword}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.message')) like ?", ["%{$keyword}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.content')) like ?", ["%{$keyword}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.target')) like ?", ["%{$keyword}%"]);

                if (ctype_digit($keyword) && $subjectIdColumn !== null) {
                    $builder->orWhere($subjectIdColumn, (int) $keyword);
                }

                if ($actorCandidates['admin'] !== []) {
                    $builder->orWhere(function ($query) use ($actorCandidates) {
                        $query->where('user_type', 'admin')
                            ->whereIn('user_id', $actorCandidates['admin']);
                    });
                }

                if ($actorCandidates['client'] !== []) {
                    $builder->orWhere(function ($query) use ($actorCandidates) {
                        $query->where('user_type', 'client')
                            ->whereIn('user_id', $actorCandidates['client']);
                    });
                }
            });
        }

        if (! empty($filters['actor_keyword'])) {
            $keyword = trim((string) $filters['actor_keyword']);
            $actorCandidates = $this->resolveActorKeywordCandidates($keyword);
            $query->where(function ($builder) use ($keyword, $actorCandidates) {
                if (ctype_digit($keyword)) {
                    $builder->where('user_id', (int) $keyword);
                }

                if ($actorCandidates['admin'] !== []) {
                    $builder->orWhere(function ($query) use ($actorCandidates) {
                        $query->where('user_type', 'admin')
                            ->whereIn('user_id', $actorCandidates['admin']);
                    });
                }

                if ($actorCandidates['client'] !== []) {
                    $builder->orWhere(function ($query) use ($actorCandidates) {
                        $query->where('user_type', 'client')
                            ->whereIn('user_id', $actorCandidates['client']);
                    });
                }

                if (! ctype_digit($keyword) && $actorCandidates['admin'] === [] && $actorCandidates['client'] === []) {
                    $builder->whereRaw('1 = 0');
                }
            });
        }

        if (! empty($filters['description_keyword'])) {
            $keyword = trim((string) $filters['description_keyword']);
            $query->where(function ($builder) use ($keyword) {
                $builder->where('action', 'like', "%{$keyword}%")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.title')) like ?", ["%{$keyword}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.message')) like ?", ["%{$keyword}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.content')) like ?", ["%{$keyword}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.target')) like ?", ["%{$keyword}%"]);
            });
        }

        if (! empty($filters['ip_address'])) {
            $query->where('ip_address', 'like', '%'.trim((string) $filters['ip_address']).'%');
        }

        if (! empty($filters['module'])) {
            $query->where('module', trim((string) $filters['module']));
        }

        if (! empty($filters['actor_type'])) {
            $actorType = trim((string) $filters['actor_type']);
            if ($actorType === 'system') {
                $query->where(function ($builder) {
                    $builder->whereNull('user_type')
                        ->orWhere('user_type', '')
                        ->orWhere('user_type', 'system');
                });
            } else {
                $query->where('user_type', $actorType);
            }
        }

        if (! empty($filters['subject_type'])) {
            $query->where('module', trim((string) $filters['subject_type']));
        }

        $this->applyDateFilter($query, $filters);
    }

    private function operationLogSubjectIdColumn(): ?string
    {
        if (Schema::hasColumn('operation_logs', 'subject_id')) {
            return 'subject_id';
        }

        if (Schema::hasColumn('operation_logs', 'target_id')) {
            return 'target_id';
        }

        return null;
    }

    private function mapOperationLogToActivityRow(array $item): array
    {
        $detail = is_array($item['detail'] ?? null) ? $item['detail'] : [];
        $module = trim((string) ($item['module'] ?? ''));
        $actorType = trim((string) ($item['user_type'] ?? '')) ?: 'system';
        $actorName = trim((string) ($item['actor_name'] ?? '')) ?: $this->fallbackActorName($actorType);

        return [
            'id' => 'operation-'.$item['id'],
            'source' => 'operation_log',
            'actor_type' => $actorType,
            'actor_id' => $item['user_id'] ?? null,
            'actor_name' => $actorName,
            'module' => $module,
            'action' => trim((string) ($item['action'] ?? '')),
            'description' => $this->operationLogDescription($item),
            'subject_type' => $module !== '' ? $module : null,
            'subject_id' => $item['target_id'] ?? null,
            'context' => SensitiveDataSanitizer::sanitize($detail),
            'ip_address' => trim((string) ($item['ip_address'] ?? '')),
            'created_at' => $item['created_at'] ?? null,
        ];
    }

    private function operationLogDescription(array $item): string
    {
        foreach (['title', 'content', 'target'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $detail = is_array($item['detail'] ?? null) ? $item['detail'] : [];
        foreach (['title', 'message', 'content', 'target'] as $key) {
            $value = trim((string) ($detail[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) ($item['action'] ?? ''));
    }

    private function fallbackActorName(string $actorType): string
    {
        return [
            'admin' => '管理员',
            'client' => '客户',
            'system' => '系统',
            'sub_account' => '子账号',
        ][$actorType] ?? $actorType;
    }

    private function resolveActorKeywordCandidates(string $keyword): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['admin' => [], 'client' => []];
        }

        $adminIds = collect();
        if (Schema::hasTable('admin_users')) {
            $adminIds = AdminUser::query()
                ->where(function ($query) use ($keyword) {
                    $query->where('username', 'like', "%{$keyword}%")
                        ->orWhere('nickname', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%");

                    if (ctype_digit($keyword)) {
                        $query->orWhere('id', (int) $keyword);
                    }
                })
                ->limit(200)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);
        }

        $clientIds = collect();
        if (Schema::hasTable('users')) {
            $clientIds = User::query()
                ->where(function ($query) use ($keyword) {
                    $query->where('email', 'like', "%{$keyword}%")
                        ->orWhere('phone', 'like', "%{$keyword}%")
                        ->orWhere('nickname', 'like', "%{$keyword}%")
                        ->orWhere('real_name', 'like', "%{$keyword}%");

                    if (ctype_digit($keyword)) {
                        $query->orWhere('id', (int) $keyword);
                    }
                })
                ->limit(200)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);
        }

        return [
            'admin' => $adminIds->values()->all(),
            'client' => $clientIds->values()->all(),
        ];
    }
}
