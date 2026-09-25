<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\ServiceUpstreamBindingWriter;
use App\Services\System\OperationLogService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 电源/重装/密码子服务
 * 负责：powerActionForUser、getModuleStatusForUser、getReinstallOptionsForUser、
 *       resetPasswordForUser、reinstallForUser
 */
class ServicePowerService
{
    /**
     * 重装系统选项缓存时长（秒）。
     *
     * 上游 /host/header 返回的 cloud_os[].id 是随节点状态动态轮换的临时 ID，
     * 实测同一实例在数小时内会整体变化（如 19001650 → 19059152），
     * 因此选项缓存必须短于该轮换周期，否则用户提交的 os_id 已被上游作废，
     * 上游会返回 406「操作系统错误」。此处取 5 分钟。
     */
    private const REINSTALL_OPTIONS_CACHE_TTL_SECONDS = 300;

    /** 控制台动作（电源/重装）互斥锁：同一实例同时只允许一个动作在上游执行 */
    private const CONSOLE_ACTION_LOCK_TTL_SECONDS = 120;

    public function __construct(
        private readonly OperationLogService $operationLogService,
        private readonly ServiceDetailService $detailService,
        private readonly ServiceTransformService $transformService,
        private ?PluginBindingResolver $bindingResolver = null,
        private ?ServiceUpstreamBindingWriter $bindingWriter = null,
    ) {}

