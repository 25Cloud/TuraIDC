<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\OperationLog;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\ServiceUpstreamBindingWriter;
use App\Services\Integrations\Support\ProviderErrorMapper;
use App\Services\System\OperationLogService;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\ProviderResolver;
use App\Services\Upstream\Support\WebSessionCookieParser;
use App\Support\SensitiveDataSanitizer;
use App\Support\ServiceHostname;
use App\Support\TextSanitizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 服务详情子服务
 * 负责：getServiceConfigForUser、getDetailForUser、getBaseDetailForUser、
 *       getRemoteStatusPatchForUser、getOperationLogsForUser、updateRemarkForUser
 *       以及 syncServiceFromRemote、fetchRemoteState 等远程同步方法
 */
class ServiceDetailService
{
    private const DETAIL_REMOTE_SNAPSHOT_TTL_SECONDS = 120; // 2分钟：远程快照

    private const DETAIL_RESPONSE_CACHE_TTL_SECONDS = 30; // 30秒：服务详情请求频繁，降低后端压力

    private const REMOTE_STATUS_CACHE_TTL_SECONDS = 30; // 30秒：远程状态

    private const SERVICE_CONFIG_CACHE_TTL_SECONDS = 120; // 2分钟：服务配置

    private const PRODUCT_CONFIG_OPTIONS_CACHE_TTL_SECONDS = 604800; // 1周：产品配置选项 rarely change

    private const MONITOR_MODULE_CACHE_TTL_SECONDS = 600; // 10分钟：监控模块

    private readonly PluginBindingResolver $bindingResolver;

