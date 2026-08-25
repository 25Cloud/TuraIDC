<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\User;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\ServiceUpstreamBindingWriter;
use App\Services\System\OperationLogService;
use App\Support\SensitiveDataSanitizer;

/**
 * 暂停/解除暂停子服务
 * 负责：suspendForUser、unsuspendForUser（用户端与管理端共用）
 *
 * 暂停/解除暂停是模块级功能，约定上游路径：
 *   - suspend   → PUT /v1/hosts/{hostId}/module/suspend
 *   - unsuspend → PUT /v1/hosts/{hostId}/module/unsuspend
 * 若驱动实现了 suspendHost()/unsuspendHost() 具名方法则优先调用。
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
        $response = is_callable([$runtime, 'suspendHost'])
            ? $runtime->suspendHost($supplier, $hostId, $jwt)
            : $runtime->put($supplier, "/v1/hosts/{$hostId}/module/suspend", [], $jwt);
        $this->detailService->assertSuccess($response, '暂停实例');

        $reason = trim((string) ($data['reason'] ?? ''));
        $service->forceFill([
            'status' => ServiceStatus::SUSPENDED,
            'suspended_reason' => $reason !== '' ? $reason : null,
        ])->save();
        $service->refresh();

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
        $response = is_callable([$runtime, 'unsuspendHost'])
            ? $runtime->unsuspendHost($supplier, $hostId, $jwt)
            : $runtime->put($supplier, "/v1/hosts/{$hostId}/module/unsuspend", [], $jwt);
        $this->detailService->assertSuccess($response, '解除暂停');

        $service->forceFill([
            'status' => ServiceStatus::ACTIVE,
            'suspended_reason' => null,
        ])->save();
        $service->refresh();

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
            $response = is_callable([$runtime, 'unsuspendHost'])
                ? $runtime->unsuspendHost($supplier, $hostId, $jwt)
                : $runtime->put($supplier, "/v1/hosts/{$hostId}/module/unsuspend", [], $jwt);
            $this->detailService->assertSuccess($response, '解除暂停');

            if ((int) $service->status === ServiceStatus::SUSPENDED) {
                $service->forceFill([
                    'status' => ServiceStatus::ACTIVE,
                    'suspended_reason' => null,
                ])->save();
                $service->refresh();
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
