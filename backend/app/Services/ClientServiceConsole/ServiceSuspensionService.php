<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\User;
use App\Jobs\PushServiceToZjmfDownstreamJob;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\ServiceUpstreamBindingWriter;
use App\Services\System\OperationLogService;
use App\Services\Upstream\Contracts\ProvidesHostSuspension;
use App\Services\Upstream\Contracts\ProvidesHostTermination;
use App\Services\ZjmfUpstream\ZjmfDownstreamPushService;
use App\Support\SensitiveDataSanitizer;

/**
 * 暂停/解除暂停子服务
 * 负责：suspendForUser、unsuspendForUser（用户端与管理端共用）
 *
 * 暂停/解除暂停是模块级功能，约定上游路径：
 *   - suspend   → PUT /v1/hosts/{hostId}/module/suspend
 *   - unsuspend → PUT /v1/hosts/{hostId}/module/unsuspend
 * 实现 ProvidesHostSuspension 的驱动优先走具名方法（suspendHost/unsuspendHost），
 * 未实现的通用 runtime 回退到上述 REST 路径。
 */
class ServiceSuspensionService
{
    public function __construct(
        private readonly OperationLogService $operationLogService,
        private readonly ServiceDetailService $detailService,
        private readonly ServiceTransformService $transformService,
        private ?PluginBindingResolver $bindingResolver = null,
        private ?ServiceUpstreamBindingWriter $bindingWriter = null,
    ) {}