    private ?ServiceUpstreamBindingWriter $serviceBindingWriter = null;

    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly OperationLogService $operationLogService,
        private readonly ServiceResolverService $resolverService,
        private readonly ServiceTransformService $transformService,
        ?PluginBindingResolver $bindingResolver = null,
        private readonly ?WebSessionCookieParser $webSessionCookieParser = null,
    ) {
        $this->bindingResolver = $bindingResolver ?? new PluginBindingResolver;
    }

    // ── Public API ─────────────────────────────────────────────────────────

    public function getServiceConfigForUser(User $user, int $serviceId): array
    {
        $service = $this->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
        ]);

        $cacheKey = $this->buildServiceConfigCacheKey($service);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $provisionData = $this->serviceProvisionData($service);
        $catalogProductType = $this->resolverService->resolveGroupedOverviewTypeValue($service);
        $consoleMode = $this->resolverService->resolveConsoleMode($service, $provisionData);

        $payload = [
            'id' => $service->id,
            'name' => $service->name,
            'status' => (int) $service->status,
            'status_label' => ServiceStatus::$labels[$service->status] ?? (string) $service->status,
            'product_type' => $catalogProductType,
            'product_type_label' => ProductType::businessLabelOf($catalogProductType),
            'machine_category' => $this->transformService->resolveMachineCategory($service, $catalogProductType, $consoleMode),
            'console_mode' => $consoleMode,
            'is_nat_console' => $consoleMode === 'nat',
            'expires_at' => $service->expires_at?->format('Y-m-d H:i:s'),
        ];

        Cache::put($cacheKey, $payload, now()->addSeconds(self::SERVICE_CONFIG_CACHE_TTL_SECONDS));

        return $payload;
    }

    public function getDetailForUser(User $user, int $serviceId, bool $refreshRemote = false): array
    {
        $service = $this->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,pricing,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
            'order:id,order_no,status,paid_at,created_at',
            'order.invoice:id,order_id,invoice_no',
            'invoice:id,invoice_no,status,paid_at,order_id',
            'invoice.order:id,order_no',
        ]);

        $needsRemoteRefresh = $refreshRemote
            || $this->needsRemoteSnapshotRefresh($service)
            || $this->needsConnectionHydration($service)
            || $this->needsRuntimeStatusRefresh($service)
            || $this->needsNatRemoteHydration($service);

        if (! $needsRemoteRefresh) {
            $cacheKey = $this->buildDetailResponseCacheKey($service);
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
        }

        $remote = null;
        $remoteError = '';

        if ($needsRemoteRefresh && $this->transformService->canManageService($service)) {
            try {
                $remote = $this->fetchRemoteState($service);
                if (! empty($remote['host']) || ! empty($remote['runtime']) || ! empty($remote['nat'])) {
                    $this->syncServiceFromRemote($service, $remote['host'] ?? [], $remote['runtime'] ?? [], $remote['nat'] ?? []);
                    $service->refresh()->loadMissing([
                        'product:id,product_type,service_type_code,product_group_id,config_options,pricing,purchase_requires',
                        'product.productGroup.secondProductGroup.firstProductGroup',
                        'product.supplier',
                        'order:id,order_no,status,paid_at,created_at',
                        'order.invoice:id,order_id,invoice_no',
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::warning('[服务详情] 远程状态刷新失败', [
                    'service_id' => (int) $service->id,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);
                $remoteError = $exception->getMessage();
            }
        }

        $detail = $this->transformService->transformDetail($service, $remote, $remoteError);

        if ($remoteError === '') {
            Cache::put(
                $this->buildDetailResponseCacheKey($service),
                $detail,
                now()->addSeconds(self::DETAIL_RESPONSE_CACHE_TTL_SECONDS)
            );
        }

        return $detail;
    }

    public function getBaseDetailForUser(User $user, int $serviceId): array
    {
        $service = $this->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,pricing,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
            'order:id,order_no,status,paid_at,created_at',
            'order.invoice:id,order_id,invoice_no',
            'invoice:id,invoice_no,status,paid_at,order_id',
            'invoice.order:id,order_no',
        ]);

        $cacheKey = $this->buildDetailResponseCacheKey($service);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $detail = $this->transformService->transformDetail($service);
        Cache::put($cacheKey, $detail, now()->addSeconds(self::DETAIL_RESPONSE_CACHE_TTL_SECONDS));

        return $detail;
    }

    public function getRemoteStatusPatchForUser(User $user, int $serviceId): array
    {
        $service = $this->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,pricing,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
            'order:id,order_no,status,paid_at,created_at',
            'order.invoice:id,order_id,invoice_no',
            'invoice:id,invoice_no,status,paid_at',
        ]);

        $remoteStatusCacheKey = $this->buildRemoteStatusCacheKey($service);
        $cached = Cache::get($remoteStatusCacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $remote = null;
        $remoteError = '';

        if ($this->transformService->canManageService($service)) {
            try {
                $remote = $this->fetchRemoteState($service);
                if (! empty($remote['host']) || ! empty($remote['runtime']) || ! empty($remote['nat'])) {
                    $this->syncServiceFromRemote($service, $remote['host'] ?? [], $remote['runtime'] ?? [], $remote['nat'] ?? []);
                    $service->refresh()->loadMissing([
                        'product:id,product_type,service_type_code,product_group_id,config_options,pricing,purchase_requires',
                        'product.productGroup.secondProductGroup.firstProductGroup',
                        'product.supplier',
                        'order:id,order_no,status,paid_at,created_at',
                        'order.invoice:id,order_id,invoice_no',
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::warning('[服务详情] 远程状态补丁刷新失败', [
                    'service_id' => (int) $service->id,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);
                $remoteError = $exception->getMessage();
            }
        }

        $detail = $this->transformService->transformDetail($service, $remote, $remoteError);

        $result = [
            'id' => (int) ($detail['id'] ?? 0),
            'name' => (string) ($detail['name'] ?? ''),
            'domain' => (string) ($detail['domain'] ?? ''),
            'status' => (int) ($detail['status'] ?? 0),
            'status_label' => (string) ($detail['status_label'] ?? ''),
            'status_tone' => (string) ($detail['status_tone'] ?? 'info'),
            'expires_at' => (string) ($detail['expires_at'] ?? ''),
            'created_at' => (string) ($detail['created_at'] ?? ''),
            'upstream' => is_array($detail['upstream'] ?? null) ? $detail['upstream'] : [],
            'runtime' => is_array($detail['runtime'] ?? null) ? $detail['runtime'] : [],
            'connection' => is_array($detail['connection'] ?? null) ? $detail['connection'] : [],
            'specs' => is_array($detail['specs'] ?? null) ? $detail['specs'] : [],
            'traffic' => is_array($detail['traffic'] ?? null) ? $detail['traffic'] : [],
            'actions' => is_array($detail['actions'] ?? null) ? $detail['actions'] : [],
        ];

        if ($remoteError === '') {
            Cache::put($remoteStatusCacheKey, $result, now()->addSeconds(self::REMOTE_STATUS_CACHE_TTL_SECONDS));
        }

        return $result;
    }

    private function buildRemoteStatusCacheKey(Service $service): string
    {
        return 'service_console:remote_status:'.$service->id.':'.$service->user_id.':'.optional($service->updated_at)?->timestamp;
    }

    public function getOperationLogsForUser(User $user, int $serviceId, array $filters = [], int $perPage = 10): array
    {
        $service = $this->findUserService($user, $serviceId);
        $query = OperationLog::query()
            ->where(function (Builder $builder) use ($serviceId) {
                $builder->where(function (Builder $serviceBuilder) use ($serviceId) {
                    $serviceBuilder->where('module', 'service')->where('subject_id', $serviceId);
                })->orWhere('context->service_id', $serviceId);
            })
            ->where(function (Builder $builder) {
                $builder->where('context->actor_type', '!=', 'admin')
                    ->orWhereNull('context->actor_type');
            });

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('action', 'like', '%'.$keyword.'%')
                    ->orWhere('context->summary', 'like', '%'.$keyword.'%')
                    ->orWhere('context->actor_name', 'like', '%'.$keyword.'%')
                    ->orWhere('context->operator_name', 'like', '%'.$keyword.'%')
                    ->orWhere('context->service_name', 'like', '%'.$keyword.'%')
                    ->orWhere('context->group_name', 'like', '%'.$keyword.'%')
                    ->orWhere('context->forwarding_name', 'like', '%'.$keyword.'%');
            });
        }

        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            $query->where(function (Builder $builder) use ($category) {
                $builder->where('context->category', $category);
                if ($category === 'service') {
                    $builder->orWhere('action', 'order.service.manual_create');
                }
            });
        }

        $latestLog = (clone $query)->orderByDesc('id')->first(['id', 'created_at']);
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        return [
            'list' => collect($paginator->items())
                ->map(fn (OperationLog $log) => $this->transformService->transformServiceOperationLog($log))
                ->values()->all(),
            'summary' => [
                'total' => $paginator->total(),
                'today_total' => (clone $query)->where('created_at', '>=', now()->startOfDay())->count(),
                'latest_created_at' => $latestLog?->created_at?->format('Y-m-d H:i:s'),
                'service_name' => (string) $service->name,
            ],
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    public function updateRemarkForUser(User $user, int $serviceId, ?string $remark, array $context = []): array
    {
        $service = $this->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'order:id,order_no,status,paid_at',
            'invoice:id,invoice_no,status,paid_at',
        ]);

        $cleanRemark = TextSanitizer::clean($remark);
        $provisionData = $this->serviceProvisionData($service);
        $provisionData['client_remark'] = $cleanRemark;

        $service->forceFill(['provision_data' => $provisionData])->save();
        $this->serviceBindingWriter()->syncServiceState($service, null, $provisionData);

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.remark.update', [
            'category' => 'service',
            'summary' => $cleanRemark !== '' ? '更新服务备注' : '清空服务备注',
            'remark' => $cleanRemark,
        ], $context);

        return $this->transformService->transformListItem($service);
    }

    public function updateServiceNameForUser(User $user, int $serviceId, ?string $serviceName, array $context = []): array
    {
        $service = $this->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'order:id,order_no,status,paid_at',
            'invoice:id,invoice_no,status,paid_at',
        ]);

        $cleanServiceName = mb_substr(TextSanitizer::clean($serviceName), 0, 120);
        $provisionData = $this->serviceProvisionData($service);
        $provisionData = ServiceHostname::rememberDefaultServiceName($provisionData, (string) ($service->name ?? ''));
        $provisionData = ServiceHostname::writeCustomServiceName($provisionData, $cleanServiceName);

        $service->forceFill([
            'name' => ServiceHostname::resolveInstanceName($service, $provisionData),
            'provision_data' => $provisionData,
        ])->save();

        $this->serviceBindingWriter()->syncServiceState($service, null, $provisionData);

        $this->forgetDetailCaches($service);

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.name.update', [
            'category' => 'service',
            'summary' => $cleanServiceName !== '' ? '更新实例名称' : '清空实例名称',
            'service_name' => (string) $service->name,
        ], $context);

        return $this->transformService->transformListItem($service->fresh([
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'order:id,order_no,status,paid_at',
            'invoice:id,invoice_no,status,paid_at',
        ]));
    }

    // ── Remote sync (shared with power/vnc sub-services via this class) ───

    public function syncServiceFromRemote(Service $service, array $host, array $runtime = [], array $nat = []): void
    {
        if ($host === [] && $runtime === [] && $nat === []) {
            return;
        }

        $currentProvisionData = $this->serviceProvisionData($service, includeSecrets: true);
        $cachedConnection = $this->transformService->readCachedConnection($currentProvisionData);
        $natRemote = $this->transformService->resolveNatRemoteSnapshot($currentProvisionData, $nat);
        $normalizedHostStatus = strtolower(trim((string) ($host['domainstatus'] ?? '')));
        $shouldResetRuntimeSnapshot = $host !== []
            && $normalizedHostStatus !== ''
            && $normalizedHostStatus !== 'active';

        $mergedConnection = [
            'hostname' => ServiceHostname::resolveConnectionHostname($service, $currentProvisionData, $cachedConnection, $host),
            'username' => trim((string) ($host['username'] ?? ($cachedConnection['username'] ?? ''))),
            'password' => $this->resolveConnectionPasswordFromHost($host, $cachedConnection),
            'port' => (int) (($host['port'] ?? ($cachedConnection['port'] ?? 0)) ?: 0),
            'internal_ip' => trim((string) ($host['internalip'] ?? $host['privateip'] ?? ($cachedConnection['internal_ip'] ?? ''))),
        ];

        $provisionData = array_merge($currentProvisionData, [
            'provision_error' => null,
            'upstream_status' => (string) ($host['domainstatus'] ?? ($currentProvisionData['upstream_status'] ?? '')),
            'upstream_product_id' => (int) (($host['product_id'] ?? ($currentProvisionData['upstream_product_id'] ?? 0)) ?: 0),
            'upstream_product_name' => trim((string) ($host['product_name'] ?? ($currentProvisionData['upstream_product_name'] ?? ''))),
            'dedicated_ip' => (string) ($host['dedicatedip'] ?? ($currentProvisionData['dedicated_ip'] ?? '')),
            'assigned_ips' => is_array($host['assignedips'] ?? null) ? $host['assignedips'] : (array) ($currentProvisionData['assigned_ips'] ?? []),
            'host_config_option' => is_array($host['config_option'] ?? null) ? $host['config_option'] : (array) ($currentProvisionData['host_config_option'] ?? []),
            'bw_usage' => is_numeric($host['bwusage'] ?? null)
                ? number_format((float) $host['bwusage'], 2, '.', '')
                : (string) ($currentProvisionData['bw_usage'] ?? ''),
            'bw_limit' => is_numeric($host['bwlimit'] ?? null)
                ? (int) $host['bwlimit']
                : (int) ($currentProvisionData['bw_limit'] ?? 0),
            'os' => (string) ($host['os'] ?? ($currentProvisionData['os'] ?? '')),
            'runtime_status' => array_key_exists('status', $runtime)
                ? (string) ($runtime['status'] ?? '')
                : ($shouldResetRuntimeSnapshot ? '' : (string) ($currentProvisionData['runtime_status'] ?? '')),
            'runtime_description' => array_key_exists('des', $runtime)
                ? (string) ($runtime['des'] ?? '')
                : ($shouldResetRuntimeSnapshot ? '' : (string) ($currentProvisionData['runtime_description'] ?? '')),
            'nat_remote_address' => $natRemote['remote_address'],
            'nat_remote_host' => $natRemote['host'],
            'nat_remote_port' => $natRemote['port'],
            'nat_remote_checked_at' => $natRemote['checked_at'],
            'connection_cached_hostname' => $mergedConnection['hostname'],
            'connection_secret' => $this->transformService->writeCachedConnection($mergedConnection),
            'connection_cached_at' => now()->format('Y-m-d H:i:s'),
            'last_synced_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $resolvedStatus = $host !== []
            ? $this->transformService->resolveServiceStatusFromUpstream((string) ($host['domainstatus'] ?? ''))
            : (int) $service->status;

        $service->forceFill([
            'name' => ServiceHostname::resolveInstanceName($service, $provisionData, $host),
            'domain' => trim((string) ($host['domain'] ?? $service->domain)),
            'status' => $resolvedStatus,
            'expires_at' => $host !== []
                ? $this->transformService->resolveExpiry($host, $service)
                : $service->expires_at,
            'suspended_reason' => $this->resolveConsoleSyncedSuspendedReason($service, $resolvedStatus),
            'provision_data' => $provisionData,
        ])->save();
        $this->serviceBindingWriter()->syncServiceState($service, null, $provisionData);
    }

    private function resolveConsoleSyncedSuspendedReason(Service $service, int $resolvedStatus): ?string
    {
        $localReason = trim((string) ($service->suspended_reason ?? ''));

        if ((int) $service->status === ServiceStatus::SUSPENDED
            && $localReason !== ''
            && in_array($resolvedStatus, [ServiceStatus::ACTIVE, ServiceStatus::PENDING, ServiceStatus::SUSPENDED], true)) {
            return $service->suspended_reason;
        }

        return $resolvedStatus === ServiceStatus::SUSPENDED
            && (int) $service->status === ServiceStatus::SUSPENDED
            ? $service->suspended_reason
            : null;
    }

    public function fetchRemoteState(Service $service, ?Supplier $supplier = null, ?string $jwt = null): array
    {
        $runtime = null;

        if (! $supplier) {
            [$supplier, $hostId] = $this->resolveManagedSupplierAndHost($service);
            $runtime = $this->resolveRuntimeCapabilityForSupplier($supplier);
        } else {
            $provisionData = $this->serviceProvisionData($service);
            $hostId = (int) (($provisionData['upstream_host_id'] ?? 0) ?: 0);
            $runtime = $this->resolveRuntimeCapabilityForSupplier($supplier);
        }

        $detailPayload = [];
        $resolvedJwt = trim((string) $jwt);

        try {
            if ($resolvedJwt === '') {
                $resolvedJwt = $runtime->login($supplier);
            }

            $detailResponse = is_callable([$runtime, 'getHostDetail'])
                ? $runtime->getHostDetail($supplier, $hostId, $resolvedJwt)
                : $runtime->get($supplier, "/v1/hosts/{$hostId}", $resolvedJwt);
            $this->assertSuccess($detailResponse, '读取主机详情', $this->providerKeyForSupplier($supplier));
            $detailPayload = $this->extractPayload($detailResponse);
        } catch (\Throwable $exception) {
            Log::info('[实例详情] 上游 API 详情不可用，尝试网页登录详情接口', [
                'service_id' => (int) $service->id,
                'supplier_id' => (int) $supplier->id,
                'host_id' => $hostId,
                'message' => $exception->getMessage(),
            ]);

            try {
                $detailPayload = $this->fetchWebServiceDetailPayload($runtime, $supplier, $hostId);
            } catch (\Throwable $fallbackException) {
                Log::warning('[实例详情] 网页详情回退失败', [
                    'service_id' => (int) $service->id,
                    'supplier_id' => (int) $supplier->id,
                    'host_id' => $hostId,
                    'message' => $fallbackException->getMessage(),
                ]);
                $detailPayload = [];
            }
        }
        $statusPayload = [];

        try {
            if ($resolvedJwt !== '') {
                $hostDomainstatus = strtolower(trim((string) ($detailPayload['host']['domainstatus'] ?? '')));
                if (! in_array($hostDomainstatus, ['suspended', 'cancelled', 'deleted'], true)) {
                    $statusPayload = $this->fetchModuleStatusPayload($supplier, $hostId, $resolvedJwt, 'host');
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('[实例详情] 上游 runtime 读取失败', [
                'service_id' => $service->id,
                'host_id' => $hostId,
                'message' => $exception->getMessage(),
            ]);
        }

        $natPayload = [];

        try {
            $natPayload = $resolvedJwt !== '' ? $this->fetchNatRemoteConnection($supplier, $hostId, $resolvedJwt) : [];
        } catch (\Throwable $exception) {
            Log::info('[实例详情] NAT远程地址获取失败', [
                'service_id' => $service->id,
                'host_id' => $hostId,
                'message' => $exception->getMessage(),
            ]);
        }

        return [
            'host' => is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [],
            'runtime' => is_array($statusPayload) ? $statusPayload : [],
            'nat' => $natPayload,
            'jwt' => $resolvedJwt,
        ];
    }

    private function fetchWebServiceDetailPayload(ProvidesConsoleRuntime $runtime, Supplier $supplier, int $hostId): array
    {
        $headers = $this->buildSupplierWebSessionHeaders($supplier);
        throw_if($headers === [], new BusinessException('供应商 API 登录失败，且未配置网页登录会话 Cookie', 42200));
        $baseUrl = $this->resolveSupplierBaseUrl($supplier);
        throw_if($baseUrl === '', new BusinessException('供应商未配置上游插件 API 地址', 42200));

        $responseText = $runtime->getText(
            $supplier,
            rtrim($baseUrl, '/').'/host/dedicatedserver',
            null,
            ['host_id' => $hostId],
            $headers
        );

        $decoded = json_decode(trim($responseText, "\xEF\xBB\xBF"), true);
        throw_if(! is_array($decoded), new BusinessException('网页登录详情接口返回格式异常', 42200));
        throw_if((int) ($decoded['status'] ?? 0) !== 200, new BusinessException((string) ($decoded['msg'] ?? '网页登录详情接口读取失败'), 42200));

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $host = is_array($data['host_data'] ?? null) ? $data['host_data'] : [];
        if (is_array($data['config_options'] ?? null)) {
            $host['config_option'] = $this->normalizeWebConfigOptions((array) $data['config_options']);
        }

        return ['host' => $host];
    }

    private function normalizeWebConfigOptions(array $items): array
    {
        return collect($items)
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item): array => [
                'id' => (int) ($item['id'] ?? 0),
                'key' => (string) ($item['name_k'] ?? $item['key'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'type' => (int) ($item['option_type'] ?? $item['type'] ?? 0),
                'value' => (string) ($item['sub_name'] ?? $item['value'] ?? ''),
                'code' => (string) ($item['code'] ?? ''),
                'os_group' => (string) ($item['os_group'] ?? ''),
            ])
            ->values()
            ->all();
    }

    public function fetchSupportedModules(Supplier $supplier, int $hostId, string $jwt, bool $fresh = false): array
    {
        $cacheKey = $this->buildMonitorModuleCacheKey($supplier, $hostId);
        $cachedModules = $fresh ? null : Cache::get($cacheKey);
        if (is_array($cachedModules)) {
            return $cachedModules;
        }

        $runtime = $this->resolveRuntimeCapabilityForSupplier($supplier);
        $response = is_callable([$runtime, 'getSupportedModules'])
            ? $runtime->getSupportedModules($supplier, $hostId, $jwt)
            : $runtime->get($supplier, "/v1/hosts/{$hostId}/module", $jwt);
        $this->assertSuccess($response, '读取监控模块', $this->providerKeyForSupplier($supplier));

        $payload = $this->extractPayload($response);

        if ($payload === []) {
            Cache::put($cacheKey, [], now()->addSeconds(self::MONITOR_MODULE_CACHE_TTL_SECONDS));

            return [];
        }

        if (array_is_list($payload)) {
            $modules = array_values(array_filter($payload, 'is_array'));
            Cache::put($cacheKey, $modules, now()->addSeconds(self::MONITOR_MODULE_CACHE_TTL_SECONDS));

            return $modules;
        }

        foreach (['list', 'module', 'data'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $modules = array_values(array_filter($payload[$key], 'is_array'));
                Cache::put($cacheKey, $modules, now()->addSeconds(self::MONITOR_MODULE_CACHE_TTL_SECONDS));

                return $modules;
            }
        }

        Cache::put($cacheKey, [], now()->addSeconds(self::MONITOR_MODULE_CACHE_TTL_SECONDS));

        return [];
    }

    // ── Upstream context helpers ───────────────────────────────────────────

    public function resolveUpstreamContext(Service $service): array
    {
        throw_if(! $this->transformService->canManageService($service), new BusinessException('当前服务未接入可控的上游主机', 42200));

        [$supplier, $hostId] = $this->resolveManagedSupplierAndHost($service);
        $runtime = $this->resolveRuntimeCapabilityForSupplier($supplier);
        $jwt = $runtime->login($supplier);

        return [$runtime, $supplier, $hostId, $jwt];
    }

    public function resolveManagedSupplierAndHost(Service $service): array
    {
        $supplierId = $this->resolveManagedSupplierId($service);

        if ($supplierId > 0) {
            $service->loadMissing('product.supplier');
            $supplier = $this->bindingResolver->supplierForService($service);

            if (! $supplier instanceof Supplier && (int) ($this->bindingResolver->supplierIdForProduct($service->product) ?? 0) === $supplierId) {
                $supplier = $this->bindingResolver->supplierForProduct($service->product);
            }

            if (! $supplier instanceof Supplier) {
                $supplier = $this->findSupplierById($supplierId);
            }

            throw_if(! $supplier instanceof Supplier, new BusinessException('当前服务绑定的供应商配置不存在', 42200));
            $supplier = $this->bindingResolver->supplierWithRuntimeCredentials($supplier);
            throw_if(
                ! $this->providerResolver->resolveForSupplier($supplier)->supports(ProvidesConsoleRuntime::class),
                new BusinessException('当前服务绑定的上游类型不支持实例控制', 42200)
            );

            $hostId = (int) (($this->bindingResolver->upstreamServiceIdForService($service) ?? '') ?: 0);
            throw_if($hostId <= 0, new BusinessException('当前服务未绑定有效的上游主机', 42200));

            return [$supplier, $hostId];
        }

        throw new BusinessException('当前服务未绑定有效的上游供应商', 42200);
    }

    public function findUserService(User $user, int $serviceId, array $relations = []): Service
    {
        $service = Service::query()
            ->with($relations)
            ->where('user_id', $user->id)
            ->find($serviceId);

        throw_if(! $service, new BusinessException('服务不存在', 40400, 404));

        return $service;
    }

    protected function findSupplierById(int $supplierId): ?Supplier
    {
        $supplier = Supplier::query()->find($supplierId);

        return $supplier instanceof Supplier ? $this->bindingResolver->supplierWithRuntimeCredentials($supplier) : null;
    }

    // ── Module status helpers ──────────────────────────────────────────────

    public function assertSuccess(array $response, string $action, string $providerKey = ''): void
    {
        $status = (int) ($response['status'] ?? $response['code'] ?? 0);
        if (in_array($status, [200, 1001], true)) {
            return;
        }

        $message = trim((string) ($response['msg'] ?? $response['message'] ?? ''));
        Log::warning('[服务控制台] 上游返回失败', [
            'action' => $action,
            'status' => $status,
            'message' => SensitiveDataSanitizer::sanitizeText($message),
        ]);

        throw new BusinessException(app(ProviderErrorMapper::class)->toUserMessage($providerKey, $action, $message), 42200);
    }

    public function extractPayload(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    public function extractSecondVerify(array $response): array
    {
        $payload = $this->extractPayload($response);

        return is_array($payload['second_verify'] ?? null) ? $payload['second_verify'] : [];
    }

    public function fetchModuleStatusPayload(Supplier $supplier, int $hostId, string $jwt, string $type): array
    {
        $normalizedType = $this->normalizeModuleStatusType($type);
        $runtime = $this->resolveRuntimeCapabilityForSupplier($supplier);
        $response = is_callable([$runtime, 'getModuleStatus'])
            ? $runtime->getModuleStatus($supplier, $hostId, $normalizedType, $jwt)
            : $runtime->get($supplier, "/v1/hosts/{$hostId}/module/status", $jwt, [
                'type' => $normalizedType,
            ]);
        $this->assertSuccess($response, ClientServiceConsoleService::MODULE_STATUS_TYPES[$normalizedType] ?? '读取状态', $this->providerKeyForSupplier($supplier));

        return $this->extractPayload($response);
    }

    public function normalizeModuleStatusType(string $type): string
    {
        $normalizedType = strtolower(trim($type));
        throw_if(! isset(ClientServiceConsoleService::MODULE_STATUS_TYPES[$normalizedType]), new BusinessException('不支持的状态类型', 42200));

        return $normalizedType;
    }

    public function normalizeModuleStatus(array $payload, string $type): array
    {
        $status = trim((string) ($payload['status'] ?? $payload['state'] ?? $payload['result'] ?? ''));
        $description = trim((string) ($payload['des'] ?? $payload['description'] ?? $payload['msg'] ?? $payload['message'] ?? ''));
        $progress = $this->extractModuleProgress($payload, $status, $description);
        $isFailed = $this->moduleStatusContainsKeyword($status, $description, [
            'fail', 'failed', 'error', 'cancel', '取消', '失败', '错误', '异常',
        ]);
        $isSuccess = $type === 'host'
            ? ($status !== '' || $description !== '')
            : (
                $progress === 100
                || $this->moduleStatusContainsKeyword($status, $description, [
                    'success', 'successful', 'done', 'finish', 'finished', 'complete', 'completed', '成功', '完成', '已完成',
                ])
            );
        $isFinished = $type === 'host'
            ? ($status !== '' || $description !== '')
            : ($isSuccess || $isFailed);

        return [
            'type' => $type,
            'type_label' => ClientServiceConsoleService::MODULE_STATUS_TYPES[$type] ?? '状态',
            'status' => $status,
            'description' => $description,
            'progress' => $progress,
            'is_finished' => $isFinished,
            'is_success' => $isSuccess,
            'is_failed' => $isFailed,
            'raw' => $payload,
        ];
    }

    public function buildPasswordResetPendingStatus(): array
    {
        return [
            'type' => 'repassword',
            'type_label' => '密码重置状态',
            'status' => 'unsupported',
            'description' => '当前上游不提供重置密码进度，请稍后使用新密码登录验证结果。',
            'progress' => null,
            'is_finished' => true,
            'is_success' => true,
            'is_failed' => false,
            'raw' => ['unsupported' => true],
        ];
    }

    public function buildReinstallOptionsCacheKey(Supplier $supplier, int $hostId): string
    {
        return 'upstream:'.$this->providerKeyForSupplier($supplier).":reinstall_options:{$supplier->id}:{$hostId}";
    }

    // ── Cache key helpers ──────────────────────────────────────────────────

    public function buildDetailResponseCacheKey(Service $service): string
    {
        return 'service_console:detail:'.$service->id.':'.$service->user_id.':'.optional($service->updated_at)?->timestamp;
    }

    public function buildServiceConfigCacheKey(Service $service): string
    {
        return 'service_console:config:'.$service->id.':'.$service->user_id.':'.optional($service->updated_at)?->timestamp;
    }

    public function buildMonitorModuleCacheKey(Supplier $supplier, int $hostId): string
    {
        return 'upstream:'.$this->providerKeyForSupplier($supplier).":host_modules:v2:{$supplier->id}:{$hostId}";
    }

    private function providerKeyForSupplier(Supplier $supplier): string
    {
        $providerKey = $this->bindingResolver->providerKeyForSupplier($supplier);

        return trim((string) $providerKey) !== '' ? trim((string) $providerKey) : 'unbound';
    }

    public function forgetDetailCaches(Service $service): void
    {
        Cache::forget($this->buildDetailResponseCacheKey($service));
        Cache::forget($this->buildServiceConfigCacheKey($service));
        Cache::forget($this->buildRemoteStatusCacheKey($service));
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function needsRemoteSnapshotRefresh(Service $service): bool
    {
        $provisionData = $this->serviceProvisionData($service);
        $lastSyncedAt = trim((string) ($provisionData['last_synced_at'] ?? ''));

        if ($lastSyncedAt === '') {
            return true;
        }

        try {
            return Carbon::parse($lastSyncedAt)
                ->addSeconds(self::DETAIL_REMOTE_SNAPSHOT_TTL_SECONDS)
                ->isPast();
        } catch (\Throwable) {
            return true;
        }
    }

    private function needsConnectionHydration(Service $service): bool
    {
        $provisionData = $this->serviceProvisionData($service);
        $hasRemoteSnapshot = trim((string) ($provisionData['last_synced_at'] ?? '')) !== ''
            || trim((string) ($provisionData['connection_cached_at'] ?? '')) !== '';
        $hasConnectionCache = trim((string) ($provisionData['connection_cached_at'] ?? '')) !== '';
        $hasHostConfigOption = is_array($provisionData['host_config_option'] ?? null)
            && (array) ($provisionData['host_config_option'] ?? []) !== [];
        $hasUpstreamProductId = (int) (($provisionData['upstream_product_id'] ?? 0) ?: 0) > 0;

        if ($hasRemoteSnapshot) {
            return false;
        }

        if ($hasConnectionCache && $hasHostConfigOption && $hasUpstreamProductId) {
            return false;
        }

        $cachedConnection = $this->transformService->readCachedConnection($provisionData);

        return ! $hasHostConfigOption
            || ! $hasUpstreamProductId
            || trim((string) ($cachedConnection['username'] ?? '')) === ''
            || trim((string) ($cachedConnection['password'] ?? '')) === '';
    }

    private function needsRuntimeStatusRefresh(Service $service): bool
    {
        $provisionData = $this->serviceProvisionData($service);
        $runtimeStatus = strtolower(trim((string) ($provisionData['runtime_status'] ?? '')));
        $runtimeDescription = trim((string) ($provisionData['runtime_description'] ?? ''));

        if ($runtimeStatus === '' && $runtimeDescription === '') {
            return false;
        }

        if (in_array($runtimeStatus, ['process', 'task', 'starting', 'booting', 'stopping', 'shutting_down', 'reboot', 'rebooting'], true)) {
            return true;
        }

        return preg_match('/处理中|开机中|关机中|重启中/u', $runtimeDescription) === 1;
    }

    /**
     * 从上游 /servicedetail?action=flowpacket 页面 HTML 解析流量包档位列表。
     * 返回格式与 pullPackagesFromUpstreamOption 一致：
     *   [['label' => '1024GB', 'target_value' => 1024, 'price' => '20.00', 'enabled' => 1, 'sort_order' => 1, 'flow_packet_id' => 1], ...]
     * 无法获取或解析时返回空数组。
     */
    public function fetchFlowPacketList(Supplier $supplier, int $hostId, string $jwt = ''): array
    {
        $rootUrl = $this->resolveSupplierRootUrl($supplier);
        $url = $rootUrl.'/servicedetail?'.http_build_query([
            'action' => 'flowpacket',
            'id' => $hostId,
        ]);

        $runtime = $this->resolveRuntimeCapabilityForSupplier($supplier);
        $headers = $this->buildSupplierWebSessionHeaders($supplier);
        $html = $runtime->getText($supplier, $url, trim($jwt) !== '' ? $jwt : null, [], $headers);

        return $this->parseFlowPacketPage($html);
    }

    public function buildSupplierWebSessionHeaders(Supplier $supplier): array
    {
        $cookie = $this->resolveSupplierWebSessionCookie($supplier);

        return $cookie !== '' ? ['Cookie: '.$cookie] : [];
    }

    private function resolveSupplierWebSessionCookie(Supplier $supplier): string
    {
        return $this->webSessionCookieParser()->parse((string) ($supplier->notes ?? ''));
    }

    private function webSessionCookieParser(): WebSessionCookieParser
    {
        return $this->webSessionCookieParser ?? new WebSessionCookieParser;
    }

    private function serviceBindingWriter(): ServiceUpstreamBindingWriter
    {
        return $this->serviceBindingWriter ??= app(ServiceUpstreamBindingWriter::class);
    }

    private function serviceProvisionData(Service $service, bool $includeSecrets = false): array
    {
        $legacy = (array) ($service->provision_data ?? []);
        $projection = $this->bindingResolver->serviceProvisionProjection($service, $includeSecrets);

        return $projection === [] ? $legacy : array_replace($legacy, $projection);
    }

    private function parseFlowPacketPage(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $xpath = $this->createHtmlXPath($html);

        if ($xpath) {
            $packages = $this->parseFlowPacketTableByXPath($xpath);
            if ($packages !== []) {
                return $packages;
            }
        }

        return $this->parseFlowPacketTableByRegex($html);
    }

    private function parseFlowPacketTableByXPath(\DOMXPath $xpath): array
    {
        $rows = $xpath->query("//a[contains(@class, 'buy_flowpacket')]/ancestor::tr");
        if (! $rows || $rows->length === 0) {
            $rows = $xpath->query("//table[contains(@class, 'table')]//tbody//tr");
        }

        if (! $rows || $rows->length === 0) {
            return [];
        }

        $packages = [];
        $sortOrder = 0;

        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);
            if (! $cells || $cells->length < 3) {
                continue;
            }

            $nameText = $this->normalizeHtmlNodeText($cells->item(0));
            $sizeText = $cells->length >= 4 ? $this->normalizeHtmlNodeText($cells->item(1)) : $nameText;
            $priceText = $cells->length >= 4 ? $this->normalizeHtmlNodeText($cells->item(2)) : $this->normalizeHtmlNodeText($cells->item(1));

            $targetValue = $this->extractFlowPacketTargetValue($sizeText !== '' ? $sizeText : $nameText);
            if ($targetValue <= 0) {
                continue;
            }

            $price = $this->extractFlowPacketPrice($priceText);

            $flowPacketId = 0;
            $buyLink = $xpath->query(".//a[contains(@class, 'buy_flowpacket')]", $row);
            if ($buyLink && $buyLink->length > 0) {
                $linkNode = $buyLink->item(0);
                $flowPacketId = $this->extractFlowPacketIdFromAttributes([
                    $linkNode?->getAttribute('data-id') ?: '',
                    $linkNode?->getAttribute('data-flow-packet-id') ?: '',
                    $linkNode?->getAttribute('href') ?: '',
                    $linkNode?->getAttribute('onclick') ?: '',
                ]);
            }

            $sortOrder++;
            $packages[] = [
                'label' => $nameText !== '' ? $nameText : $this->formatFlowPacketLabel($targetValue),
                'target_value' => $targetValue,
                'price' => $price,
                'enabled' => 1,
                'sort_order' => $sortOrder,
                'flow_packet_id' => $flowPacketId,
            ];
        }

        return $packages;
    }

    private function parseFlowPacketTableByRegex(string $html): array
    {
        if (preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/isu', $html, $rows, PREG_SET_ORDER) === 0) {
            return [];
        }

        $packages = [];
        $sortOrder = 0;

        foreach ($rows as $rowMatch) {
            $rowHtml = (string) ($rowMatch[1] ?? '');
            if (preg_match_all('/<td\b[^>]*>(.*?)<\/td>/isu', $rowHtml, $cellMatches) === 0) {
                continue;
            }

            $cells = array_map(
                fn (string $cell): string => trim(strip_tags(html_entity_decode($cell, ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
                $cellMatches[1]
            );

            if (count($cells) < 3) {
                continue;
            }

            $nameText = (string) ($cells[0] ?? '');
            $sizeText = (string) ($cells[1] ?? $nameText);
            $priceText = (string) ($cells[2] ?? '');

            $targetValue = $this->extractFlowPacketTargetValue($sizeText !== '' ? $sizeText : $nameText);
            if ($targetValue <= 0) {
                continue;
            }

            $price = $this->extractFlowPacketPrice($priceText);
            $flowPacketId = $this->extractFlowPacketIdFromAttributes([$rowHtml]);

            $sortOrder++;
            $packages[] = [
                'label' => $nameText !== '' ? $nameText : $this->formatFlowPacketLabel($targetValue),
                'target_value' => $targetValue,
                'price' => $price,
                'enabled' => 1,
                'sort_order' => $sortOrder,
                'flow_packet_id' => $flowPacketId,
            ];
        }

        return $packages;
    }

    private function extractFlowPacketTargetValue(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*T/i', $text, $matches) === 1) {
            return (int) round(((float) $matches[1]) * 1024);
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*G/i', $text, $matches) === 1) {
            return (int) round((float) $matches[1]);
        }

        if (is_numeric($text)) {
            return max((int) round((float) $text), 0);
        }

        return 0;
    }

    private function extractFlowPacketIdFromAttributes(array $values): int
    {
        foreach ($values as $value) {
            $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($text === '') {
                continue;
            }

            if (preg_match('/^\d+$/', trim($text)) === 1) {
                return (int) trim($text);
            }

            foreach ([
                '/(?:data-id|data-flow-packet-id|flow_packet_id|flowpacket_id|packet_id|id)\s*=\s*["\']?(\d+)/iu',
                '/buy_flowpacket\s*\(\s*["\']?(\d+)/iu',
            ] as $pattern) {
                if (preg_match($pattern, $text, $matches) === 1) {
                    return (int) ($matches[1] ?? 0);
                }
            }
        }

        return 0;
    }

    private function extractFlowPacketPrice(string $text): string
    {
        $text = trim($text);

        if (preg_match('/([\d]+(?:\.[\d]+)?)/', $text, $matches) === 1) {
            return number_format(max((float) $matches[1], 0), 2, '.', '');
        }

        return '0.00';
    }

    private function formatFlowPacketLabel(int $targetValue): string
    {
        if ($targetValue >= 1024 && $targetValue % 1024 === 0) {
            return ($targetValue / 1024).'TB';
        }

        return $targetValue.'GB';
    }

    private function needsNatRemoteHydration(Service $service): bool
    {
        $provisionData = $this->serviceProvisionData($service);

        return trim((string) ($provisionData['nat_remote_checked_at'] ?? '')) === '';
    }

    private function fetchNatRemoteConnection(Supplier $supplier, int $hostId, string $jwt): array
    {
        $url = $this->resolveNatRemoteDetailRootUrl($supplier).'/servicedetail?'.http_build_query([
            'action' => 'nat',
            'id' => $hostId,
        ]);
        $runtime = $this->resolveRuntimeCapabilityForSupplier($supplier);
        $html = $runtime->getText($supplier, $url, $jwt);

        return $this->parseNatServiceDetailPage($html);
    }

    public function resolveRuntimeCapabilityForSupplier(Supplier $supplier): object
    {
        return $this->providerResolver
            ->resolveForSupplier($supplier)
            ->require(ProvidesConsoleRuntime::class, '当前供应商不支持实例控制');
    }

    private function resolveManagedSupplierId(Service $service): int
    {
        $supplierId = (int) (($this->bindingResolver->supplierIdForService($service) ?? 0) ?: 0);

        if ($supplierId <= 0 && $service->product instanceof Product) {
            $supplierId = (int) (($this->bindingResolver->supplierIdForProduct($service->product) ?? 0) ?: 0);
        }

        if ($supplierId > 0) {
            return $supplierId;
        }

        return 0;
    }

    private function parseNatServiceDetailPage(string $html): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $remoteAddress = $this->extractNatRemoteAddress($html);
        [$host, $port] = $this->splitNatAclExternalAddress($remoteAddress);

        return [
            'checked' => true,
            'checked_at' => now()->format('Y-m-d H:i:s'),
            'remote_address' => $remoteAddress,
            'host' => $host,
            'port' => (int) ($port !== '' ? $port : 0),
        ];
    }

    private function extractNatRemoteAddress(string $html): string
    {
        $xpath = $this->createHtmlXPath($html);

        if ($xpath) {
            $nodes = $xpath->query("//*[@id='nat_aclBox']");
            if ($nodes && $nodes->length > 0) {
                return $this->normalizeNatRemoteAddressCandidate($this->normalizeHtmlNodeText($nodes->item(0)));
            }

            $valueNodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' remote-address-value ')]");
            if ($valueNodes && $valueNodes->length > 0) {
                foreach ($valueNodes as $valueNode) {
                    $candidate = $this->normalizeNatRemoteAddressCandidate($this->normalizeHtmlNodeText($valueNode));
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }

            $labelNodes = $xpath->query("//label[contains(normalize-space(.), '远程地址')]");
            if ($labelNodes && $labelNodes->length > 0) {
                foreach ($labelNodes as $labelNode) {
                    $candidate = $this->extractNatRemoteAddressFromLabelNode($xpath, $labelNode);
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }
        }

        if (preg_match('/id\s*=\s*[\'"]nat_aclBox[\'"][^>]*>([^<]+)/iu', $html, $matches) === 1) {
            return $this->normalizeNatRemoteAddressCandidate((string) ($matches[1] ?? ''));
        }

        $patterns = [
            '/远程地址\s*[：:]\s*<\/label>\s*<span[^>]*>([^<]+)/iu',
            '/远程地址\s*[：:]\s*<\/[^>]+>\s*<span[^>]*>([^<]+)/iu',
            '/远程地址\s*[：:]\s*([^<]+)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                $candidate = $this->normalizeNatRemoteAddressCandidate((string) ($matches[1] ?? ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function splitNatAclExternalAddress(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['', ''];
        }

        if (preg_match('/^(.*):(\d{1,5})$/', $value, $matches) === 1) {
            return [trim((string) ($matches[1] ?? '')), trim((string) ($matches[2] ?? ''))];
        }

        return [$value, ''];
    }

    public function resolveSupplierRootUrl(Supplier $supplier): string
    {
        $baseUrl = $this->resolveSupplierBaseUrl($supplier);
        $parts = parse_url($baseUrl);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return rtrim($baseUrl, '/');
        }

        $rootUrl = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $rootUrl .= ':'.$parts['port'];
        }

        return $rootUrl;
    }

    private function resolveSupplierBaseUrl(Supplier $supplier): string
    {
        $projection = $this->bindingResolver->supplierBindingProjection($supplier);

        return trim((string) ($projection['base_url'] ?? ''));
    }

    private function resolveNatRemoteDetailRootUrl(Supplier $supplier): string
    {
        $rootUrl = $this->resolveSupplierRootUrl($supplier);
        $parts = parse_url($rootUrl);
        $host = strtolower(trim((string) ($parts['host'] ?? '')));

        if ($host !== '' && str_ends_with($host, 'meidecloud.com')) {
            return 'https://www.meidecloud.com';
        }

        return $rootUrl;
    }

    private function createHtmlXPath(string $html): ?\DOMXPath
    {
        if (trim($html) === '') {
            return null;
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        if (! $loaded) {
            return null;
        }

        return new \DOMXPath($dom);
    }

    private function normalizeHtmlNodeText(\DOMNode $node): string
    {
        return trim(html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function extractNatRemoteAddressFromLabelNode(\DOMXPath $xpath, \DOMNode $labelNode): string
    {
        $siblingNodes = $xpath->query('./following-sibling::*|./following-sibling::text()', $labelNode);

        if ($siblingNodes) {
            foreach ($siblingNodes as $siblingNode) {
                $text = $siblingNode instanceof \DOMText
                    ? trim((string) $siblingNode->nodeValue)
                    : $this->normalizeHtmlNodeText($siblingNode);
                $candidate = $this->normalizeNatRemoteAddressCandidate($text);

                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        $parentNode = $labelNode->parentNode;
        if ($parentNode instanceof \DOMNode) {
            return $this->normalizeNatRemoteAddressCandidate($this->normalizeHtmlNodeText($parentNode));
        }

        return '';
    }

    private function normalizeNatRemoteAddressCandidate(string $value): string
    {
        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = str_replace(['远程地址：', '远程地址:'], '', $value);
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/((?:\d{1,3}\.){3}\d{1,3}(?::\d{1,5})?|(?:[a-z0-9-]+\.)+[a-z0-9-]+(?::\d{1,5})?)/iu', $value, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function resolveConnectionPasswordFromHost(array $host, array $cachedConnection): string
    {
        $remotePassword = trim((string) ($host['password'] ?? ''));
        if ($remotePassword === '') {
            return trim((string) ($cachedConnection['password'] ?? ''));
        }

        $isMasked = preg_match('/^[*]+$/', $remotePassword) === 1
            || in_array(mb_strtolower($remotePassword), ['hidden', 'masked', 'secret'], true);

        return $isMasked
            ? trim((string) ($cachedConnection['password'] ?? ''))
            : $remotePassword;
    }

    private function extractModuleProgress(array $payload, string $status = '', string $description = ''): ?int
    {
        foreach (['progress', 'percent', 'percentage'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_numeric($value)) {
                return max(0, min(100, (int) round((float) $value)));
            }
        }

        $combined = trim($status.' '.$description);
        if ($combined !== '' && preg_match('/(\d{1,3})\s*%/', $combined, $matches) === 1) {
            return max(0, min(100, (int) $matches[1]));
        }

        return null;
    }

    private function moduleStatusContainsKeyword(string $status, string $description, array $keywords): bool
    {
        $haystack = strtolower(trim($status.' '.$description));
        if ($haystack === '') {
            return false;
        }

        foreach ($keywords as $keyword) {
            if (str_contains($haystack, strtolower((string) $keyword))) {
                return true;
            }
        }

        return false;
    }
}
