<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Models\OperationLog;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\ServiceUpstreamBindingWriter;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\System\SettingService;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\ProviderResolver;
use App\Support\ServiceHostname;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * 共享转换辅助服务
 * 负责：transformListItem、transformDetail、buildSpecs、连接信息加解密、状态标签转换
 * 被 ServiceOverviewService、ServiceDetailService、ServicePowerService 等共同依赖
 */
class ServiceTransformService
{
    private const CONSOLE_MODE_NAT = 'nat';

    // 上游面板 config_options 中属于"范围/滑块"类型的 option_type，这些类型不从 sub_options 推断规格值
    private const SPEC_RANGE_OPTION_TYPES = [4, 7, 9, 11, 14, 15, 16, 17, 18, 19];

    private const POWER_ACTIONS = [
        'on' => '开机',
        'off' => '关机',
        'reboot' => '重启',
        'hard_off' => '强制关机',
        'hard_reboot' => '强制重启',
    ];

    private const OPERATION_CATEGORY_LABELS = [
        'power' => '电源管理',
        'password' => '密码重置',
        'reinstall' => '重装系统',
        'renew' => '续费管理',
        'upgrade' => '规格升级',
        'nat_forwarding' => '端口转发',
        'security_group' => '安全组',
        'security_rule' => '安全组规则',
        'vnc' => 'VNC远程',
        'service' => '实例变更',
    ];

    private const OPERATION_ACTION_LABELS = [
        'service.console.power.on' => '开机',
        'service.console.power.off' => '关机',
        'service.console.power.reboot' => '重启',
        'service.console.power.hard_off' => '强制关机',
        'service.console.power.hard_reboot' => '强制重启',
        'service.console.name.update' => '设置实例名称',
        'service.console.hostname.update' => '设置主机名',
        'service.console.meta.update' => '更新业务信息',
        'service.console.password.reset' => '重置密码',
        'service.console.reinstall.submit' => '重装系统',
        'service.console.renew.order.create' => '创建续费订单',
        'service.console.renew.auto_update' => '切换自动续费',
        'service.console.traffic_package.order.create' => '创建流量包订单',
        'service.console.traffic_package.purchase' => '购买流量包',
        'service.console.nat_forwarding.create' => '创建端口转发',
        'service.console.nat_forwarding.delete' => '删除端口转发',
        'service.console.security_group.create' => '创建安全组',
        'service.console.security_group.apply' => '应用安全组',
        'service.console.security_group.delete' => '删除安全组',
        'service.console.security_rule.create' => '创建安全组规则',
        'service.console.security_rule.delete' => '删除安全组规则',
        'service.console.vnc.get' => '获取VNC链接',
        'order.service.manual_create' => '管理员创建服务',
    ];

    public function __construct(
        private readonly ServiceResolverService $resolverService,
        private readonly ?SettingService $settingService = null,
        private readonly ?ProductDisplayNameResolver $productDisplayNameResolver = null,
    ) {}

    // ── List item transform ────────────────────────────────────────────────

    public function transformListItem(Service $service): array
    {
        $provisionData = $this->serviceProvisionData($service);
        $displayDomain = ServiceHostname::resolveDisplayDomain($service, $provisionData);
        $service->loadMissing([
            'product.productGroup.secondProductGroup.firstProductGroup',
        ]);
        $leafGroup = $service->product?->productGroup;
        $rootGroup = $this->resolverService->resolveServiceRootGroup($service);
        $rootGroupName = trim((string) ($rootGroup?->name ?? ''));
        $leafGroupName = trim((string) ($leafGroup?->name ?? ''));
        $catalogProductType = $this->resolverService->resolveGroupedOverviewTypeValue($service);
        $catalogProductTypeLabel = ProductType::businessLabelOf($catalogProductType);
        $consoleMode = $this->resolverService->resolveConsoleMode($service);
        $productConfigOptions = is_array($service->product?->config_options ?? null)
            ? $service->product->config_options
            : [];
        $productDisplayName = $this->resolveProductDisplayName($service);

        return [
            'id' => $service->id,
            'name' => $service->name,
            'product_display_name' => $productDisplayName,
            'product_full_path' => $this->resolveServiceProductPath($service, $productDisplayName),
            'domain' => $displayDomain,
            'custom_hostname' => ServiceHostname::custom($provisionData),
            'has_custom_hostname' => ServiceHostname::hasCustom($provisionData),
            'status' => (int) $service->status,
            'status_label' => ServiceStatus::$labels[$service->status] ?? (string) $service->status,
            'status_tone' => $this->resolveServiceTone((int) $service->status),
            'billing_cycle' => (string) $service->billing_cycle,
            'billing_cycle_label' => $this->resolveBillingCycleLabel((string) $service->billing_cycle),
            'amount' => number_format((float) $service->amount, 2, '.', ''),
            'expires_at' => $service->expires_at?->format('Y-m-d H:i:s'),
            'created_at' => $service->created_at?->format('Y-m-d H:i:s'),
            'auto_renew' => (int) $service->auto_renew,
            'product' => [
                'name' => $service->product?->name ?? '',
                'display_name' => $productDisplayName,
                'type' => $service->product?->product_type ?? '',
                'type_label' => $catalogProductTypeLabel,
                'catalog_type' => $catalogProductType,
                'console_template' => $service->product?->console_template,
                'group_name' => $leafGroupName,
                'root_group_name' => $rootGroupName,
                'menu_name' => $catalogProductTypeLabel,
            ],
            'invoice' => [
                'id' => (int) ($service->invoice?->id ?? 0),
                'invoice_no' => $service->invoice?->invoice_no ?? '',
            ],
            'custom_service_name' => (string) ($provisionData['custom_service_name'] ?? ''),
            'has_custom_service_name' => trim((string) ($provisionData['custom_service_name'] ?? '')) !== '',
            'upstream' => [
                'host_id' => (int) (($this->bindingResolver()->upstreamServiceIdForService($service) ?? 0) ?: 0),
                'status' => (string) ($provisionData['upstream_status'] ?? ''),
                'status_label' => $this->resolveUpstreamStatusLabel((string) ($provisionData['upstream_status'] ?? '')),
                'dedicated_ip' => (string) ($provisionData['dedicated_ip'] ?? ''),
                'os' => (string) ($provisionData['os'] ?? ''),
            ],
            'remark' => (string) ($provisionData['client_remark'] ?? ''),
            'can_manage' => $this->canManageService($service),
            'console_template' => $service->product?->console_template,
            'console_mode' => $consoleMode,
            'is_nat_console' => $consoleMode === self::CONSOLE_MODE_NAT,
            'machine_category' => $this->resolveMachineCategory($service, $catalogProductType, $consoleMode),
            'specs' => $this->buildSpecs([], $provisionData, $productConfigOptions),
        ];
    }

    // ── Detail transform ───────────────────────────────────────────────────

