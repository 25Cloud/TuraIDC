<?php

namespace App\Services\System;

use App\Models\ActivityLog;
use App\Models\OperationLog;
use App\Models\Service;
use Illuminate\Support\Facades\Log;

class OperationLogService
{
    public function write(
        ?int $userId,
        string $userType,
        string $action,
        string $module,
        ?int $targetId = null,
        array $detail = [],
        ?string $ipAddress = null,
    ): void {
        // 写 operation_logs（当前主源，保留至 activity_logs 完成验收后停用）
        OperationLog::query()->create([
            'user_id' => $userId,
            'user_type' => $userType,
            'action' => $action,
            'module' => $module,
            'target_id' => $targetId,
            'detail' => $detail,
            'ip_address' => $ipAddress,
        ]);

        // L4 过渡期双写：同步写入 activity_logs（新日志真源）
        // 待 activity_logs 数据经过一个完整账期验收后，移除上方 OperationLog 写入
        $this->writeActivityLog(
            userId: $userId,
            userType: $userType,
            action: $action,
            module: $module,
            targetId: $targetId,
            detail: $detail,
            ipAddress: $ipAddress,
        );
    }

    public function writeServiceConsoleLog(
        Service $service,
        string $action,
        array $detail = [],
        array $context = [],
    ): void {
        $service->loadMissing([
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'order:id,order_no',
        ]);

        $actorType = trim((string) ($context['actor_type'] ?? 'client')) ?: 'client';
        $actorUserId = isset($context['actor_user_id']) ? (int) ($context['actor_user_id'] ?? 0) : 0;

        if ($actorUserId <= 0 && $actorType === 'client') {
            $actorUserId = (int) $service->user_id;
        }

        $payload = $this->filterDetail(array_merge([
            'service_id' => (int) $service->id,
            'service_name' => trim((string) $service->name),
            'product_name' => trim((string) ($service->product?->name ?? '')),
            'order_no' => trim((string) ($service->order?->order_no ?? '')),
            'actor_type' => $actorType,
            'actor_name' => trim((string) ($context['actor_name'] ?? '')),
            'trace_id' => trim((string) ($context['trace_id'] ?? '')),
        ], $detail));

        $ipAddress = trim((string) ($context['ip_address'] ?? ''));

        $this->write(
            userId: $actorUserId > 0 ? $actorUserId : null,
            userType: $actorType,
            action: $action,
            module: 'service',
            targetId: (int) $service->id,
            detail: $payload,
            ipAddress: $ipAddress !== '' ? $ipAddress : null,
        );
    }

    /**
     * L4 过渡期辅助写入 activity_logs。
     * 将 operation_logs 语义映射到 activity_logs 字段，满足其 NOT NULL 约束。
     * 写入失败时静默记录错误，不阻断主流程。
     */
    private function writeActivityLog(
        ?int $userId,
        string $userType,
        string $action,
        string $module,
        ?int $targetId,
        array $detail,
        ?string $ipAddress,
    ): void {
        try {
            $actorName = trim((string) ($detail['actor_name'] ?? ''));
            if ($actorName === '') {
                $actorName = match ($userType) {
                    'admin' => 'admin',
                    'client' => 'client',
                    default => 'system',
                };
            }

            $description = $action;
            if ($module !== '' && $module !== $action) {
                $description = "[{$module}] {$action}";
            }
            if ($targetId !== null) {
                $description .= " #{$targetId}";
            }

            ActivityLog::query()->create([
                'actor_type' => $userType,
                'actor_id' => $userId,
                'actor_name' => $actorName,
                'module' => $module,
                'action' => $action,
                'description' => $description,
                'subject_type' => $module ?: null,
                'subject_id' => $targetId,
                'context' => $detail !== [] ? $detail : null,
                'ip_address' => $ipAddress,
            ]);
        } catch (\Throwable $e) {
            // 双写失败不阻断主流程；待 activity_logs 稳定后改为强制抛出
            Log::warning(
                'activity_log double-write failed',
                ['error' => $e->getMessage(), 'action' => $action, 'module' => $module]
            );
        }
    }

    private function filterDetail(array $detail): array
    {
        return collect($detail)
            ->reject(function ($value) {
                if (is_bool($value)) {
                    return false;
                }

                if (is_array($value)) {
                    return $value === [];
                }

                return $value === null || (is_string($value) && trim($value) === '');
            })
            ->all();
    }
}
