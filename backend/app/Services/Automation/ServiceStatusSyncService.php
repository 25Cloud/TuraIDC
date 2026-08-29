<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\ServiceUpstreamBindingWriter;
use App\Services\Integrations\Support\ProviderErrorMapper;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use App\Services\Upstream\ProviderResolver;
use App\Support\SensitiveDataSanitizer;
use App\Support\ServiceHostname;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ServiceStatusSyncService
{
    private const DEFAULT_SERVICE_CHUNK_SIZE = 100;

    private const DEFAULT_SUPPLIER_REQUEST_CHUNK_SIZE = 10;

    private const RUNTIME_UNAVAILABLE_UPSTREAM_STATUSES = ['suspended', 'cancelled', 'deleted'];

    private const RUNTIME_OPERATION_NOT_ALLOWED_KEYWORDS = [
        '不能执行该操作',
        '无法执行该操作',
        '该操作无法执行',
        '当前状态不允许执行该操作',
    ];

    private const RUNTIME_HOST_MISSING_KEYWORDS = [
        '主机不存在',
        'host not found',
    ];

    public function __construct(
        private ProviderResolver $providerResolver,
        private ?PluginBindingResolver $bindingResolver = null,
        private ?ServiceUpstreamBindingWriter $bindingWriter = null,
    ) {}

    public function handle(
        int $serviceChunkSize = self::DEFAULT_SERVICE_CHUNK_SIZE,
        int $supplierRequestChunkSize = self::DEFAULT_SUPPLIER_REQUEST_CHUNK_SIZE,
        array $excludedProviderKeys = [],
    ): array {
        $summary = [
            'scanned' => 0,
            'synced' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        Service::query()
            ->select(['services.id', 'services.name', 'services.domain', 'services.status', 'services.provision_data', 'services.expires_at', 'services.product_id'])
            ->join('products', 'services.product_id', '=', 'products.id')
            ->whereIn('services.status', [
                ServiceStatus::PENDING,
                ServiceStatus::ACTIVE,
                ServiceStatus::SUSPENDED,
                ServiceStatus::EXPIRED,
            ])
            ->tap(fn ($query) => $this->applySyncableUpstreamBindingScope($query, null, $excludedProviderKeys))
            ->chunkById(max(1, $serviceChunkSize), function (EloquentCollection $services) use (&$summary, $supplierRequestChunkSize) {
                $summary['scanned'] += $services->count();
                $this->syncChunk($services, max(1, $supplierRequestChunkSize), $summary);
            }, 'services.id', 'id');

        return $summary;
    }

    public function handleProvider(
        string $providerKey,
        int $serviceChunkSize = self::DEFAULT_SERVICE_CHUNK_SIZE,
        int $supplierRequestChunkSize = self::DEFAULT_SUPPLIER_REQUEST_CHUNK_SIZE,
    ): array {
        $summary = [
            'scanned' => 0,
            'synced' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $normalizedProviderKey = trim($providerKey);
        if ($normalizedProviderKey === '') {
            return $summary;
        }

        Service::query()
            ->select(['services.id', 'services.name', 'services.domain', 'services.status', 'services.provision_data', 'services.expires_at', 'services.product_id'])
            ->join('products', 'services.product_id', '=', 'products.id')
            ->whereIn('services.status', [
                ServiceStatus::PENDING,
                ServiceStatus::ACTIVE,
                ServiceStatus::SUSPENDED,
                ServiceStatus::EXPIRED,
            ])
            ->tap(fn ($query) => $this->applySyncableUpstreamBindingScope($query, $normalizedProviderKey))
            ->chunkById(max(1, $serviceChunkSize), function (EloquentCollection $services) use (&$summary, $supplierRequestChunkSize) {
                $summary['scanned'] += $services->count();
                $this->syncChunk($services, max(1, $supplierRequestChunkSize), $summary);
            }, 'services.id', 'id');

        return $summary;
    }

    public function syncServices(
        EloquentCollection $services,
        int $supplierRequestChunkSize = self::DEFAULT_SUPPLIER_REQUEST_CHUNK_SIZE,
    ): array {
        $summary = [
            'scanned' => $services->count(),
            'synced' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $syncableServices = $services
            ->filter(fn (Service $service) => $this->isSyncableService($service))
            ->values();

        $summary['skipped'] += $services->count() - $syncableServices->count();

        if ($syncableServices->isEmpty()) {
            return $summary;
        }

        $this->syncChunk(new EloquentCollection($syncableServices->all()), max(1, $supplierRequestChunkSize), $summary);

        return $summary;
    }

    private function syncChunk(EloquentCollection $services, int $supplierRequestChunkSize, array &$summary): void
    {
        $serviceIds = $services->pluck('id')->all();
        $serviceProductMap = Service::query()
            ->whereIn('id', $serviceIds)
            ->with([
                'product',
                'order:id,config_snapshot',
            ])
            ->get()
            ->keyBy('id');

        $supplierIds = collect($services)
            ->map(function (Service $service) use ($serviceProductMap): int {
                $serviceWithProduct = $serviceProductMap->get($service->id);

                return $this->resolveSupplierId($service, $serviceWithProduct?->product);
            })
            ->filter(fn (int $supplierId): bool => $supplierId > 0)
            ->unique()
            ->values()
            ->all();

        $suppliers = Supplier::query()
            ->enabled()
            ->whereIn('id', $supplierIds)
            ->get()
            ->map(fn (Supplier $supplier): Supplier => $this->bindingResolver()->supplierWithRuntimeCredentials($supplier))
            ->keyBy('id');

        collect($services)
            ->groupBy(function (Service $service) use ($serviceProductMap): int {
                $serviceWithProduct = $serviceProductMap->get($service->id);

                return $this->resolveSupplierId($service, $serviceWithProduct?->product);
            })
            ->each(function (Collection $group, int|string $supplierId) use ($suppliers, $supplierRequestChunkSize, $serviceProductMap, &$summary): void {
                $supplier = $suppliers->get((int) $supplierId);

                if (! $supplier instanceof Supplier) {
                    $summary['skipped'] += $group->count();

                    Log::warning('[定时任务] 用户产品状态同步跳过：供应商不可用', [
                        'supplier_id' => (int) $supplierId,
                        'service_ids' => $group->pluck('id')->values()->all(),
                    ]);

                    return;
                }

                $this->syncSupplierServices($supplier, $group->values(), $supplierRequestChunkSize, $summary, $serviceProductMap);
            });
    }

    private function isSyncableService(Service $service): bool
    {
        $hostId = $this->resolveHostId($service);

        return $this->providerResolver->resolveForService($service)->supports(ProvidesStatusSync::class)
            && $hostId > 0
            && in_array((int) $service->status, [
                ServiceStatus::PENDING,
                ServiceStatus::ACTIVE,
                ServiceStatus::SUSPENDED,
                ServiceStatus::EXPIRED,
            ], true);
    }

    private function syncSupplierServices(
        Supplier $supplier,
        Collection $services,
        int $supplierRequestChunkSize,
        array &$summary,
        Collection $serviceProductMap,
    ): void {
        $provider = $this->providerResolver->resolveForSupplier($supplier);
        if (! $provider->supports(ProvidesStatusSync::class)) {
            $summary['skipped'] += $services->count();

            return;
        }
        $providerKey = trim((string) ($provider->key() ?? $provider->rawKey() ?? ''));

        $statusSync = $provider->require(ProvidesStatusSync::class, '当前供应商不支持状态同步');

        if (method_exists($statusSync, 'syncServiceStatuses')) {
            $this->syncSupplierServicesThroughPlugin($statusSync, $supplier, $services, $supplierRequestChunkSize, $summary, $serviceProductMap);

            return;
        }

        try {
            $jwt = $statusSync->login($supplier);
        } catch (\Throwable $exception) {
            $summary['failed'] += $services->count();

            foreach ($services as $service) {
                if ($service instanceof Service) {
                    $this->markSyncFailure($service, $exception->getMessage());
                }
            }

            Log::error('[定时任务] 用户产品状态同步失败：供应商登录失败', [
                'supplier_id' => $supplier->id,
                'service_ids' => $services->pluck('id')->values()->all(),
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return;
        }

        $services
            ->chunk($supplierRequestChunkSize)
            ->each(function (Collection $batch) use ($statusSync, $supplier, $serviceProductMap, $providerKey, &$jwt, &$summary): void {
                try {
                    $requestResult = $this->executeBatchRequests($statusSync, $supplier, $batch, $jwt);
                    $jwt = $requestResult['jwt'];
                    $responses = $requestResult['responses'];
                } catch (\Throwable $exception) {
                    $summary['failed'] += $batch->count();

                    foreach ($batch as $service) {
                        if ($service instanceof Service) {
                            $this->markSyncFailure($service, $exception->getMessage());
                        }
                    }

                    Log::error('[定时任务] 用户产品状态同步失败：批量请求异常', [
                        'supplier_id' => $supplier->id,
                        'service_ids' => $batch->pluck('id')->values()->all(),
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);

                    return;
                }

                foreach ($batch as $service) {
                    if (! $service instanceof Service) {
                        continue;
                    }

                    try {
                        $hydratedService = $serviceProductMap->get($service->id);
                        if ($hydratedService instanceof Service) {
                            $service->setRelation('product', $hydratedService->product);
                            $service->setRelation('order', $hydratedService->order);
                        }

                        $host = $this->extractHostPayload($responses['detail_'.$service->id] ?? [], $providerKey);
                        $runtime = $this->extractRuntimePayload($responses['runtime_'.$service->id] ?? [], $service, $host, $providerKey);

                        $this->syncServiceSnapshot($service, $host, $runtime);
                        $summary['synced']++;
                    } catch (\Throwable $exception) {
                        $summary['failed']++;
                        $this->markSyncFailure($service, $exception->getMessage());

                        Log::warning('[定时任务] 用户产品状态同步失败', [
                            'service_id' => $service->id,
                            'supplier_id' => $supplier->id,
                            'host_id' => $this->resolveHostId($service),
                            'message' => $exception->getMessage(),
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });
    }

    private function syncSupplierServicesThroughPlugin(
        object $statusSync,
        Supplier $supplier,
        Collection $services,
        int $supplierRequestChunkSize,
        array &$summary,
        Collection $serviceProductMap,
    ): void {
        $services
            ->chunk($supplierRequestChunkSize)
            ->each(function (Collection $batch) use ($statusSync, $supplier, $serviceProductMap, $supplierRequestChunkSize, &$summary): void {
                try {
                    $requestResult = $statusSync->syncServiceStatuses(
                        $supplier,
                        $this->buildPluginStatusSyncItems($batch),
                        $supplierRequestChunkSize
                    );
                    $responses = is_array($requestResult['services'] ?? null) ? $requestResult['services'] : [];
                } catch (\Throwable $exception) {
                    $summary['failed'] += $batch->count();

                    foreach ($batch as $service) {
                        if ($service instanceof Service) {
                            $this->markSyncFailure($service, $exception->getMessage());
                        }
                    }

                    Log::error('[定时任务] 用户产品状态同步失败：插件批量请求异常', [
                        'supplier_id' => $supplier->id,
                        'service_ids' => $batch->pluck('id')->values()->all(),
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);

                    return;
                }

                foreach ($batch as $service) {
                    if (! $service instanceof Service) {
                        continue;
                    }

                    try {
                        $hydratedService = $serviceProductMap->get($service->id);
                        if ($hydratedService instanceof Service) {
                            $service->setRelation('product', $hydratedService->product);
                            $service->setRelation('order', $hydratedService->order);
                        }

                        $response = $responses[$service->id] ?? $responses[(string) $service->id] ?? [];
                        if (! is_array($response) || $response === []) {
                            throw new BusinessException('状态同步失败：插件未返回服务结果', 42200);
                        }

                        $error = trim((string) ($response['error'] ?? ''));
                        if ($error !== '') {
                            throw new BusinessException($error, 42200);
                        }

                        $host = is_array($response['host'] ?? null) ? $response['host'] : [];
                        $runtime = is_array($response['runtime'] ?? null) ? $response['runtime'] : [];

                        $this->syncServiceSnapshot($service, $host, $runtime);
                        $summary['synced']++;
                    } catch (\Throwable $exception) {
                        $summary['failed']++;
                        $this->markSyncFailure($service, $exception->getMessage());

                        Log::warning('[定时任务] 用户产品状态同步失败', [
                            'service_id' => $service->id,
                            'supplier_id' => $supplier->id,
                            'host_id' => $this->resolveHostId($service),
                            'message' => $exception->getMessage(),
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });
    }

    private function buildPluginStatusSyncItems(Collection $services): array
    {
        return $services
            ->filter(fn ($service): bool => $service instanceof Service && $this->resolveHostId($service) > 0)
            ->map(fn (Service $service): array => [
                'service_id' => (int) $service->id,
                'host_id' => $this->resolveHostId($service),
            ])
            ->values()
            ->all();
    }

    private function executeBatchRequests(object $statusSync, Supplier $supplier, Collection $services, string $jwt): array
    {
        $requests = $this->buildBatchRequests($services);
        $responses = $statusSync->parallelGet($supplier, $requests, $jwt);

        if ($this->shouldRetryWithFreshJwt($responses)) {
            $jwt = $statusSync->refreshJwt($supplier);
            $responses = $statusSync->parallelGet($supplier, $requests, $jwt);
        }

        return [
            'jwt' => $jwt,
            'responses' => $responses,
        ];
    }

    private function buildBatchRequests(Collection $services): array
    {
        $requests = [];

        foreach ($services as $service) {
            if (! $service instanceof Service) {
                continue;
            }

            $hostId = $this->resolveHostId($service);
            if ($hostId <= 0) {
                continue;
            }

            $requests['detail_'.$service->id] = [
                'uri' => "/v1/hosts/{$hostId}",
            ];
            $requests['runtime_'.$service->id] = [
                'uri' => "/v1/hosts/{$hostId}/module/status",
                'query' => [
                    'type' => 'host',
                ],
            ];
        }

        return $requests;
    }

    private function shouldRetryWithFreshJwt(array $responses): bool
    {
        foreach ($responses as $response) {
            if (! is_array($response)) {
                continue;
            }

            if ((int) ($response['status_code'] ?? 0) === 401) {
                return true;
            }

            $payload = is_array($response['response'] ?? null) ? $response['response'] : [];
            $status = (int) ($payload['status'] ?? $payload['code'] ?? $payload['status_code'] ?? 0);

            if ($status === 401) {
                return true;
            }
        }

        return false;
    }

    private function extractHostPayload(array $response, string $providerKey): array
    {
        $payload = $this->extractParallelPayload($response, '读取主机详情', $providerKey);
        $host = is_array($payload['host'] ?? null) ? $payload['host'] : [];

        if ($host === []) {
            throw new BusinessException('读取主机详情失败：上游未返回实例数据', 42200);
        }

        return $host;
    }

    private function extractRuntimePayload(array $response, Service $service, array $host, string $providerKey): array
    {
        try {
            return $this->extractParallelPayload($response, '读取电源状态', $providerKey);
        } catch (\Throwable $exception) {
            $runtimeUnavailableContext = $this->resolveRuntimeUnavailableContext($response, $host);

            if ($runtimeUnavailableContext !== null) {
                Log::debug('[定时任务] 电源状态不可用，降级为仅同步实例详情', [
                    'service_id' => $service->id,
                    'reason' => $runtimeUnavailableContext['reason'],
                    'upstream_status' => $runtimeUnavailableContext['upstream_status'],
                ]);

                return [];
            }

            throw $exception;
        }
    }

    private function resolveRuntimeUnavailableContext(array $response, array $host): ?array
    {
        $upstreamStatus = strtolower(trim((string) ($host['domainstatus'] ?? '')));

        $message = $this->extractRuntimeFailureMessage($response);
        if ($message === '') {
            return null;
        }

        $payload = is_array($response['response'] ?? null) ? $response['response'] : [];
        $httpStatus = (int) ($response['status_code'] ?? 0);
        $businessStatus = (int) ($payload['status'] ?? $payload['code'] ?? $payload['status_code'] ?? 0);

        // 无论主机当前状态如何，"不能执行该操作"均视为暂时不可查询，降级为仅同步实例详情
        if ($this->messageContainsAny($message, self::RUNTIME_OPERATION_NOT_ALLOWED_KEYWORDS)) {
            return [
                'upstream_status' => $upstreamStatus,
                'reason' => 'operation_not_allowed',
                'message' => $message,
                'http_status' => $httpStatus,
                'business_status' => $businessStatus,
            ];
        }

        if (! $this->isExpectedRuntimeFailureStatus($httpStatus, $businessStatus)) {
            return null;
        }

        if (
            in_array($upstreamStatus, self::RUNTIME_UNAVAILABLE_UPSTREAM_STATUSES, true)
            && in_array($upstreamStatus, ['cancelled', 'deleted'], true)
            && $this->messageContainsAny($message, self::RUNTIME_HOST_MISSING_KEYWORDS)
        ) {
            return [
                'upstream_status' => $upstreamStatus,
                'reason' => 'host_missing_after_termination',
                'message' => $message,
                'http_status' => $httpStatus,
                'business_status' => $businessStatus,
            ];
        }

        return null;
    }

    private function extractRuntimeFailureMessage(array $response): string
    {
        $errorMessage = trim((string) ($response['error'] ?? ''));
        if ($errorMessage !== '') {
            return $errorMessage;
        }

        $payload = is_array($response['response'] ?? null) ? $response['response'] : [];

        return trim((string) ($payload['msg'] ?? $payload['message'] ?? ''));
    }

    private function isExpectedRuntimeFailureStatus(int $httpStatus, int $businessStatus): bool
    {
        $expectedStatuses = [400, 403, 404, 422];

        if ($httpStatus > 0 && in_array($httpStatus, $expectedStatuses, true)) {
            return true;
        }

        if ($businessStatus > 0 && in_array($businessStatus, $expectedStatuses, true)) {
            return true;
        }

        return $httpStatus === 0 && $businessStatus === 0;
    }

    private function messageContainsAny(string $message, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (mb_stripos($message, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function extractParallelPayload(array $response, string $action, string $providerKey): array
    {
        if ($response === []) {
            throw new BusinessException($action.'失败：未获取到有效响应', 42200);
        }

        $error = trim((string) ($response['error'] ?? ''));
        if ($error !== '') {
            throw new BusinessException($action.'失败：'.$error, 42200);
        }

        $payload = is_array($response['response'] ?? null) ? $response['response'] : [];
        if ($payload === []) {
            throw new BusinessException($action.'失败：响应为空', 42200);
        }

        $this->assertSuccess($payload, $action, $providerKey);

        return $this->extractPayload($payload);
    }

    private function assertSuccess(array $response, string $action, string $providerKey): void
    {
        $status = (int) ($response['status'] ?? $response['code'] ?? $response['status_code'] ?? 0);

        if (in_array($status, [200, 1001], true)) {
            return;
        }

        $message = trim((string) ($response['msg'] ?? $response['message'] ?? ''));
        Log::warning('[上游状态同步] 返回失败', [
            'action' => $action,
            'status' => $status,
            'message' => SensitiveDataSanitizer::sanitizeText($message),
        ]);

        throw new BusinessException(app(ProviderErrorMapper::class)->toUserMessage($providerKey, $action, $message), 42200);
    }

    private function extractPayload(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    private function syncServiceSnapshot(Service $service, array $host, array $runtime = []): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $currentProvisionData = $this->serviceProvisionData($service, includeSecrets: true);
        $cachedConnection = $this->readCachedConnection($currentProvisionData);
        $normalizedHostStatus = strtolower(trim((string) ($host['domainstatus'] ?? '')));
        $resolvedUpstreamStatus = $this->resolveServiceStatusFromUpstream((string) ($host['domainstatus'] ?? ''));
        $resolvedServiceStatus = $this->resolveSyncedServiceStatus($service, $resolvedUpstreamStatus);

        if ($resolvedServiceStatus === ServiceStatus::ACTIVE
            && (int) $service->status === ServiceStatus::ACTIVE
            && $resolvedUpstreamStatus === ServiceStatus::SUSPENDED) {
            Log::warning('[状态同步] 续费保护生效：上游仍暂停但本地已开通且未到期，保留本地状态', [
                'service_id' => $service->id,
                'expires_at' => $service->expires_at?->toDateTimeString(),
            ]);
        }
        $shouldResetRuntimeSnapshot = $normalizedHostStatus !== '' && $normalizedHostStatus !== 'active';
        $mergedConnection = [
            'hostname' => ServiceHostname::resolveConnectionHostname($service, $currentProvisionData, $cachedConnection, $host),
            'username' => trim((string) ($host['username'] ?? ($cachedConnection['username'] ?? ''))),
            'password' => $this->resolveConnectionPassword($host, $cachedConnection),
            'port' => (int) (($host['port'] ?? ($cachedConnection['port'] ?? 0)) ?: 0),
            'internal_ip' => trim((string) ($host['internalip'] ?? $host['privateip'] ?? ($cachedConnection['internal_ip'] ?? ''))),
        ];

        $provisionData = array_merge($currentProvisionData, [
            'upstream_status' => (string) ($host['domainstatus'] ?? ($currentProvisionData['upstream_status'] ?? '')),
            'upstream_product_id' => (int) (($host['product_id'] ?? ($currentProvisionData['upstream_product_id'] ?? 0)) ?: 0),
            'upstream_product_name' => trim((string) ($host['product_name'] ?? ($currentProvisionData['upstream_product_name'] ?? ''))),
            'dedicated_ip' => (string) ($host['dedicatedip'] ?? ($currentProvisionData['dedicated_ip'] ?? '')),
            'assigned_ips' => is_array($host['assignedips'] ?? null) ? $host['assignedips'] : (array) ($currentProvisionData['assigned_ips'] ?? []),
            'host_config_option' => is_array($host['config_option'] ?? null) ? $host['config_option'] : (array) ($currentProvisionData['host_config_option'] ?? []),
            'os' => (string) ($host['os'] ?? ($currentProvisionData['os'] ?? '')),
            'runtime_status' => array_key_exists('status', $runtime)
                ? (string) ($runtime['status'] ?? '')
                : ($shouldResetRuntimeSnapshot ? '' : (string) ($currentProvisionData['runtime_status'] ?? '')),
            'runtime_description' => array_key_exists('des', $runtime)
                ? (string) ($runtime['des'] ?? '')
                : ($shouldResetRuntimeSnapshot ? '' : (string) ($currentProvisionData['runtime_description'] ?? '')),
            'connection_cached_hostname' => $mergedConnection['hostname'],
            'connection_secret' => $this->writeCachedConnection($mergedConnection),
            'connection_cached_at' => $now,
            'last_synced_at' => $now,
            'last_status_sync_at' => $now,
            'last_status_sync_attempt_at' => $now,
            'status_sync_error' => null,
        ]);

        $persistedProvisionData = $this->bindingResolver()->sanitizeServiceProvisionData($provisionData);
        $service->forceFill([
            'name' => ServiceHostname::resolveInstanceName($service, $provisionData, $host),
            'domain' => trim((string) ($host['domain'] ?? $service->domain)),
            'status' => $resolvedServiceStatus,
            'expires_at' => $this->resolveExpiry($host, $service),
            'suspended_reason' => $this->resolveSyncedSuspendedReason($service, $resolvedServiceStatus, $resolvedUpstreamStatus),
            'provision_data' => $persistedProvisionData,
        ])->save();

        $service->refresh()->loadMissing('product.supplier');
        $this->bindingWriter()->syncServiceState($service, $service->product, $provisionData);
    }

    private function markSyncFailure(Service $service, string $message): void
    {
        try {
            $provisionData = $this->serviceProvisionData($service);
            $provisionData['last_status_sync_attempt_at'] = now()->format('Y-m-d H:i:s');
            $provisionData['status_sync_error'] = mb_substr(trim($message), 0, 200);

            $service->forceFill([
                'provision_data' => $provisionData,
            ])->save();

            $service->refresh()->loadMissing('product.supplier');
            $this->bindingWriter()->syncServiceState($service, $service->product, $provisionData);
        } catch (\Throwable $exception) {
            Log::warning('[定时任务] 用户产品状态同步失败记录写入异常', [
                'service_id' => $service->id,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    private function resolveSupplierId(Service $service, ?Product $product = null): int
    {
        $supplierId = (int) (($this->bindingResolver()->supplierIdForService($service) ?? 0) ?: 0);

        if ($supplierId <= 0 && $product instanceof Product) {
            $supplierId = (int) (($this->bindingResolver()->supplierIdForProduct($product) ?? 0) ?: 0);
        }

        if ($supplierId > 0) {
            return $supplierId;
        }

        return 0;
    }

    private function resolveHostId(Service $service): int
    {
        $bindingHostId = $this->bindingResolver()->upstreamServiceIdForService($service);
        if ($bindingHostId !== null) {
            return (int) $bindingHostId;
        }

        return 0;
    }

    private function applySyncableUpstreamBindingScope($query, ?string $providerKey = null, array $excludedProviderKeys = []): void
    {
        if (! Schema::hasTable('service_upstream_bindings')) {
            $query->whereRaw('0 = 1');

            return;
        }

        $normalizedProviderKey = trim((string) $providerKey);
        $normalizedExcluded = array_values(array_unique(array_map(
            static fn (string $key): string => trim($key),
            $excludedProviderKeys
        )));

        $query->whereExists(function ($subQuery) use ($normalizedProviderKey, $normalizedExcluded): void {
            $subQuery
                ->selectRaw('1')
                ->from('service_upstream_bindings as sub')
                ->whereColumn('sub.service_id', 'services.id')
                ->whereNotNull('sub.upstream_service_id')
                ->where('sub.upstream_service_id', '<>', '');

            if ($normalizedProviderKey !== '') {
                $subQuery->where('sub.provider_key', $normalizedProviderKey);
            }

            if ($normalizedExcluded !== []) {
                $subQuery->whereNotIn('sub.provider_key', $normalizedExcluded);
            }
        });
    }

    private function bindingResolver(): PluginBindingResolver
    {
        return $this->bindingResolver ??= app(PluginBindingResolver::class);
    }

    private function bindingWriter(): ServiceUpstreamBindingWriter
    {
        return $this->bindingWriter ??= app(ServiceUpstreamBindingWriter::class);
    }

    private function serviceProvisionData(Service $service, bool $includeSecrets = false): array
    {
        $legacy = is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $projection = $this->bindingResolver()->serviceProvisionProjection($service, $includeSecrets);

        $provisionData = $projection === [] ? $legacy : array_replace($legacy, $projection);

        return $includeSecrets
            ? $provisionData
            : $this->bindingResolver()->sanitizeServiceProvisionData($provisionData);
    }

    private function readCachedConnection(array $provisionData): array
    {
        $payload = trim((string) ($provisionData['connection_secret'] ?? ''));
        if ($payload === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($payload), true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function writeCachedConnection(array $connection): string
    {
        return Crypt::encryptString((string) json_encode([
            'hostname' => trim((string) ($connection['hostname'] ?? '')),
            'username' => trim((string) ($connection['username'] ?? '')),
            'password' => (string) ($connection['password'] ?? ''),
            'port' => (int) (($connection['port'] ?? 0) ?: 0),
            'internal_ip' => trim((string) ($connection['internal_ip'] ?? '')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

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

    private function resolveServiceStatusFromUpstream(string $status): int
    {
        return match (strtolower(trim($status))) {
            'active' => ServiceStatus::ACTIVE,
            'suspended' => ServiceStatus::SUSPENDED,
            'cancelled', 'deleted' => ServiceStatus::CANCELLED,
            default => ServiceStatus::PENDING,
        };
    }

    private function resolveSyncedServiceStatus(Service $service, int $upstreamStatus): int
    {
        if ($this->shouldPreserveLocalSuspendedState($service, $upstreamStatus)) {
            return (int) $service->status;
        }

        // 续费保护：本地已开通且未到期、无本地暂停原因，但上游仍显示暂停
        // （续费后魔方未解除暂停的过渡态），保留本地状态，避免"续费成功却仍被暂停"。
        if ($this->shouldPreserveLocalActiveState($service, $upstreamStatus)) {
            return (int) $service->status;
        }

        return $upstreamStatus;
    }

    private function resolveSyncedSuspendedReason(Service $service, int $resolvedStatus, int $upstreamStatus): ?string
    {
        if ($this->shouldPreserveLocalSuspendedState($service, $upstreamStatus)) {
            return $service->suspended_reason;
        }

        return $resolvedStatus === ServiceStatus::SUSPENDED
            && (int) $service->status === ServiceStatus::SUSPENDED
            ? $service->suspended_reason
            : null;
    }

    private function shouldPreserveLocalSuspendedState(Service $service, int $upstreamStatus): bool
    {
        $localReason = trim((string) ($service->suspended_reason ?? ''));

        return (int) $service->status === ServiceStatus::SUSPENDED
            && $localReason !== ''
            && in_array($upstreamStatus, [ServiceStatus::ACTIVE, ServiceStatus::PENDING, ServiceStatus::SUSPENDED], true);
    }

    /**
     * 续费保护：本地已开通、未到期且无本地暂停原因，而上游显示暂停时，
     * 视为续费后上游未解除暂停的过渡态，保留本地已开通状态。
     */
    private function shouldPreserveLocalActiveState(Service $service, int $upstreamStatus): bool
    {
        return (int) $service->status === ServiceStatus::ACTIVE
            && trim((string) ($service->suspended_reason ?? '')) === ''
            && $service->expires_at instanceof Carbon
            && $service->expires_at->isFuture()
            && $upstreamStatus === ServiceStatus::SUSPENDED;
    }

    private function resolveExpiry(array $host, Service $service): ?Carbon
    {
        $nextDueDate = $host['nextduedate'] ?? null;
        $resolved = null;

        if (is_numeric($nextDueDate)) {
            $timestamp = (int) $nextDueDate;

            if ($timestamp > 0) {
                $resolved = Carbon::createFromTimestamp($timestamp, config('app.timezone'));
            }
        } elseif (is_string($nextDueDate) && trim($nextDueDate) !== '') {
            // 兼容上游 Y-m-d / Y-m-d H:i:s 日期字符串。
            try {
                $resolved = Carbon::parse(trim($nextDueDate));
            } catch (\Throwable) {
                $resolved = null;
            }
        }

        // 续费保护：本地到期时间晚于上游解析值时保留本地（上游可能尚未同步续费结果），
        // 防止续费后的延期被旧到期时间回退而触发到期暂停误杀。
        if ($resolved instanceof Carbon && $service->expires_at instanceof Carbon && $service->expires_at->greaterThan($resolved)) {
            return $service->expires_at;
        }

        return $resolved ?? $service->expires_at;
    }
}