    public function transformDetail(Service $service, ?array $remoteState = null, string $remoteError = ''): array
    {
        $provisionData = $this->serviceProvisionData($service, includeSecrets: true);
        $cachedConnection = $this->readCachedConnection($provisionData);
        $host = (array) ($remoteState['host'] ?? []);
        $runtime = (array) ($remoteState['runtime'] ?? []);
        $natRemote = $this->resolveNatRemoteSnapshot($provisionData, (array) ($remoteState['nat'] ?? []));
        if ($runtime === []) {
            $runtime = [
                'status' => (string) ($provisionData['runtime_status'] ?? ''),
                'des' => (string) ($provisionData['runtime_description'] ?? ''),
            ];
        }

        $displayDomain = ServiceHostname::resolveDisplayDomain($service, $provisionData, $host);
        $serviceStatus = trim((string) ($host['domainstatus'] ?? ($provisionData['upstream_status'] ?? '')));
        $dedicatedIp = trim((string) ($host['dedicatedip'] ?? ($provisionData['dedicated_ip'] ?? '')));
        $assignedIps = is_array($host['assignedips'] ?? null)
            ? $host['assignedips']
            : (is_array($provisionData['assigned_ips'] ?? null) ? $provisionData['assigned_ips'] : []);
        $runtimeState = trim((string) ($runtime['status'] ?? ''));
        $specConfigOptions = $this->resolveSpecConfigOptions($service, $host, $provisionData);
        $canExecuteConsoleActions = $this->canExecuteConsoleActions($service, $serviceStatus, $runtimeState);
        $catalogProductType = $this->resolverService->resolveGroupedOverviewTypeValue($service);
        $catalogProductTypeLabel = ProductType::businessLabelOf($catalogProductType);
        $consoleMode = $this->resolverService->resolveConsoleMode($service);
        $productPricing = Service::extractSupportedRenewPricing(
            is_array($service->product?->pricing ?? null) ? $service->product->pricing : []
        );
        $renewPricingConfig = $service->resolveRenewPricingConfig($productPricing);
        $trafficPayload = $this->buildTrafficPayload($host, $provisionData);
        $trafficPackageEnabled = $this->canExposeTrafficPackage($service, $trafficPayload);
        $productDisplayName = $this->resolveProductDisplayName($service);
        $instanceName = ServiceHostname::resolveInstanceName($service, $provisionData, $host);

        return [
            'id' => $service->id,
            'name' => $instanceName !== '' ? $instanceName : ($service->name ?? ''),
            'product_display_name' => $productDisplayName,
            'product_full_path' => $this->resolveServiceProductPath($service, $productDisplayName),
            'combined_display_name' => $this->resolveCombinedDisplayName($service),
            'domain' => $displayDomain,
            'status' => (int) $service->status,
            'status_label' => ServiceStatus::$labels[$service->status] ?? (string) $service->status,
            'status_tone' => $this->resolveServiceTone((int) $service->status),
            'billing_cycle' => (string) $service->billing_cycle,
            'billing_cycle_label' => $this->resolveBillingCycleLabel((string) $service->billing_cycle),
            'amount' => number_format((float) $service->amount, 2, '.', ''),
            'expires_at' => $service->expires_at?->format('Y-m-d H:i:s'),
            'created_at' => $service->created_at?->format('Y-m-d H:i:s'),
            'auto_renew' => (int) $service->auto_renew,
            'suspended_reason' => $service->suspended_reason,
            'remark' => (string) ($provisionData['client_remark'] ?? ''),
            'custom_service_name' => (string) ($provisionData['custom_service_name'] ?? ''),
            'has_custom_service_name' => trim((string) ($provisionData['custom_service_name'] ?? '')) !== '',
            'custom_hostname' => ServiceHostname::custom($provisionData),
            'has_custom_hostname' => ServiceHostname::hasCustom($provisionData),
            'has_custom_renew_pricing' => $service->usesCustomRenewPricing($productPricing),
            'has_locked_pricing' => $service->usesCustomRenewPricing($productPricing),
            'renew_pricing_cycles' => $this->transformRenewPricingCycles($renewPricingConfig),
            'console_template' => $service->product?->console_template,
            'console_mode' => $consoleMode,
            'is_nat_console' => $consoleMode === self::CONSOLE_MODE_NAT,
            'product' => [
                'id' => $service->product_id,
                'name' => $service->product?->name ?? '',
                'display_name' => $productDisplayName,
                'type' => $service->product?->product_type ?? '',
                'type_label' => $catalogProductTypeLabel,
                'catalog_type' => $catalogProductType,
                'console_template' => $service->product?->console_template,
            ],
            'invoice' => [
                'id' => (int) ($service->invoice?->id ?? 0),
                'invoice_no' => $service->invoice?->invoice_no ?? '',
                'order_no' => $service->order?->order_no ?: $service->invoice?->order?->order_no ?: '',
                'status' => (int) ($service->invoice?->status ?? 0),
                'paid_at' => $service->invoice?->paid_at?->format('Y-m-d H:i:s'),
            ],
            'upstream' => [
                'provider_key' => (string) ($this->bindingResolver()->providerKeyForService($service) ?? ''),
                'supplier_id' => $this->resolveManagedSupplierId($service, $provisionData),
                'upstream_product_id' => $this->resolveUpstreamProductId($service, $provisionData),
                'host_id' => $this->resolveUpstreamHostId($service, $provisionData),
                'invoice_id' => (int) (($provisionData['upstream_invoice_id'] ?? 0) ?: 0),
                'status' => $serviceStatus,
                'status_label' => $this->resolveUpstreamStatusLabel($serviceStatus),
                'remote_error' => $this->sanitizeRemoteErrorMessage(
                    $remoteError !== '' ? $remoteError : ($remoteState !== null ? '' : (string) ($provisionData['provision_error'] ?? ''))
                ),
                'dedicated_ip' => $dedicatedIp,
                'os' => trim((string) ($host['os'] ?? ($provisionData['os'] ?? ''))),
            ],
            'runtime' => [
                'power_state' => $runtimeState,
                'power_label' => $runtimeState !== ''
                    ? $this->resolvePowerLabel($runtimeState, (string) ($runtime['des'] ?? ''))
                    : '',
                'description' => (string) ($runtime['des'] ?? ''),
            ],
            'connection' => [
                'hostname' => ServiceHostname::resolveConnectionHostname($service, $provisionData, $cachedConnection, $host),
                'username' => trim((string) ($host['username'] ?? ($cachedConnection['username'] ?? ''))),
                'password' => $this->resolveConnectionPassword($host, $cachedConnection),
                'has_password' => $this->resolveConnectionPassword($host, $cachedConnection) !== '',
                'port' => (int) (($host['port'] ?? ($cachedConnection['port'] ?? 0)) ?: 0),
                'dedicated_ip' => $dedicatedIp,
                'internal_ip' => trim((string) ($host['internalip'] ?? $host['privateip'] ?? ($cachedConnection['internal_ip'] ?? ''))),
                'assigned_ips' => array_values(array_filter(array_map('strval', $assignedIps))),
                'nat_remote_address' => $natRemote['remote_address'],
                'nat_remote_host' => $natRemote['host'],
                'nat_remote_port' => $natRemote['port'],
                'nat_remote_checked_at' => $natRemote['checked_at'],
            ],
            'specs' => $this->buildSpecs($host, $provisionData, $specConfigOptions),
            'traffic' => $trafficPayload,
            'actions' => [
                'refresh' => true,
                'power' => $canExecuteConsoleActions,
                'module_status' => $this->canManageService($service),
                'manual_provision' => $this->canManualProvisionService($service),
                'password_reset' => $this->canResetPassword($service, $serviceStatus),
                'reinstall' => $canExecuteConsoleActions,
                'traffic_package' => $this->canManageService($service) && $trafficPackageEnabled,
                'available' => array_keys(self::POWER_ACTIONS),
            ],
        ];
    }

    /**
     * 用户端出口脱敏：移除 upstream 中的供应商身份字段，避免泄露上游提供商信息。
     * 管理端仍使用完整 detail（transformDetail 原样输出）。
     */
    public function sanitizeClientDetail(array $detail): array
    {
        if (is_array($detail['upstream'] ?? null)) {
            foreach (['provider_key', 'supplier_id', 'upstream_product_id', 'invoice_id'] as $field) {
                unset($detail['upstream'][$field]);
            }
        }

        return $detail;
    }

