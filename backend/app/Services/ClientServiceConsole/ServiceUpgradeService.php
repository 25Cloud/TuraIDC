<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\InvoiceService;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\ServiceUpstreamBindingWriter;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\System\OperationLogService;
use App\Support\OrderInvoiceNoGenerator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServiceUpgradeService
{
    private const ORDER_KIND = 'host_upgrade';

    private const UPGRADE_ATTEMPT_ACTION = 'upgrade';

    private readonly PluginBindingResolver $bindingResolver;

    public function __construct(
        private readonly ServiceDetailService $detailService,
        private readonly InvoiceService $invoiceService,
        private readonly OperationLogService $operationLogService,
        ?PluginBindingResolver $bindingResolver = null,
        private ?ServiceUpstreamBindingWriter $serviceBindingWriter = null,
    ) {
        $this->bindingResolver = $bindingResolver ?? new PluginBindingResolver;
    }

    public function previewForUser(User $user, int $serviceId): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, ['product.supplier']);
        [$catalog, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);

        $response = $catalog->getHostUpgradeOptions($supplier, $hostId, $jwt);
        $this->detailService->assertSuccess($response, '读取产品升降级选项');
        $payload = $this->detailService->extractPayload($response);

        return [
            'service_id' => (int) $service->id,
            'service_name' => (string) $service->name,
            'upstream_host_id' => $hostId,
            'options' => $payload,
        ];
    }

    public function quoteForUser(User $user, int $serviceId, array $selection): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, ['product.supplier']);
        [$catalog, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);

        $productId = (int) ($selection['product_id'] ?? 0);
        $billingCycle = trim((string) ($selection['billing_cycle'] ?? ''));
        throw_if($productId <= 0, new BusinessException('请选择目标商品', 42200));
        throw_if($billingCycle === '', new BusinessException('请选择目标周期', 42200));

        $response = $catalog->previewHostUpgrade($supplier, $hostId, $productId, $billingCycle, $jwt);
        $this->detailService->assertSuccess($response, '预览产品升降级');
        $payload = $this->detailService->extractPayload($response);

        return [
            'service_id' => (int) $service->id,
            'service_name' => (string) $service->name,
            'upstream_host_id' => $hostId,
            'product_id' => $productId,
            'billing_cycle' => $billingCycle,
            'quote' => $payload,
        ];
    }

    public function createInvoiceForUser(User $user, int $serviceId, array $selection, array $context = []): Invoice
    {
        $service = $this->detailService->findUserService($user, $serviceId, ['product.supplier', 'product']);
        $product = $service->product;
        if (! $product instanceof Product) {
            throw new BusinessException('服务关联商品不存在，无法创建升降级账单');
        }

        $quote = $this->quoteForUser($user, $serviceId, $selection);
        $quotePayload = is_array($quote['quote'] ?? null) ? $quote['quote'] : [];
        $amount = number_format((float) ($quotePayload['amount_total'] ?? 0), 2, '.', '');
        $productId = (int) $quote['product_id'];
        $billingCycle = (string) $quote['billing_cycle'];
        $promoCode = trim((string) ($selection['promo_code'] ?? ''));

        $existingInvoice = Invoice::query()
            ->where('user_id', (int) $user->id)
            ->where('service_id', (int) $service->id)
            ->where('type', OrderType::UPGRADE)
            ->where('status', InvoiceStatus::UNPAID)
            ->latest('id')
            ->first();

        if ($existingInvoice instanceof Invoice) {
            $sameKind = (string) data_get($existingInvoice->config_pricing_snapshot ?? [], 'meta.kind', '') === self::ORDER_KIND;
            $sameProduct = (int) data_get($existingInvoice->config_pricing_snapshot ?? [], 'meta.product_id', 0) === $productId;
            $sameCycle = (string) data_get($existingInvoice->config_pricing_snapshot ?? [], 'meta.billing_cycle', '') === $billingCycle;
            $samePromo = (string) data_get($existingInvoice->config_pricing_snapshot ?? [], 'meta.promo_code', '') === $promoCode;
            $sameAmount = round((float) ($existingInvoice->amount ?? 0), 2) === round((float) $amount, 2);

            if ($sameKind && $sameProduct && $sameCycle && $samePromo && $sameAmount) {
                $this->ensureHostUpgradeOrderForInvoice($existingInvoice);

                return $existingInvoice->fresh(['product', 'service', 'order'])
                    ?? $existingInvoice->loadMissing(['product', 'service', 'order']);
            }

            app(CheckoutService::class)->cancel($existingInvoice, array_merge($context, [
                'actor_type' => (string) ($context['actor_type'] ?? 'system'),
                'actor_user_id' => (int) ($context['actor_user_id'] ?? $user->id),
                'actor_name' => (string) ($context['actor_name'] ?? ($user->email ?? 'system')),
                'reason' => 'host_upgrade_invoice_replaced',
            ]));
        }

        $invoice = DB::transaction(function () use ($service, $product, $context, $quotePayload, $productId, $billingCycle, $promoCode, $amount) {
            $displayPayload = (new ProductDisplayNameResolver)->resolveForProduct($product);
            $productSpecDisplay = (string) ($displayPayload['product_spec_display'] ?? '');

            $invoice = Invoice::query()->create([
                'invoice_no' => Invoice::generateInvoiceNo(),
                'user_id' => (int) $service->user_id,
                'product_id' => (int) $product->id,
                'product_spec_snapshot' => $productSpecDisplay,
                'product_type_snapshot' => (string) $product->product_type,
                'service_id' => (int) $service->id,
                'type' => OrderType::UPGRADE,
                'amount' => $amount,
                'discount' => '0.00',
                'paid_amount' => '0.00',
                'billing_cycle' => $billingCycle,
                'quantity' => 1,
                'config_snapshot' => [
                    'upgrade_product_id' => $productId,
                    'billing_cycle' => $billingCycle,
                ],
                'config_pricing_snapshot' => [
                    'base_amount' => '0.00',
                    'config_amount' => $amount,
                    'setup_fee' => '0.00',
                    'items' => [[
                        'field' => 'upgrade_product_id',
                        'label' => '升降级商品',
                        'value_label' => (string) ($quotePayload['name'] ?? $productId),
                        'amount' => $amount,
                    ]],
                    'meta' => [
                        'kind' => self::ORDER_KIND,
                        'mode' => 'host_upgrade',
                        'product_id' => $productId,
                        'billing_cycle' => $billingCycle,
                        'promo_code' => $promoCode,
                        'upstream_host_id' => (int) ($quote['upstream_host_id'] ?? 0),
                        'quote' => $quotePayload,
                    ],
                ],
                'status' => InvoiceStatus::UNPAID,
                'due_date' => now()->addDays(7),
                'trace_id' => (string) ($context['trace_id'] ?? ''),
            ]);

            $this->invoiceService->syncProjection($invoice);
            $this->createHostUpgradeOrderForInvoice($invoice, $service, $product, $productSpecDisplay);

            return $invoice->load(['product', 'service', 'order']);
        });

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.host_upgrade.invoice.create', [
            'category' => 'upgrade',
            'summary' => '创建产品升降级账单',
            'amount' => $amount,
            'invoice_id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'order_id' => (int) ($invoice->order_id ?? 0),
            'target_product_id' => $productId,
            'billing_cycle' => $billingCycle,
            'promo_code' => $promoCode,
        ], $context);

        return $invoice;
    }

    /**
     * 为历史或异常缺失内部 Order 的主机升降级账单恢复履约投影。
     *
     * 正常创建路径在同一事务内已创建 Order；此方法只承担旧未付账单
     * 或支付回调竞争窗口的保护，不能把 invoice-only 语义扩展到其他账单类型。
     */
    public function ensureHostUpgradeOrderForInvoice(Invoice $invoice): ?Order
    {
        if ((string) data_get($invoice->config_pricing_snapshot ?? [], 'meta.kind', '') !== self::ORDER_KIND) {
            return null;
        }

        $invoice->loadMissing(['order', 'service', 'product']);
        if ($invoice->order instanceof Order) {
            return $invoice->order;
        }

        if (! in_array((int) $invoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::PAID], true)) {
            return null;
        }

        $service = $invoice->service;
        if (! $service instanceof Service) {
            $this->logMissingHostUpgradeOrderContext($invoice);

            return null;
        }

        $product = $invoice->product;
        if (! $product instanceof Product) {
            $product = $service->product;
        }

        if (! $product instanceof Product) {
            $this->logMissingHostUpgradeOrderContext($invoice);

            return null;
        }

        return DB::transaction(function () use ($invoice, $service, $product): ?Order {
            $lockedInvoice = Invoice::query()
                ->lockForUpdate()
                ->with('order')
                ->find((int) $invoice->id);

            if (! $lockedInvoice instanceof Invoice) {
                return null;
            }

            if ($lockedInvoice->order instanceof Order) {
                return $lockedInvoice->order;
            }

            if ((string) data_get($lockedInvoice->config_pricing_snapshot ?? [], 'meta.kind', '') !== self::ORDER_KIND
                || ! in_array((int) $lockedInvoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::PAID], true)) {
                return null;
            }

            $displayPayload = (new ProductDisplayNameResolver)->resolveForProduct($product);
            $productSpecDisplay = (string) ($lockedInvoice->product_spec_snapshot
                ?: $displayPayload['product_spec_display']);

            return $this->createHostUpgradeOrderForInvoice($lockedInvoice, $service, $product, $productSpecDisplay);
        });
    }

    private function createHostUpgradeOrderForInvoice(
        Invoice $invoice,
        Service $service,
        Product $product,
        string $productSpecDisplay,
    ): Order {
        $orderNo = OrderInvoiceNoGenerator::deriveOrderNoFromInvoiceNo((string) $invoice->invoice_no)
            ?? Order::generateOrderNo();

        $order = Order::query()->create([
            'order_no' => $orderNo,
            'projection_type' => Order::PROJECTION_TYPE_PROVISIONING,
            'user_id' => (int) $invoice->user_id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => $productSpecDisplay,
            'product_type_snapshot' => (string) $product->product_type,
            'service_id' => (int) $service->id,
            'type' => OrderType::UPGRADE,
            'amount' => (string) $invoice->amount,
            'discount' => (string) ($invoice->discount ?? '0.00'),
            'paid_amount' => (string) ($invoice->paid_amount ?? '0.00'),
            'billing_cycle' => (string) ($invoice->billing_cycle ?: 'one_time'),
            'quantity' => 1,
            'config_snapshot' => (array) ($invoice->config_snapshot ?? []),
            'config_pricing_snapshot' => (array) ($invoice->config_pricing_snapshot ?? []),
            'coupon_snapshot' => (array) ($invoice->coupon_snapshot ?? []),
            'status' => (int) $invoice->status === InvoiceStatus::PAID ? OrderStatus::PAID : OrderStatus::PENDING,
            'paid_at' => $invoice->paid_at,
            'trace_id' => (string) ($invoice->trace_id ?? ''),
        ]);

        $invoice->forceFill(['order_id' => (int) $order->id])->save();

        return $order;
    }

    private function logMissingHostUpgradeOrderContext(Invoice $invoice): void
    {
        Log::error('[产品升降级] 无法恢复履约订单，账单缺少服务或商品', [
            'invoice_id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'service_id' => (int) ($invoice->service_id ?? 0),
            'product_id' => (int) ($invoice->product_id ?? 0),
            'trace_id' => (string) ($invoice->trace_id ?? ''),
        ]);
    }

    public function processPaidUpgradeOrder(Order $order): ?Service
    {
        try {
            return Cache::lock("lock:host-upgrade:order:{$order->id}", 300)
                ->block(3, fn () => $this->processPaidUpgradeOrderLocked($order));
        } catch (LockTimeoutException) {
            Log::info('[产品升降级] 同一订单正在处理，跳过本次重复履约', [
                'order_id' => (int) $order->id,
                'order_no' => (string) ($order->order_no ?? ''),
            ]);

            return $order->service?->fresh(['product.supplier', 'order']);
        }
    }

    private function processPaidUpgradeOrderLocked(Order $order): ?Service
    {
        $order->loadMissing(['service.product.supplier']);
        $service = $order->service;
        if (! $service instanceof Service) {
            return null;
        }

        // 已完成的订单是上游操作的幂等边界，不能因重复回调或任务重放再次升降级。
        if ((int) $order->status === OrderStatus::COMPLETED) {
            return $service->fresh(['product.supplier', 'order']) ?? $service;
        }

        $meta = (array) data_get($order->config_pricing_snapshot ?? [], 'meta', []);
        $productId = (int) ($meta['product_id'] ?? 0);
        $billingCycle = trim((string) ($meta['billing_cycle'] ?? ''));
        $promoCode = trim((string) ($meta['promo_code'] ?? ''));

        throw_if($productId <= 0, new BusinessException('升降级订单缺少目标商品', 42200));
        throw_if($billingCycle === '', new BusinessException('升降级订单缺少目标周期', 42200));

        $attemptRequestMeta = $this->upgradeAttemptRequestMeta($order, $productId, $billingCycle, $promoCode);
        [$catalog, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        $resolvedHostId = (int) $hostId;
        $invoiceId = 0;

        try {
            if (is_callable([$catalog, 'purchaseHostUpgrade'])) {
                $purchaseResult = $catalog->purchaseHostUpgrade(
                    $supplier,
                    $hostId,
                    $productId,
                    $billingCycle,
                    $promoCode,
                    $jwt
                );
                $invoiceId = (int) ($purchaseResult['upstream_invoice_id'] ?? 0);
                $hostDetail = is_array($purchaseResult['host_detail'] ?? null) ? $purchaseResult['host_detail'] : [];
            } else {
                $previewResponse = $catalog->previewHostUpgrade($supplier, $hostId, $productId, $billingCycle, $jwt);
                $this->detailService->assertSuccess($previewResponse, '提交产品升降级预览');

                if ($promoCode !== '') {
                    $promoResponse = $catalog->applyHostUpgradePromoCode($supplier, $hostId, $promoCode, $jwt);
                    $this->detailService->assertSuccess($promoResponse, '应用产品升降级优惠码');
                }

                $checkoutResponse = $catalog->checkoutHostUpgrade($supplier, $hostId, $jwt);
                $this->detailService->assertSuccess($checkoutResponse, '生成产品升降级账单');
                $checkoutPayload = $this->detailService->extractPayload($checkoutResponse);
                $invoiceId = (int) ($checkoutPayload['invoiceid'] ?? $checkoutResponse['invoiceid'] ?? 0);
                throw_if($invoiceId <= 0, new BusinessException('上游未返回产品升降级账单 ID', 42200));

                $fundResponse = $catalog->post($supplier, "/v1/invoices/{$invoiceId}/fund", [], $jwt);
                $this->detailService->assertSuccess($fundResponse, '使用供应商余额支付产品升降级账单');

                $detailResponse = $catalog->getHostDetail($supplier, $hostId, $jwt);
                $this->detailService->assertSuccess($detailResponse, '读取产品升降级结果');
                $detailPayload = $this->detailService->extractPayload($detailResponse);
                $hostDetail = is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];
            }
            if ($hostDetail !== []) {
                $this->detailService->syncServiceFromRemote($service, $hostDetail);
                $service->refresh();
            }

            $provisionData = DB::transaction(function () use ($service, $order, $invoiceId, $productId, $billingCycle): array {
                $provisionData = $this->serviceProvisionData($service);
                $provisionData['last_upgrade_kind'] = self::ORDER_KIND;
                $provisionData['last_upgrade_order_id'] = (int) $order->id;
                $provisionData['last_upgrade_invoice_id'] = $invoiceId;
                $provisionData['last_upgrade_product_id'] = $productId;
                $provisionData['last_upgrade_billing_cycle'] = $billingCycle;
                $provisionData['upgrade_error'] = null;
                $provisionData['last_upgraded_at'] = now()->format('Y-m-d H:i:s');

                $service->forceFill(['provision_data' => $provisionData])->save();
                $order->forceFill(['status' => OrderStatus::COMPLETED])->save();

                return $provisionData;
            });
            $this->serviceBindingWriter()->syncServiceState($service, $service->product, $provisionData);
            $this->serviceBindingWriter()->recordProvisionAttempt(
                $service,
                $service->product,
                $provisionData,
                'success',
                null,
                $attemptRequestMeta,
                $this->filterAttemptMeta([
                    'upstream_host_id' => $resolvedHostId > 0 ? $resolvedHostId : null,
                    'upstream_invoice_id' => $invoiceId > 0 ? $invoiceId : null,
                    'last_upgraded_at' => $provisionData['last_upgraded_at'] ?? null,
                ]),
                self::UPGRADE_ATTEMPT_ACTION
            );

            $this->operationLogService->writeServiceConsoleLog($service, 'service.console.host_upgrade.purchase', [
                'category' => 'upgrade',
                'summary' => '产品升降级已完成',
                'amount' => (string) number_format((float) ($order->amount ?? 0), 2, '.', ''),
                'order_id' => (int) $order->id,
                'order_no' => (string) $order->order_no,
                'target_product_id' => $productId,
                'billing_cycle' => $billingCycle,
                'promo_code' => $promoCode,
            ], [
                'actor_type' => 'system',
                'actor_user_id' => 0,
                'actor_name' => 'system',
            ]);

            return $service->fresh(['product.supplier', 'order']) ?? $service;
        } catch (\Throwable $exception) {
            $provisionData = DB::transaction(function () use ($service, $order, $exception): array {
                $provisionData = $this->serviceProvisionData($service);
                $provisionData['upgrade_error'] = $exception instanceof BusinessException ? $exception->getMessage() : '产品升降级失败，请联系管理员处理';
                $provisionData['last_upgrade_attempt_at'] = now()->format('Y-m-d H:i:s');
                $provisionData['last_upgrade_kind'] = self::ORDER_KIND;

                $service->forceFill(['provision_data' => $provisionData])->save();
                $order->forceFill(['status' => OrderStatus::PROCESSING])->save();

                return $provisionData;
            });
            $this->serviceBindingWriter()->syncServiceState($service, $service->product, $provisionData);
            $this->serviceBindingWriter()->recordProvisionAttempt(
                $service,
                $service->product,
                $provisionData,
                'failed',
                (string) ($provisionData['upgrade_error'] ?? ''),
                $attemptRequestMeta,
                $this->filterAttemptMeta([
                    'upstream_host_id' => $resolvedHostId > 0 ? $resolvedHostId : null,
                    'upstream_invoice_id' => $invoiceId > 0 ? $invoiceId : null,
                ]),
                self::UPGRADE_ATTEMPT_ACTION
            );

            Log::error('[产品升降级] 上游执行失败', [
                'order_id' => (int) $order->id,
                'order_no' => (string) $order->order_no,
                'service_id' => (int) $service->id,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return $service->fresh(['product.supplier', 'order']) ?? $service;
        }
    }

    private function serviceBindingWriter(): ServiceUpstreamBindingWriter
    {
        return $this->serviceBindingWriter ??= app(ServiceUpstreamBindingWriter::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceProvisionData(Service $service): array
    {
        $legacy = is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $projection = $this->bindingResolver->serviceProvisionProjection($service);
        $provisionData = $projection === [] ? $legacy : array_replace($legacy, $projection);

        return $this->bindingResolver->sanitizeServiceProvisionData($provisionData);
    }

    /**
     * @return array<string, mixed>
     */
    private function upgradeAttemptRequestMeta(Order $order, int $productId, string $billingCycle, string $promoCode): array
    {
        return $this->filterAttemptMeta([
            'order_id' => (int) $order->id,
            'order_no' => (string) $order->order_no,
            'kind' => self::ORDER_KIND,
            'target_product_id' => $productId,
            'billing_cycle' => $billingCycle,
            'promo_code' => $promoCode,
            'trace_id' => (string) ($order->trace_id ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterAttemptMeta(array $payload): array
    {
        return array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