    /**
     * 控制台动作互斥锁（电源/重装共用同一把）。
     *
     * 电源与重装都会向上游下发改变实例状态的指令，快速连点或网络重试会造成
     * 重复下发（例如连发两次 reboot）。fail-fast 而非排队：动作本身就是用户
     * 主动行为，排在后面的重复动作没有意义。
     *
     * @template TLockResult
     *
     * @param  callable(): TLockResult  $callback
     * @return TLockResult
     */
    private function withConsoleActionLock(int $serviceId, callable $callback)
    {
        $lock = Cache::lock("lock:service:console-action:{$serviceId}", self::CONSOLE_ACTION_LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            throw new BusinessException('该实例的上一个控制台操作还在处理中，请稍后再试', 42200);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

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

        return $this->withConsoleActionLock($service->id, function () use ($service, $action, $context): array {
            [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
            // 契约分层：实现具名 powerAction 的驱动走具名协议，通用 REST runtime
            // 回退 PUT /v1/hosts/{hostId}/module/{action}。
            $response = is_callable([$runtime, 'powerAction'])
                ? $runtime->powerAction($supplier, $hostId, $action, $jwt)
                : $runtime->put($supplier, "/v1/hosts/{$hostId}/module/{$action}", [], $jwt);
            $this->detailService->assertSuccess($response, ClientServiceConsoleService::POWER_ACTIONS[$action]);

            $refreshError = '';

            try {
                $this->applyPendingPowerSnapshot($service, $action);
            } catch (\Throwable $exception) {
                // 上游已受理，本地快照失败不影响操作结果，但必须留下系统级痕迹
                $refreshError = SensitiveDataSanitizer::sanitizeText($exception->getMessage());
                Log::warning('[控制台] 电源指令已提交但本地状态快照写入失败', [
                    'service_id' => $service->id,
                    'action' => $action,
                    'message' => $refreshError,
                    'exception' => $exception::class,
                ]);
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
        });
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

        return $this->withConsoleActionLock($service->id, function () use ($service, $data, $context): array {
            [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
            $osId = (string) ($data['os_id'] ?? '');

            // 契约分层：实现具名 reinstall 的驱动走具名协议，通用 REST runtime
            // 回退 PUT /v1/hosts/{hostId}/module/reinstall。
            $submit = fn (string $submitOsId): array => is_callable([$runtime, 'reinstall'])
                ? $runtime->reinstall($supplier, $hostId, $submitOsId, $jwt)
                : $runtime->put($supplier, "/v1/hosts/{$hostId}/module/reinstall", ['os_id' => $submitOsId], $jwt);

            $response = $submit($osId);

            // 上游 cloud_os[].id 会随节点状态轮换，客户端持有的 os_id 可能已作废，
            // 上游据此返回「操作系统错误」。此时强制刷新选项、按系统名把请求重映射到
            // 当前有效的 os_id 后重试一次，避免用户看到无法自愈的失败。
            if ($this->isStaleReinstallOsResponse($response)) {
                $remapped = $this->remapStaleReinstallOsId($supplier, $hostId, $runtime, $jwt, $osId);
                if ($remapped !== null && $remapped !== $osId) {
                    $response = $submit($remapped);
                    $osId = $remapped;
                }
            }

            $this->detailService->assertSuccess($response, '重装系统');

            $payload = ['os_id' => $osId];

            $taskStatus = null;
            try {
                $taskStatus = $this->detailService->normalizeModuleStatus(
                    $this->detailService->fetchModuleStatusPayload($supplier, $hostId, $jwt, 'reinstall'),
                    'reinstall'
                );
            } catch (\Throwable $statusException) {
                // 重装指令上游已受理，补充状态读取失败不影响提交结果，但留下系统级痕迹
                $taskStatus = null;
                Log::warning('[控制台] 重装指令已提交但任务状态读取失败', [
                    'service_id' => $service->id,
                    'host_id' => $hostId,
                    'message' => SensitiveDataSanitizer::sanitizeText($statusException->getMessage()),
                    'exception' => $statusException::class,
                ]);
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
        });
    }

    /**
     * 当重装提交因 os_id 失效（上游「操作系统错误」类）失败时，刷新重装选项并把
     * 请求中的 os_id 重映射到当前列表里的有效 ID（按系统名匹配）。返回 null 表示
     * 不满足重试条件或无法映射。
     */
    private function remapStaleReinstallOsId(
        Supplier $supplier,
        int $hostId,
        object $runtime,
        ?string $jwt,
        string $staleOsId,
    ): ?string {
        if ($staleOsId === '') {
            return null;
        }

        $cacheKey = $this->detailService->buildReinstallOptionsCacheKey($supplier, $hostId);
        $stale = Cache::get($cacheKey);
        $staleOs = collect(is_array($stale) ? ($stale['os'] ?? []) : [])
            ->firstWhere('os_id', $staleOsId);

        try {
            // 强制绕过缓存重取选项：os_id 轮换后旧缓存已不可用。
            $response = is_callable([$runtime, 'getReinstallOptions'])
                ? $runtime->getReinstallOptions($supplier, $hostId, $jwt)
                : $runtime->get($supplier, "/v1/hosts/{$hostId}/module/reinstall", $jwt);
            $this->detailService->assertSuccess($response, '读取重装系统');
            $payload = $this->detailService->extractPayload($response);
        } catch (\Throwable $refreshException) {
            Log::warning('[控制台] 重装 os_id 失效后刷新系统列表失败', [
                'host_id' => $hostId,
                'os_id' => $staleOsId,
                'message' => SensitiveDataSanitizer::sanitizeText($refreshException->getMessage()),
                'exception' => $refreshException::class,
            ]);

            return null;
        }

        $freshOs = collect($payload['os'] ?? [])->filter(fn ($item) => is_array($item))->values();
        if ($freshOs->isEmpty()) {
            return null;
        }

        // 旧缓存中的 os_id 已失效，清掉以免后续请求继续命中。
        Cache::forget($cacheKey);

        if (is_array($staleOs) && trim((string) ($staleOs['name'] ?? '')) !== '') {
            $matched = $freshOs->firstWhere('name', (string) $staleOs['name']);
            if (is_array($matched) && trim((string) ($matched['os_id'] ?? '')) !== '') {
                return (string) $matched['os_id'];
            }
        }

        return null;
    }

    /**
     * 判定上游重装响应是否属于「客户端 os_id 已作废」这一类可重试错误。
     */
    private function isStaleReinstallOsResponse(array $response): bool
    {
        $status = (int) ($response['status'] ?? $response['code'] ?? 0);
        if (in_array($status, [200, 1001], true)) {
            return false;
        }

        $message = trim((string) ($response['msg'] ?? $response['message'] ?? ''));

        return $message !== '' && (
            str_contains($message, '操作系统错误')
            || str_contains($message, '系统错误')
        );
    }

    public function rescueForUser(User $user, int $serviceId, array $data, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
            'order:id,order_no,status,paid_at,created_at',
        ]);
        throw_if(! $this->transformService->canExecuteConsoleActions($service), new BusinessException('当前实例状态不支持该操作', 42200));

        $system = (string) ($data['system'] ?? '1');
        throw_if(! in_array($system, ['1', '2'], true), new BusinessException('不支持的救援系统', 42200));

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        $payload = ['system' => $system];
        $response = is_callable([$runtime, 'rescue'])
            ? $runtime->rescue($supplier, $hostId, $system, $jwt)
            : $runtime->put($supplier, "/v1/hosts/{$hostId}/module/rescue", $payload, $jwt);
        $this->detailService->assertSuccess($response, '进入救援模式');

        $message = trim((string) ($response['msg'] ?? '')) ?: '救援模式指令已提交';
        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.rescue.submit', [
            'category' => 'reinstall',
            'summary' => '提交进入救援模式请求',
            'host_id' => $hostId,
            'system' => $system === '2' ? 'windows' : 'linux',
            'system_label' => $system === '2' ? 'Windows' : 'Linux',
            'message' => $message,
            'second_verify_required' => $this->detailService->extractSecondVerify($response) !== [],
        ], $context);

        return [
            'message' => $message,
            'second_verify' => $this->detailService->extractSecondVerify($response),
            'detail' => $this->transformService->transformDetail($service),
        ];
    }
}