    public function suspendForUser(User $user, int $serviceId, array $data = [], array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
            'order:id,order_no,status,paid_at,created_at',
        ]);

        throw_if(! $this->transformService->canManageService($service), new BusinessException('当前实例未接入可控的上游主机', 42200));
        throw_if((int) $service->status !== ServiceStatus::ACTIVE, new BusinessException('仅已开通的实例可以暂停', 42200));

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        $response = $runtime instanceof ProvidesHostSuspension
            ? $runtime->suspendHost($supplier, $hostId, $jwt)
            : $runtime->put($supplier, "/v1/hosts/{$hostId}/module/suspend", [], $jwt);
        $this->detailService->assertSuccess($response, '暂停实例');

        $reason = trim((string) ($data['reason'] ?? ''));
        $service->forceFill([
            'status' => ServiceStatus::SUSPENDED,
            'suspended_reason' => $reason !== '' ? $reason : null,
        ])->save();
        $service->refresh();

        // 旁路通知魔方财务下游（未登记回推目标时自动跳过）
        PushServiceToZjmfDownstreamJob::dispatch(
            (int) $service->id,
            ZjmfDownstreamPushService::TYPE_SUSPEND
        );

        $message = trim((string) ($response['msg'] ?? '')) ?: '实例已暂停';
        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.suspend', [
            'category' => 'suspend',
            'summary' => '暂停实例',
            'host_id' => $hostId,
            'reason' => $reason,
            'message' => $message,
        ], $context);

        return [
            'action' => 'suspend',
            'action_label' => '暂停',
            'message' => $message,
            'detail' => $this->transformService->transformDetail($service),
        ];
    }

    public function unsuspendForUser(User $user, int $serviceId, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
            'order:id,order_no,status,paid_at,created_at',
        ]);

        throw_if(! $this->transformService->canManageService($service), new BusinessException('当前实例未接入可控的上游主机', 42200));
        throw_if((int) $service->status !== ServiceStatus::SUSPENDED, new BusinessException('仅已暂停的实例可以解除暂停', 42200));

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        $response = $runtime instanceof ProvidesHostSuspension
            ? $runtime->unsuspendHost($supplier, $hostId, $jwt)
            : $runtime->put($supplier, "/v1/hosts/{$hostId}/module/unsuspend", [], $jwt);
        $this->detailService->assertSuccess($response, '解除暂停');

        $service->forceFill([
            'status' => ServiceStatus::ACTIVE,
            'suspended_reason' => null,
        ])->save();
        $service->refresh();

        // 旁路通知魔方财务下游（未登记回推目标时自动跳过）
        PushServiceToZjmfDownstreamJob::dispatch(
            (int) $service->id,
            ZjmfDownstreamPushService::TYPE_UNSUSPEND
        );

        $message = trim((string) ($response['msg'] ?? '')) ?: '实例已解除暂停';
        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.unsuspend', [
            'category' => 'suspend',
            'summary' => '解除暂停',
            'host_id' => $hostId,
            'message' => $message,
        ], $context);

        return [
            'action' => 'unsuspend',
            'action_label' => '解除暂停',
            'message' => $message,
            'detail' => $this->transformService->transformDetail($service),
        ];
    }

    /**
     * 销毁上游实例（到期自动终止等场景），对齐魔方下游 Host::terminate 协议。
     *
     * 语义：
     * - 服务未接入可控上游或驱动不支持终止 → true（无需销毁，放行本地取消）
     * - 上游已不存在（幂等）或销毁成功 → true
     * - 上游调用失败 → false（调用方保持现状，下一轮重试）；本方法绝不抛出
     */
    public function tryTerminateUpstream(Service $service, string $reason = ''): bool
    {
        try {
            if (! $this->transformService->canManageService($service)) {
                return true;
            }

            [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);

            if (! $runtime instanceof ProvidesHostTermination) {
                return true;
            }

            $response = $runtime->terminateHost($supplier, $hostId, $reason !== '' ? $reason : '到期自动终止', $jwt);

            logger()->info('[服务终止] 上游实例销毁完成', [
                'service_id' => (int) $service->id,
                'host_id' => $hostId,
                'already_terminated' => (bool) ($response['already_terminated'] ?? false),
            ]);

            return true;
        } catch (\Throwable $exception) {
            logger()->warning('[服务终止] 上游实例销毁失败', [
                'service_id' => (int) $service->id,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            return false;
        }
    }

    private function bindingResolver(): PluginBindingResolver
    {
        return $this->bindingResolver ??= app(PluginBindingResolver::class);
    }

    private function bindingWriter(): ServiceUpstreamBindingWriter
    {
        return $this->bindingWriter ??= app(ServiceUpstreamBindingWriter::class);
    }

    /**
     * 供续费履约后调用：上游续费成功时主动解除暂停（幂等，失败不影响续费结果）。
     *
     * @param  bool  $wasSuspended  续费前实例是否处于暂停/到期状态（续费收尾时本地状态已被置为 ACTIVE，需显式传入）
     */
    public function tryUnsuspendUpstream(Service $service, bool $wasSuspended = false): bool
    {
        try {
            if (! $wasSuspended && (int) $service->status !== ServiceStatus::SUSPENDED) {
                return false;
            }

            [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
            $response = $runtime instanceof ProvidesHostSuspension
                ? $runtime->unsuspendHost($supplier, $hostId, $jwt)
                : $runtime->put($supplier, "/v1/hosts/{$hostId}/module/unsuspend", [], $jwt);
            $this->detailService->assertSuccess($response, '解除暂停');

            if ((int) $service->status === ServiceStatus::SUSPENDED) {
                $service->forceFill([
                    'status' => ServiceStatus::ACTIVE,
                    'suspended_reason' => null,
                ])->save();
                $service->refresh();

                PushServiceToZjmfDownstreamJob::dispatch(
                    (int) $service->id,
                    ZjmfDownstreamPushService::TYPE_UNSUSPEND
                );
            }

            $this->operationLogService->writeServiceConsoleLog($service, 'service.console.unsuspend.after_renew', [
                'category' => 'suspend',
                'summary' => '续费后自动解除暂停',
                'host_id' => $hostId,
            ]);

            return true;
        } catch (\Throwable $exception) {
            // 续费主流程不应被解除暂停的失败阻断
            logger()->warning('[续费] 上游解除暂停失败（已忽略）', [
                'service_id' => $service->id,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            return false;
        }
    }
}