    private function sanitizeRemoteErrorMessage(string $message): string
    {
        $normalized = trim($message);
        if ($normalized === '') {
            return '';
        }

        $lowerMessage = mb_strtolower($normalized);

        if (str_contains($lowerMessage, 'timeout') || str_contains($lowerMessage, 'timed out')) {
            return '上游状态同步超时，请稍后重试';
        }

        if (str_contains($lowerMessage, '401') || str_contains($lowerMessage, 'jwt') || str_contains($lowerMessage, 'auth')) {
            return '上游认证状态异常，请联系管理员检查接口配置';
        }

        return '上游状态同步失败，请稍后重试';
    }

    // ── Operation log transform ────────────────────────────────────────────

    public function transformServiceOperationLog(OperationLog $log): array
    {
        $detail = is_array($log->detail ?? null) ? $log->detail : [];
        $category = trim((string) ($detail['category'] ?? $this->resolveOperationCategoryByAction((string) $log->action)));
        $actionLabel = trim((string) ($detail['operation_label'] ?? self::OPERATION_ACTION_LABELS[$log->action] ?? '')) ?: '实例操作';
        $actorType = trim((string) ($detail['actor_type'] ?? $log->user_type ?? ''));
        $summary = trim((string) ($detail['summary'] ?? '')) ?: $this->resolveOperationSummary((string) $log->action, $detail, $actionLabel);

        return [
            'id' => (int) $log->id,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            'action' => (string) $log->action,
            'action_label' => $actionLabel,
            'category' => $category,
            'category_label' => self::OPERATION_CATEGORY_LABELS[$category] ?? '实例操作',
            'summary' => $summary,
            'actor_type' => $actorType !== '' ? $actorType : ($log->user_id ? 'client' : 'system'),
            'actor_label' => $this->resolveOperationActorLabel($actorType !== '' ? $actorType : ($log->user_id ? 'client' : 'system')),
            'actor_name' => $this->resolveOperationActorName($detail, $actorType !== '' ? $actorType : ($log->user_id ? 'client' : 'system')),
            'ip_address' => (string) ($log->ip_address ?? ''),
            'detail_items' => $this->buildOperationLogDetailItems($detail, $actionLabel, $summary),
        ];
    }

    private function transformRenewPricingCycles(array $config): array
    {
        $cycles = [];

        foreach (Service::SUPPORTED_RENEW_BILLING_CYCLES as $cycle => $label) {
            $entry = is_array($config[$cycle] ?? null) ? $config[$cycle] : [];
            $baseAmount = $entry['base_amount'] ?? null;
            $manualAmount = $entry['manual_amount'] ?? null;

            $cycles[] = [
                'billing_cycle' => $cycle,
                'billing_cycle_label' => $label,
                'enabled' => (bool) ($entry['enabled'] ?? false),
                'base_amount' => $baseAmount,
                'manual_amount' => $manualAmount,
                'effective_amount' => $manualAmount ?: $baseAmount,
            ];
        }

        return $cycles;
    }

    // ── Service state helpers (used across sub-services) ──────────────────

    public function canManageService(Service $service): bool
    {
        $provisionData = $this->serviceProvisionData($service);
        $supplierId = $this->resolveManagedSupplierId($service, $provisionData);
        $hostId = $this->resolveUpstreamHostId($service, $provisionData);

        return app(ProviderResolver::class)->resolveForService($service)->supports(ProvidesConsoleRuntime::class)
            && $hostId > 0
            && $supplierId > 0;
    }

    public function canExecuteConsoleActions(Service $service, string $upstreamStatus = '', string $runtimeStatus = ''): bool
    {
        if (! $this->canManageService($service)) {
            return false;
        }

        if (in_array((int) $service->status, [
            ServiceStatus::PENDING,
            ServiceStatus::SUSPENDED,
            ServiceStatus::EXPIRED,
            ServiceStatus::CANCELLED,
        ], true)) {
            return false;
        }

        $provisionData = $this->serviceProvisionData($service);
        $normalizedUpstreamStatus = strtolower(trim($upstreamStatus !== ''
            ? $upstreamStatus
            : (string) ($provisionData['upstream_status'] ?? '')));

        if (in_array($normalizedUpstreamStatus, ['pending', 'suspended', 'cancelled', 'deleted', 'fraud'], true)) {
            return false;
        }

        $normalizedRuntimeStatus = strtolower(trim($runtimeStatus !== ''
            ? $runtimeStatus
            : (string) ($provisionData['runtime_status'] ?? '')));

        return ! in_array($normalizedRuntimeStatus, [
            'process', 'task', 'starting', 'booting', 'stopping', 'shutting_down', 'reboot', 'rebooting',
        ], true);
    }

    public function canManualProvisionService(Service $service): bool
    {
        $provisionData = $this->serviceProvisionData($service);
        $provisionError = trim((string) ($provisionData['provision_error'] ?? ''));

        return $provisionError !== ''
            && (int) $service->status === ServiceStatus::PENDING;
    }

    /**
     * 密码重置不需要实例处于开机状态，只检查业务状态和上游状态。
     */
    public function canResetPassword(Service $service, string $upstreamStatus = ''): bool
    {
        if (! $this->canManageService($service)) {
            return false;
        }

        if (in_array((int) $service->status, [
            ServiceStatus::PENDING,
            ServiceStatus::SUSPENDED,
            ServiceStatus::EXPIRED,
            ServiceStatus::CANCELLED,
        ], true)) {
            return false;
        }

        $provisionData = $this->serviceProvisionData($service);
        $normalizedUpstreamStatus = strtolower(trim($upstreamStatus !== ''
            ? $upstreamStatus
            : (string) ($provisionData['upstream_status'] ?? '')));

        return ! in_array($normalizedUpstreamStatus, ['pending', 'suspended', 'cancelled', 'deleted', 'fraud'], true);
    }

    // ── Connection cache helpers ───────────────────────────────────────────

    public function readCachedConnection(array $provisionData): array
    {
        $directConnection = [
            'hostname' => trim((string) ($provisionData['hostname'] ?? $provisionData['connection_cached_hostname'] ?? '')),
            'username' => trim((string) ($provisionData['username'] ?? '')),
            'password' => (string) ($provisionData['password'] ?? ''),
            'port' => (int) (($provisionData['port'] ?? $provisionData['nat_remote_port'] ?? 0) ?: 0),
            'internal_ip' => trim((string) ($provisionData['internal_ip'] ?? '')),
        ];
        $directConnection = array_filter($directConnection, static fn (mixed $value): bool => $value !== '' && $value !== 0);

        $payload = trim((string) ($provisionData['connection_secret'] ?? ''));
        if ($payload === '') {
            return $directConnection;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($payload), true);
        } catch (\Throwable) {
            return $directConnection;
        }

