<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ClientServiceConsole\Concerns\HandlesClientServiceConsoleMonitoring;

/**
 * 客户端服务控制台主服务（门面/协调器）
 *
 * 本类保留全部公开接口以兼容控制器，所有业务实现均委托到对应子服务：
 *   - ServiceOverviewService   — 列表、分组概览、汇总
 *   - ServiceDetailService     — 详情、同步、操作日志、备注
 *   - ServiceTransformService  — 数据转换、标签解析
 *   - ServicePowerService      — 电源、重装、密码
 *   - ServiceVncService        — VNC
 *   - ServiceNatService        — NAT 端口转发
 *   - ServiceSecurityGroupService — 安全组
 *
 * Monitoring 功能通过 HandlesClientServiceConsoleMonitoring Trait 实现，
 * 该 Trait 通过 $this->xxx() 调用本类代理方法，代理方法转发至 ServiceDetailService。
 */
class ClientServiceConsoleService
{
    use HandlesClientServiceConsoleMonitoring;

    // ── 监控 Trait 所需常量（Trait 中通过 self:: 引用）───────────────────
    private const MONITOR_RESPONSE_CACHE_TTL_SECONDS = 600;

    // ── 子服务引用的公开常量（保持兼容）──────────────────────────────────
    public const POWER_ACTIONS = [
        'on' => '开机',
        'off' => '关机',
        'reboot' => '重启',
        'hard_off' => '强制关机',
        'hard_reboot' => '强制重启',
    ];

    public const MODULE_STATUS_TYPES = [
        'host' => '电源状态',
        'reinstall' => '任务状态',
        'repassword' => '密码重置状态',
    ];

    public function __construct(
        private readonly ServiceOverviewService $overviewService,
        private readonly ServiceDetailService $detailService,
        private readonly ServiceTransformService $transformService,
        private readonly ServicePowerService $powerService,
        private readonly ServiceSuspensionService $suspensionService,
        private readonly ServiceVncService $vncService,
        private readonly ServiceNatService $natService,
        private readonly ServiceSecurityGroupService $securityGroupService,
    ) {}

    // ══════════════════════════════════════════════════════════════════════
    // 概览 / 列表
    // ══════════════════════════════════════════════════════════════════════

    public function paginateForUser(User $user, array $filters = []): array
    {
        return $this->overviewService->paginateForUser($user, $filters);
    }

    public function groupedOverviewForUser(User $user): array
    {
        return $this->overviewService->groupedOverviewForUser($user);
    }

