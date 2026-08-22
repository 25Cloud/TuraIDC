<?php

declare(strict_types=1);

namespace App\Services\User\Concerns;

use App\Constants\FinanceLedgerEventType;
use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\ServiceUpstreamBindingWriter;
use App\Services\Integrations\Plugins\UpstreamBindingWriter;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\ProviderResolver;
use App\Support\ServiceHostname;
use App\Support\TextSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait HandlesAdminUserServices
{
    /**
     * 用户服务列表
     */
    public function services(User $user, array $filters, int $perPage = 20)
    {
        return $this->clientServiceConsoleService->paginateForUser($user, array_merge($filters, [
            'page_size' => $perPage,
        ]));
    }

    /**
     * 用户服务详情
     */
    public function serviceDetail(User $user, int $serviceId, bool $refreshRemote = false, bool $includeSensitiveConnection = true): array
    {
        return $this->clientServiceConsoleService->getDetailForUser($user, $serviceId, $refreshRemote, $includeSensitiveConnection);
    }

    public function serviceBaseDetail(User $user, int $serviceId, bool $includeSensitiveConnection = true): array
    {
        return $this->clientServiceConsoleService->getBaseDetailForUser($user, $serviceId, $includeSensitiveConnection);
    }

    public function serviceRemoteStatusPatch(User $user, int $serviceId, bool $includeSensitiveConnection = true): array
    {
        return $this->clientServiceConsoleService->getRemoteStatusPatchForUser($user, $serviceId, $includeSensitiveConnection);
    }

    public function refreshServiceStatuses(User $user, array $serviceIds = []): array
    {
        $ids = collect($serviceIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $services = Service::query()
            ->select([
                'id',
                'user_id',
                'product_id',
                'name',
                'domain',
                'status',
                'provision_data',
                'expires_at',
            ])
            ->where('user_id', $user->id)
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->get();

        throw_if($services->isEmpty(), new BusinessException('没有可刷新的服务记录'));

        return $this->serviceStatusSyncService->syncServices($services);
    }

    /**
     * 更新用户服务业务信息
     */
    public function updateServiceMeta(User $user, int $serviceId, array $data, array $context = []): array
    {
        $service = Service::query()
            ->with(['product.supplier', 'user'])
            ->where('user_id', $user->id)
            ->findOrFail($serviceId);

        $currentProvisionData = $this->serviceProvisionData($service);
        $previousCustomHostname = ServiceHostname::custom($currentProvisionData);
        $previousAmount = round((float) ($service->amount ?? 0), 2);
        $previousSupplierId = $this->resolveServiceSupplierId($service, $currentProvisionData);
        $previousUpstreamProductId = $this->resolveServiceUpstreamProductId($service, $currentProvisionData);
        $previousUpstreamHostId = $this->resolveServiceUpstreamHostId($service, $currentProvisionData);
        $supportsUpstream = $this->supportsManagedUpstream($service->product);
        $hasUpstreamBinding = $previousSupplierId > 0 || $this->bindingResolver()->upstreamServiceIdForService($service) !== null;
        $rawAmount = $data['amount'] ?? null;
        $newAmount = $rawAmount === null ? null : round((float) $rawAmount, 2);
        $rawSupplierId = $data['supplier_id'] ?? null;
        $supplierId = $rawSupplierId === null ? null : (int) $rawSupplierId;
        $rawUpstreamProductId = $data['upstream_product_id'] ?? null;
        $upstreamProductId = $rawUpstreamProductId === null ? null : (int) $rawUpstreamProductId;
        $rawUpstreamHostId = $data['upstream_host_id'] ?? null;
        $upstreamHostId = $rawUpstreamHostId === null ? null : (int) $rawUpstreamHostId;
        $selectedSupplier = null;

        if ($newAmount !== null) {
            throw_if($newAmount < 0, new BusinessException('请输入有效的购买价格'));
        }

        if ($supplierId !== null) {
            $selectedSupplier = Supplier::query()
                ->enabled()
                ->find($supplierId);

            throw_if(! $selectedSupplier instanceof Supplier, new BusinessException('请选择有效的上游接口'));
            app(UpstreamBindingWriter::class)->syncSupplierBinding($selectedSupplier);
            throw_if(
                ! app(ProviderResolver::class)->resolveForSupplier($selectedSupplier)->supports(ProvidesConsoleRuntime::class),
                new BusinessException('当前上游接口不支持实例控制')
            );
            throw_if(
                $upstreamHostId === null && $upstreamProductId === null,
                new BusinessException('重新绑定上游接口时必须填写新的上游产品 ID 或上游实例 ID')
            );
        }

        if ($upstreamProductId !== null) {
            throw_if($upstreamProductId <= 0, new BusinessException('请输入有效的上游产品 ID'));
            throw_if(
                $supplierId === null && $upstreamHostId === null,
                new BusinessException('更换上游产品 ID 时必须同时绑定上游接口或上游实例 ID')
            );
        }

        if ($upstreamHostId !== null) {
            throw_if($upstreamHostId <= 0, new BusinessException('请输入有效的上游主机 ID'));
            throw_if(
                ! $supportsUpstream && ! $hasUpstreamBinding && ! $selectedSupplier instanceof Supplier,
                new BusinessException('当前服务未接入可控上游，无法修改上游主机 ID')
            );
        }

        $traceId = trim((string) ($context['trace_id'] ?? ''));
        $operatorId = (int) (($context['operator_id'] ?? 0) ?: 0);
        $operatorName = trim((string) ($context['operator_name'] ?? ''));
        $rawServiceName = array_key_exists('service_name', $data) ? (string) ($data['service_name'] ?? '') : null;
        $newServiceName = $rawServiceName === null ? 'skip' : mb_substr(TextSanitizer::clean($rawServiceName), 0, 120);
        $clearCustomHostname = ! empty($data['clear_custom_hostname']);
        $newCustomHostname = 'skip';

        if ($clearCustomHostname) {
            $newCustomHostname = '';
        } elseif (array_key_exists('custom_hostname', $data)) {
            $rawCustomHostname = trim((string) ($data['custom_hostname'] ?? ''));
            $normalizedCustomHostname = $rawCustomHostname !== ''
                ? $this->settingService->normalizeHostname($rawCustomHostname, true)
                : '';

            if ($rawCustomHostname !== '' && $normalizedCustomHostname === '') {
                throw new BusinessException('请输入有效的自定义主机名');
            }

            $newCustomHostname = $normalizedCustomHostname;
        }

        $productPricing = Service::extractSupportedRenewPricing(
            is_array($service->product?->pricing ?? null) ? $service->product->pricing : []
        );
        $currentRenewPricing = $service->resolveRenewPricingConfig($productPricing);

        // 处理续费配置：clear_locked_pricing=true 恢复默认快照，否则按传入值更新，未传则跳过
        $clearLocked = ! empty($data['clear_locked_pricing']);
        $newLockedPricing = 'skip';

        if ($clearLocked) {
            $newLockedPricing = $service->resetRenewPricingConfig($productPricing);
        } elseif (isset($data['locked_pricing']) && is_array($data['locked_pricing'])) {
            $newLockedPricing = [];

            foreach (Service::SUPPORTED_RENEW_BILLING_CYCLES as $cycle => $cycleLabel) {
                $incoming = is_array($data['locked_pricing'][$cycle] ?? null) ? $data['locked_pricing'][$cycle] : [];
                $currentCycleConfig = is_array($currentRenewPricing[$cycle] ?? null) ? $currentRenewPricing[$cycle] : [
                    'enabled' => false,
                    'base_amount' => null,
                    'manual_amount' => null,
                ];

                $baseAmount = $this->normalizePositiveAmount($currentCycleConfig['base_amount'] ?? null);
                $manualAmount = array_key_exists('manual_amount', $incoming)
                    ? $this->normalizePositiveAmount($incoming['manual_amount'])
                    : $this->normalizePositiveAmount($currentCycleConfig['manual_amount'] ?? null);
                $enabled = array_key_exists('enabled', $incoming)
                    ? filter_var($incoming['enabled'], FILTER_VALIDATE_BOOLEAN)
                    : (bool) ($currentCycleConfig['enabled'] ?? false);

                $effectiveAmount = $manualAmount !== null && $manualAmount > 0 ? $manualAmount : $baseAmount;
                if ($enabled && ($effectiveAmount === null || $effectiveAmount <= 0)) {
                    throw new BusinessException("{$cycleLabel}已开启，请填写有效续费价格");
                }

                $newLockedPricing[$cycle] = [
                    'enabled' => (bool) ($enabled && $effectiveAmount !== null && $effectiveAmount > 0),
                    'base_amount' => $baseAmount !== null && $baseAmount > 0 ? number_format($baseAmount, 2, '.', '') : null,
                    'manual_amount' => $manualAmount !== null && $manualAmount > 0 ? number_format($manualAmount, 2, '.', '') : null,
                ];
            }
        }

        $supplierChanged = false;
        $supplierProductChanged = false;
        $amountChanged = false;
        $upstreamHostChanged = false;

        DB::transaction(function () use (
            $service,
            $newAmount,
            $selectedSupplier,
            $upstreamHostId,
            $upstreamProductId,
            $currentProvisionData,
            $traceId,
            $operatorId,
            $operatorName,
            $newLockedPricing,
            $newServiceName,
            $newCustomHostname,
            &$supplierChanged,
            &$supplierProductChanged,
            &$amountChanged,
            &$upstreamHostChanged,
            $previousAmount,
            $previousSupplierId,
            $previousUpstreamProductId,
            $previousUpstreamHostId
        ) {
            $provisionData = $currentProvisionData;
            $fillData = [];

            if ($newAmount !== null) {
                $fillData['amount'] = $newAmount;
                $amountChanged = abs($newAmount - $previousAmount) > 0.0001;
            }

            if ($selectedSupplier instanceof Supplier) {
                $providerKey = $this->resolveSupplierProviderKey($selectedSupplier);
                throw_if($providerKey === '', new BusinessException('供应商未配置上游插件绑定，无法绑定上游实例'));

                $provisionData['source_type'] = 'upstream';
                $provisionData['provider_key'] = $providerKey;
                $provisionData['supplier_id'] = (int) $selectedSupplier->id;
                $provisionData['upstream_product_id'] = $upstreamProductId ?? $this->resolveProductUpstreamProductId($service->product);
                $supplierChanged = (int) $selectedSupplier->id !== $previousSupplierId;
                $supplierProductChanged = (int) $provisionData['upstream_product_id'] !== $previousUpstreamProductId;
            }

            if ($upstreamHostId !== null) {
                $provisionData['source_type'] = 'upstream';
                $existingProvider = $selectedSupplier instanceof Supplier
                    ? $this->resolveSupplierProviderKey($selectedSupplier)
                    : $this->resolveServiceProviderKey($service, $provisionData);
                throw_if($existingProvider === '', new BusinessException('服务未配置上游插件绑定，无法绑定上游实例'));

                $provisionData['provider_key'] = $existingProvider;
                $provisionData['supplier_id'] = $selectedSupplier instanceof Supplier
                    ? (int) $selectedSupplier->id
                    : $this->resolveServiceSupplierId($service, $provisionData);
                $provisionData['upstream_product_id'] = $upstreamProductId
                    ?? $this->resolveServiceUpstreamProductId($service, $provisionData);
                $supplierProductChanged = (int) $provisionData['upstream_product_id'] !== $previousUpstreamProductId;
                $provisionData['upstream_host_id'] = $upstreamHostId;
                $provisionData['last_manual_linked_at'] = now()->format('Y-m-d H:i:s');
                $upstreamHostChanged = $upstreamHostId !== $previousUpstreamHostId;
            }

            if ($supplierChanged || $supplierProductChanged || $upstreamHostChanged) {
                unset(
                    $provisionData['connection_secret'],
                    $provisionData['connection_cached_at'],
                    $provisionData['last_synced_at'],
                    $provisionData['last_status_sync_at'],
                    $provisionData['last_status_sync_attempt_at'],
                    $provisionData['status_sync_error'],
                    $provisionData['runtime_status'],
                    $provisionData['runtime_description'],
                    $provisionData['host_config_option'],
                    $provisionData['assigned_ips'],
                    $provisionData['dedicated_ip'],
                    $provisionData['nat_remote_address'],
                    $provisionData['nat_remote_host'],
                    $provisionData['nat_remote_port'],
                    $provisionData['nat_remote_checked_at'],
                    $provisionData['upstream_status'],
                    $provisionData['upstream_product_name'],
                    $provisionData['os'],
                    $provisionData['provision_error']
                );
            }

            if ($traceId !== '') {
                $provisionData['trace_id'] = $traceId;
            }

            if ($operatorId > 0) {
                $provisionData['updated_from_admin_id'] = $operatorId;
            }

            if ($operatorName !== '') {
                $provisionData['updated_from_admin_name'] = $operatorName;
            }

            if ($newServiceName !== 'skip') {
                $provisionData = ServiceHostname::rememberDefaultServiceName($provisionData, (string) ($service->name ?? ''));
                $provisionData = ServiceHostname::writeCustomServiceName($provisionData, (string) $newServiceName, [
                    'operator_id' => $operatorId,
                    'operator_name' => $operatorName,
                ]);
                $fillData['name'] = ServiceHostname::resolveInstanceName($service, $provisionData);
            }

            if ($newCustomHostname !== 'skip') {
                $provisionData = ServiceHostname::writeCustomHostname($provisionData, (string) $newCustomHostname, [
                    'operator_id' => $operatorId,
                    'operator_name' => $operatorName,
                ]);
            }
            $fillData['provision_data'] = $provisionData;

            // 'skip' 表示请求未传 locked_pricing 字段，保持现有值不变
            if ($newLockedPricing !== 'skip') {
                $fillData['locked_pricing'] = $newLockedPricing;
            }

            $service->forceFill($fillData)->save();
        });

        $service = $service->refresh()->loadMissing(['product', 'order']);
        if ($supplierChanged || $supplierProductChanged || $upstreamHostChanged || $newServiceName !== 'skip' || $newCustomHostname !== 'skip') {
            app(ServiceUpstreamBindingWriter::class)->syncServiceState(
                $service,
                $service->product,
                (array) ($service->provision_data ?? [])
            );
        }
        app(ServiceDetailService::class)->forgetDetailCaches($service);

        if ($newCustomHostname !== 'skip' && $previousCustomHostname !== $newCustomHostname) {
            try {
                $this->operationLogService->writeServiceConsoleLog($service, 'service.console.hostname.update', [
                    'category' => 'service',
                    'summary' => $newCustomHostname !== '' ? '设置自定义主机名' : '清空自定义主机名',
                    'hostname' => $newCustomHostname,
                    'previous_hostname' => $previousCustomHostname,
                ], [
                    'actor_type' => 'admin',
                    'actor_user_id' => $operatorId,
                    'actor_name' => $operatorName,
                    'trace_id' => $traceId,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('[管理员更新服务业务信息] 自定义主机名日志写入失败', [
                    'user_id' => $user->id,
                    'service_id' => $service->id,
                    'operator_id' => $operatorId,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);
            }
        }

        if ($newServiceName !== 'skip') {
            try {
                $this->operationLogService->writeServiceConsoleLog($service, 'service.console.name.update', [
                    'category' => 'service',
                    'summary' => trim((string) ($service->name ?? '')) !== '' ? '管理员更新实例名称' : '管理员清空实例名称',
                    'service_name' => (string) ($service->name ?? ''),
                ], [
                    'actor_type' => 'admin',
                    'actor_user_id' => $operatorId,
                    'actor_name' => $operatorName,
                    'trace_id' => $traceId,
                    'ip_address' => (string) ($context['ip_address'] ?? ''),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('[管理员更新服务业务信息] 实例名称日志写入失败', [
                    'user_id' => $user->id,
                    'service_id' => $service->id,
                    'operator_id' => $operatorId,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);
            }
        }

        if ($amountChanged || $supplierChanged || $supplierProductChanged || $upstreamHostChanged || $newLockedPricing !== 'skip') {
            try {
                $renewPricingChanges = [];
                if ($newLockedPricing !== 'skip') {
                    foreach (Service::SUPPORTED_RENEW_BILLING_CYCLES as $cycle => $label) {
                        $entry = is_array($newLockedPricing[$cycle] ?? null) ? $newLockedPricing[$cycle] : [];
                        $renewPricingChanges[] = [
                            'billing_cycle' => $cycle,
                            'billing_cycle_label' => $label,
                            'enabled' => (bool) ($entry['enabled'] ?? false),
                            'manual_amount' => $entry['manual_amount'] ?? null,
                        ];
                    }
                }

                $latestProvisionData = $this->serviceProvisionData($service);
                $this->operationLogService->writeServiceConsoleLog($service, 'service.console.meta.update', [
                    'category' => 'service',
                    'summary' => '管理员更新实例业务信息',
                    'previous_amount' => number_format($previousAmount, 2, '.', ''),
                    'amount' => number_format((float) $service->amount, 2, '.', ''),
                    'previous_supplier_id' => $previousSupplierId > 0 ? $previousSupplierId : null,
                    'supplier_id' => (int) (($latestProvisionData['supplier_id'] ?? 0) ?: 0),
                    'supplier_name' => $selectedSupplier?->name,
                    'previous_upstream_product_id' => $previousUpstreamProductId > 0 ? $previousUpstreamProductId : null,
                    'upstream_product_id' => (int) (($latestProvisionData['upstream_product_id'] ?? 0) ?: 0),
                    'previous_upstream_host_id' => $previousUpstreamHostId > 0 ? $previousUpstreamHostId : null,
                    'upstream_host_id' => (int) (($latestProvisionData['upstream_host_id'] ?? 0) ?: 0),
                    'clear_locked_pricing' => ! empty($data['clear_locked_pricing']),
                    'renew_pricing_changes' => $renewPricingChanges,
                ], [
                    'actor_type' => 'admin',
                    'actor_user_id' => $operatorId,
                    'actor_name' => $operatorName,
                    'trace_id' => $traceId,
                    'ip_address' => (string) ($context['ip_address'] ?? ''),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('[管理员更新服务业务信息] 操作日志写入失败', [
                    'user_id' => $user->id,
                    'service_id' => $service->id,
                    'operator_id' => $operatorId,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);
            }
        }

        return $this->clientServiceConsoleService->getDetailForUser($user, $serviceId, true);
    }

    /**
     * 用户服务电源操作
     */
    public function servicePower(User $user, int $serviceId, string $action, array $context = []): array
    {
        return $this->clientServiceConsoleService->powerActionForUser($user, $serviceId, $action, $context);
    }

    /**
     * 用户服务模块状态
     */
    public function serviceModuleStatus(User $user, int $serviceId, string $type = 'host'): array
    {
        return $this->clientServiceConsoleService->getModuleStatusForUser($user, $serviceId, $type);
    }

    /**
     * 用户服务重装系统选项
     */
    public function serviceReinstallOptions(User $user, int $serviceId, bool $forceRefresh = false): array
    {
        return $this->clientServiceConsoleService->getReinstallOptionsForUser($user, $serviceId, $forceRefresh);
    }

    /**
     * 用户服务重置密码
     */
    public function serviceResetPassword(User $user, int $serviceId, array $data, array $context = []): array
    {
        return $this->clientServiceConsoleService->resetPasswordForUser($user, $serviceId, $data, $context);
    }

    /**
     * 用户服务重装系统
     */
    public function serviceReinstall(User $user, int $serviceId, array $data, array $context = []): array
    {
        return $this->clientServiceConsoleService->reinstallForUser($user, $serviceId, $data, $context);
    }

    /**
     * 管理员手动新增服务
     */
    public function createManualService(User $user, array $data, array $context = []): array
    {
        $product = Product::query()
            ->with('supplier')
            ->findOrFail((int) $data['product_id']);
        $sourceType = trim((string) ($data['source_type'] ?? 'manual'));

        throw_if((int) $product->status !== 1, new BusinessException('商品已下架，无法开通'));
        throw_if($sourceType === 'upstream' && (int) $product->stock === 0, new BusinessException('该商品库存不足，无法继续开通'));

        $billingCycle = trim((string) ($data['billing_cycle'] ?? ''));
        $cyclePrice = $product->getPriceByBillingCycle($billingCycle);

        throw_if(
            $cyclePrice <= 0,
            new BusinessException('所选计费周期不在当前商品的可售范围内')
        );

        $status = (int) ($data['status'] ?? ServiceStatus::ACTIVE);
        $domain = trim((string) ($data['domain'] ?? ''));
        $amount = isset($data['amount'])
            ? round((float) $data['amount'], 2)
            : round((float) $cyclePrice, 2);

        throw_if($amount < 0, new BusinessException('服务金额不能小于 0'));

        $supportsUpstream = $this->supportsManagedUpstream($product);
        $upstreamHostId = (int) (($data['upstream_host_id'] ?? 0) ?: 0);

        if ($sourceType === 'upstream') {
            throw_if(! $supportsUpstream, new BusinessException('当前商品未接入可控上游，无法绑定上游主机'));
            throw_if($upstreamHostId <= 0, new BusinessException('请输入有效的上游实例 ID'));
        }

        $createOrder = (int) ($data['create_order'] ?? 1) === 1;
        $createInvoice = (int) ($data['create_invoice'] ?? 1) === 1;
        $deductBalance = (int) ($data['deduct_balance'] ?? 1) === 1;

        throw_if($deductBalance && ! $createInvoice, new BusinessException('从余额扣款需同时开启自动创建账单'));

        $now = now();
        $orderStatus = $status === ServiceStatus::PENDING ? OrderStatus::PROCESSING : OrderStatus::COMPLETED;
        $expiresAt = $this->resolveManualServiceExpiresAt(
            $data['expires_at'] ?? null,
            $billingCycle,
            $status
        );
        $serviceName = $this->resolveManualServiceName($product, $data, $domain);
        $traceId = trim((string) ($context['trace_id'] ?? ''));
        $operatorId = (int) (($context['operator_id'] ?? 0) ?: 0);
        $operatorName = trim((string) ($context['operator_name'] ?? ''));
        $ipAddress = trim((string) ($context['ip_address'] ?? ''));
        $autoRenew = (int) ($data['auto_renew'] ?? 1);
        $remark = trim((string) ($data['remark'] ?? ''));
        $upstreamStatus = trim((string) ($data['upstream_status'] ?? ''));
        $upstreamProviderKey = $sourceType === 'upstream'
            ? $this->resolveProductProviderKey($product)
            : '';
        throw_if($sourceType === 'upstream' && $upstreamProviderKey === '', new BusinessException('商品未配置上游插件绑定，无法创建上游服务'));

        $upstreamSupplierId = $sourceType === 'upstream' ? $this->resolveProductSupplierId($product) : 0;
        $upstreamProductId = $sourceType === 'upstream' ? $this->resolveProductUpstreamProductId($product) : 0;
        $dedicatedIp = trim((string) ($data['dedicated_ip'] ?? ''));
        $internalIp = trim((string) ($data['internal_ip'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $os = trim((string) ($data['os'] ?? ''));
        $port = (int) (($data['port'] ?? 0) ?: 0);

        $balanceTransactionId = 0;
        $balanceAfter = '';

        $service = DB::transaction(function () use (
            $user,
            $product,
            $billingCycle,
            $amount,
            $status,
            $sourceType,
            $domain,
            $now,
            $orderStatus,
            $expiresAt,
            $serviceName,
            $traceId,
            $operatorId,
            $operatorName,
            $remark,
            $upstreamHostId,
            $upstreamStatus,
            $upstreamProviderKey,
            $upstreamSupplierId,
            $upstreamProductId,
            $dedicatedIp,
            $internalIp,
            $username,
            $password,
            $os,
            $port,
            $autoRenew,
            $createOrder,
            $createInvoice,
            $deductBalance,
            &$balanceTransactionId,
            &$balanceAfter
        ) {
            $order = null;
            if ($createOrder) {
                $order = Order::create([
                    'order_no' => Order::generateOrderNo(),
                    'projection_type' => Order::PROJECTION_TYPE_PROVISIONING,
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'product_spec_snapshot' => trim((string) $product->name),
                    'product_type_snapshot' => (string) $product->product_type,
                    'type' => 'new',
                    'amount' => $amount,
                    'discount' => 0,
                    'paid_amount' => 0,
                    'billing_cycle' => $billingCycle,
                    'config_snapshot' => array_filter([
                        'hostname' => $domain,
                        'source_type' => $sourceType,
                        'admin_manual' => true,
                        'remark' => $remark,
                        'trace_id' => $traceId,
                    ], fn ($value) => ! in_array($value, ['', null], true)),
                    'status' => OrderStatus::PENDING,
                    'operator' => $operatorName !== '' ? $operatorName : null,
                    'trace_id' => $traceId !== '' ? $traceId : null,
                ]);
            }

            $provisionData = array_filter([
                'source_type' => $sourceType,
                'provider_key' => $upstreamProviderKey,
                'supplier_id' => $upstreamSupplierId,
                'upstream_product_id' => $upstreamProductId,
                'upstream_host_id' => $sourceType === 'upstream' ? $upstreamHostId : 0,
                'upstream_status' => $sourceType === 'upstream'
                    ? ($upstreamStatus !== '' ? $upstreamStatus : ($status === ServiceStatus::ACTIVE ? 'active' : 'pending'))
                    : '',
                'dedicated_ip' => $dedicatedIp,
                'os' => $os,
                'requested_host' => $domain,
                'manual_remark' => $remark,
                'created_from_admin' => true,
                'created_from_admin_id' => $operatorId > 0 ? $operatorId : null,
                'created_from_admin_name' => $operatorName,
                'trace_id' => $traceId,
                'last_manual_linked_at' => $now->format('Y-m-d H:i:s'),
                'connection_secret' => $this->buildConnectionSecret([
                    'hostname' => $domain,
                    'username' => $username,
                    'password' => $password,
                    'port' => $port,
                    'internal_ip' => $internalIp,
                ]),
                'connection_cached_at' => ($domain !== '' || $username !== '' || $password !== '' || $port > 0 || $internalIp !== '')
                    ? $now->format('Y-m-d H:i:s')
                    : '',
            ], function ($value, string $key) {
                if (in_array($key, ['created_from_admin', 'source_type'], true)) {
                    return true;
                }

                return ! in_array($value, ['', null, 0], true);
            }, ARRAY_FILTER_USE_BOTH);

            $service = Service::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'name' => $serviceName,
                'domain' => $domain,
                'billing_cycle' => $billingCycle,
                'amount' => $amount,
                'locked_pricing' => Service::buildDefaultRenewPricing(
                    is_array($product->pricing ?? null) ? $product->pricing : [],
                    $billingCycle,
                    $amount
                ),
                'status' => $status,
                'provision_data' => $provisionData,
                'expires_at' => $expiresAt,
                'auto_renew' => $autoRenew,
                'suspended_reason' => $status === ServiceStatus::PENDING
                    ? '管理员已创建服务，等待后续开通'
                    : ($status === ServiceStatus::SUSPENDED ? '管理员手动暂停' : null),
            ]);

            if ($order instanceof Order) {
                $order->forceFill([
                    'service_id' => $service->id,
                ])->save();
                $service->forceFill(['order_id' => $order->id])->save();
            }

            $invoice = null;
            if ($createInvoice) {
                $invoice = $order instanceof Order
                    ? $this->invoiceService->createFromOrder($order)
                    : $this->invoiceService->createDirect([
                        'user_id' => $user->id,
                        'product_id' => $product->id,
                        'product_spec_snapshot' => trim((string) $product->name),
                        'product_type_snapshot' => (string) $product->product_type,
                        'service_id' => $service->id,
                        'type' => 'normal',
                        'amount' => $amount,
                        'billing_cycle' => $billingCycle,
                        'config_snapshot' => array_filter([
                            'hostname' => $domain,
                            'source_type' => $sourceType,
                            'admin_manual' => true,
                            'remark' => $remark,
                            'trace_id' => $traceId,
                        ], fn ($value) => ! in_array($value, ['', null], true)),
                        'trace_id' => $traceId !== '' ? $traceId : null,
                    ]);

                $invoice->forceFill([
                    'service_id' => $service->id,
                    'remark' => $remark !== '' ? $remark : null,
                    'operator' => $operatorName !== '' ? $operatorName : null,
                ])->save();

                $provisionData['source_invoice_id'] = (int) $invoice->id;
                $service->forceFill([
                    'invoice_id' => $invoice->id,
                    'provision_data' => $provisionData,
                ])->save();
            }

            if ($deductBalance && $invoice instanceof Invoice) {
                $lockedUser = User::query()
                    ->lockForUpdate()
                    ->findOrFail($user->id);
                $currentBalance = $this->accounts()->cashBalance($lockedUser, true);

                throw_if(
                    $currentBalance < $amount,
                    new BusinessException('余额不足，无法创建实例')
                );

                if ($amount > 0) {
                    $balanceAfter = $this->accounts()->setCashBalance($lockedUser, $currentBalance - $amount);
                    $balanceTransaction = AccountTransaction::query()->create([
                        'user_id' => (int) $lockedUser->id,
                        'account_type' => 'cash',
                        'event_type' => FinanceLedgerEventType::INVOICE_PAYMENT,
                        'change_amount' => number_format(-$amount, 2, '.', ''),
                        'balance_after' => $balanceAfter,
                        'source_type' => 'invoice',
                        'source_id' => (int) $invoice->id,
                        'origin_type' => 'invoice',
                        'origin_id' => (int) $invoice->id,
                        'remark' => '支付账单 '.(string) $invoice->invoice_no,
                        'operator' => $operatorName !== '' ? $operatorName : null,
                        'trace_id' => $traceId !== '' ? $traceId : null,
                    ]);
                    $balanceTransactionId = (int) $balanceTransaction->id;
                }

                if ($order instanceof Order) {
                    $order->forceFill([
                        'status' => $orderStatus,
                        'paid_amount' => $amount,
                        'paid_at' => $now,
                    ])->save();
                }

                $invoice->forceFill([
                    'status' => InvoiceStatus::PAID,
                    'paid_amount' => $amount,
                    'paid_at' => $now,
                ])->save();
            }

            if ($invoice instanceof Invoice) {
                $this->invoiceService->syncProjection($invoice);
            }

            if ($sourceType === 'upstream' && (int) $product->stock > 0) {
                $product->decrement('stock');
            }

            return $service;
        });

        $service->loadMissing(['order.invoice', 'invoice']);
        if ($sourceType === 'upstream') {
            $service->loadMissing('product.supplier');
            app(ServiceUpstreamBindingWriter::class)->syncServiceState(
                $service,
                $service->product,
                $this->serviceProvisionData($service, includeSecrets: true)
            );
        }

        try {
            $invoice = $service->invoice ?? $service->order?->invoice;
            $this->operationLogService->write(
                userId: $operatorId > 0 ? $operatorId : null,
                userType: 'admin',
                action: 'order.service.manual_create',
                module: $service->order ? 'order' : 'service',
                targetId: (int) ($service->order?->id ?? $service->id),
                detail: [
                    'order_no' => $service->order?->order_no ?? '',
                    'invoice_id' => (int) ($invoice?->id ?? 0),
                    'invoice_no' => $invoice?->invoice_no ?? '',
                    'service_id' => (int) $service->id,
                    'source_type' => $sourceType,
                    'service_status' => $status,
                    'billing_cycle' => $billingCycle,
                    'amount' => number_format($amount, 2, '.', ''),
                    'create_order' => $createOrder,
                    'create_invoice' => $createInvoice,
                    'deduct_balance' => $deductBalance,
                    'payment_method' => $deductBalance ? 'balance' : null,
                    'balance_transaction_id' => $balanceTransactionId ?: null,
                    'balance_after' => $balanceAfter !== '' ? $balanceAfter : null,
                    'operator_name' => $operatorName,
                    'trace_id' => $traceId,
                ],
                ipAddress: $ipAddress !== '' ? $ipAddress : null,
            );
        } catch (\Throwable $exception) {
            Log::warning('[管理员添加服务] 操作日志写入失败', [
                'user_id' => $user->id,
                'service_id' => $service->id,
                'order_id' => (int) ($service->order?->id ?? 0),
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }

        return $this->clientServiceConsoleService->getDetailForUser($user, (int) $service->id, false);
    }

    private function normalizePositiveAmount(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $amount = round((float) $value, 2);

        return $amount > 0 ? $amount : null;
    }

    /**
     * 失败服务重新提交上游开通
     */
    public function manualProvisionService(User $user, int $serviceId, array $data, array $context = []): array
    {
        $service = Service::query()
            ->with(['product', 'order'])
            ->where('user_id', $user->id)
            ->findOrFail($serviceId);

        $product = $service->product;
        throw_if(! $product, new BusinessException('服务未关联商品，暂不支持重新提交上游开通'));

        $currentProvisionData = $this->serviceProvisionData($service);
        $provisionError = trim((string) ($currentProvisionData['provision_error'] ?? ''));

        throw_if($provisionError === '', new BusinessException('当前服务不存在上游开通失败记录，无需重新提交'));
        $operatorId = (int) (($context['operator_id'] ?? 0) ?: 0);
        $operatorName = trim((string) ($context['operator_name'] ?? ''));
        $traceId = trim((string) ($context['trace_id'] ?? ''));
        $ipAddress = trim((string) ($context['ip_address'] ?? ''));
        $remark = TextSanitizer::clean((string) ($data['remark'] ?? ''), true);
        $logContext = [
            'actor_type' => 'admin',
            'actor_user_id' => $operatorId,
            'actor_name' => $operatorName,
            'ip_address' => $ipAddress,
            'trace_id' => $traceId,
        ];

        try {
            $boundInvoice = Invoice::query()
                ->where(function ($query) use ($service) {
                    $query->where('service_id', (int) $service->id);

                    if ((int) ($service->invoice_id ?? 0) > 0) {
                        $query->orWhere('id', (int) $service->invoice_id);
                    }
                })
                ->latest('id')
                ->first();

            if ($service->order instanceof Order) {
                $service = $this->provisionService->retryFailedProvision($service->order);
            } elseif ($boundInvoice instanceof Invoice) {
                $service = $this->provisionService->retryFailedProvisionByInvoice($boundInvoice);
            } else {
                throw new BusinessException('服务未关联账单，无法重新提交上游开通');
            }
        } catch (\Throwable $exception) {
            try {
                $this->operationLogService->writeServiceConsoleLog($service->refresh()->loadMissing(['product', 'order']), 'service.console.manual_provision', [
                    'category' => 'service',
                    'summary' => '管理员重新提交上游开通失败',
                    'remark' => $remark,
                    'manual_source' => 'retry_upstream_purchase',
                    'previous_provision_error' => $provisionError,
                    'provision_error' => $exception->getMessage(),
                    'result' => 'failed',
                ], $logContext);
            } catch (\Throwable $logException) {
                Log::warning('[管理员重试上游开通] 失败日志写入失败', [
                    'user_id' => $user->id,
                    'service_id' => $service->id,
                    'operator_id' => $operatorId,
                    'message' => $logException->getMessage(),
                    'exception' => $logException::class,
                ]);
            }

            throw $exception;
        }

        try {
            $this->operationLogService->writeServiceConsoleLog($service->refresh()->loadMissing(['product', 'order']), 'service.console.manual_provision', [
                'category' => 'service',
                'summary' => '管理员重新提交上游开通',
                'remark' => $remark,
                'manual_source' => 'retry_upstream_purchase',
                'previous_provision_error' => $provisionError,
                'result' => 'submitted',
            ], $logContext);
        } catch (\Throwable $exception) {
            Log::warning('[管理员重试上游开通] 操作日志写入失败', [
                'user_id' => $user->id,
                'service_id' => $service->id,
                'operator_id' => $operatorId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }

        return $this->clientServiceConsoleService->getDetailForUser($user, (int) $service->id, true);
    }

    /**
     * 删除用户服务记录
     */
    public function deleteService(User $user, int $serviceId, array $context = []): void
    {
        $service = Service::query()
            ->with(['product', 'order.invoice'])
            ->where('user_id', $user->id)
            ->findOrFail($serviceId);

        $product = $service->product;
        $order = $service->order;
        $serviceName = trim((string) ($service->name ?: ($product?->name ?? '')));
        $orderNo = trim((string) ($order?->order_no ?? ''));
        $productDisplayName = trim((string) ($product?->name ?? ''));
        $operatorId = (int) (($context['operator_id'] ?? 0) ?: 0);
        $operatorName = trim((string) ($context['operator_name'] ?? ''));
        $traceId = trim((string) ($context['trace_id'] ?? ''));
        $ipAddress = trim((string) ($context['ip_address'] ?? ''));

        DB::transaction(function () use ($service, $product) {
            Order::query()
                ->where('service_id', $service->id)
                ->update(['service_id' => null]);

            Ticket::query()
                ->where('service_id', $service->id)
                ->update(['service_id' => null]);

            // Record-only deletion must not invoke or destroy the upstream instance.
            DB::table('service_upstream_bindings')
                ->where('service_id', $service->id)
                ->delete();

            if ($product instanceof Product && (int) $product->stock >= 0) {
                $product->increment('stock');
            }

            $service->delete();
        });

        try {
            $this->operationLogService->write(
                userId: $user->id,
                userType: 'client',
                action: 'service.record.deleted',
                module: 'service',
                targetId: $serviceId,
                detail: [
                    'service_id' => $serviceId,
                    'service_name' => $serviceName,
                    'product_name' => $productDisplayName,
                    'order_no' => $orderNo,
                    'operator_type' => 'admin',
                    'operator_id' => $operatorId,
                    'operator_name' => $operatorName,
                    'trace_id' => $traceId,
                ],
                ipAddress: $ipAddress !== '' ? $ipAddress : null,
            );

            $this->operationLogService->write(
                userId: $operatorId > 0 ? $operatorId : null,
                userType: 'admin',
                action: 'user.service.record.deleted',
                module: 'service',
                targetId: $serviceId,
                detail: [
                    'target_user_id' => (int) $user->id,
                    'target_user_email' => trim((string) $user->email),
                    'service_name' => $serviceName,
                    'product_name' => $productDisplayName,
                    'order_no' => $orderNo,
                    'operator_name' => $operatorName,
                    'trace_id' => $traceId,
                ],
                ipAddress: $ipAddress !== '' ? $ipAddress : null,
            );
        } catch (\Throwable $exception) {
            Log::warning('[管理员删除服务记录] 操作日志写入失败', [
                'user_id' => $user->id,
                'service_id' => $serviceId,
                'operator_id' => $operatorId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    private function supportsManagedUpstream(?Product $product): bool
    {
        if (! $product instanceof Product) {
            return false;
        }

        $supplier = $this->bindingResolver()->supplierForProduct($product);

        if ($supplier instanceof Supplier) {
            $supplier = $this->bindingResolver()->supplierWithRuntimeCredentials($supplier);
            app(UpstreamBindingWriter::class)->syncSupplierBinding($supplier);
            app(UpstreamBindingWriter::class)->syncProductBinding($product, $supplier);
        }

        return $supplier instanceof Supplier
            && app(ProviderResolver::class)->resolveForSupplier($supplier)->supports(ProvidesConsoleRuntime::class);
    }

    /**
     * @param  array<string, mixed>  $provisionData
     */
    private function resolveServiceProviderKey(Service $service, array $provisionData): string
    {
        $providerKey = $this->bindingResolver()->providerKeyForService($service);
        $providerKey ??= $service->product instanceof Product ? $this->resolveProductProviderKey($service->product) : null;

        return trim((string) $providerKey);
    }

    private function resolveProductProviderKey(?Product $product): string
    {
        if (! $product instanceof Product) {
            return '';
        }

        $providerKey = $this->bindingResolver()->providerKeyForProduct($product);

        return trim((string) $providerKey);
    }

    private function resolveSupplierProviderKey(Supplier $supplier): string
    {
        $providerKey = $this->bindingResolver()->providerKeyForSupplier($supplier);

        return trim((string) ($providerKey ?? ''));
    }

    /**
     * @param  array<string, mixed>  $provisionData
     */
    private function resolveServiceSupplierId(Service $service, array $provisionData): int
    {
        $supplierId = $this->bindingResolver()->supplierIdForService($service);
        $supplierId ??= $service->product instanceof Product ? $this->resolveProductSupplierId($service->product) : null;

        return (int) ($supplierId ?? 0);
    }

    private function resolveProductSupplierId(?Product $product): int
    {
        if (! $product instanceof Product) {
            return 0;
        }

        $supplierId = $this->bindingResolver()->supplierIdForProduct($product);

        return (int) ($supplierId ?? 0);
    }

    /**
     * @param  array<string, mixed>  $provisionData
     */
    private function resolveServiceUpstreamProductId(Service $service, array $provisionData): int
    {
        $upstreamProductId = $this->bindingResolver()->upstreamProductIdForService($service);
        $upstreamProductId ??= $service->product instanceof Product ? $this->resolveProductUpstreamProductId($service->product) : null;

        return (int) ($upstreamProductId ?? 0);
    }

    private function resolveProductUpstreamProductId(?Product $product): int
    {
        if (! $product instanceof Product) {
            return 0;
        }

        $upstreamProductId = $this->positiveInt($this->bindingResolver()->upstreamProductIdForProduct($product));

        return (int) ($upstreamProductId ?? 0);
    }

    /**
     * @param  array<string, mixed>  $provisionData
     */
    private function resolveServiceUpstreamHostId(Service $service, array $provisionData): int
    {
        return (int) ($this->positiveInt($this->bindingResolver()->upstreamServiceIdForService($service)) ?? 0);
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    private function nonBlank(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function bindingResolver(): PluginBindingResolver
    {
        return app(PluginBindingResolver::class);
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

    private function resolveManualServiceExpiresAt(mixed $expiresAt, string $billingCycle, int $status): ?Carbon
    {
        if ($expiresAt !== null && $expiresAt !== '') {
            return Carbon::parse((string) $expiresAt);
        }

        if ($status === ServiceStatus::CANCELLED) {
            return null;
        }

        return match ($billingCycle) {
            'monthly' => now()->addMonth(),
            'quarterly' => now()->addMonths(3),
            'semiannually' => now()->addMonths(6),
            'annually' => now()->addYear(),
            'biennially' => now()->addYears(2),
            'triennially' => now()->addYears(3),
            default => null,
        };
    }

    private function resolveManualServiceName(Product $product, array $data, string $domain): string
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        if ($domain !== '') {
            return $domain;
        }

        return trim((string) $product->name) !== '' ? (string) $product->name : '未命名服务';
    }

    private function buildConnectionSecret(array $connection): ?string
    {
        $payload = [
            'hostname' => trim((string) ($connection['hostname'] ?? '')),
            'username' => trim((string) ($connection['username'] ?? '')),
            'password' => (string) ($connection['password'] ?? ''),
            'port' => (int) (($connection['port'] ?? 0) ?: 0),
            'internal_ip' => trim((string) ($connection['internal_ip'] ?? '')),
        ];

        $hasValue = collect($payload)->contains(fn ($value) => ! in_array($value, ['', null, 0], true));
        if (! $hasValue) {
            return null;
        }

        return Crypt::encryptString((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