        // 注意：$directConnection 的来源既可能是历史遗留的 provision_data 明文，也可能是
        // service_connection_snapshots 归一化快照（secret_json 解密后的值）。快照是新的事实来源，
        // 必须压过 legacy provision_data 里的 connection_secret 旧值，因此这里保持
        // “direct 覆盖 decoded”。真正的明文风险改由写入端消除（见 cacheSubmittedPasswordForService）。
        return is_array($decoded) ? array_replace($decoded, $directConnection) : $directConnection;
    }

    public function writeCachedConnection(array $connection): string
    {
        return Crypt::encryptString((string) json_encode([
            'hostname' => trim((string) ($connection['hostname'] ?? '')),
            'username' => trim((string) ($connection['username'] ?? '')),
            'password' => (string) ($connection['password'] ?? ''),
            'port' => (int) (($connection['port'] ?? 0) ?: 0),
            'internal_ip' => trim((string) ($connection['internal_ip'] ?? '')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function cacheSubmittedPasswordForService(Service $service, string $password): void
    {
        $provisionData = $this->serviceProvisionData($service, includeSecrets: true);
        $cachedConnection = $this->readCachedConnection($provisionData);
        $connection = [
            'hostname' => ServiceHostname::resolveConnectionHostname($service, $provisionData, $cachedConnection),
            'username' => trim((string) ($cachedConnection['username'] ?? '')),
            'password' => $password,
            'port' => (int) (($cachedConnection['port'] ?? 0) ?: 0),
            'internal_ip' => trim((string) ($cachedConnection['internal_ip'] ?? '')),
        ];

        $provisionData['connection_secret'] = $this->writeCachedConnection($connection);
        $provisionData['connection_cached_at'] = now()->format('Y-m-d H:i:s');
        $provisionData['last_password_reset_requested_at'] = now()->format('Y-m-d H:i:s');

        // $provisionData 是 legacy provision_data 与快照投影（includeSecrets 已解密）的合并结果，
        // 直接回写会把明文密码落进 services.provision_data JSON 列。密码只保留在 connection_secret
        // （以及快照 secret_json）里，这里显式剔除明文，避免明文旁路加密字段。
        unset($provisionData['password']);

        $service->forceFill(['provision_data' => $provisionData])->save();
        app(ServiceUpstreamBindingWriter::class)
            ->syncServiceState($service, null, $provisionData);
    }

    public function resolveNatRemoteSnapshot(array $provisionData, array $nat = []): array
    {
        $checked = (bool) ($nat['checked'] ?? false);
        $cachedAddress = trim((string) ($provisionData['nat_remote_address'] ?? ''));
        $cachedHost = trim((string) ($provisionData['nat_remote_host'] ?? ''));
        $cachedPort = (int) (($provisionData['nat_remote_port'] ?? 0) ?: 0);
        $remoteAddress = trim((string) ($nat['remote_address'] ?? ''));
        $remoteHost = trim((string) ($nat['host'] ?? ''));
        $remotePort = (int) (($nat['port'] ?? 0) ?: 0);

        if ($checked && $remoteAddress === '' && $cachedAddress !== '') {
            $remoteAddress = $cachedAddress;
            $remoteHost = $cachedHost;
            $remotePort = $cachedPort;
        }

        return [
            'remote_address' => $checked ? $remoteAddress : $cachedAddress,
            'host' => $checked ? $remoteHost : $cachedHost,
            'port' => $checked ? $remotePort : $cachedPort,
            'checked_at' => trim((string) ($checked ? ($nat['checked_at'] ?? now()->format('Y-m-d H:i:s')) : ($provisionData['nat_remote_checked_at'] ?? ''))),
        ];
    }

    // ── Spec builders ──────────────────────────────────────────────────────

    public function buildSpecs(array $host, array $provisionData, array $productConfigOptions = []): array
    {
        $configOptionItems = is_array($host['config_option'] ?? null)
            ? $host['config_option']
            : (is_array($provisionData['host_config_option'] ?? null) ? $provisionData['host_config_option'] : []);
        $requested = (array) ($provisionData['requested_config'] ?? []);
        $configOptionMap = $this->buildConfigOptionMap($configOptionItems);
        $definitions = $this->mergeSpecDefinitions(
            $this->buildSpecDefinitions($productConfigOptions),
            $this->buildFallbackSpecDefinitions($configOptionMap, $requested)
        );

        if ($definitions === []) {
            return [];
        }

        return collect($definitions)
            ->map(function (array $definition) use ($requested, $configOptionMap) {
                $field = (string) $definition['field'];
                $hostValue = $configOptionMap[$field]['value'] ?? null;
                $requestedValue = array_key_exists($field, $requested) ? $requested[$field] : null;
                $rawValue = $hostValue;

                if ($rawValue === null || trim((string) $rawValue) === '') {
                    $rawValue = $requestedValue;
                }

                $value = $this->resolveSpecValue($field, $rawValue, $definition);
                if ($value === null) {
                    return null;
                }

                return [
                    'key' => $field,
                    'label' => (string) $definition['label'],
                    'value' => $value,
                ];
            })
            ->filter(fn ($item) => is_array($item))
            ->values()->all();
    }

    public function resolveSpecConfigOptions(Service $service, array $host, array $provisionData): array
    {
        $localConfigOptions = is_array($service->product?->config_options ?? null) ? $service->product->config_options : [];
        $upstreamProductId = (int) (($host['product_id'] ?? ($provisionData['upstream_product_id'] ?? 0)) ?: 0);
        $supplier = $service->product instanceof Product ? $this->resolveProductSupplier($service->product) : null;

        if (
            ! $supplier instanceof Supplier
            || ! app(ProviderResolver::class)->resolveForSupplier($supplier)->supports(ProvidesConsoleRuntime::class)
            || $upstreamProductId <= 0
        ) {
            return $localConfigOptions;
        }

        if ($service->product instanceof Product
            && $this->resolveProductUpstreamProductId($service->product) === $upstreamProductId
            && $localConfigOptions !== []
        ) {
            return $localConfigOptions;
        }

        $cached = $this->getCachedProductConfigOptions($supplier, $upstreamProductId);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        return $localConfigOptions;
    }

    private function buildTrafficPayload(array $host, array $provisionData): array
    {
        $config = $this->resolveTrafficPackageConfig();
        $usage = is_numeric($host['bwusage'] ?? null)
            ? round((float) $host['bwusage'], 2)
            : (is_numeric($provisionData['bw_usage'] ?? null) ? round((float) $provisionData['bw_usage'], 2) : 0.0);

        $upstreamLimit = is_numeric($host['bwlimit'] ?? null)
            ? max((int) $host['bwlimit'], 0)
            : max((int) ($provisionData['bw_limit'] ?? 0), 0);

        // bwlimit=0 只表示实例级别未单独设限，流量上限可能配在商品 config_option 里
        $limit = $upstreamLimit > 0
            ? $upstreamLimit
            : $this->resolveFlowLimitGbFromConfigOption($host, $provisionData);

        $remaining = $limit > 0 ? max(round($limit - $usage, 2), 0) : null;
        $usagePercent = $limit > 0
            ? min(round(($usage / $limit) * 100, 2), 100.0)
            : null;

        return [
            'usage' => number_format($usage, 2, '.', ''),
            'limit' => $limit,
            'remaining' => $remaining !== null ? number_format($remaining, 2, '.', '') : '',
            'usage_label' => $this->formatTrafficAmount($usage),
            'limit_label' => $limit > 0 ? $this->formatTrafficAmount($limit) : '不限',
            'remaining_label' => $remaining !== null ? $this->formatTrafficAmount($remaining) : '不限',
            'usage_percent' => $usagePercent,
            'limited' => $limit > 0,
            'button_text' => trim((string) ($config['button_text'] ?? '')) !== ''
                ? (string) $config['button_text']
                : '购买流量包',
            'display_threshold_percent' => (int) ($config['display_threshold_percent'] ?? 0),
            'purchase_enabled' => (bool) ($config['enabled'] ?? true),
        ];
    }

    private function resolveFlowLimitGbFromConfigOption(array $host, array $provisionData): int
    {
        $items = is_array($host['config_option'] ?? null)
            ? $host['config_option']
            : (is_array($provisionData['host_config_option'] ?? null) ? $provisionData['host_config_option'] : []);

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $field = $this->normalizeSpecField(
                (string) ($item['key'] ?? ''),
                (string) ($item['name'] ?? $item['option_name'] ?? '')
            );

            if ($field !== 'flow_limit') {
                continue;
            }

            $raw = $item['value'] ?? $item['qty'] ?? null;
            $gb = $this->parseFlowLimitGb($raw);
            if ($gb > 0) {
                return $gb;
            }
        }

        return 0;
    }

    private function parseFlowLimitGb(mixed $raw): int
    {
        if (is_array($raw)) {
            return 0;
        }

        $text = trim((string) $raw);
        if ($text === '') {
            return 0;
        }

        // 兼容 "1024|1024G"、"2|2T" 这种 raw|label 组合，取管道左侧 raw 值
        if (str_contains($text, '|')) {
            [$left] = explode('|', $text, 2);
            $text = trim($left);
        }

        if (! preg_match('/^([\d.]+)\s*([tT][bB]?|[gG][bB]?|[mM][bB]?)?$/u', $text, $matches)) {
            return 0;
        }

        $number = (float) $matches[1];
        if ($number <= 0) {
            return 0;
        }

        $unit = strtoupper($matches[2] ?? 'G');

        if (str_starts_with($unit, 'T')) {
            return (int) round($number * 1024);
        }

        if (str_starts_with($unit, 'M')) {
            return (int) max(round($number / 1024), 0);
        }

        return (int) round($number);
    }

    private function canExposeTrafficPackage(Service $service, array $trafficPayload): bool
    {
        $purchaseEnabled = (bool) ($trafficPayload['purchase_enabled'] ?? true);
        $limited = (bool) ($trafficPayload['limited'] ?? false);
        $usagePercent = is_numeric($trafficPayload['usage_percent'] ?? null)
            ? (float) $trafficPayload['usage_percent']
            : 0.0;
        $threshold = max((int) ($trafficPayload['display_threshold_percent'] ?? 0), 0);

        if (! $purchaseEnabled || ! $limited || ! $this->hasConfiguredTrafficPackages($service)) {
            return false;
        }

        return $threshold <= 0 || $usagePercent >= $threshold;
    }

    private function hasConfiguredTrafficPackages(Service $service): bool
    {
        if (! $this->settingService) {
            return true;
        }

        $categoryId = $this->effectiveProductGroupId($service);
        if ($categoryId <= 0) {
            return false;
        }

        return $this->settingService->getTrafficPackageCatalogForCategory(
            $categoryId,
            (string) ($service->product?->product_type ?? ''),
            (int) ($service->product_id ?? 0)
        ) !== [];
    }

    private function effectiveProductGroupId(Service $service): int
    {
        $thirdGroupId = (int) ($service->product?->third_product_group_id ?? 0);
        if ($thirdGroupId > 0) {
            return $thirdGroupId;
        }

        return (int) ($service->product?->second_product_group_id ?? 0);
    }

    private function resolveTrafficPackageConfig(): array
    {
        if ($this->settingService) {
            return $this->settingService->getTrafficPackageConfig();
        }

        $defaults = SettingService::defaultTrafficPackageConfig();

        return [
            'enabled' => (bool) ($defaults['traffic_package_enabled'] ?? true),
            'display_threshold_percent' => (int) ($defaults['traffic_package_display_threshold_percent'] ?? 0),
            'button_text' => (string) ($defaults['traffic_package_button_text'] ?? '购买流量包'),
            'option_field' => (string) ($defaults['traffic_package_option_field'] ?? 'flow_limit'),
            'option_keyword' => (string) ($defaults['traffic_package_option_keyword'] ?? '流量'),
            'allow_choice_mode' => (bool) ($defaults['traffic_package_allow_choice_mode'] ?? true),
            'allow_quantity_mode' => (bool) ($defaults['traffic_package_allow_quantity_mode'] ?? true),
        ];
    }

    // ── Status/label resolution helpers ───────────────────────────────────

    public function resolveServiceTone(int $status): string
    {
        return match ($status) {
            ServiceStatus::ACTIVE => 'success',
            ServiceStatus::SUSPENDED => 'danger',
            ServiceStatus::EXPIRED => 'warning',
            ServiceStatus::CANCELLED => 'muted',
            default => 'info',
        };
    }

    public function resolveBillingCycleLabel(string $billingCycle): string
    {
        return match ($billingCycle) {
            'monthly' => '月付',
            'quarterly' => '季付',
            'semiannually' => '半年付',
            'annually' => '年付',
            'biennially' => '两年付',
            'triennially' => '三年付',
            'one_time' => '一次性',
            default => $billingCycle !== '' ? $billingCycle : '--',
        };
    }

    public function resolveUpstreamStatusLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            'active' => '已激活',
            'pending' => '开通中',
            'suspended' => '已暂停',
            'cancelled' => '已取消',
            'deleted' => '已删除',
            'fraud' => '欺诈',
            default => $status !== '' ? $status : '--',
        };
    }

    public function resolvePowerLabel(string $powerState, string $fallback = ''): string
    {
        return match (strtolower(trim($powerState))) {
            'on', 'running' => '运行中',
            'off', 'stopped' => '已关机',
            'reboot', 'rebooting' => '重启中',
            'task' => '任务处理中',
            'starting', 'booting' => '开机中',
            'stopping', 'shutting_down' => '关机中',
            default => $fallback !== '' ? $fallback : '--',
        };
    }

    public function resolveServiceStatusFromUpstream(string $status): int
    {
        return match (strtolower(trim($status))) {
            'active' => ServiceStatus::ACTIVE,
            'suspended' => ServiceStatus::SUSPENDED,
            'cancelled', 'deleted' => ServiceStatus::CANCELLED,
            default => ServiceStatus::PENDING,
        };
    }

    public function resolveExpiry(array $host, Service $service): ?Carbon
    {
        $nextDueDate = $host['nextduedate'] ?? null;
        if (is_numeric($nextDueDate) && (int) $nextDueDate > 0) {
            return Carbon::createFromTimestamp((int) $nextDueDate);
        }

        return $service->expires_at;
    }

    public function resolveMachineCategory(Service $service, string $catalogProductType, string $consoleMode): array
    {
        $normalizedType = strtolower(trim($catalogProductType));

        if ($consoleMode === self::CONSOLE_MODE_NAT) {
            return ['key' => 'nat', 'label' => 'NAT / 云电脑'];
        }

        $typeMap = [
            'cloud_server' => ['key' => 'cloud_server', 'label' => '云服务器'],
            'game_cloud' => ['key' => 'game_cloud', 'label' => '游戏云'],
            'cloud_desktop' => ['key' => 'cloud_desktop', 'label' => '云电脑'],
            'bare_metal' => ['key' => 'bare_metal', 'label' => '裸金属'],
            'cdn' => ['key' => 'cdn', 'label' => 'CDN'],
            'physical_machine' => ['key' => 'physical_machine', 'label' => '物理机'],
            'web_hosting' => ['key' => 'web_hosting', 'label' => '虚拟主机'],
            'other' => ['key' => 'other', 'label' => '其他'],
        ];

        if (isset($typeMap[$normalizedType])) {
            return $typeMap[$normalizedType];
        }

        $textPool = strtolower(implode(' ', array_filter([
            $service->name,
            $service->product?->name ?? '',
            $service->product?->service_type_code ?? '',
            $service->product?->productGroup?->secondProductGroup?->firstProductGroup?->name ?? '',
            $service->product?->productGroup?->secondProductGroup?->firstProductGroup?->description ?? '',
            $service->product?->productGroup?->secondProductGroup?->name ?? '',
            $service->product?->productGroup?->secondProductGroup?->description ?? '',
            $service->product?->name ?? '',
            $service->product?->productGroup?->description ?? '',
        ])));
        $keywordMap = [
            'cloud_server' => ['云服务器', 'vps', 'ecs', 'cvm', '轻量'],
            'nat' => ['nat', '云电脑', '云桌面'],
            'cdn' => ['cdn', '加速'],
            'game_cloud' => ['游戏云'],
            'physical_machine' => ['物理机', '物理服务器', '独立服务器', 'dedicated'],
            'bare_metal' => ['裸金属', 'bare metal', 'bare_metal'],
            'web_hosting' => ['虚拟主机', '虚机', 'hosting', '空间'],
        ];
        $labelMap = [
            'cloud_server' => '云服务器',
            'nat' => 'NAT / 云电脑',
            'cdn' => 'CDN',
            'game_cloud' => '游戏云',
            'physical_machine' => '物理机',
            'bare_metal' => '裸金属',
            'web_hosting' => '虚拟主机',
        ];

        foreach ($keywordMap as $key => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($textPool, $kw)) {
                    return ['key' => $key, 'label' => $labelMap[$key]];
                }
            }
        }

        return ['key' => 'other', 'label' => '其他'];
    }

    public function resolveSelectOptionLabel(array $options, string $value): string
    {
        $normalizedValue = trim($value);
        if ($normalizedValue === '') {
            return '';
        }

        $option = collect($options)
            ->first(fn ($item) => is_array($item) && trim((string) ($item['value'] ?? '')) === $normalizedValue);

        return trim((string) ($option['label'] ?? ''));
    }

    public function buildProductConfigOptionsCacheKey(Supplier $supplier, int $productId): string
    {
        $providerKey = $this->bindingResolver()->providerKeyForSupplier($supplier);

        $providerKey = trim((string) $providerKey) !== '' ? trim((string) $providerKey) : 'unbound';

        return "upstream:{$providerKey}:product_config_options:{$supplier->id}:{$productId}";
    }

    /**
     * @param  array<string, mixed>  $provisionData
     */
    private function resolveManagedSupplierId(Service $service, array $provisionData): int
    {
        $supplierId = (int) (($this->bindingResolver()->supplierIdForService($service) ?? 0) ?: 0);

        if ($supplierId <= 0 && $service->product instanceof Product) {
            $supplierId = (int) (($this->bindingResolver()->supplierIdForProduct($service->product) ?? 0) ?: 0);
        }

        if ($supplierId > 0) {
            return $supplierId;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $provisionData
     */
    private function resolveUpstreamProductId(Service $service, array $provisionData): int
    {
        $upstreamProductId = $this->bindingResolver()->upstreamProductIdForService($service);

        if ($upstreamProductId === null && $service->product instanceof Product) {
            $upstreamProductId = $this->bindingResolver()->upstreamProductIdForProduct($service->product);
        }

        if ($upstreamProductId !== null) {
            return (int) $upstreamProductId;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $provisionData
     */
    private function resolveUpstreamHostId(Service $service, array $provisionData): int
    {
        $upstreamServiceId = $this->bindingResolver()->upstreamServiceIdForService($service);
        if ($upstreamServiceId !== null) {
            return (int) $upstreamServiceId;
        }

        return 0;
    }

    private function bindingResolver(): PluginBindingResolver
    {
        return app(PluginBindingResolver::class);
    }

    private function serviceProvisionData(Service $service, bool $includeSecrets = false): array
    {
        $legacy = (array) ($service->provision_data ?? []);
        $projection = $this->bindingResolver()->serviceProvisionProjection($service, $includeSecrets);

        return $projection === [] ? $legacy : array_replace($legacy, $projection);
    }

    private function resolveProductSupplier(Product $product): ?Supplier
    {
        $boundSupplier = $this->bindingResolver()->supplierForProduct($product);
        if ($boundSupplier instanceof Supplier) {
            return $this->bindingResolver()->supplierWithRuntimeCredentials($boundSupplier);
        }

        return null;
    }

    private function resolveProductUpstreamProductId(Product $product): int
    {
        $upstreamProductId = $this->bindingResolver()->upstreamProductIdForProduct($product);
        if ($upstreamProductId !== null) {
            return (int) $upstreamProductId;
        }

        return 0;
    }

    private function getCachedProductConfigOptions(Supplier $supplier, int $productId): array
    {
        $cacheKey = $this->buildProductConfigOptionsCacheKey($supplier, $productId);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        return [];
    }

    // ── Private spec helpers ───────────────────────────────────────────────

    private function buildSpecDefinitions(array $productConfigOptions): array
    {
        return collect($productConfigOptions)
            ->filter(fn ($item) => is_array($item) && (int) ($item['hidden'] ?? 0) !== 1)
            ->values()
            ->map(function (array $item, int $index) {
                $field = $this->resolveSpecDefinitionField($item);
                if ($field === '' || $this->shouldSkipSpecField($field)) {
                    return null;
                }

                return [
                    'field' => $field,
                    'label' => trim((string) ($item['name'] ?? '')),
                    'option_type' => (int) ($item['option_type'] ?? 0),
                    'unit' => trim((string) ($item['unit'] ?? '')),
                    'sort_order' => (int) ($item['sort_order'] ?? $item['order'] ?? $index),
                    'display_index' => $index,
                    'sub_options' => collect((array) ($item['sub'] ?? []))
                        ->filter(fn ($sub) => is_array($sub))
                        ->map(fn (array $sub) => [
                            'id' => trim((string) ($sub['id'] ?? '')),
                            'value' => trim((string) ($sub['option_name_first'] ?? $sub['option_name'] ?? $sub['version'] ?? $sub['id'] ?? '')),
                            'label' => $this->resolveSpecSubLabel($sub),
                        ])
                        ->filter(fn (array $sub) => $sub['id'] !== '' || $sub['value'] !== '' || $sub['label'] !== '')
                        ->values()->all(),
                ];
            })
            ->filter(fn ($item) => is_array($item) && trim((string) ($item['label'] ?? '')) !== '')
            ->sort(fn (array $left, array $right) => [$left['sort_order'], $left['display_index']] <=> [$right['sort_order'], $right['display_index']])
            ->values()->all();
    }

    private function buildConfigOptionMap(array $configOptionItems): array
    {
        return collect($configOptionItems)
            ->filter(fn ($item) => is_array($item))
            ->reduce(function (array $carry, array $item) {
                $field = $this->normalizeSpecField(
                    (string) ($item['key'] ?? ''),
                    (string) ($item['name'] ?? $item['option_name'] ?? '')
                );

                if ($field === '' || isset($carry[$field])) {
                    return $carry;
                }

                $carry[$field] = $item;

                return $carry;
            }, []);
    }

    private function buildFallbackSpecDefinitions(array $configOptionMap, array $requested): array
    {
        $fieldKeys = collect(array_merge(array_keys($configOptionMap), array_map('strval', array_keys($requested))))
            ->map(fn (string $field) => $this->normalizeSpecField($field))
            ->filter(fn (string $field) => $field !== '' && ! $this->shouldSkipSpecField($field))
            ->unique()
            ->values();

        return $fieldKeys
            ->map(function (string $field, int $index) use ($configOptionMap) {
                $configOption = $configOptionMap[$field] ?? [];
                $label = $this->resolveFallbackSpecLabel($field, $configOption);

                if ($label === '') {
                    return null;
                }

                return [
                    'field' => $field,
                    'label' => $label,
                    'option_type' => (int) ($configOption['type'] ?? 0),
                    'unit' => trim((string) ($configOption['unit'] ?? '')),
                    'sort_order' => 10000 + $index,
                    'display_index' => 10000 + $index,
                    'sub_options' => [],
                ];
            })
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();
    }

    private function mergeSpecDefinitions(array $definitions, array $fallbackDefinitions): array
    {
        $merged = [];

        foreach (array_merge($definitions, $fallbackDefinitions) as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $field = trim((string) ($definition['field'] ?? ''));
            if ($field === '' || isset($merged[$field])) {
                continue;
            }

            $merged[$field] = $definition;
        }

        return array_values($merged);
    }

    private function resolveSpecValue(string $field, mixed $rawValue, ?array $definition): ?string
    {
        if (is_array($rawValue)) {
            $rawValue = implode(', ', array_values(array_filter(array_map(fn ($item) => trim((string) $item), $rawValue))));
        }

        $text = trim((string) ($rawValue ?? ''));
        $subOptions = is_array($definition['sub_options'] ?? null) ? $definition['sub_options'] : [];
        $normalizedText = $this->normalizeSpecToken($text);

        if ($normalizedText !== '') {
            foreach ($subOptions as $sub) {
                $candidates = array_filter([
                    $this->normalizeSpecToken((string) ($sub['id'] ?? '')),
                    $this->normalizeSpecToken((string) ($sub['value'] ?? '')),
                    $this->normalizeSpecToken((string) ($sub['label'] ?? '')),
                ], fn (string $candidate) => $candidate !== '');

                if (in_array($normalizedText, $candidates, true)) {
                    return (string) ($sub['label'] ?: $text);
                }
            }

            if ($text === '0' && $field === 'system_disk_size') {
                $inferred = $this->inferSpecValueFromDefinition($field, $definition);
                if ($inferred !== null) {
                    return $inferred;
                }
            }

            if (is_numeric($text)) {
                return $this->shouldFormatNumericSpecValue($field, $definition)
                    ? $this->formatNumericSpecValue($field, $text, $definition)
                    : $text;
            }

            return $text;
        }

        return $this->inferSpecValueFromDefinition($field, $definition);
    }

    private function inferSpecValueFromDefinition(string $field, ?array $definition): ?string
    {
        if (! is_array($definition)) {
            return null;
        }

        $subOptions = is_array($definition['sub_options'] ?? null) ? $definition['sub_options'] : [];
        if ($field === 'system_disk_size' && $subOptions !== [] && trim((string) ($definition['label'] ?? '')) === '系统盘') {
            return trim((string) ($subOptions[0]['label'] ?? $subOptions[0]['value'] ?? '')) ?: null;
        }

        $optionType = (int) ($definition['option_type'] ?? 0);
        if (count($subOptions) === 1 && ! in_array($optionType, self::SPEC_RANGE_OPTION_TYPES, true)) {
            return trim((string) ($subOptions[0]['label'] ?? $subOptions[0]['value'] ?? '')) ?: null;
        }

        return null;
    }

    private function shouldFormatNumericSpecValue(string $field, ?array $definition): bool
    {
        $subOptions = is_array($definition['sub_options'] ?? null) ? $definition['sub_options'] : [];
        $optionType = (int) ($definition['option_type'] ?? 0);

        if ($subOptions !== [] && ! in_array($optionType, self::SPEC_RANGE_OPTION_TYPES, true)) {
            return false;
        }

        return in_array($field, ['cpu', 'memory', 'system_disk_size', 'data_disk_size', 'bw', 'in_bw', 'out_bw'], true);
    }

    private function formatNumericSpecValue(string $field, string $text, ?array $definition): string
    {
        $value = str_contains($text, '.')
            ? rtrim(rtrim(number_format((float) $text, 2, '.', ''), '0'), '.')
            : (string) ((int) $text);
        $unit = trim((string) ($definition['unit'] ?? ''));

        return match ($field) {
            'cpu' => $value.'核',
            'memory' => $this->formatMemorySpecValue((int) round((float) $text)),
            'system_disk_size', 'data_disk_size' => $value.'G',
            'bw', 'in_bw', 'out_bw' => $value.'Mbps',
            default => $unit !== '' ? $value.$unit : $value,
        };
    }

    private function formatMemorySpecValue(int $value): string
    {
        if ($value <= 0) {
            return '0';
        }

        if ($value < 1024) {
            return $value.'M';
        }

        if ($value % 1024 === 0) {
            return ((string) ($value / 1024)).'G';
        }

        return $value.'M';
    }

    private function resolveSpecDefinitionField(array $item): string
    {
        $label = trim((string) ($item['name'] ?? $item['option_name'] ?? ''));
        $field = (string) ($item['field'] ?? $item['spec_key'] ?? '');

        return $this->normalizeSpecField($field, $label);
    }

    private function resolveSpecSubLabel(array $sub): string
    {
        foreach (['version', 'option_name', 'option_name_first', 'id'] as $key) {
            $value = trim((string) ($sub[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function shouldSkipSpecField(string $field): bool
    {
        return in_array($field, ['hostname', 'password', 'os_group', 'os_sub_id', 'data_disk', 'network_type'], true);
    }

    private function normalizeSpecField(string $field, string $label = ''): string
    {
        $normalizedField = mb_strtolower(trim($field));
        $normalizedField = str_replace([' ', '-'], '_', $normalizedField);
        $normalizedField = preg_replace('/_+/u', '_', $normalizedField) ?? $normalizedField;
        $normalizedLabel = mb_strtolower(trim($label));

        if ($normalizedField !== '') {
            return match ($normalizedField) {
                'region' => 'area',
                'ip', 'ipv4', 'ipv4_num' => 'ip_num',
                'bandwidth' => 'bw',
                'flow', 'traffic' => 'flow_limit',
                default => $normalizedField,
            };
        }

        if (str_contains($normalizedLabel, 'ipv6')) {
            return 'ipv6_num';
        }

        if (str_contains($normalizedLabel, 'ipv4')) {
            return 'ip_num';
        }

        if (str_contains($label, '数据盘')) {
            return 'data_disk_size';
        }

        if (str_contains($label, '系统盘')) {
            return 'system_disk_size';
        }

        if (str_contains($label, '下行带宽')) {
            return 'in_bw';
        }

        if (str_contains($label, '上行带宽')) {
            return 'out_bw';
        }

        if (str_contains($label, '带宽')) {
            return 'bw';
        }

        if (str_contains($label, '流量')) {
            return 'flow_limit';
        }

        if (str_contains($label, '区域') || str_contains($label, '地区') || str_contains($label, '机房') || str_contains($label, '数据中心') || str_contains($label, '地域')) {
            return 'area';
        }

        if (str_contains($label, '操作系统')) {
            return 'os';
        }

        if (str_contains($normalizedLabel, 'cpu') || str_contains($label, '核心')) {
            return 'cpu';
        }

        if (str_contains($label, '内存') || str_contains($normalizedLabel, 'ram')) {
            return 'memory';
        }

        if (str_contains($label, 'ip数量')) {
            return 'ip_num';
        }

        return '';
    }

    private function resolveFallbackSpecLabel(string $field, array $configOption = []): string
    {
        $rawLabel = trim((string) ($configOption['name'] ?? $configOption['option_name'] ?? ''));
        if ($rawLabel !== '') {
            return $rawLabel;
        }

        return match ($field) {
            'cpu' => 'CPU',
            'memory' => '内存',
            'area' => '区域',
            'region' => '区域',
            'node' => '节点',
            'node_group' => '节点分组',
            'os' => '操作系统',
            'system_disk_size' => '系统盘',
            'data_disk_size' => '数据盘',
            'disk' => '磁盘',
            'bandwidth', 'bw' => '带宽',
            'in_bw' => '下行带宽',
            'out_bw' => '上行带宽',
            'flow_limit' => '流量',
            'flow_way' => '流量方向',
            'ip', 'ip_num' => 'IP数量',
            'ipv6_num' => 'IPv6数量',
            'port' => '端口',
            'type' => '云节点类型',
            'advanced_cpu' => '智能CPU',
            'advanced_bw' => '智能带宽',
            'qty' => '数量',
            'period' => '周期',
            default => $field,
        };
    }

    private function normalizeSpecToken(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    // ── Connection/password helpers ────────────────────────────────────────

    private function resolveConnectionPassword(array $host, array $cachedConnection): string
    {
        $remotePassword = trim((string) ($host['password'] ?? ''));

        if ($this->isMaskedRemotePassword($remotePassword)) {
            return trim((string) ($cachedConnection['password'] ?? ''));
        }

        return $remotePassword !== ''
            ? $remotePassword
            : trim((string) ($cachedConnection['password'] ?? ''));
    }

    private function isMaskedRemotePassword(string $password): bool
    {
        if ($password === '') {
            return false;
        }

        return preg_match('/^[*]+$/', $password) === 1
            || in_array(mb_strtolower($password), ['hidden', 'masked', 'secret'], true);
    }

    // ── Operation log helpers ──────────────────────────────────────────────

    private function resolveOperationCategoryByAction(string $action): string
    {
        if (str_starts_with($action, 'service.console.power.')) {
            return 'power';
        }

        return match ($action) {
            'service.console.password.reset' => 'password',
            'service.console.reinstall.submit' => 'reinstall',
            'service.console.renew.order.create', 'service.console.renew.auto_update' => 'renew',
            'service.console.traffic_package.order.create', 'service.console.traffic_package.purchase' => 'upgrade',
            'service.console.nat_forwarding.create', 'service.console.nat_forwarding.delete' => 'nat_forwarding',
            'service.console.security_group.create', 'service.console.security_group.apply', 'service.console.security_group.delete' => 'security_group',
            'service.console.security_rule.create', 'service.console.security_rule.delete' => 'security_rule',
            'order.service.manual_create' => 'service',
            default => 'service',
        };
    }

    private function resolveOperationSummary(string $action, array $detail, string $actionLabel): string
    {
        return match ($action) {
            'order.service.manual_create' => '管理员手动创建服务',
            'service.console.name.update' => trim((string) ($detail['service_name'] ?? '')) !== ''
                ? '实例名称已更新为'.(string) $detail['service_name']
                : '已清空实例名称',
            'service.console.hostname.update' => trim((string) ($detail['hostname'] ?? '')) !== ''
                ? '自定义主机名已设置为'.(string) $detail['hostname']
                : '已清空自定义主机名',
            'service.console.meta.update' => '管理员已更新实例业务信息',
            'service.console.renew.auto_update' => trim((string) ($detail['auto_renew_label'] ?? '')) !== ''
                ? '自动续费已切换为'.(string) $detail['auto_renew_label']
                : $actionLabel,
            default => $actionLabel,
        };
    }

    private function resolveOperationActorLabel(string $actorType): string
    {
        return match (trim($actorType)) {
            'admin' => '管理员',
            'system' => '系统',
            default => '客户',
        };
    }

    private function resolveOperationActorName(array $detail, string $actorType): string
    {
        $actorName = trim((string) ($detail['actor_name'] ?? $detail['operator_name'] ?? ''));
        if ($actorName !== '') {
            return $actorName;
        }

        return match (trim($actorType)) {
            'admin' => '管理员',
            'system' => '系统任务',
            default => '当前用户',
        };
    }

    private function buildOperationLogDetailItems(array $detail, string $actionLabel, string $summary): array
    {
        $items = [];

        $this->pushDetailItem($items, '执行说明', $summary);
        $this->pushDetailItem($items, '操作类型', $actionLabel);
        $this->pushDetailItem($items, '实例名称', $detail['service_name'] ?? null);
        $this->pushDetailItem($items, '配置名称', $detail['product_display_name'] ?? $detail['product_name'] ?? null);
        $this->pushDetailItem($items, '上游实例 ID', $detail['host_id'] ?? null);
        $this->pushDetailItem($items, '账单号', $detail['invoice_no'] ?? null);
        $this->pushDetailItem($items, '主机名', $detail['hostname'] ?? null);
        $this->pushDetailItem($items, '原主机名', $detail['previous_hostname'] ?? null);
        $this->pushDetailItem($items, '续费周期', isset($detail['billing_cycle']) ? $this->resolveBillingCycleLabel((string) $detail['billing_cycle']) : ($detail['billing_cycle_label'] ?? null));
        $this->pushDetailItem($items, '金额', isset($detail['amount']) ? ('￥'.number_format((float) $detail['amount'], 2, '.', '')) : null);
        $this->pushDetailItem($items, '自动续费', array_key_exists('auto_renew', $detail) ? ((int) $detail['auto_renew'] === 1 ? '已开启' : '已关闭') : ($detail['auto_renew_label'] ?? null));
        $this->pushDetailItem($items, '流量包', $detail['traffic_label'] ?? null);
        $this->pushDetailItem($items, '转发名称', $detail['forwarding_name'] ?? null);
        $this->pushDetailItem($items, '公网端口', $detail['external_port'] ?? null);
        $this->pushDetailItem($items, '内部端口', $detail['internal_port'] ?? null);
        $this->pushDetailItem($items, '协议', $detail['protocol_label'] ?? ($detail['protocol'] ?? null));
        $this->pushDetailItem($items, '安全组 ID', $detail['group_id'] ?? null);
        $this->pushDetailItem($items, '安全组名称', $detail['group_name'] ?? null);
        $this->pushDetailItem($items, '规则 ID', $detail['rule_id'] ?? null);
        $this->pushDetailItem($items, '方向', $detail['direction_label'] ?? ($detail['direction'] ?? null));
        $this->pushDetailItem($items, '端口范围', $detail['port'] ?? null);
        $this->pushDetailItem($items, '来源地址', $detail['ip'] ?? null);
        $this->pushDetailItem($items, '备注', $detail['description'] ?? null);
        $this->pushDetailItem($items, '请求追踪', $detail['trace_id'] ?? null);
        $this->pushDetailItem($items, '操作消息', $detail['message'] ?? null);

        return $items;
    }

    private function pushDetailItem(array &$items, string $label, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $text = is_bool($value)
            ? ($value ? '是' : '否')
            : (is_scalar($value) ? trim((string) $value) : '');

        if ($text === '') {
            return;
        }

        $items[] = ['label' => $label, 'value' => $text];
    }

    private function formatTrafficAmount(float|int $value): string
    {
        $normalized = round((float) $value, 2);

        if ($normalized <= 0) {
            return '0G';
        }

        if ($normalized >= 1024) {
            return $this->trimTrafficNumber($normalized / 1024).'TB';
        }

        return $this->trimTrafficNumber($normalized).'G';
    }

    private function trimTrafficNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function resolveServiceProductPath(Service $service, string $productDisplayName): string
    {
        $service->loadMissing([
            'product.productGroup.secondProductGroup.firstProductGroup',
        ]);
        $leafGroup = $service->product?->productGroup;
        $rootGroup = $this->resolverService->resolveServiceRootGroup($service);
        $clean = [];
        foreach ([
            trim((string) ($rootGroup?->name ?? '')),
            trim((string) ($leafGroup?->secondProductGroup?->name ?? '')),
            trim((string) ($leafGroup?->name ?? '')),
            trim((string) $productDisplayName),
        ] as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '' || in_array($segment, $clean, true)) {
                continue;
            }
            $clean[] = $segment;
        }

        return $clean !== [] ? implode('/', $clean) : $productDisplayName;
    }

    private function resolveProductDisplayName(Service $service): string
    {
        $orderDisplayName = trim((string) ($service->order?->display_product_name ?? ''));
        if ($orderDisplayName !== '' && $orderDisplayName !== '未配置规格') {
            return $orderDisplayName;
        }

        if ($service->product instanceof Product) {
            $resolver = $this->productDisplayNameResolver ?? new ProductDisplayNameResolver;
            $resolved = $resolver->resolveForProduct(
                $service->product,
                (array) ($service->order?->config_snapshot ?? [])
            );

            return trim((string) ($resolved['product_display_name'] ?? ''));
        }

        return '';
    }

    private function resolveCombinedDisplayName(Service $service): string
    {
        if ($service->product instanceof Product) {
            $resolver = $this->productDisplayNameResolver ?? new ProductDisplayNameResolver;
            $resolved = $resolver->resolveForProduct(
                $service->product,
                (array) ($service->order?->config_snapshot ?? [])
            );

            return trim((string) ($resolved['combined_display_name'] ?? ''));
        }

        return '';
    }
}