    public function summaryForUser(User $user): array
    {
        return $this->overviewService->summaryForUser($user);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 详情 / 配置 / 备注 / 操作日志
    // ══════════════════════════════════════════════════════════════════════

    public function getServiceConfigForUser(User $user, int $serviceId): array
    {
        return $this->detailService->getServiceConfigForUser($user, $serviceId);
    }

    public function getDetailForUser(User $user, int $serviceId, bool $refreshRemote = false, bool $includeSensitiveConnection = true): array
    {
        return $this->sanitizeConnectionDetail(
            $this->detailService->getDetailForUser($user, $serviceId, $refreshRemote),
            $includeSensitiveConnection
        );
    }

    public function getBaseDetailForUser(User $user, int $serviceId, bool $includeSensitiveConnection = true): array
    {
        return $this->sanitizeConnectionDetail(
            $this->detailService->getBaseDetailForUser($user, $serviceId),
            $includeSensitiveConnection
        );
    }

    public function getRemoteStatusPatchForUser(User $user, int $serviceId, bool $includeSensitiveConnection = true, bool $forceRefresh = false): array
    {
        return $this->sanitizeConnectionDetail(
            $this->detailService->getRemoteStatusPatchForUser($user, $serviceId, $forceRefresh),
            $includeSensitiveConnection
        );
    }

    public function sanitizeClientDetail(array $detail): array
    {
        return $this->transformService->sanitizeClientDetail($detail);
    }

    public function getOperationLogsForUser(User $user, int $serviceId, array $filters = [], int $perPage = 10): array
    {
        return $this->detailService->getOperationLogsForUser($user, $serviceId, $filters, $perPage);
    }

    public function updateRemarkForUser(User $user, int $serviceId, ?string $remark, array $context = []): array
    {
        return $this->detailService->updateRemarkForUser($user, $serviceId, $remark, $context);
    }

    public function updateServiceNameForUser(User $user, int $serviceId, ?string $serviceName, array $context = []): array
    {
        return $this->detailService->updateServiceNameForUser($user, $serviceId, $serviceName, $context);
    }

    public function getTrafficPackagePreviewForUser(User $user, int $serviceId): array
    {
        return app(ServiceTrafficPackageService::class)->previewForUser($user, $serviceId);
    }

    public function quoteTrafficPackageForUser(User $user, int $serviceId, array $data): array
    {
        return app(ServiceTrafficPackageService::class)->quoteForUser($user, $serviceId, $data);
    }

    public function createTrafficPackageInvoiceForUser(User $user, int $serviceId, array $data, array $context = []): Invoice
    {
        return app(ServiceTrafficPackageService::class)->createInvoiceForUser($user, $serviceId, $data, $context);
    }

    public function getHostUpgradePreviewForUser(User $user, int $serviceId): array
    {
        return app(ServiceUpgradeService::class)->previewForUser($user, $serviceId);
    }

    public function quoteHostUpgradeForUser(User $user, int $serviceId, array $data): array
    {
        return app(ServiceUpgradeService::class)->quoteForUser($user, $serviceId, $data);
    }

    public function createHostUpgradeInvoiceForUser(User $user, int $serviceId, array $data, array $context = []): Invoice
    {
        return app(ServiceUpgradeService::class)->createInvoiceForUser($user, $serviceId, $data, $context);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 电源 / 重装 / 密码
    // ══════════════════════════════════════════════════════════════════════

    public function powerActionForUser(User $user, int $serviceId, string $action, array $context = []): array
    {
        return $this->powerService->powerActionForUser($user, $serviceId, $action, $context);
    }

    public function getModuleStatusForUser(User $user, int $serviceId, string $type = 'host'): array
    {
        return $this->powerService->getModuleStatusForUser($user, $serviceId, $type);
    }

    public function getReinstallOptionsForUser(User $user, int $serviceId, bool $forceRefresh = false): array
    {
        return $this->powerService->getReinstallOptionsForUser($user, $serviceId, $forceRefresh);
    }

    public function resetPasswordForUser(User $user, int $serviceId, array $data, array $context = []): array
    {
        return $this->powerService->resetPasswordForUser($user, $serviceId, $data, $context);
    }

    public function reinstallForUser(User $user, int $serviceId, array $data, array $context = []): array
    {
        return $this->powerService->reinstallForUser($user, $serviceId, $data, $context);
    }

    public function rescueForUser(User $user, int $serviceId, array $data, array $context = []): array
    {
        return $this->powerService->rescueForUser($user, $serviceId, $data, $context);
    }

    public function suspendForUser(User $user, int $serviceId, array $data = [], array $context = []): array
    {
        return $this->suspensionService->suspendForUser($user, $serviceId, $data, $context);
    }

    public function unsuspendForUser(User $user, int $serviceId, array $context = []): array
    {
        return $this->suspensionService->unsuspendForUser($user, $serviceId, $context);
    }

    // ══════════════════════════════════════════════════════════════════════
    // VNC
    // ══════════════════════════════════════════════════════════════════════

    public function getVncUrlForUser(User $user, int $serviceId, array $context = []): array
    {
        return $this->vncService->getVncUrlForUser($user, $serviceId, $context);
    }

    public function previewVncToken(string $token): array
    {
        return $this->vncService->previewVncToken($token);
    }

    public function resolveVncToken(string $token): array
    {
        return $this->vncService->resolveVncToken($token);
    }

    public function resolvePublicVncTokenPayload(string $token): array
    {
        return $this->vncService->resolvePublicVncTokenPayload($token);
    }

    // ══════════════════════════════════════════════════════════════════════
    // NAT 端口转发
    // ══════════════════════════════════════════════════════════════════════

    public function getNatForwardingsForUser(User $user, int $serviceId): array
    {
        return $this->natService->getNatForwardingsForUser($user, $serviceId);
    }

    public function createNatForwardingForUser(User $user, int $serviceId, array $data, array $context = []): array
    {
        return $this->natService->createNatForwardingForUser($user, $serviceId, $data, $context);
    }

    public function deleteNatForwardingForUser(User $user, int $serviceId, int $forwardingId, array $context = []): array
    {
        return $this->natService->deleteNatForwardingForUser($user, $serviceId, $forwardingId, $context);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 安全组
    // ══════════════════════════════════════════════════════════════════════

    public function getSecurityGroupsForUser(User $user, int $serviceId, bool $fresh = false): array
    {
        return $this->securityGroupService->getSecurityGroupsForUser($user, $serviceId, $fresh);
    }

    public function getSecurityGroupRulesForUser(User $user, int $serviceId, int $groupId): array
    {
        return $this->securityGroupService->getSecurityGroupRulesForUser($user, $serviceId, $groupId);
    }

    public function createSecurityGroupForUser(User $user, int $serviceId, array $data, array $context = []): array
    {
        return $this->securityGroupService->createSecurityGroupForUser($user, $serviceId, $data, $context);
    }

    public function applySecurityGroupForUser(User $user, int $serviceId, int $groupId, array $context = []): array
    {
        return $this->securityGroupService->applySecurityGroupForUser($user, $serviceId, $groupId, $context);
    }

    public function deleteSecurityGroupForUser(User $user, int $serviceId, int $groupId, array $context = []): array
    {
        return $this->securityGroupService->deleteSecurityGroupForUser($user, $serviceId, $groupId, $context);
    }

    public function createSecurityRuleForUser(User $user, int $serviceId, int $groupId, array $data, array $context = []): array
    {
        return $this->securityGroupService->createSecurityRuleForUser($user, $serviceId, $groupId, $data, $context);
    }

    public function deleteSecurityRuleForUser(User $user, int $serviceId, int $groupId, int $ruleId, array $context = []): array
    {
        return $this->securityGroupService->deleteSecurityRuleForUser($user, $serviceId, $groupId, $ruleId, $context);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 监控 Trait 代理方法
    // HandlesClientServiceConsoleMonitoring 通过 $this-> 调用以下方法
    // ══════════════════════════════════════════════════════════════════════

    /** @internal 供监控 Trait 调用 */
    private function findUserService(User $user, int $serviceId, array $relations = []): Service
    {
        return $this->detailService->findUserService($user, $serviceId, $relations);
    }

    /** @internal 供监控 Trait 调用 */
    private function canManageService(Service $service): bool
    {
        return $this->transformService->canManageService($service);
    }

    /** @internal 供监控 Trait 调用 */
    private function resolveManagedSupplierAndHost(Service $service): array
    {
        return $this->detailService->resolveManagedSupplierAndHost($service);
    }

    /** @internal 供监控 Trait 调用 */
    private function resolveUpstreamContext(Service $service): array
    {
        return $this->detailService->resolveUpstreamContext($service);
    }

    /** @internal 供监控 Trait 调用 */
    private function fetchSupportedModules(Supplier $supplier, int $hostId, string $jwt): array
    {
        return $this->detailService->fetchSupportedModules($supplier, $hostId, $jwt);
    }

    /** @internal 供监控 Trait 调用 */
    private function assertSuccess(array $response, string $action): void
    {
        $this->detailService->assertSuccess($response, $action);
    }

    /** @internal 供监控 Trait 调用 */
    private function extractPayload(array $response): array
    {
        return $this->detailService->extractPayload($response);
    }

    /** @internal 供监控 Trait 调用 */
    private function buildMonitorModuleCacheKey(Supplier $supplier, int $hostId): string
    {
        return $this->detailService->buildMonitorModuleCacheKey($supplier, $hostId);
    }

    /** @internal 供监控 Trait 调用 */
    private function fetchMonitorChartResponse(Supplier $supplier, int $hostId, string $jwt, array $query): array
    {
        $runtime = $this->detailService->resolveRuntimeCapabilityForSupplier($supplier);

        if (is_callable([$runtime, 'getMonitorChart'])) {
            return $runtime->getMonitorChart($supplier, $hostId, $query, $jwt);
        }

        return $runtime->get($supplier, "/v1/hosts/{$hostId}/module/charts", $jwt, $query);
    }

    /** @internal 供监控 Trait 调用 */
    private function fetchMonitorChartResponses(Supplier $supplier, int $hostId, array $queries, string $jwt): array
    {
        $runtime = $this->detailService->resolveRuntimeCapabilityForSupplier($supplier);

        if (is_callable([$runtime, 'getMonitorCharts'])) {
            return $runtime->getMonitorCharts($supplier, $hostId, $queries, $jwt);
        }

        $requests = collect($queries)->mapWithKeys(fn (array $query, string $type) => [
            $type => [
                'uri' => "/v1/hosts/{$hostId}/module/charts",
                'query' => $query,
                'connect_timeout' => self::MONITOR_UPSTREAM_CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::MONITOR_UPSTREAM_TIMEOUT_SECONDS,
            ],
        ])->all();

        return $runtime->parallelGet($supplier, $requests, $jwt);
    }

    private function sanitizeConnectionDetail(array $detail, bool $includeSensitiveConnection): array
    {
        if ($includeSensitiveConnection) {
            return $detail;
        }

        if (is_array($detail['connection'] ?? null)) {
            $detail['connection']['password'] = '';
            $detail['connection']['has_password'] = false;
        }

        return $detail;
    }
}
