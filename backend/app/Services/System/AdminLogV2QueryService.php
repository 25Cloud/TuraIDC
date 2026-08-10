<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Http\Resources\Admin\V2\AdminLogSummaryResource;
use App\Models\ActivityLog;
use App\Models\AdminUser;
use App\Models\GatewayLog;
use App\Models\IntegrationPluginRuntimeLog;
use App\Models\MessageLog;
use App\Models\OperationLog;
use App\Models\ScheduleRunLog;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AdminLogV2QueryService
{
    /**
     * @var list<string>
     */
    private const CHANNELS = [
        'activity',
        'admin-logins',
        'api',
        'email',
        'gateway',
        'runtime',
        'schedule',
        'sms',
        'system',
        'tasks',
    ];

    public function __construct(
        private readonly AdminLogService $adminLogService,
        private readonly ScheduleRunLogService $scheduleRunLogService,
    ) {}

    /**
     * @return list<string>
     */
    public static function channels(): array
    {
        return self::CHANNELS;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(string $channel, array $filters, int $page, int $perPage, bool $withSummary = true): array
    {
        $payload = $this->legacyList($channel, $filters, $page, $perPage, $withSummary);
        $rows = $payload['data'] ?? $payload['items'] ?? $payload['list'] ?? [];

        return [
            'list' => collect(is_array($rows) ? $rows : [])
                ->map(fn (mixed $row): array => AdminLogSummaryResource::make([
                    'channel' => $channel,
                    'row' => $this->normalizeRow($row),
                ])->resolve())
                ->values()
                ->all(),
            'total' => (int) ($payload['total'] ?? 0),
            'page' => (int) ($payload['current_page'] ?? $payload['page'] ?? $page),
            'page_size' => (int) ($payload['per_page'] ?? $payload['page_size'] ?? $perPage),
            'summary' => $withSummary ? $this->resolveSummary($channel, $filters, $payload) : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(string $channel, array $filters): array
    {
        return match ($channel) {
            'sms' => $this->adminLogService->getSmsLogsSummary($filters),
            'email' => $this->adminLogService->getEmailLogsSummary($filters),
            'tasks' => $this->adminLogService->getTaskLogsSummary($filters),
            'system', 'activity' => $this->adminLogService->getActivityLogsSummary($filters),
            'runtime' => $this->adminLogService->getRuntimeLogsSummary($filters),
            'schedule' => $this->scheduleSummary($filters),
            default => $this->legacyList($channel, $filters, 1, 1)['summary'] ?? [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(string $channel, string $log): array
    {
        $row = match ($channel) {
            'sms' => $this->notificationDetail('sms', $log),
            'email' => $this->notificationDetail('email', $log),
            'api' => $this->operationLogDetail($channel, $log),
            'admin-logins' => $this->adminLoginDetail($log),
            'system', 'activity' => $this->activityDetail($channel, $log),
            'gateway' => $this->gatewayDetail($log),
            'runtime' => $this->runtimeDetail($log),
            'tasks', 'schedule' => $this->scheduleDetail($channel, $log),
            default => null,
        };

        if ($row === null) {
            throw new NotFoundHttpException('日志不存在');
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function legacyList(string $channel, array $filters, int $page, int $perPage, bool $withSummary = true): array
    {
        return match ($channel) {
            'sms' => $this->adminLogService->getSmsLogs($filters, $perPage),
            'email' => $this->adminLogService->getEmailLogs($filters, $perPage),
            'api' => $this->adminLogService->getApiLogs($filters, $page, $perPage, $withSummary),
            'tasks' => $this->adminLogService->getTaskLogs($filters, $page, $perPage),
            'system' => $this->adminLogService->getSystemLogs($filters, $page, $perPage, $withSummary),
            'activity' => $this->adminLogService->getActivityLogs($filters, $page, $perPage, $withSummary),
            'runtime' => $this->adminLogService->getRuntimeLogs($filters, $page, $perPage),
            'admin-logins' => $this->adminLogService->getAdminLoginLogs($filters, $page, $perPage, $withSummary),
            'gateway' => $this->adminLogService->getGatewayLogs($filters, $page, $perPage, $withSummary),
            'schedule' => $this->scheduleRunLogService->getScheduleStatus($page, $perPage, $filters),
            default => throw new NotFoundHttpException('日志 channel 不存在'),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveSummary(string $channel, array $filters, array $payload): array
    {
        $summary = $payload['summary'] ?? [];

        if (is_array($summary) && $summary !== []) {
            return $summary;
        }

        return $this->summary($channel, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function scheduleSummary(array $filters): array
    {
        $payload = $this->scheduleRunLogService->getScheduleStatus(1, 1, $filters);

        return [
            'total' => (int) ($payload['total'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function notificationDetail(string $channel, string $log): ?array
    {
        if (! Schema::hasTable('message_logs')) {
            return null;
        }

        $model = MessageLog::query()->where('channel', $channel)->find($log);
        if (! $model instanceof Model) {
            return null;
        }

        $row = $this->normalizeRow($model);
        $recipient = (string) ($row['recipient'] ?? '');
        $fields = array_filter([
            'id' => (int) $model->getKey(),
            'phone' => $channel === 'sms' ? ($row['phone'] ?? $recipient) : null,
            'to_email' => $channel === 'email' ? ($row['to_email'] ?? $recipient) : null,
            'template_code' => $row['template_code'] ?? null,
            'subject' => $row['subject'] ?? null,
            'provider' => $row['provider'] ?? null,
            'request_id' => $row['request_id'] ?? null,
            'status' => $row['status'] ?? null,
            'plugin_id' => $row['plugin_id'] ?? null,
            'driver_key' => $row['driver_key'] ?? null,
            'trace_id' => $row['trace_id'] ?? null,
            'sent_at' => $this->dateValue($model, 'sent_at'),
            'created_at' => $this->dateValue($model, 'created_at'),
            'error_msg' => $row['error_msg'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return [
            'id' => (string) $model->getKey(),
            'channel' => $channel,
            'source' => 'message_logs',
            'fields' => $fields,
            'message' => (string) ($row['content'] ?? ''),
            'context' => [
                'params' => $row['params_json'] ?? $row['params'] ?? [],
                'error_msg' => $row['error_msg'] ?? '',
            ],
            'created_at' => $this->dateValue($model, 'created_at'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function operationLogDetail(string $channel, string $log): ?array
    {
        $model = OperationLog::query()->find($log);
        if (! $model instanceof OperationLog) {
            return null;
        }

        $context = $this->dropSensitiveKeys((array) ($model->detail ?? []));

        return [
            'id' => (string) $model->id,
            'channel' => $channel,
            'source' => 'operation_logs',
            'fields' => [
                'id' => (int) $model->id,
                'user_type' => (string) ($model->user_type ?? ''),
                'user_id' => $model->user_id !== null ? (int) $model->user_id : null,
                'module' => (string) ($model->module ?? ''),
                'action' => (string) ($model->action ?? ''),
                'subject_id' => $model->subject_id !== null ? (int) $model->subject_id : null,
                'ip_address' => SensitiveDataSanitizer::sanitize($model->ip_address ?? ''),
                'created_at' => $this->dateValue($model, 'created_at'),
            ],
            'message' => (string) ($model->action ?? ''),
            'context' => $context,
            'created_at' => $this->dateValue($model, 'created_at'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function adminLoginDetail(string $log): ?array
    {
        $operation = OperationLog::query()
            ->where('module', 'auth')
            ->where('action', 'admin.login')
            ->find($log);

        if ($operation instanceof OperationLog) {
            return $this->operationLogDetail('admin-logins', $log);
        }

        $admin = AdminUser::query()->with('role:id,name,label')->find($log);
        if (! $admin instanceof AdminUser) {
            return null;
        }

        return [
            'id' => (string) $admin->id,
            'channel' => 'admin-logins',
            'source' => 'admin_snapshot',
            'fields' => [
                'id' => (int) $admin->id,
                'admin_username' => (string) $admin->username,
                'admin_nickname' => (string) ($admin->nickname ?? ''),
                'role_name' => (string) ($admin->role?->label ?: $admin->role?->name ?: ''),
                'created_at' => $admin->last_login_at?->format('Y-m-d H:i:s'),
                'ip_address' => SensitiveDataSanitizer::sanitize($admin->last_login_ip ?? ''),
            ],
            'message' => '管理员登录快照',
            'context' => ['source' => 'admin_users.last_login_at'],
            'created_at' => $admin->last_login_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activityDetail(string $channel, string $log): ?array
    {
        if (str_starts_with($log, 'operation-')) {
            return $this->operationLogDetail($channel, substr($log, strlen('operation-')));
        }

        $activity = Schema::hasTable('activity_logs') ? ActivityLog::query()->find($log) : null;
        if (! $activity instanceof ActivityLog) {
            return $this->operationLogDetail($channel, $log);
        }

        $row = $this->normalizeRow($activity);

        return [
            'id' => (string) $activity->id,
            'channel' => $channel,
            'source' => 'activity_logs',
            'fields' => [
                'id' => (int) $activity->id,
                'actor_type' => (string) ($row['actor_type'] ?? ''),
                'actor_id' => $row['actor_id'] ?? null,
                'actor_name' => (string) ($row['actor_name'] ?? ''),
                'module' => (string) ($row['module'] ?? ''),
                'action' => (string) ($row['action'] ?? ''),
                'subject_type' => $row['subject_type'] ?? null,
                'subject_id' => $row['subject_id'] ?? null,
                'ip_address' => SensitiveDataSanitizer::sanitize($row['ip_address'] ?? ''),
                'created_at' => $this->dateValue($activity, 'created_at'),
            ],
            'message' => (string) ($row['description'] ?? ''),
            'context' => $this->dropSensitiveKeys((array) ($row['context'] ?? [])),
            'created_at' => $this->dateValue($activity, 'created_at'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function gatewayDetail(string $log): ?array
    {
        $model = GatewayLog::query()->find($log);
        if (! $model instanceof GatewayLog) {
            return null;
        }

        $row = $this->normalizeRow($model);

        return [
            'id' => (string) $model->id,
            'channel' => 'gateway',
            'source' => 'gateway_logs',
            'fields' => [
                'id' => (int) $model->id,
                'gateway' => $row['gateway'] ?? '',
                'gateway_key' => $row['gateway_key'] ?? '',
                'action' => $row['action'] ?? '',
                'result_status' => $row['result_status'] ?? '',
                'plugin_id' => $row['plugin_id'] ?? null,
                'trace_id' => $row['trace_id'] ?? '',
                'out_trade_no' => $row['out_trade_no'] ?? '',
                'trade_no' => $row['trade_no'] ?? '',
                'invoice_id' => $row['invoice_id'] ?? null,
                'created_at' => $this->dateValue($model, 'created_at'),
                'error_msg' => $row['error_msg'] ?? '',
            ],
            'message' => (string) ($row['error_msg'] ?? $row['action'] ?? ''),
            'context' => $this->dropSensitiveKeys([
                'request_data' => $row['request_data'] ?? [],
                'response_data' => $row['response_data'] ?? [],
            ]),
            'created_at' => $this->dateValue($model, 'created_at'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runtimeDetail(string $log): ?array
    {
        if (! str_starts_with($log, 'plugin-runtime-')) {
            return $this->runtimeFileDetail($log);
        }

        $id = $this->stripLogPrefix($log, 'plugin-runtime-');
        $model = IntegrationPluginRuntimeLog::query()->find($id);
        if (! $model instanceof IntegrationPluginRuntimeLog) {
            return null;
        }

        $row = $this->normalizeRow($model);

        return [
            'id' => (string) $model->id,
            'channel' => 'runtime',
            'source' => 'integration_plugin_runtime_logs',
            'fields' => [
                'id' => (int) $model->id,
                'level' => ($row['status'] ?? '') === 'failed' ? 'ERROR' : 'INFO',
                'plugin_id' => $row['plugin_id'] ?? null,
                'plugin_key' => $row['plugin_key'] ?? '',
                'trace_id' => $row['trace_id'] ?? '',
                'status' => $row['status'] ?? '',
                'duration_ms' => $row['duration_ms'] ?? null,
                'error_code' => $row['error_code'] ?? '',
                'time' => $this->dateValue($model, 'created_at'),
            ],
            'message' => (string) ($row['error_message'] ?? $row['action'] ?? ''),
            'context' => $this->dropSensitiveKeys([
                'request_meta' => $row['request_meta_json'] ?? [],
                'response_meta' => $row['response_meta_json'] ?? [],
            ]),
            'created_at' => $this->dateValue($model, 'created_at'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runtimeFileDetail(string $log): ?array
    {
        $entry = $this->adminLogService->findRuntimeFileLog($log);
        if ($entry === null) {
            return null;
        }

        $message = (string) ($entry['message'] ?? '');

        return [
            'id' => (string) ($entry['id'] ?? $log),
            'channel' => 'runtime',
            'source' => 'laravel_log',
            'fields' => [
                'id' => (string) ($entry['id'] ?? $log),
                'level' => (string) ($entry['level'] ?? ''),
                'time' => $entry['time'] ?? null,
            ],
            'message' => $message,
            'context' => [
                'raw' => (string) ($entry['raw'] ?? $message),
            ],
            'created_at' => $entry['time'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scheduleDetail(string $channel, string $log): ?array
    {
        $id = $this->stripLogPrefix($log, 'schedule-');
        $model = ScheduleRunLog::query()->find($id);
        if (! $model instanceof ScheduleRunLog) {
            return null;
        }

        $row = $this->normalizeRow($model);

        return [
            'id' => (string) $model->id,
            'channel' => $channel,
            'source' => 'schedule_run_logs',
            'fields' => [
                'id' => (int) $model->id,
                'task_name' => $row['task_name'] ?? '',
                'task_key' => $row['task_name'] ?? '',
                'status' => $row['status'] ?? '',
                'duration_ms' => $row['duration_ms'] ?? null,
                'started_at' => $this->dateValue($model, 'started_at'),
                'finished_at' => $this->dateValue($model, 'finished_at'),
                'created_at' => $this->dateValue($model, 'created_at'),
                'error_msg' => $row['error_msg'] ?? '',
            ],
            'message' => (string) ($row['error_msg'] ?? $row['task_name'] ?? ''),
            'context' => $this->dropSensitiveKeys(['summary' => $row['summary'] ?? []]),
            'created_at' => $this->dateValue($model, 'created_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRow(mixed $row): array
    {
        if ($row instanceof Model) {
            return $row->toArray();
        }

        return is_array($row) ? $row : [];
    }

    private function dateValue(Model $model, string $key): ?string
    {
        $value = $model->getAttribute($key);

        return method_exists($value, 'format') ? $value->format('Y-m-d H:i:s') : ($value ? (string) $value : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function dropSensitiveKeys(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            $keyText = is_string($key) ? strtolower($key) : '';
            if ($keyText !== '') {
                $blocked = false;
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
                    if (str_contains($keyText, $needle)) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) {
                    continue;
                }
            }

            $result[$key] = is_array($value) ? $this->dropSensitiveKeys($value) : SensitiveDataSanitizer::sanitize($value, is_string($key) ? $key : null);
        }

        return $result;
    }

    private function stripLogPrefix(string $log, string $prefix): int|string
    {
        if (str_starts_with($log, $prefix)) {
            $stripped = substr($log, strlen($prefix));

            return is_numeric($stripped) ? (int) $stripped : $stripped;
        }

        return is_numeric($log) ? (int) $log : $log;
    }
}
