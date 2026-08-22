<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\User;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\ServiceUpstreamBindingWriter;
use App\Services\System\OperationLogService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Cache;

/**
 * 电源/重装/密码子服务
 * 负责：powerActionForUser、getModuleStatusForUser、getReinstallOptionsForUser、
 *       resetPasswordForUser、reinstallForUser
 */
class ServicePowerService
{
    private const REINSTALL_OPTIONS_CACHE_TTL_SECONDS = 604800;

    public function __construct(
        private readonly OperationLogService $operationLogService,
        private readonly ServiceDetailService $detailService,
        private readonly ServiceTransformService $transformService,
        private ?PluginBindingResolver $bindingResolver = null,
        private ?ServiceUpstreamBindingWriter $bindingWriter = null,
    ) {}

    public function powerActionForUser(User $user, int $serviceId, string $action, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
            'order:id,order_no,status,paid_at,created_at',
        ]);

        $action = trim($action);
        throw_if(! isset(ClientServiceConsoleService::POWER_ACTIONS[$action]), new BusinessException('不支持的电源动作', 42200));
        throw_if(! $this->transformService->canExecuteConsoleActions($service), new BusinessException('当前实例状态不支持该操作', 42200));

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        $response = is_callable([$runtime, 'powerAction'])
            ? $runtime->powerAction($supplier, $hostId, $action, $jwt)
            : $runtime->put($supplier, "/v1/hosts/{$hostId}/module/{$action}", [], $jwt);
        $this->detailService->assertSuccess($response, ClientServiceConsoleService::POWER_ACTIONS[$action]);

        $refreshError = '';

        try {
            $this->applyPendingPowerSnapshot($service, $action);
        } catch (\Throwable $exception) {
            $refreshError = SensitiveDataSanitizer::sanitizeText($exception->getMessage());
        }

        $actionLabel = ClientServiceConsoleService::POWER_ACTIONS[$action];
        $message = trim((string) ($response['msg'] ?? '')) ?: ($actionLabel.'指令已发送');
        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.power.'.$action, [
            'category' => 'power',
            'summary' => '提交'.$actionLabel.'指令',
            'operation' => $action,
            'operation_label' => $actionLabel,
            'host_id' => $hostId,
            'message' => $message,
            'refresh_error' => $refreshError,
        ], $context);

        return [
            'action' => $action,
            'action_label' => $actionLabel,
            'message' => $message,
            'detail' => $this->transformService->transformDetail($service),
        ];
    }

    private function applyPendingPowerSnapshot(Service $service, string $action): void
    {
        $snapshot = $this->resolvePendingPowerSnapshot($action);
        if ($snapshot === []) {
            return;
        }

        $provisionData = $this->serviceProvisionData($service);
        $provisionData['runtime_status'] = (string) ($snapshot['runtime_status'] ?? '');
        $provisionData['runtime_description'] = (string) ($snapshot['runtime_description'] ?? '');
        $provisionData['last_power_action'] = $action;
        $provisionData['last_power_action_requested_at'] = now()->format('Y-m-d H:i:s');

        $service->forceFill([
            'provision_data' => $provisionData,
        ])->save();

        $service->refresh()->loadMissing('product.supplier');
        $this->bindingWriter()->syncServiceState($service, $service->product, $provisionData);
    }

    private function serviceProvisionData(Service $service): array
    {
        $legacy = is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $projection = $this->bindingResolver()->serviceProvisionProjection($service);
        $provisionData = $projection === [] ? $legacy : array_replace($legacy, $projection);

        return $this->bindingResolver()->sanitizeServiceProvisionData($provisionData);
    }

    private function bindingResolver(): PluginBindingResolver
    {
        return $this->bindingResolver ??= app(PluginBindingResolver::class);
    }

    private function bindingWriter(): ServiceUpstreamBindingWriter
    {
        return $this->bindingWriter ??= app(ServiceUpstreamBindingWriter::class);
    }

    private function resolvePendingPowerSnapshot(string $action): array
    {
        return match ($action) {
            'on' => [
                'runtime_status' => 'starting',
                'runtime_description' => '开机中',
            ],
            'off', 'hard_off' => [
                'runtime_status' => 'stopping',
                'runtime_description' => '关机中',
            ],
            'reboot', 'hard_reboot' => [
                'runtime_status' => 'rebooting',
                'runtime_description' => '重启中',
            ],
            default => [],
        };
    }

    public function getModuleStatusForUser(User $user, int $serviceId, string $type = 'host'): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);

        $type = $this->detailService->normalizeModuleStatusType($type);

        if ($type === 'repassword') {
            throw_if(! $this->transformService->canManageService($service), new BusinessException('当前服务未接入可控的上游主机', 42200));

            return $this->detailService->buildPasswordResetPendingStatus();
        }

        [, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        $payload = $this->detailService->fetchModuleStatusPayload($supplier, $hostId, $jwt, $type);

        if ($type === 'host' && $payload !== []) {
            $this->detailService->syncServiceFromRemote($service, [], $payload);
        }

        return $this->detailService->normalizeModuleStatus($payload, $type);
    }

    public function getReinstallOptionsForUser(User $user, int $serviceId, bool $forceRefresh = false): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        $cacheKey = $this->detailService->buildReinstallOptionsCacheKey($supplier, $hostId);

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
        }

        $response = is_callable([$runtime, 'getReinstallOptions'])
            ? $runtime->getReinstallOptions($supplier, $hostId, $jwt)
            : $runtime->get($supplier, "/v1/hosts/{$hostId}/module/reinstall", $jwt);
        $this->detailService->assertSuccess($response, '读取重装系统');

        $payload = $this->detailService->extractPayload($response);
        $osList = collect($payload['os'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => [
                'os_id' => (string) ($item['os_id'] ?? ''),
                'name' => trim((string) ($item['name'] ?? '')),
                'group_name' => trim((string) ($item['group_name'] ?? '')) ?: '默认分组',
            ])
            ->filter(fn (array $item) => $item['os_id'] !== '' && $item['name'] !== '')
            ->values()->all();

        $groups = collect($payload['os_group'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => [
                'group_name' => trim((string) ($item['group_name'] ?? '')) ?: '默认分组',
                'img' => trim((string) ($item['img'] ?? '')),
            ])
            ->values()->all();

        $result = ['os' => $osList, 'os_groups' => $groups];

        if ($osList !== []) {
            Cache::put($cacheKey, $result, now()->addSeconds(self::REINSTALL_OPTIONS_CACHE_TTL_SECONDS));
        }

        return $result;
    }

    public function resetPasswordForUser(User $user, int $serviceId, array $data, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
            'order:id,order_no,status,paid_at,created_at',
        ]);
        throw_if(! $this->transformService->canResetPassword($service), new BusinessException('当前实例状态不支持该操作', 42200));

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        $password = (string) ($data['password'] ?? '');
        $response = is_callable([$runtime, 'resetPassword'])
            ? $runtime->resetPassword($supplier, $hostId, $password, $jwt)
            : $runtime->put($supplier, "/v1/hosts/{$hostId}/module/repassword", [
                'password' => $password,
            ], $jwt);
        $this->detailService->assertSuccess($response, '重置密码');
        $secondVerify = $this->detailService->extractSecondVerify($response);

        if ($secondVerify === [] && $password !== '') {
            $this->transformService->cacheSubmittedPasswordForService($service, $password);
            $service->refresh()->loadMissing([
                'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
                'product.productGroup.secondProductGroup.firstProductGroup',
                'product.supplier',
                'order:id,order_no,status,paid_at,created_at',
            ]);
        }

        $taskStatus = $this->detailService->buildPasswordResetPendingStatus();
        $message = trim((string) ($response['msg'] ?? '')) ?: '重置密码指令已提交';
        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.password.reset', [
            'category' => 'password',
            'summary' => '提交密码重置请求',
            'host_id' => $hostId,
            'message' => $message,
            'second_verify_required' => $secondVerify !== [],
        ], $context);

        return [
            'message' => $message,
            'second_verify' => $secondVerify,
            'status' => $taskStatus,
            'detail' => $this->transformService->transformDetail($service),
        ];
    }

    public function reinstallForUser(User $user, int $serviceId, array $data, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
            'order:id,order_no,status,paid_at,created_at',
        ]);
        throw_if(! $this->transformService->canExecuteConsoleActions($service), new BusinessException('当前实例状态不支持该操作', 42200));

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        $payload = ['os_id' => (string) ($data['os_id'] ?? '')];
        $response = is_callable([$runtime, 'reinstall'])
            ? $runtime->reinstall($supplier, $hostId, (string) $payload['os_id'], $jwt)
            : $runtime->put($supplier, "/v1/hosts/{$hostId}/module/reinstall", $payload, $jwt);
        $this->detailService->assertSuccess($response, '重装系统');

        $taskStatus = null;
        try {
            $taskStatus = $this->detailService->normalizeModuleStatus(
                $this->detailService->fetchModuleStatusPayload($supplier, $hostId, $jwt, 'reinstall'),
                'reinstall'
            );
        } catch (\Throwable) {
            $taskStatus = null;
        }

        $message = trim((string) ($response['msg'] ?? '')) ?: '重装系统任务已提交';
        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.reinstall.submit', [
            'category' => 'reinstall',
            'summary' => '提交重装系统任务',
            'host_id' => $hostId,
            'os_id' => (string) ($payload['os_id'] ?? ''),
            'message' => $message,
            'second_verify_required' => $this->detailService->extractSecondVerify($response) !== [],
        ], $context);

        return [
            'message' => $message,
            'second_verify' => $this->detailService->extractSecondVerify($response),
            'status' => $taskStatus,
            'detail' => $this->transformService->transformDetail($service),
        ];
    }
}
