<?php

namespace App\Services\Provisioning;

use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\ServiceUpstreamBindingWriter;
use App\Services\Integrations\Plugins\UpstreamBindingWriter;
use App\Services\Integrations\Support\ProviderErrorMapper;
use App\Services\System\SettingService;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\ProviderResolver;
use App\Support\ProductProvisionHostname;
use App\Support\SensitiveDataSanitizer;
use App\Support\ServiceHostname;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class ProvisionService
{
    private const RANGE_TYPES = [4, 7, 9, 11, 14, 15, 16, 17, 18, 19];

    private const OS_TYPES = [5];

    private const TYPE_FIELD_MAP = [
        4 => 'ip_num',
        5 => 'os',
        6 => 'cpu',
        7 => 'cpu',
        8 => 'memory',
        9 => 'memory',
        10 => 'bw',
        11 => 'bw',
        12 => 'area',
        13 => 'system_disk_size',
        14 => 'system_disk_size',
        16 => 'cpu',
        17 => 'memory',
        18 => 'bw',
        19 => 'system_disk_size',
    ];

    private ?ServiceUpstreamBindingWriter $serviceBindingWriter = null;

    public function __construct(
        private ProviderResolver $providerResolver,
        private SettingService $settingService,
        private ?PluginBindingResolver $bindingResolver = null,
    ) {}

    public function processPaidOrder(Order $order): ?Service
    {
        $order->loadMissing(['product.supplier', 'user', 'service']);

        if (! $order->exists || $order->type !== 'new' || ! $order->product) {
            return null;
        }

        $service = $this->ensureLocalService($order);

        // 管理员手动开通的服务在建单时就已就绪，事后付款只需完成订单，
        // 不能因为商品非自动开通就把已激活的服务打回待开通。
        if ($this->isAdminManualOrder($order) && $service->status !== ServiceStatus::PENDING) {
            $order->forceFill([
                'service_id' => $service->id,
                'status' => OrderStatus::COMPLETED,
                'service_snapshot' => $this->buildServiceSnapshot($service),
            ])->save();

            return $service;
        }

        if (! $this->shouldAutoProvision($order->product)) {
            $this->markPending($order, $service, '待人工开通');

            return $service;
        }

        if ($service->status === ServiceStatus::ACTIVE && $this->resolveServiceUpstreamServiceId($service) !== null) {
            $order->forceFill([
                'service_id' => $service->id,
                'status' => OrderStatus::COMPLETED,
                'service_snapshot' => $this->buildServiceSnapshot($service),
            ])->save();

            return $service;
        }

        $this->assertNoUnresolvedUpstreamProvisionInvoice($service);

        return $this->submitUpstreamProvision($order, $service);
    }

    /**
     * 基于账单直接开通（无订单场景的过渡桥接）
     * 优先尝试从 invoice->order 委托，若无 order 则用 invoice 自带字段构造上下文。
     */
    public function processPaidInvoice(Invoice $invoice): ?Service
    {
        $invoice->loadMissing(['order.product.supplier', 'order.user', 'order.service', 'product.supplier']);

        if ($invoice->order) {
            return $this->processPaidOrder($invoice->order);
        }

        if (! $invoice->product_id || (string) ($invoice->type ?? '') !== 'normal') {
            return null;
        }

        $product = $invoice->product;
        if (! $product) {
            return null;
        }

        Log::info('[自动开通] 基于账单开通（无关联订单）', [
            'invoice_id' => $invoice->id,
            'invoice_no' => $invoice->invoice_no,
            'product_id' => $product->id,
        ]);

        $service = Service::create([
            'user_id' => $invoice->user_id,
            'product_id' => $invoice->product_id,
            'name' => $invoice->display_product_name ?: '未命名服务',
            'domain' => '',
            'billing_cycle' => (string) ($invoice->billing_cycle ?? ''),
            'amount' => (float) $invoice->amount,
            'locked_pricing' => Service::buildDefaultRenewPricing(
                is_array($product->pricing ?? null) ? $product->pricing : [],
                (string) ($invoice->billing_cycle ?? ''),
                $invoice->amount
            ),
            'status' => ServiceStatus::PENDING,
            'auto_renew' => 0,
            'provision_data' => [
                'created_from_invoice' => $invoice->invoice_no,
            ],
        ]);

        $invoice->forceFill(['service_id' => $service->id])->save();

        return $service;
    }

    public function retryFailedProvision(Order $order): Service
    {
        $order->loadMissing(['product.supplier', 'user', 'service']);

        if (! $order->exists || $order->type !== 'new' || ! $order->product) {
            throw new BusinessException('订单未关联可自动开通商品，无法重新提交上游购买');
        }

        $service = $this->ensureLocalService($order);

        if (! $this->shouldAutoProvision($order->product)) {
            throw new BusinessException('当前商品未接入自动开通上游，无法重新提交上游购买');
        }

        if ($service->status === ServiceStatus::ACTIVE && $this->resolveServiceUpstreamServiceId($service) !== null) {
            return $service;
        }

        $this->assertNoUnresolvedUpstreamProvisionInvoice($service);

        $this->prepareServiceForProvisionRetry($order, $service);

        return $this->submitUpstreamProvision($order, $service, true);
    }

    public function retryFailedProvisionByInvoice(Invoice $invoice): Service
    {
        $invoice->loadMissing(['order', 'service']);

        if ($invoice->order instanceof Order) {
            return $this->retryFailedProvision($invoice->order);
        }

        throw new BusinessException('账单未关联订单，暂不支持重新提交上游开通');
    }

    /**
     * 管理员主动发起的上游开通（不检查 auto_setup，直接走完整购物车流程）
     */
    public function adminInitiatedProvision(Order $order): Service
    {
        $order->loadMissing(['product.supplier', 'user', 'service']);

        if (! $order->exists || ! $order->product) {
            throw new BusinessException('订单未关联商品，无法发起上游开通');
        }

        if (! $this->resolveProductSupplier($order->product) instanceof Supplier) {
            throw new BusinessException('当前商品未配置供应商，无法发起上游开通');
        }

        $service = $this->ensureLocalService($order);

        $this->assertNoUnresolvedUpstreamProvisionInvoice($service);

        return $this->submitUpstreamProvision($order, $service, true);
    }

    private function submitUpstreamProvision(Order $order, Service $service, bool $throwOnFailure = false): Service
    {
        $this->markPending($order, $service);
        $providerKey = $this->resolveProviderKeyForProduct($order->product);

        try {
            $result = $this->provisionViaUpstream($order, $service);
            $hostDetail = $result['host_detail'];
            $serviceStatus = $this->resolveServiceStatusFromUpstream($hostDetail);

            $service->forceFill([
                'status' => $serviceStatus,
                'name' => (string) ($service->name ?: $order->display_product_name ?: $order->product?->name ?: '未命名服务'),
                'domain' => (string) ($hostDetail['domain'] ?? $result['requested_host']),
                'expires_at' => $this->resolveServiceExpiry($hostDetail, $order),
                'suspended_reason' => null,
                'provision_data' => array_merge($this->serviceProvisionData($service), [
                    'provider_key' => $providerKey,
                    'supplier_id' => $this->resolveProductSupplierId($order->product),
                    'requested_host' => $result['requested_host'],
                    'upstream_invoice_id' => $result['upstream_invoice_id'],
                    'upstream_host_id' => $result['upstream_host_id'],
                    'upstream_host_ids' => $result['upstream_host_ids'],
                    'upstream_product_id' => $this->resolveUpstreamProductId($order->product),
                    'upstream_product_name' => trim((string) ($hostDetail['product_name'] ?? '')),
                    'upstream_status' => (string) ($hostDetail['domainstatus'] ?? ''),
                    'dedicated_ip' => (string) ($hostDetail['dedicatedip'] ?? ''),
                    'assigned_ips' => is_array($hostDetail['assignedips'] ?? null) ? $hostDetail['assignedips'] : [],
                    'host_config_option' => is_array($hostDetail['config_option'] ?? null) ? $hostDetail['config_option'] : [],
                    'os' => (string) ($hostDetail['os'] ?? ''),
                    'connection_secret' => $this->encryptConnectionCache([
                        'hostname' => (string) ($hostDetail['domain'] ?? $result['requested_host']),
                        'username' => (string) ($hostDetail['username'] ?? ''),
                        'password' => (string) ($hostDetail['password'] ?? ''),
                        'port' => (int) (($hostDetail['port'] ?? 0) ?: 0),
                        'internal_ip' => (string) ($hostDetail['internalip'] ?? $hostDetail['privateip'] ?? ''),
                    ]),
                    'connection_cached_at' => now()->format('Y-m-d H:i:s'),
                    'last_provisioned_at' => now()->format('Y-m-d H:i:s'),
                    'provision_error' => null,
                ]),
            ])->save();
            $this->serviceBindingWriter()->recordProvisionAttempt(
                $service,
                $order->product,
                (array) ($service->provision_data ?? []),
                'success',
                null,
                [
                    'order_id' => (int) $order->id,
                    'order_no' => (string) $order->order_no,
                    'supplier_id' => $this->resolveProductSupplierId($order->product),
                    'upstream_product_id' => $this->resolveUpstreamProductId($order->product),
                    'requested_host' => $result['requested_host'] ?? null,
                ],
                [
                    'upstream_invoice_id' => $result['upstream_invoice_id'] ?? null,
                    'upstream_host_id' => $result['upstream_host_id'] ?? null,
                    'upstream_host_ids' => $result['upstream_host_ids'] ?? null,
                ]
            );

            $order->forceFill([
                'service_id' => $service->id,
                'status' => $serviceStatus === ServiceStatus::ACTIVE ? OrderStatus::COMPLETED : OrderStatus::PROCESSING,
                'service_snapshot' => $this->buildServiceSnapshot($service),
            ])->save();

            return $service;
        } catch (\Throwable $exception) {
            $message = $exception instanceof BusinessException
                ? $exception->getMessage()
                : '上游开通失败，请检查供应商配置或日志';

            $service->forceFill([
                'status' => ServiceStatus::PENDING,
                'suspended_reason' => mb_substr($message, 0, 200),
                'provision_data' => array_merge($this->serviceProvisionData($service), [
                    'provider_key' => $providerKey,
                    'supplier_id' => $this->resolveProductSupplierId($order->product),
                    'upstream_product_id' => $this->resolveUpstreamProductId($order->product),
                    'last_provision_attempt_at' => now()->format('Y-m-d H:i:s'),
                    'provision_error' => $message,
                ]),
            ])->save();
            $this->serviceBindingWriter()->recordProvisionAttempt(
                $service,
                $order->product,
                (array) ($service->provision_data ?? []),
                'failed',
                $message,
                [
                    'order_id' => (int) $order->id,
                    'order_no' => (string) $order->order_no,
                    'supplier_id' => $this->resolveProductSupplierId($order->product),
                    'upstream_product_id' => $this->resolveUpstreamProductId($order->product),
                ]
            );

            $order->forceFill([
                'service_id' => $service->id,
                'status' => OrderStatus::PROCESSING,
                'service_snapshot' => $this->buildServiceSnapshot($service),
            ])->save();

            Log::error('[自动开通] 支付后上游开通失败', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'service_id' => $service->id,
                'supplier_id' => $this->resolveProductSupplierId($order->product),
                'upstream_product_id' => $this->resolveUpstreamProductId($order->product),
                'message' => $message,
                'exception' => $exception::class,
            ]);

            if ($throwOnFailure) {
                throw $exception instanceof BusinessException
                    ? $exception
                    : new BusinessException($message);
            }

            return $service;
        }
    }

    private function ensureLocalService(Order $order): Service
    {
        if ($order->service) {
            return $order->service;
        }

        $hostname = $this->resolveProvisionHostname($order);
        $instanceName = ServiceHostname::resolveInstanceNameFromProduct(
            $order->product,
            array_merge((array) ($order->config_snapshot ?? []), [
                'hostname' => $hostname,
            ]),
            trim((string) ($order->display_product_name ?? '')),
            trim((string) ($order->product_spec_snapshot ?? $order->display_product_name ?? $order->product?->name ?? ''))
        );

        $service = Service::create([
            'user_id' => $order->user_id,
            'product_id' => (int) ($order->product?->id ?? $order->product_id),
            'order_id' => $order->id,
            'name' => $instanceName !== '' ? $instanceName : '未命名服务',
            'domain' => $hostname,
            'billing_cycle' => (string) $order->billing_cycle,
            'amount' => (float) $order->amount,
            // 开通时快照标准续费周期价格，默认按购买时价格续费。
            'locked_pricing' => Service::buildDefaultRenewPricing(
                is_array($order->product?->pricing ?? null) ? $order->product->pricing : [],
                (string) $order->billing_cycle,
                $order->amount
            ),
            'status' => ServiceStatus::PENDING,
            'auto_renew' => 0,
            'provision_data' => [
                'requested_config' => $this->sanitizeRequestedConfig(
                    array_merge((array) ($order->config_snapshot ?? []), [
                        'hostname' => $hostname,
                    ])
                ),
                'created_from_order' => $order->order_no,
            ],
        ]);

        $order->forceFill([
            'service_id' => $service->id,
            'service_snapshot' => $this->buildServiceSnapshot($service),
        ])->save();

        return $service;
    }

    private function isAdminManualOrder(Order $order): bool
    {
        $snapshot = (array) $order->config_snapshot;

        return filter_var($snapshot['admin_manual'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function markPending(Order $order, Service $service, ?string $reason = null): void
    {
        $service->forceFill([
            'status' => ServiceStatus::PENDING,
            'suspended_reason' => $reason,
        ])->save();

        $order->forceFill([
            'service_id' => $service->id,
            'status' => OrderStatus::PROCESSING,
            'service_snapshot' => $this->buildServiceSnapshot($service),
        ])->save();
    }

    private function shouldAutoProvision(Product $product): bool
    {
        $this->ensureProductBinding($product);

        return (int) $product->auto_setup === 1
            && $this->resolveProductSupplierId($product) > 0
            && $this->resolveUpstreamProductId($product) > 0
            && $this->providerResolver->resolveForProduct($product)->supports(ProvidesProvisioning::class);
    }

    private function resolveProviderKeyForProduct(?Product $product): string
    {
        if ($product instanceof Product) {
            $this->ensureProductBinding($product);
            $resolved = $this->providerResolver->resolveForProduct($product);
            if ($resolved->key() !== null && trim($resolved->key()) !== '') {
                return (string) $resolved->key();
            }
        }

        return '';
    }

    private function provisionViaUpstream(Order $order, ?Service $service = null): array
    {
        $product = $order->product;
        $supplier = $this->resolveProductSupplier($product);

        if (! $supplier instanceof Supplier) {
            throw new BusinessException('供应商信息不存在，无法自动开通');
        }

        $provisioning = $this->resolveProvisioningCapability($product);
        if (method_exists($provisioning, 'provisionOrder')) {
            $this->resolveProvisionHostname($order);
            $cartLockKey = $this->supplierCartLockKey($supplier);

            return Cache::lock($cartLockKey, $this->supplierCartLockTtl())->block(10, function () use ($order, $supplier, $provisioning, $service) {
                // 锁内基于 DB 最新状态复查幂等：并发"队列履约 + 管理员手动重试"时，
                // 另一路径可能已 checkout 并提交 checkpoint（upstream_invoice_id/upstream_host_id 已落库）。
                // 必须用 fresh 数据做幂等回查，避免基于旧内存值重复走购物车流程造成上游二次开通。
                $freshService = ($service ?? $order->service)?->fresh();
                if ($freshService instanceof Service) {
                    $this->assertNoUnresolvedUpstreamProvisionInvoice($freshService);
                }

                return $provisioning->provisionOrder($order, $supplier, $freshService ?? $service ?? $order->service);
            });
        }

        // 幂等键回查：如果 service 已有 upstream_host_id，先查上游确认 host 存在且 Active，
        // 直接返回而不重新走购物车流程，避免重复开通。
        $existingService = $service ?? $order->service;
        $existingHostId = $existingService instanceof Service ? $this->resolveReusableServiceUpstreamServiceId($existingService) : null;
        if ($existingService instanceof Service && $existingHostId !== null) {
            $existingHostValue = $this->upstreamServiceIdPayloadValue($existingHostId);
            try {
                $jwt = $provisioning->login($supplier);
                $detailResponse = $provisioning->get($supplier, "/v1/hosts/{$existingHostId}", $jwt);
                if (($detailResponse['status'] ?? 0) === 200) {
                    $detailPayload = $this->extractPayload($detailResponse);
                    $hostDetail = is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];
                    Log::info('[幂等回查] 上游 host 已存在，跳过重复开通', [
                        'order_id' => $order->id,
                        'upstream_host_id' => $existingHostId,
                    ]);

                    $existingProvisionData = $this->serviceProvisionData($existingService);

                    return [
                        'requested_host' => (string) ($existingProvisionData['requested_host'] ?? ''),
                        'upstream_invoice_id' => (int) ($existingProvisionData['upstream_invoice_id'] ?? 0),
                        'upstream_host_ids' => $existingProvisionData['upstream_host_ids'] ?? [$existingHostValue],
                        'upstream_host_id' => $existingHostValue,
                        'host_detail' => $hostDetail,
                    ];
                }
            } catch (\Throwable $e) {
                // 回查失败（404/鉴权过期等）不阻断，继续走正常开通流程
                Log::warning('[幂等回查] 上游 host 查询失败，继续正常开通', [
                    'order_id' => $order->id,
                    'upstream_host_id' => $existingHostId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $cartLockKey = $this->supplierCartLockKey($supplier);

        $startedAt = microtime(true);

        return Cache::lock($cartLockKey, $this->supplierCartLockTtl())->block(10, function () use ($order, $product, $supplier, $startedAt, $provisioning, $service) {
            $latency = [
                'cart_lock_wait_ms' => 0,
                'login_ms' => 0,
                'clear_cart_before_ms' => 0,
                'add_cart_ms' => 0,
                'get_cart_ms' => 0,
                'checkout_ms' => 0,
                'fund_invoice_ms' => 0,
                'find_host_ids_ms' => 0,
                'host_detail_ms' => 0,
                'clear_cart_after_ms' => 0,
            ];
            $result = 'failed';
            $requestedHost = '';
            $invoiceId = 0;
            $hostId = 0;
            $errorMessage = '';
            $errorClass = '';
            $jwt = null;

            $latency['cart_lock_wait_ms'] = $this->elapsedMilliseconds($startedAt);

            try {
                $stepStartedAt = microtime(true);
                $jwt = $provisioning->login($supplier);
                $latency['login_ms'] = $this->elapsedMilliseconds($stepStartedAt);

                $stepStartedAt = microtime(true);
                $provisioning->request($supplier, 'DELETE', '/v1/cart/clear', [], $jwt);
                $latency['clear_cart_before_ms'] = $this->elapsedMilliseconds($stepStartedAt);

                $payload = $this->buildUpstreamCartPayload($order);
                $requestedHost = (string) ($payload['host'] ?? '');

                $stepStartedAt = microtime(true);
                $addCartResponse = $this->addProductToUpstreamCart($provisioning, $supplier, $jwt, $payload);
                $latency['add_cart_ms'] = $this->elapsedMilliseconds($stepStartedAt);
                $this->assertUpstreamSuccess($addCartResponse, [200], '加入上游购物车', $this->resolveProviderKeyForProduct($product));

                $stepStartedAt = microtime(true);
                $cartResponse = $provisioning->get($supplier, '/v1/cart', $jwt);
                $latency['get_cart_ms'] = $this->elapsedMilliseconds($stepStartedAt);
                $this->assertUpstreamSuccess($cartResponse, [200], '读取上游购物车', $this->resolveProviderKeyForProduct($product));
                $cartPayload = $this->extractPayload($cartResponse);
                $gateway = $this->resolveGateway($cartPayload);

                $stepStartedAt = microtime(true);
                $checkoutResponse = $provisioning->post($supplier, '/v1/cart/checkout', [
                    'payment' => $gateway,
                    'position' => [0],
                ], $jwt);
                $latency['checkout_ms'] = $this->elapsedMilliseconds($stepStartedAt);
                $this->assertUpstreamSuccess($checkoutResponse, [200, 1001], '上游购物车结算', $this->resolveProviderKeyForProduct($product));

                $checkoutPayload = $this->extractPayload($checkoutResponse);
                $invoiceId = $this->extractInvoiceId($checkoutResponse, $checkoutPayload);
                $hostIds = $this->extractHostIds($checkoutResponse, $checkoutPayload);

                // checkout 已在上游产生账单，从这里往后的任何失败都必须留下 checkpoint：
                // 否则 assertNoUnresolvedUpstreamProvisionInvoice() 读到 upstream_invoice_id=0 会放行，
                // 队列重试（$tries=3）就会重新走一遍"清空购物车→加购→checkout"，造成上游重复开通。
                $this->checkpointUpstreamInvoice($service ?? $order->service, $order, $invoiceId);

                if ($hostIds === [] && $invoiceId > 0 && ! $this->isCompletedCheckoutResponse($checkoutResponse)) {
                    $stepStartedAt = microtime(true);
                    $fundResponse = $provisioning->post($supplier, "/v1/invoices/{$invoiceId}/fund", [], $jwt);
                    $latency['fund_invoice_ms'] = $this->elapsedMilliseconds($stepStartedAt);
                    $this->assertUpstreamSuccess($fundResponse, [200, 1001], '使用供应商余额支付上游账单', $this->resolveProviderKeyForProduct($product));
                    $fundPayload = $this->extractPayload($fundResponse);
                    $hostIds = $this->extractHostIds($fundResponse, $fundPayload);

                    if ($hostIds === []) {
                        $message = trim((string) ($fundResponse['msg'] ?? ''));
                        throw new BusinessException($message !== ''
                            ? "上游账单 {$invoiceId} 支付未完成：{$message}"
                            : "上游账单 {$invoiceId} 未支付完成，请检查供应商余额是否充足");
                    }
                }

                if ($hostIds === []) {
                    $stepStartedAt = microtime(true);
                    $hostIds = $this->findUpstreamHostIdsByName($provisioning, $supplier, $jwt, (string) $payload['host']);
                    $latency['find_host_ids_ms'] = $this->elapsedMilliseconds($stepStartedAt);
                }

                if ($hostIds === []) {
                    throw new BusinessException('上游已接受订单，但未返回已开通的产品 ID');
                }

                $hostId = (int) $hostIds[0];

                $stepStartedAt = microtime(true);
                $detailResponse = $provisioning->get($supplier, "/v1/hosts/{$hostId}", $jwt);
                $latency['host_detail_ms'] = $this->elapsedMilliseconds($stepStartedAt);
                $this->assertUpstreamSuccess($detailResponse, [200], '读取上游产品详情', $this->resolveProviderKeyForProduct($product));
                $detailPayload = $this->extractPayload($detailResponse);
                $hostDetail = is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];

                $result = 'success';

                return [
                    'requested_host' => (string) $payload['host'],
                    'upstream_invoice_id' => $invoiceId,
                    'upstream_host_ids' => $hostIds,
                    'upstream_host_id' => $hostId,
                    'host_detail' => $hostDetail,
                ];
            } catch (\Throwable $exception) {
                $errorMessage = $exception->getMessage();
                $errorClass = $exception::class;

                throw $exception;
            } finally {
                $clearStartedAt = microtime(true);

                try {
                    if (is_string($jwt) && trim($jwt) !== '') {
                        $provisioning->request($supplier, 'DELETE', '/v1/cart/clear', [], $jwt);
                    }
                } catch (\Throwable $exception) {
                    Log::warning('[自动开通] 清理供应商购物车失败', [
                        'supplier_id' => $supplier->id,
                        'order_id' => $order->id,
                        'message' => $exception->getMessage(),
                    ]);
                } finally {
                    $latency['clear_cart_after_ms'] = $this->elapsedMilliseconds($clearStartedAt);
                }

                $logContext = array_merge($latency, [
                    'result' => $result,
                    'order_id' => (int) $order->id,
                    'order_no' => (string) $order->order_no,
                    'supplier_id' => (int) $supplier->id,
                    'upstream_product_id' => $this->resolveUpstreamProductId($product),
                    'requested_host' => $requestedHost,
                    'upstream_invoice_id' => $invoiceId,
                    'upstream_host_id' => $hostId,
                    'duration_ms' => $this->elapsedMilliseconds($startedAt),
                ]);

                if ($result === 'success') {
                    Log::info('[购买链路] 上游开通请求耗时', $logContext);
                } else {
                    $logContext['message'] = $errorMessage;
                    $logContext['exception'] = $errorClass;
                    Log::warning('[购买链路] 上游开通请求耗时', $logContext);
                }
            }
        });
    }

    private function buildUpstreamCartPayload(Order $order): array
    {
        $product = $order->product;
        $configSnapshot = array_merge(
            (array) (($product->purchase_requires ?? [])['upstream_default_config'] ?? []),
            (array) ($order->config_snapshot ?? [])
        );
        $hostname = $this->resolveProvisionHostname($order);
        $password = trim((string) ($configSnapshot['password'] ?? ''));

        if ($password === '') {
            $password = $this->generateProvisionPassword();
        }

        $configOptionMap = $this->buildUpstreamConfigOptionMap($product, $configSnapshot);

        return [
            'product_id' => $this->resolveUpstreamProductId($product),
            'billingcycle' => (string) $order->billing_cycle,
            'qty' => 1,
            'host' => $hostname,
            'password' => $password,
            'configoption' => $configOptionMap,
        ];
    }

    private function buildUpstreamConfigOptionMap(Product $product, array $configSnapshot): array
    {
        $result = [];

        foreach ((array) ($product->config_options ?? []) as $item) {
            $optionId = $this->resolveOptionId((array) $item);
            $field = $this->parseField($item);
            $type = (int) ($item['option_type'] ?? -1);

            if ($optionId <= 0 || $field === '') {
                continue;
            }

            if (in_array($type, self::RANGE_TYPES, true)) {
                $value = $configSnapshot[$field] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $result[$optionId] = (int) $value;

                continue;
            }

            if ($field === 'os' && ! empty($configSnapshot['os_sub_id'])) {
                $result[$optionId] = (int) $configSnapshot['os_sub_id'];

                continue;
            }

            $selected = $configSnapshot[$field] ?? null;
            if ($selected === null || $selected === '') {
                continue;
            }

            $subId = $this->resolveSubId($item, $selected);
            if ($subId !== null) {
                $result[$optionId] = $subId;
            }
        }

        return $result;
    }

    private function resolveOptionId(array $item): int
    {
        foreach ([
            $item['id'] ?? null,
            $item['config_id'] ?? null,
            $item['extra']['id'] ?? null,
            $item['extra']['config_id'] ?? null,
        ] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        foreach ([(array) ($item['sub'] ?? []), (array) ($item['extra']['sub'] ?? [])] as $subList) {
            foreach ($subList as $sub) {
                $configId = $sub['config_id'] ?? $sub['configid'] ?? null;
                if (is_numeric($configId) && (int) $configId > 0) {
                    return (int) $configId;
                }
            }
        }

        return 0;
    }

    private function resolveSubId(array $item, mixed $selected): ?int
    {
        $selected = trim((string) $selected);
        if ($selected === '') {
            return null;
        }

        foreach ((array) ($item['sub'] ?? []) as $sub) {
            $subId = (int) ($sub['id'] ?? 0);
            $optionNameFirst = trim((string) ($sub['option_name_first'] ?? ''));
            $optionName = trim((string) ($sub['option_name'] ?? ''));

            if ($selected === (string) $subId || $selected === $optionNameFirst || $selected === $optionName) {
                return $subId > 0 ? $subId : null;
            }
        }

        return is_numeric($selected) ? (int) $selected : null;
    }

    private function parseField(array $item): string
    {
        $field = trim((string) ($item['field'] ?? ''));
        if ($field !== '') {
            return $field;
        }

        $type = (int) ($item['option_type'] ?? -1);
        if (isset(self::TYPE_FIELD_MAP[$type])) {
            return self::TYPE_FIELD_MAP[$type];
        }

        $source = (string) ($item['option_name'] ?? $item['spec_key'] ?? '');
        $parts = explode('|', $source);

        return trim((string) ($parts[0] ?? ''));
    }

    private function resolveGateway(array $cartPayload): string
    {
        $gateway = trim((string) ($cartPayload['default_gateway'] ?? ''));
        if ($gateway !== '') {
            return $gateway;
        }

        $gatewayList = $cartPayload['gateway_list'] ?? [];
        if (is_array($gatewayList)) {
            foreach ($gatewayList as $item) {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        throw new BusinessException('上游未配置可用支付网关，无法完成自动开通');
    }

    private function extractPayload(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    private function extractInvoiceId(array $response, array $payload): int
    {
        return (int) ($payload['invoiceid'] ?? $response['invoiceid'] ?? 0);
    }

    private function extractHostIds(array $response, array $payload): array
    {
        $hostIds = $payload['hostid'] ?? $response['hostid'] ?? [];

        if (! is_array($hostIds)) {
            $hostIds = [$hostIds];
        }

        return array_values(array_filter(array_map(
            fn ($value) => is_numeric($value) && (int) $value > 0 ? (int) $value : null,
            $hostIds
        )));
    }

    private function assertUpstreamSuccess(array $response, array $allowedStatuses, string $action, string $providerKey = ''): void
    {
        $hasStatus = array_key_exists('status', $response)
            || array_key_exists('code', $response)
            || array_key_exists('status_code', $response);

        if (! $hasStatus) {
            return;
        }

        $status = $this->extractResponseStatus($response);
        if (in_array($status, $allowedStatuses, true)) {
            return;
        }

        $message = trim((string) ($response['msg'] ?? $response['message'] ?? ''));
        Log::warning('[上游开通] 返回失败', [
            'action' => $action,
            'status' => $status,
            'message' => SensitiveDataSanitizer::sanitizeText($message),
        ]);

        throw new BusinessException(app(ProviderErrorMapper::class)->toUserMessage($providerKey, $action, $message));
    }

    private function addProductToUpstreamCart(object $provisioning, Supplier $supplier, string $jwt, array $payload): array
    {
        $requestPayload = $payload;
        if (($requestPayload['configoption'] ?? []) === []) {
            $requestPayload['configoption'] = (object) [];
        }

        return $provisioning->request(
            $supplier,
            'POST',
            '/v1/cart/products',
            (string) json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $jwt,
            [
                'Content-Type: application/json',
                'Accept: application/json',
            ]
        );
    }

    private function isCompletedCheckoutResponse(array $response): bool
    {
        $status = $this->extractResponseStatus($response);

        return $status === 1001;
    }

    private function extractResponseStatus(array $response): int
    {
        return (int) ($response['status'] ?? $response['code'] ?? $response['status_code'] ?? 0);
    }

    private function findUpstreamHostIdsByName(object $provisioning, Supplier $supplier, string $jwt, string $hostname): array
    {
        $response = $provisioning->get($supplier, '/v1/hosts', $jwt, [
            'page' => 1,
            'limit' => 20,
            'keywords' => $hostname,
            'sort' => 'DESC',
        ]);

        $payload = $this->extractPayload($response);
        $hosts = is_array($payload['host'] ?? null) ? $payload['host'] : [];

        return array_values(array_filter(array_map(function ($host) use ($hostname) {
            if (! is_array($host)) {
                return null;
            }

            $domain = trim((string) ($host['domain'] ?? ''));
            if ($domain !== '' && $domain !== $hostname) {
                return null;
            }

            $id = (int) ($host['id'] ?? 0);

            return $id > 0 ? $id : null;
        }, $hosts)));
    }

    private function resolveServiceStatusFromUpstream(array $hostDetail): int
    {
        return match (strtolower(trim((string) ($hostDetail['domainstatus'] ?? '')))) {
            'active' => ServiceStatus::ACTIVE,
            'suspended' => ServiceStatus::SUSPENDED,
            'cancelled', 'deleted' => ServiceStatus::CANCELLED,
            default => ServiceStatus::PENDING,
        };
    }

    private function resolveServiceExpiry(array $hostDetail, Order $order): ?Carbon
    {
        $nextDueDate = $hostDetail['nextduedate'] ?? null;
        if (is_numeric($nextDueDate) && (int) $nextDueDate > 0) {
            return Carbon::createFromTimestamp((int) $nextDueDate);
        }

        return match ((string) $order->billing_cycle) {
            'monthly' => now()->addMonth(),
            'quarterly' => now()->addMonths(3),
            'semiannually' => now()->addMonths(6),
            'annually' => now()->addYear(),
            'biennially' => now()->addYears(2),
            'triennially' => now()->addYears(3),
            default => null,
        };
    }

    private function sanitizeRequestedConfig(array $config): array
    {
        if (isset($config['password'])) {
            $config['password'] = '***';
        }

        return $config;
    }

    private function prepareServiceForProvisionRetry(Order $order, Service $service): void
    {
        $hostname = $this->resolveProvisionHostname($order);
        $provisionData = $this->serviceProvisionData($service, includeSecrets: true);

        foreach ([
            'upstream_status',
            'upstream_product_name',
            'dedicated_ip',
            'assigned_ips',
            'host_config_option',
            'os',
            'connection_secret',
            'connection_cached_at',
            'last_provisioned_at',
            'last_provision_attempt_at',
            'last_synced_at',
            'provision_error',
            'runtime_status',
            'runtime_description',
            'nat_remote_address',
            'nat_remote_host',
            'nat_remote_port',
            'nat_remote_checked_at',
            'manual_remark',
            'manually_provisioned',
            'manual_provisioned_at',
            'manual_provisioned_by_admin_id',
            'manual_provisioned_by_admin_name',
        ] as $key) {
            unset($provisionData[$key]);
        }

        $provisionData['requested_config'] = $this->sanitizeRequestedConfig(
            array_merge((array) ($order->config_snapshot ?? []), [
                'hostname' => $hostname,
            ])
        );

        $service->forceFill([
            'status' => ServiceStatus::PENDING,
            'domain' => $hostname !== '' ? $hostname : $service->domain,
            'suspended_reason' => null,
            'provision_data' => $provisionData,
        ])->save();
    }

    private function generateProvisionPassword(): string
    {
        $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';

        for ($i = 0; $i < 13; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return substr($password.'Aa1', 0, 16);
    }

    private function encryptConnectionCache(array $connection): string
    {
        return Crypt::encryptString((string) json_encode([
            'hostname' => trim((string) ($connection['hostname'] ?? '')),
            'username' => trim((string) ($connection['username'] ?? '')),
            'password' => (string) ($connection['password'] ?? ''),
            'port' => (int) (($connection['port'] ?? 0) ?: 0),
            'internal_ip' => trim((string) ($connection['internal_ip'] ?? '')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function resolveProvisionHostname(Order $order): string
    {
        $configSnapshot = (array) ($order->config_snapshot ?? []);
        $hostnameConfig = $this->settingService->getProvisionHostnameConfig();
        $productHostnameRule = ProductProvisionHostname::fromPurchaseRequires(
            is_array($order->product?->purchase_requires ?? null) ? $order->product->purchase_requires : []
        );
        $upstreamHostnameRule = $this->resolveUpstreamProvisionHostnameRule($order);
        $snapshotHostname = $this->settingService->normalizeHostname((string) ($configSnapshot['hostname'] ?? ''));
        $hostnameMode = (string) ($productHostnameRule['mode'] ?? ProductProvisionHostname::MODE_SYSTEM);
        $enforceSystemRule = (bool) ($hostnameConfig['enforce'] ?? false);
        $hostname = $enforceSystemRule
            ? $this->generateProvisionHostname(
                $order,
                ProductProvisionHostname::buildGenerationRule($hostnameConfig, [], $upstreamHostnameRule)
            )
            : match ($hostnameMode) {
                ProductProvisionHostname::MODE_FIXED => $this->settingService->normalizeHostname(
                    (string) ($productHostnameRule['value'] ?? ''),
                    true
                ),
                ProductProvisionHostname::MODE_PREFIX => $this->generateProvisionHostname(
                    $order,
                    ProductProvisionHostname::buildGenerationRule($hostnameConfig, $productHostnameRule, $upstreamHostnameRule)
                ),
                default => $upstreamHostnameRule !== []
                    ? $this->generateProvisionHostname(
                        $order,
                        ProductProvisionHostname::buildGenerationRule($hostnameConfig, [], $upstreamHostnameRule)
                    )
                    : $snapshotHostname,
            };

        if ($hostname === '' && $upstreamHostnameRule !== []) {
            $hostname = $this->generateProvisionHostname(
                $order,
                ProductProvisionHostname::buildGenerationRule($hostnameConfig, [], $upstreamHostnameRule)
            );
        }

        if ($hostname === '') {
            $hostname = $this->generateProvisionHostname(
                $order,
                ProductProvisionHostname::buildGenerationRule($hostnameConfig)
            );
        }

        if (($configSnapshot['hostname'] ?? '') !== $hostname) {
            $configSnapshot['hostname'] = $hostname;
            $order->forceFill([
                'config_snapshot' => $configSnapshot,
            ])->save();
        }

        return $hostname;
    }

    private function resolveUpstreamProvisionHostnameRule(Order $order): array
    {
        $product = $order->product;
        $supplier = $product instanceof Product ? $this->resolveProductSupplier($product) : null;

        if (! $product instanceof Product || ! $supplier instanceof Supplier) {
            return [];
        }

        if (! $this->shouldAutoProvision($product)) {
            return [];
        }

        $supplierProductId = $this->resolveUpstreamProductId($product);
        if ($supplierProductId <= 0) {
            return [];
        }

        $cacheKey = sprintf('provision:upstream_hostname_rule:%d:%d', (int) $supplier->id, $supplierProductId);
        $cachedRule = Cache::get($cacheKey);
        if (is_array($cachedRule) && $cachedRule !== []) {
            return $cachedRule;
        }

        $rule = (function () use ($product, $supplier, $supplierProductId) {
            try {
                $provisioning = $this->resolveProvisioningCapability($product);
                if (method_exists($provisioning, 'getProductProvisionConfig')) {
                    $response = $provisioning->getProductProvisionConfig($supplier, $supplierProductId);

                    return $this->extractUpstreamProvisionHostnameRule($response, $supplierProductId);
                }

                $jwt = $provisioning->login($supplier);
                $response = $provisioning->get($supplier, '/v1/productsconfig', $jwt, [
                    'product_id' => $supplierProductId,
                ]);

                return $this->extractUpstreamProvisionHostnameRule($response, $supplierProductId);
            } catch (\Throwable $exception) {
                Log::warning('[自动开通] 读取上游商品主机名规则失败，将回退本地主机名规则', [
                    'supplier_id' => (int) $supplier->id,
                    'upstream_product_id' => $supplierProductId,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);

                return [];
            }
        })();

        if ($rule === []) {
            Cache::forget($cacheKey);

            return [];
        }

        Cache::put($cacheKey, $rule, now()->addMinutes(30));

        return $rule;
    }

    private function resolveProvisioningCapability(?Product $product): object
    {
        throw_if(! $product instanceof Product, new BusinessException('商品信息不存在，无法解析供应商接口', 42200));

        return $this->providerResolver
            ->resolveForProduct($product)
            ->require(ProvidesProvisioning::class, '当前商品未接入可自动开通的供应商接口');
    }

    private function extractUpstreamProvisionHostnameRule(array $response, int $productId): array
    {
        $product = $this->extractUpstreamProductConfigData($response, $productId);
        if (! is_array($product)) {
            return [];
        }

        return $this->normalizeUpstreamProvisionHostnameRule($product);
    }

    private function extractUpstreamProductConfigData(array $response, int $productId): ?array
    {
        $payload = is_array($response['data'] ?? null) ? $response['data'] : $response;

        foreach ([
            $payload['products'] ?? null,
            $payload['product'] ?? null,
            $payload['items'] ?? null,
        ] as $candidate) {
            $matchedProduct = $this->matchUpstreamProductCandidate($candidate, $productId);
            if ($matchedProduct !== null) {
                return $matchedProduct;
            }
        }

        if ((int) ($payload['id'] ?? 0) === $productId) {
            return $payload;
        }

        foreach ((array) ($payload['first_group'] ?? []) as $firstGroup) {
            foreach ((array) ($firstGroup['group'] ?? []) as $group) {
                $matchedProduct = $this->matchUpstreamProductCandidate(
                    $group['products'] ?? $group['product'] ?? null,
                    $productId
                );

                if ($matchedProduct !== null) {
                    return $matchedProduct;
                }
            }
        }

        return null;
    }

    private function matchUpstreamProductCandidate(mixed $candidate, int $productId): ?array
    {
        if (! is_array($candidate)) {
            return null;
        }

        if ((int) ($candidate['id'] ?? 0) === $productId) {
            return $candidate;
        }

        foreach ($candidate as $item) {
            if (is_array($item) && (int) ($item['id'] ?? 0) === $productId) {
                return $item;
            }
        }

        return null;
    }

    private function normalizeUpstreamProvisionHostnameRule(array $product): array
    {
        $hostRule = $this->normalizeUpstreamHostRule($product['host'] ?? null);
        $hostRuleRule = is_array($hostRule['rule'] ?? null) ? $hostRule['rule'] : [];
        $productRule = is_array($product['rule'] ?? null) ? $product['rule'] : [];
        $effectiveRule = $hostRuleRule !== [] ? $hostRuleRule : $productRule;
        $show = (int) ($hostRule['show'] ?? $product['show'] ?? 1);
        $sampleHostname = $this->settingService->normalizeHostname((string) (
            $hostRule['host']
            ?? $hostRule['hostname']
            ?? $hostRuleRule['host']
            ?? $hostRuleRule['hostname']
            ?? ($hostRule === [] && is_string($product['host'] ?? null) ? $product['host'] : '')
        ), true);
        $prefix = $this->settingService->sanitizeHostnamePrefix((string) (
            $hostRule['prefix']
            ?? $hostRuleRule['prefix']
            ?? $product['prefix']
            ?? ''
        ));
        $length = $this->resolveUpstreamProvisionHostnameLength($hostRule, $effectiveRule, $product, $sampleHostname, $prefix);

        if ($prefix === '' && preg_match('/^[a-zA-Z]+/', $sampleHostname, $matches) === 1) {
            $prefix = $this->settingService->sanitizeHostnamePrefix((string) ($matches[0] ?? ''));
        }

        $pool = '';
        if ($this->resolveUpstreamProvisionHostnameFlag(
            $hostRule['num'] ?? null,
            $hostRuleRule['num'] ?? null,
            $productRule['num'] ?? null,
            $product['num'] ?? null
        ) === 1) {
            $pool .= '0123456789';
        }
        if ($this->resolveUpstreamProvisionHostnameFlag(
            $hostRule['upper'] ?? null,
            $hostRuleRule['upper'] ?? null,
            $productRule['upper'] ?? null,
            $product['upper'] ?? null
        ) === 1) {
            $pool .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }
        if ($this->resolveUpstreamProvisionHostnameFlag(
            $hostRule['lower'] ?? null,
            $hostRuleRule['lower'] ?? null,
            $productRule['lower'] ?? null,
            $product['lower'] ?? null
        ) === 1) {
            $pool .= 'abcdefghijklmnopqrstuvwxyz';
        }

        if ($pool === '' && $sampleHostname !== '') {
            if (preg_match('/[0-9]/', $sampleHostname) === 1) {
                $pool .= '0123456789';
            }
            if (preg_match('/[A-Z]/', $sampleHostname) === 1) {
                $pool .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            }
            if (preg_match('/[a-z]/', $sampleHostname) === 1) {
                $pool .= 'abcdefghijklmnopqrstuvwxyz';
            }
        }

        if ($show !== 1 && $prefix === '' && $sampleHostname === '') {
            return [];
        }

        $length = max($length, mb_strlen($prefix));
        if ($length <= 0) {
            $length = max(mb_strlen($sampleHostname), 12);
        }

        return [
            'prefix' => $prefix,
            'length' => max(4, min(63, $length)),
            'pool' => $pool !== '' ? $pool : '0123456789',
        ];
    }

    private function normalizeUpstreamHostRule(mixed $host): array
    {
        if (is_array($host)) {
            return $host;
        }

        if (! is_string($host)) {
            return [];
        }

        $decoded = json_decode(trim($host), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveUpstreamProvisionHostnameLength(
        array $hostRule,
        array $hostRuleRule,
        array $product,
        string $sampleHostname,
        string $prefix
    ): int {
        $length = $this->resolveUpstreamProvisionHostnameNumericValue([
            $hostRule['len_num'] ?? null,
            $hostRule['length'] ?? null,
            $product['len_num'] ?? null,
            $product['length'] ?? null,
        ]);

        $ruleLength = $this->resolveUpstreamProvisionHostnameNumericValue([
            $hostRuleRule['len_num'] ?? null,
            $hostRuleRule['length'] ?? null,
        ]);

        if ($ruleLength > 0) {
            $length = max($length, $ruleLength + mb_strlen($prefix));
        }

        if ($sampleHostname !== '') {
            $length = max($length, mb_strlen($sampleHostname));
        }

        if ($length <= 0) {
            $length = max(mb_strlen($prefix), 12);
        }

        return $length;
    }

    private function resolveUpstreamProvisionHostnameFlag(mixed ...$candidates): int
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            return (int) $candidate;
        }

        return 0;
    }

    private function resolveUpstreamProvisionHostnameNumericValue(array $candidates): int
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '' || ! is_numeric($candidate)) {
                continue;
            }

            return (int) $candidate;
        }

        return 0;
    }

    private function generateProvisionHostname(Order $order, array $config): string
    {
        $prefix = (string) ($config['prefix'] ?? 'srv');
        $length = max((int) ($config['length'] ?? 12), mb_strlen($prefix));
        $pool = (string) ($config['pool'] ?? '0123456789');
        $randomLength = max($length - mb_strlen($prefix), 0);

        if ($randomLength === 0) {
            return $prefix;
        }

        return $prefix.$this->buildProvisionHostnameSuffix(
            "{$order->order_no}|{$order->id}|{$order->user_id}",
            $pool,
            $randomLength
        );
    }

    private function buildProvisionHostnameSuffix(string $seed, string $pool, int $length): string
    {
        $poolLength = strlen($pool);
        if ($poolLength <= 0 || $length <= 0) {
            return '';
        }

        $result = '';
        $cursor = 0;

        while (strlen($result) < $length) {
            $hash = hash('sha256', $seed.'|'.$cursor);
            $hashLength = strlen($hash);

            for ($index = 0; $index < $hashLength && strlen($result) < $length; $index += 2) {
                $segment = substr($hash, $index, 2);
                $offset = hexdec($segment) % $poolLength;
                $result .= $pool[$offset];
            }

            $cursor++;
        }

        return substr($result, 0, $length);
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function resolveProductSupplierId(Product $product): int
    {
        $this->ensureProductBinding($product);

        $supplierId = (int) (($this->pluginBindingResolver()->supplierIdForProduct($product) ?? 0) ?: 0);
        if ($supplierId > 0) {
            return $supplierId;
        }

        return 0;
    }

    private function resolveProductSupplier(Product $product): ?Supplier
    {
        $this->ensureProductBinding($product);

        $supplier = $this->pluginBindingResolver()->supplierForProduct($product);
        if ($supplier instanceof Supplier) {
            return $this->pluginBindingResolver()->supplierWithRuntimeCredentials($supplier);
        }

        return null;
    }

    private function resolveUpstreamProductId(Product $product): int
    {
        $this->ensureProductBinding($product);

        $upstreamProductId = $this->pluginBindingResolver()->upstreamProductIdForProduct($product);
        if ($upstreamProductId !== null) {
            return (int) $upstreamProductId;
        }

        return 0;
    }

    private function resolveServiceUpstreamServiceId(Service $service): ?string
    {
        $upstreamServiceId = $this->nonBlank($this->pluginBindingResolver()->upstreamServiceIdForService($service));
        if ($upstreamServiceId !== null) {
            return $upstreamServiceId;
        }

        return null;
    }

    private function resolveReusableServiceUpstreamServiceId(Service $service): ?string
    {
        $bindingHostId = $this->nonBlank($this->pluginBindingResolver()->upstreamServiceIdForService($service));
        if ($bindingHostId !== null) {
            return $bindingHostId;
        }

        if ((int) ($service->status ?? 0) !== ServiceStatus::ACTIVE) {
            return null;
        }

        return null;
    }

    private function upstreamServiceIdPayloadValue(string $upstreamServiceId): int|string
    {
        return ctype_digit($upstreamServiceId) ? (int) $upstreamServiceId : $upstreamServiceId;
    }

    private function nonBlank(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * checkout 成功后立即把上游账单号落库，让失败路径保留 checkpoint、
     * 让重试去核对已有账单而不是再结算一次购物车。
     *
     * 与 ZjmfProvisionService::checkpointUpstreamProvision() 同一意图：
     * 内置的主机面板接口驱动没有 provisionOrder()，走的是无 checkpoint 的内联分支，
     * 因此这里必须补齐，否则 $tries=3 的队列重试会在上游产生 3 笔已付款订单。
     */
    private function checkpointUpstreamInvoice(?Service $service, Order $order, int $upstreamInvoiceId): void
    {
        if ($upstreamInvoiceId <= 0 || ! $service instanceof Service || ! $service->exists) {
            return;
        }

        $freshService = $service->fresh() ?? $service;

        $freshService->forceFill([
            'provision_data' => array_merge($this->serviceProvisionData($freshService), [
                'provider_key' => $this->resolveProviderKeyForProduct($order->product),
                'supplier_id' => $this->resolveProductSupplierId($order->product),
                'upstream_product_id' => $this->resolveUpstreamProductId($order->product),
                'upstream_invoice_id' => $upstreamInvoiceId,
                'last_provision_attempt_at' => now()->format('Y-m-d H:i:s'),
            ]),
        ])->save();

        // 让调用方手里的实例与库内保持一致，避免后续基于旧内存值判断
        $service->setAttribute('provision_data', $freshService->provision_data);
    }

    private function assertNoUnresolvedUpstreamProvisionInvoice(Service $service): void
    {
        $provisionData = $this->serviceProvisionData($service);
        $upstreamInvoiceId = (int) ($provisionData['upstream_invoice_id'] ?? 0);
        $upstreamHostId = $this->resolveServiceUpstreamServiceId($service)
            ?? $this->nonBlank($provisionData['upstream_host_id'] ?? null);

        throw_if(
            $upstreamInvoiceId > 0 && $upstreamHostId === null,
            new BusinessException('已创建上游账单但未取得实例标识，请先核实上游账单状态后再处理，禁止直接重新下单')
        );
    }

    private function supplierCartLockTtl(): int
    {
        return max(90, (int) config('queue.turaidc_worker_timeout', 1200) + 60);
    }

    /**
     * 魔方的购物车按上游登录账号共享，不能仅按本地 supplier_id 互斥。
     * 多条供应商记录复用同一上游地址和账号时，必须落到同一个分布式锁。
     */
    private function supplierCartLockKey(Supplier $supplier): string
    {
        $baseUrl = $this->normalizeUpstreamCartLockBaseUrl((string) ($supplier->api_url ?? ''));
        $accountName = strtolower(trim((string) ($supplier->api_username ?? '')));

        if ($baseUrl === '' || $accountName === '') {
            return "lock:supplier:cart:{$supplier->id}";
        }

        return 'lock:upstream:cart:'.hash('sha256', $baseUrl.'|'.$accountName);
    }

    private function normalizeUpstreamCartLockBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || ! isset($parts['host'])) {
            return $baseUrl;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return "{$scheme}://{$host}{$port}{$path}";
    }

    private function pluginBindingResolver(): PluginBindingResolver
    {
        return $this->bindingResolver ??= new PluginBindingResolver;
    }

    private function serviceBindingWriter(): ServiceUpstreamBindingWriter
    {
        return $this->serviceBindingWriter ??= app(ServiceUpstreamBindingWriter::class);
    }

    private function buildServiceSnapshot(Service $service): array
    {
        return [
            'instance_id' => (int) $service->id,
            'hostname' => (string) ($service->domain ?? ''),
        ];
    }

    private function ensureProductBinding(Product $product): void
    {
        app(UpstreamBindingWriter::class)->syncProductBinding($product);
    }

    private function serviceProvisionData(Service $service, bool $includeSecrets = false): array
    {
        $legacy = (array) ($service->provision_data ?? []);
        $projection = $this->pluginBindingResolver()->serviceProvisionProjection($service, $includeSecrets);

        return $projection === [] ? $legacy : array_replace($legacy, $projection);
    }
}
