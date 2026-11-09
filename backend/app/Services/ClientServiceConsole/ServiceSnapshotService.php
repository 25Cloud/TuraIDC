<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\System\OperationLogService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 快照 / 备份子服务（控制台）。
 *
 * 上游把快照与备份做成同一类对象、只靠 `type` 区分，所以这里也只有一个列表入口，
 * 交给前端时按 `type` 拆成 `snapshots` / `backups` 两组，前端不用自己过滤。
 *
 * 三态与安全组面板一致：
 * - `supported=false` + `message`：当前上游/当前产品没有这个能力（不是错误，是没开通）；
 * - `error` 非空：真的读失败了，给用户一句可行动的提示；
 * - 两者都空：读成功，`snapshots`/`backups` 可能为空数组（真的没有快照）。
 */
class ServiceSnapshotService
{
    /** 快照名长度上限：上游没有明确约束，取一个明显超过面板输入的护栏值 */
    private const NAME_MAX_LENGTH = 64;

    public function __construct(
        private readonly OperationLogService $operationLogService,
        private readonly ServiceDetailService $detailService,
        private readonly ServiceTransformService $transformService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSnapshotsForUser(User $user, int $serviceId): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, ['product.supplier']);

        if (! $this->transformService->canManageService($service)) {
            return $this->degraded('当前服务暂不支持快照管理');
        }

        try {
            [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        } catch (Throwable $exception) {
            return $this->failed($service->id, $exception, '读取快照失败，请稍后重试');
        }

        if (! is_callable([$runtime, 'getSnapshots'])) {
            return $this->degraded('当前上游不提供快照能力');
        }

        try {
            $response = $runtime->getSnapshots($supplier, (int) $hostId, $jwt);
            $this->detailService->assertSuccess($response, '读取快照列表');
            $payload = $this->detailService->extractPayload($response);
            $rows = is_array($payload['list'] ?? null) ? $payload['list'] : [];
        } catch (Throwable $exception) {
            return $this->failed($service->id, $exception, '读取快照失败，请稍后重试');
        }

        $canDelete = is_callable([$runtime, 'deleteSnapshot']);
        $canRestore = is_callable([$runtime, 'restoreSnapshot']);
        $snapshots = [];
        $backups = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $item = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => trim((string) ($row['name'] ?? '')),
                'type' => (string) ($row['type'] ?? 'snap') === 'backup' ? 'backup' : 'snap',
                'disk_id' => (int) ($row['disk_id'] ?? 0),
                'disk_name' => trim((string) ($row['disk_name'] ?? '')),
                'size_gb' => round((float) ($row['size_gb'] ?? 0), 2),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'can_delete' => $canDelete && (int) ($row['id'] ?? 0) > 0,
                'can_restore' => $canRestore && (int) ($row['id'] ?? 0) > 0,
            ];

            if ($item['type'] === 'backup') {
                $backups[] = $item;

                continue;
            }

            $snapshots[] = $item;
        }

        return [
            'supported' => true,
            'message' => $snapshots === [] && $backups === [] ? '当前暂无快照' : '',
            'error' => '',
            'can_create' => is_callable([$runtime, 'createSnapshot']),
            'snapshots' => $snapshots,
            'backups' => $backups,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  `name`、`type`（snap|backup）、`disk_id`（可选）
     * @return array<string, mixed>
     */
    public function createSnapshotForUser(User $user, int $serviceId, array $data, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, ['product.supplier']);
        $name = trim((string) ($data['name'] ?? ''));
        $type = strtolower(trim((string) ($data['type'] ?? 'snap'))) ?: 'snap';
        $diskId = (int) ($data['disk_id'] ?? 0);

        throw_if($name === '', new BusinessException('请填写快照名称', 42200));
        throw_if(mb_strlen($name) > self::NAME_MAX_LENGTH, new BusinessException('快照名称不能超过 '.self::NAME_MAX_LENGTH.' 个字符', 42200));
        throw_if(! in_array($type, ['snap', 'backup'], true), new BusinessException('快照类型不正确', 42200));
        throw_if($diskId < 0, new BusinessException('磁盘 ID 不正确', 42200));

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        throw_if(! is_callable([$runtime, 'createSnapshot']), new BusinessException('当前上游不提供快照创建能力', 42200));

        $response = $runtime->createSnapshot($supplier, (int) $hostId, array_filter([
            'name' => $name,
            'type' => $type,
            'disk_id' => $diskId,
        ], static fn (mixed $value): bool => $value !== 0), $jwt);
        $this->detailService->assertSuccess($response, $type === 'backup' ? '创建备份' : '创建快照');

        $message = trim((string) ($response['msg'] ?? '')) ?: ($type === 'backup' ? '备份创建任务已提交' : '快照创建任务已提交');

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.snapshot.create', [
            'category' => 'snapshot',
            'summary' => ($type === 'backup' ? '创建备份 ' : '创建快照 ').$name,
            'host_id' => (int) $hostId,
            'name' => $name,
            'type' => $type,
            'disk_id' => $diskId,
            'message' => $message,
        ], $context);

        return ['message' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteSnapshotForUser(User $user, int $serviceId, int $snapshotId, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, ['product.supplier']);
        throw_if($snapshotId <= 0, new BusinessException('快照不存在', 42200));

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        throw_if(! is_callable([$runtime, 'deleteSnapshot']), new BusinessException('当前上游不提供快照删除能力', 42200));

        $response = $runtime->deleteSnapshot($supplier, (int) $hostId, $snapshotId, $jwt);
        $this->detailService->assertSuccess($response, '删除快照');

        $message = trim((string) ($response['msg'] ?? '')) ?: '删除任务已提交';

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.snapshot.delete', [
            'category' => 'snapshot',
            'summary' => '删除快照 #'.$snapshotId,
            'host_id' => (int) $hostId,
            'snapshot_id' => $snapshotId,
            'message' => $message,
        ], $context);

        return ['message' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreSnapshotForUser(User $user, int $serviceId, int $snapshotId, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, ['product.supplier']);
        throw_if($snapshotId <= 0, new BusinessException('快照不存在', 42200));

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        throw_if(! is_callable([$runtime, 'restoreSnapshot']), new BusinessException('当前上游不提供快照回滚能力', 42200));

        // 回滚是破坏性操作：上游只受理任务，这里在返回文案里把「数据会被覆盖」说清楚，
        // 前端不用再自己拼提示语，避免不同端说法不一致。
        $response = $runtime->restoreSnapshot($supplier, (int) $hostId, $snapshotId, $jwt);
        $this->detailService->assertSuccess($response, '回滚快照');

        $message = trim((string) ($response['msg'] ?? '')) ?: '回滚任务已提交，实例数据将被覆盖，请稍后刷新查看';

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.snapshot.restore', [
            'category' => 'snapshot',
            'summary' => '用快照 #'.$snapshotId.' 回滚实例',
            'host_id' => (int) $hostId,
            'snapshot_id' => $snapshotId,
            'message' => $message,
        ], $context);

        return ['message' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    private function degraded(string $message): array
    {
        return [
            'supported' => false,
            'message' => $message,
            'error' => '',
            'can_create' => false,
            'snapshots' => [],
            'backups' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(int $serviceId, Throwable $exception, string $fallback): array
    {
        Log::warning('[服务控制台] 读取快照失败', [
            'service_id' => $serviceId,
            'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
        ]);

        return [
            'supported' => true,
            'message' => '',
            'error' => $fallback,
            'can_create' => false,
            'snapshots' => [],
            'backups' => [],
        ];
    }
}
