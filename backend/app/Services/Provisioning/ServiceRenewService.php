<?php

namespace App\Services\Provisioning;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\CouponService;
use App\Services\Finance\InvoiceService;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\ServiceUpstreamBindingWriter;
use App\Services\Integrations\Support\ProviderErrorMapper;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\System\NotificationService;
use App\Services\System\OperationLogService;
use App\Services\System\SettingService;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\ProviderResolver;
use App\Support\SensitiveDataSanitizer;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServiceRenewService
{
    private const EXPIRED_SUSPENDED_AT_KEY = 'expired_suspended_at';

    private const RENEW_FULFILLMENT_STATUS_KEY = 'renew_fulfillment_status';

    private const RENEW_FULFILLMENT_PENDING = 'pending';

    private const RENEW_FULFILLMENT_PROCESSING = 'processing';

    private const RENEW_FULFILLMENT_SUCCEEDED = 'succeeded';

    private const RENEW_FULFILLMENT_FAILED = 'failed';

    private const RENEW_ATTEMPT_ACTION = 'renew';

    private const CYCLE_SORT_MAP = [
        'monthly' => 1,
        'quarterly' => 2,
        'semiannually' => 3,
        'annually' => 4,
        'biennially' => 5,
        'triennially' => 6,
        'one_time' => 7,
        'onetime' => 7,
    ];

    private ?ServiceUpstreamBindingWriter $serviceBindingWriter = null;

    public function __construct(
        private InvoiceService $invoiceService,
        private ProviderResolver $providerResolver,
        private CouponService $couponService,
        private OperationLogService $operationLogService,
        private SettingService $settingService,
        private NotificationService $notificationService,
        private ?PluginBindingResolver $bindingResolver = null,
    ) {}

    public function previewForUser(User $user, int $serviceId, ?string $selectedBillingCycle = null, int $userCouponId = 0): array
    {
        $service = $this->findUserService($user, $serviceId);
        $service = $this->healServiceProductMapping($service);
        $renewConfig = $this->buildRenewConfig($service);
        $effectiveProduct = $this->resolveEffectiveProduct($service) ?? $service->product;
        $productPricing = Service::extractSupportedRenewPricing(
            is_array($effectiveProduct?->pricing ?? null) ? $effectiveProduct->pricing : []
        );
        $currentCycleConfig = $service->getRenewPricingCycle((string) $service->billing_cycle, $productPricing);
        $previewAmount = (float) (
            $currentCycleConfig['effective_amount']
            ?? collect($renewConfig['cycles'])->first()['amount']
            ?? 0
        );
        $resolvedBillingCycle = trim((string) ($selectedBillingCycle ?: $renewConfig['default_cycle'] ?: $service->billing_cycle));
        $selectedCycleConfig = collect($renewConfig['cycles'])->firstWhere('billing_cycle', $resolvedBillingCycle);
        $selectedAmount = round((float) ($selectedCycleConfig['amount'] ?? $previewAmount), 2);
        $availableCoupons = $effectiveProduct instanceof Product
            ? $this->couponService->availableCouponsForCheckout((int) $user->id, $effectiveProduct, $resolvedBillingCycle, $selectedAmount, 'renew')
            : [];
        $selectedCoupon = null;
        if ($effectiveProduct instanceof Product && $userCouponId > 0) {
            try {
                $selectedCoupon = $this->couponService->previewOwnedCoupon(
                    $userCouponId,
                    (int) $user->id,
                    $effectiveProduct,
                    $resolvedBillingCycle,
                    $selectedAmount,
                    'renew'
                );
            } catch (\Throwable) {
                $selectedCoupon = null;
            }
        }

        $cycles = collect($renewConfig['cycles'])
            ->map(function (array $cycle) use ($effectiveProduct, $user, $userCouponId) {
                $amount = round((float) ($cycle['amount'] ?? 0), 2);
                $originalAmount = number_format($amount, 2, '.', '');
                $discountAmount = '0.00';
                $finalAmount = $originalAmount;

                if ($effectiveProduct instanceof Product && $userCouponId > 0) {
                    try {
                        $couponPayload = $this->couponService->previewOwnedCoupon(
                            $userCouponId,
                            (int) $user->id,
                            $effectiveProduct,
                            (string) ($cycle['billing_cycle'] ?? ''),
                            $amount,
                            'renew'
                        );

                        if (is_array($couponPayload)) {
                            $discountAmount = (string) ($couponPayload['discount_amount'] ?? '0.00');
                            $finalAmount = (string) ($couponPayload['final_amount'] ?? $originalAmount);
                        }
                    } catch (\Throwable) {
                        // 单个周期不满足优惠条件时保留原价展示
                    }
                }

                return [
                    ...$cycle,
                    'original_amount' => $originalAmount,
                    'discount_amount' => $discountAmount,
                    'amount' => $finalAmount,
                ];
            })
            ->values()
            ->all();

        return [
            'service_id' => (int) $service->id,
            'service_name' => (string) $service->name,
            'billing_cycle' => (string) $service->billing_cycle,
            'billing_cycle_label' => $this->resolveBillingCycleLabel((string) $service->billing_cycle),
            'expires_at' => $service->expires_at?->format('Y-m-d H:i:s'),
            'renew_price' => number_format($previewAmount, 2, '.', ''),
            'auto_renew' => (int) $service->auto_renew,
            'supports_upstream' => $renewConfig['supports_upstream'],
            'upstream_host_id' => $renewConfig['host_id'],
            'cycles' => $cycles,
            'default_cycle' => $resolvedBillingCycle,
            'available_coupons' => $availableCoupons,
            'selected_user_coupon_id' => (int) ($selectedCoupon['user_coupon_id'] ?? 0),
            'selected_coupon' => $selectedCoupon,
            'has_locked_pricing' => $service->usesCustomRenewPricing($productPricing),
            'has_custom_renew_pricing' => $service->usesCustomRenewPricing($productPricing),
        ];
    }

    public function createRenewInvoiceForUser(User $user, int $serviceId, string $billingCycle, int $userCouponId = 0, array $context = []): Invoice
    {
        $service = $this->findUserService($user, $serviceId);
        $service = $this->healServiceProductMapping($service);
        $renewConfig = $this->buildRenewConfig($service);
        $cycle = trim($billingCycle);
        $cycleOption = collect($renewConfig['cycles'])->firstWhere('billing_cycle', $cycle);
        $effectiveProduct = $this->resolveEffectiveProduct($service) ?? $service->product;

        throw_if(! is_array($cycleOption), new BusinessException('当前服务不支持所选续费周期'));
        throw_if(! $effectiveProduct instanceof Product, new BusinessException('服务关联商品不存在，无法创建续费账单'));

        $amount = round((float) ($cycleOption['amount'] ?? 0), 2);
        throw_if($amount <= 0, new BusinessException('当前续费周期金额无效'));

        $blockingPaidInvoice = $this->findBlockingPaidRenewInvoice($user, $service, $cycle, $userCouponId);
        if ($blockingPaidInvoice instanceof Invoice) {
            $blockingPaidInvoice->loadMissing(['product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires', 'service']);
            $this->operationLogService->writeServiceConsoleLog($service, 'service.console.renew.invoice.create', [
                'category' => 'renew',
                'summary' => '获取已支付待处理续费账单',
                'billing_cycle' => $cycle,
                'billing_cycle_label' => $this->resolveBillingCycleLabel($cycle),
                'amount' => number_format((float) $blockingPaidInvoice->amount, 2, '.', ''),
                'invoice_id' => (int) $blockingPaidInvoice->id,
                'invoice_no' => (string) $blockingPaidInvoice->invoice_no,
                'reused_invoice' => true,
                'paid_unfulfilled' => true,
            ], $context);

            return $blockingPaidInvoice;
        }

        $existingInvoice = Invoice::query()
            ->where('user_id', $user->id)
            ->where('service_id', $service->id)
            ->where('type', OrderType::RENEW)
            ->where('billing_cycle', $cycle)
            ->where('user_coupon_id', $userCouponId > 0 ? $userCouponId : null)
            ->where('status', InvoiceStatus::UNPAID)
            ->latest('id')
            ->first();

        if ($existingInvoice instanceof Invoice) {
            $existingAmount = round((float) $existingInvoice->amount + (float) ($existingInvoice->discount ?? 0), 2);
            $existingDiscount = round((float) ($existingInvoice->discount ?? 0), 2);
            $expectedCouponPayload = $this->couponService->previewOwnedCoupon(
                $userCouponId > 0 ? $userCouponId : null,
                (int) $user->id,
                $effectiveProduct,
                $cycle,
                $amount,
                OrderType::RENEW
            );
            $expectedDiscount = round((float) ($expectedCouponPayload['discount_amount'] ?? 0), 2);

            if ($existingAmount === $amount && $existingDiscount === $expectedDiscount && (int) $existingInvoice->product_id === (int) $effectiveProduct->id) {
                $existingInvoice->loadMissing(['product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires', 'service']);
                $this->operationLogService->writeServiceConsoleLog($service, 'service.console.renew.invoice.create', [
                    'category' => 'renew',
                    'summary' => '获取待支付续费账单',
                    'billing_cycle' => $cycle,
                    'billing_cycle_label' => $this->resolveBillingCycleLabel($cycle),
                    'amount' => number_format(max($amount - $expectedDiscount, 0), 2, '.', ''),
                    'invoice_id' => (int) $existingInvoice->id,
                    'invoice_no' => (string) $existingInvoice->invoice_no,
                    'reused_invoice' => true,
                ], $context);

                return $existingInvoice;
            }

            app(CheckoutService::class)->cancel($existingInvoice, array_merge($context, [
                'actor_type' => (string) ($context['actor_type'] ?? 'system'),
                'actor_user_id' => (int) ($context['actor_user_id'] ?? $user->id),
                'actor_name' => (string) ($context['actor_name'] ?? ($user->display_name ?? $user->nickname ?? $user->email ?? '')),
                'reason' => 'renew_invoice_replaced',
            ]));
        }

        $sourceProvisionData = $this->serviceProvisionData($service);

        $invoice = DB::transaction(function () use ($user, $service, $cycle, $amount, $renewConfig, $cycleOption, $effectiveProduct, $userCouponId, $context, $sourceProvisionData) {
            $displayPayload = (new ProductDisplayNameResolver)->resolveForProduct($effectiveProduct);
            $productSpecDisplay = (string) ($displayPayload['product_spec_display'] ?? '');
            $couponPayload = $this->couponService->reserveOwnedCouponForInvoice(
                $userCouponId > 0 ? $userCouponId : null,
                (int) $user->id,
                $effectiveProduct,
                $cycle,
                $amount,
                'renew'
            );
            $discountAmount = round((float) ($couponPayload['discount_amount'] ?? 0), 2);
            $payableAmount = round(max($amount - $discountAmount, 0), 2);

            $invoice = Invoice::create([
                'invoice_no' => Invoice::generateInvoiceNo(),
                'user_id' => $user->id,
                'product_id' => $effectiveProduct->id,
                'product_spec_snapshot' => $productSpecDisplay,
                'product_type_snapshot' => (string) $effectiveProduct->product_type,
                'service_id' => $service->id,
                'type' => OrderType::RENEW,
                'amount' => $payableAmount,
                'discount' => $discountAmount,
                'paid_amount' => 0,
                'coupon_id' => $couponPayload['coupon_id'] ?? null,
                'user_coupon_id' => $couponPayload['user_coupon_id'] ?? null,
                'coupon_code' => $couponPayload['code'] ?? null,
                'billing_cycle' => $cycle,
                'config_snapshot' => array_filter([
                    'renew_service_id' => (int) $service->id,
                    'renew_service_name' => (string) $service->name,
                    'source_type' => (string) (($sourceProvisionData['source_type'] ?? '') ?: 'manual'),
                    'created_by' => (string) ($context['source'] ?? $context['operator'] ?? ''),
                    'auto_renew' => ! empty($context['auto_renew']) ? 1 : null,
                    'auto_renew_trace_id' => ! empty($context['auto_renew']) ? (string) ($context['trace_id'] ?? '') : null,
                    'upstream_host_id' => $renewConfig['host_id'],
                    'supports_upstream' => $renewConfig['supports_upstream'],
                    'local_renew_amount' => number_format($amount, 2, '.', ''),
                    'upstream_amount' => (string) ($cycleOption['upstream_amount'] ?? ''),
                    'discount_amount' => number_format($discountAmount, 2, '.', ''),
                ], fn ($value) => ! in_array($value, ['', null], true)),
                'coupon_snapshot' => $couponPayload,
                'status' => InvoiceStatus::UNPAID,
                'due_date' => now()->addDays(7),
                'trace_id' => (string) ($context['trace_id'] ?? ''),
            ]);

            $this->invoiceService->syncProjection($invoice);

            return $invoice->load(['product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires', 'service']);
        });
        $discountAmount = round((float) ($invoice->discount ?? 0), 2);
        $payableAmount = round((float) $invoice->amount, 2);

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.renew.invoice.create', [
            'category' => 'renew',
            'summary' => '创建续费账单',
            'billing_cycle' => $cycle,
            'billing_cycle_label' => $this->resolveBillingCycleLabel($cycle),
            'amount' => number_format($payableAmount, 2, '.', ''),
            'discount' => number_format($discountAmount, 2, '.', ''),
            'invoice_id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'reused_invoice' => false,
        ], $context);

        return $invoice;
    }

    public function createRenewOrderForUser(User $user, int $serviceId, string $billingCycle, int $userCouponId = 0, array $context = []): Order
    {
        $service = $this->findUserService($user, $serviceId);
        $service = $this->healServiceProductMapping($service);
        $renewConfig = $this->buildRenewConfig($service);
        $cycle = trim($billingCycle);
        $cycleOption = collect($renewConfig['cycles'])->firstWhere('billing_cycle', $cycle);
        $effectiveProduct = $this->resolveEffectiveProduct($service) ?? $service->product;

        throw_if(! is_array($cycleOption), new BusinessException('当前服务不支持所选续费周期'));
        throw_if(! $effectiveProduct instanceof Product, new BusinessException('服务关联商品不存在，无法创建续费订单'));

        $amount = round((float) ($cycleOption['amount'] ?? 0), 2);
        throw_if($amount <= 0, new BusinessException('当前续费周期金额无效'));

        $sourceProvisionData = $this->serviceProvisionData($service);

        $order = DB::transaction(function () use ($user, $service, $cycle, $amount, $renewConfig, $cycleOption, $effectiveProduct, $userCouponId, $context, $sourceProvisionData) {
            $displayPayload = (new ProductDisplayNameResolver)->resolveForProduct($effectiveProduct);
            $productSpecDisplay = (string) ($displayPayload['product_spec_display'] ?? '');
            $couponPayload = $this->couponService->reserveOwnedCouponForOrder(
                $userCouponId > 0 ? $userCouponId : null,
                (int) $user->id,
                $effectiveProduct,
                $cycle,
                $amount,
                OrderType::RENEW
            );
            $discountAmount = round((float) ($couponPayload['discount_amount'] ?? 0), 2);

            $order = Order::query()->create([
                'order_no' => Order::generateOrderNo(),
                'projection_type' => Order::PROJECTION_TYPE_PROVISIONING,
                'user_id' => (int) $user->id,
                'product_id' => (int) $effectiveProduct->id,
                'product_spec_snapshot' => $productSpecDisplay,
                'product_type_snapshot' => (string) $effectiveProduct->product_type,
                'service_id' => (int) $service->id,
                'type' => OrderType::RENEW,
                'coupon_id' => $couponPayload['coupon_id'] ?? null,
                'user_coupon_id' => $couponPayload['user_coupon_id'] ?? null,
                'coupon_code' => $couponPayload['code'] ?? null,
                'amount' => $amount,
                'discount' => $discountAmount,
                'paid_amount' => 0,
                'billing_cycle' => $cycle,
                'quantity' => 1,
                'config_snapshot' => array_filter([
                    'renew_service_id' => (int) $service->id,
                    'renew_service_name' => (string) $service->name,
                    'source_type' => (string) (($sourceProvisionData['source_type'] ?? '') ?: 'manual'),
                    'created_by' => (string) ($context['source'] ?? $context['operator'] ?? ''),
                    'auto_renew' => ! empty($context['auto_renew']) ? 1 : null,
                    'auto_renew_trace_id' => ! empty($context['auto_renew']) ? (string) ($context['trace_id'] ?? '') : null,
                    'upstream_host_id' => $renewConfig['host_id'],
                    'supports_upstream' => $renewConfig['supports_upstream'],
                    'local_renew_amount' => number_format($amount, 2, '.', ''),
                    'upstream_amount' => (string) ($cycleOption['upstream_amount'] ?? ''),
                    'discount_amount' => number_format($discountAmount, 2, '.', ''),
                ], fn ($value) => ! in_array($value, ['', null], true)),
                'coupon_snapshot' => $couponPayload,
                'status' => OrderStatus::PENDING,
                'trace_id' => (string) ($context['trace_id'] ?? ''),
            ]);

            $invoice = $this->invoiceService->createFromOrder($order);
            throw_if(
                ! empty($context['auto_renew']) && round((float) ($invoice->amount ?? 0), 2) <= 0,
                new BusinessException('自动续费金额异常，已拦截本次续费')
            );

            return $order->setRelation('invoice', $invoice)->load([
                'invoice.product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
                'invoice.service',
            ]);
        });

        $invoice = $order->invoice;
        $discountAmount = round((float) ($invoice?->discount ?? $order->discount ?? 0), 2);
        $payableAmount = round((float) ($invoice?->amount ?? ((float) $order->amount - (float) $order->discount)), 2);

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.renew.order.create', [
            'category' => 'renew',
            'summary' => '创建续费订单',
            'billing_cycle' => $cycle,
            'billing_cycle_label' => $this->resolveBillingCycleLabel($cycle),
            'amount' => number_format($payableAmount, 2, '.', ''),
            'discount' => number_format($discountAmount, 2, '.', ''),
            'order_id' => (int) $order->id,
            'order_no' => (string) $order->order_no,
            'invoice_id' => (int) ($invoice?->id ?? 0),
            'invoice_no' => (string) ($invoice?->invoice_no ?? ''),
            'reused_order' => false,
        ], $context);

        return $order;
    }

    public function updateAutoRenewForUser(User $user, int $serviceId, int $autoRenew, array $context = []): array
    {
        $service = $this->findUserService($user, $serviceId);
        $service = $this->healServiceProductMapping($service);
        $effectiveProduct = $this->resolveEffectiveProduct($service);
        $enabled = $autoRenew === 1 ? 1 : 0;

        $provisionData = $this->serviceProvisionData($service);
        $provisionData['initiative_renew'] = $enabled;

        $service->forceFill([
            'auto_renew' => $enabled,
            'provision_data' => $provisionData,
        ])->save();
        $this->serviceBindingWriter()->syncServiceState($service, $effectiveProduct, $provisionData);

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.renew.auto_update', [
            'category' => 'renew',
            'summary' => $enabled === 1 ? '开启自动续费' : '关闭自动续费',
            'auto_renew' => $enabled,
            'auto_renew_label' => $enabled === 1 ? '已开启' : '已关闭',
            'host_id' => $this->resolveUpstreamHostId($service),
        ], $context);

        return [
            'service_id' => (int) $service->id,
            'auto_renew' => $enabled,
        ];
    }

    public function processPaidRenewOrder(Order $order): ?Service
    {
        $order->loadMissing(['invoice', 'service.product.supplier', 'product.supplier']);

        if ($order->invoice instanceof Invoice) {
            return $this->processPaidRenewInvoice($order->invoice);
        }

        return null;
    }

    /**
     * 基于账单处理已支付续费（无订单场景的过渡桥接）
     */
    public function processPaidRenewInvoice(Invoice $invoice): ?Service
    {
        try {
            return Cache::lock("lock:renew:invoice:{$invoice->id}", 300)
                ->block(3, fn () => $this->processPaidRenewInvoiceLocked($invoice));
        } catch (LockTimeoutException) {
            Log::info('[服务续费·账单] 同一账单正在处理，跳过本次重复履约', [
                'invoice_id' => (int) $invoice->id,
                'invoice_no' => (string) ($invoice->invoice_no ?? ''),
            ]);

            return $invoice->fresh(['service.product.supplier'])?->service;
        }
    }

    private function processPaidRenewInvoiceLocked(Invoice $invoice): ?Service
    {
        $invoice->loadMissing(['service.product.supplier', 'order.service.product.supplier', 'order.product.supplier']);

        $serviceId = (int) ($invoice->service_id ?? 0);
        if ($serviceId <= 0 && $invoice->order?->service) {
            $serviceId = (int) $invoice->order->service->id;
        }

        if ($serviceId <= 0) {
            Log::warning('[服务续费] 账单无关联服务，无法续费', ['invoice_id' => $invoice->id]);

            return null;
        }

        $service = Service::with(['product.supplier'])->find($serviceId);
        if (! $service instanceof Service) {
            return null;
        }

        $newerPaidInvoice = $this->findNewerPaidRenewInvoice($invoice, $service);
        if ($newerPaidInvoice instanceof Invoice) {
            Log::warning('[服务续费·账单] 已有较新的已支付续费账单，跳过旧账单处理', [
                'invoice_id' => (int) $invoice->id,
                'invoice_no' => (string) ($invoice->invoice_no ?? ''),
                'service_id' => (int) $service->id,
                'superseded_by_invoice_id' => (int) $newerPaidInvoice->id,
                'superseded_by_invoice_no' => (string) ($newerPaidInvoice->invoice_no ?? ''),
            ]);

            return $service->fresh(['product.supplier']) ?? $service;
        }

        $provisionData = $this->serviceProvisionData($service);
        if ((int) ($provisionData['last_renew_invoice_id'] ?? 0) === (int) $invoice->id) {
            return $service->fresh(['product.supplier']) ?? $service;
        }

        $service = $this->healServiceProductMapping($service);
        $effectiveProduct = $this->resolveEffectiveProduct($service) ?? $service->product;
        $billingCycle = (string) ($invoice->billing_cycle ?? $service->billing_cycle);

        // 尝试上游续费
        if ($this->supportsUpstreamRenew($service, $effectiveProduct)) {
            $boundSupplier = $this->pluginBindingResolver()->supplierForService($service)
                ?? ($effectiveProduct instanceof Product
                    ? $this->pluginBindingResolver()->supplierForProduct($effectiveProduct)
                    : null);
            $supplier = $this->supplierWithRuntimeCredentials($boundSupplier);
            $hostId = $this->resolveUpstreamHostId($service);

            if ($supplier instanceof Supplier && $hostId > 0) {
                $existingProvisionData = $this->serviceProvisionData($service);
                $hasCurrentUpstreamRenewInvoice = (int) ($existingProvisionData['upstream_invoice_id'] ?? 0) > 0
                    && (int) ($existingProvisionData['renew_invoice_id'] ?? 0) === (int) $invoice->id;

                try {
                    if ($hasCurrentUpstreamRenewInvoice) {
                        throw new BusinessException('检测到当前续费账单已创建上游账单，正在恢复支付状态');
                    }

                    $service = $this->markRenewFulfillment($service, $invoice, self::RENEW_FULFILLMENT_PROCESSING, [
                        'upstream_invoice_id' => null,
                        'renew_recovery_context' => [],
                        'last_renew_attempt_at' => now()->format('Y-m-d H:i:s'),
                        'renew_error' => null,
                    ]);
                    $renewal = $this->resolveRenewalCapability($service, $effectiveProduct);
                    $paymentCompleted = true;
                    $upstreamFundError = '';

                    if (method_exists($renewal, 'renewServiceInvoice')) {
                        $renewResult = $renewal->renewServiceInvoice($supplier, $hostId, $billingCycle);
                        $upstreamInvoiceId = (int) ($renewResult['upstream_invoice_id'] ?? 0);
                        $hostDetail = is_array($renewResult['host_detail'] ?? null) ? $renewResult['host_detail'] : [];
                        $paymentCompleted = (bool) ($renewResult['payment_completed'] ?? true);
                        $upstreamFundError = trim((string) ($renewResult['fund_error'] ?? ''));
                        $renewRecoveryContext = is_array($renewResult['recovery_context'] ?? null)
                            ? $renewResult['recovery_context']
                            : [];
                    } else {
                        $renewRecoveryContext = [];
                        $jwt = $renewal->login($supplier);
                        $renewResponse = $renewal->renewHost($supplier, $hostId, $billingCycle);
                        $this->assertUpstreamSuccess($renewResponse, [200], '提交上游续费', $this->resolveProviderKeyForService($service, $effectiveProduct));
                        $renewPayload = $this->extractPayload($renewResponse);
                        $upstreamInvoiceId = (int) ($renewPayload['invoiceid'] ?? $renewResponse['invoiceid'] ?? 0);

                        throw_if($upstreamInvoiceId <= 0, new BusinessException('上游未返回续费账单 ID'));

                        $service = $this->markRenewFulfillment($service, $invoice, self::RENEW_FULFILLMENT_PROCESSING, [
                            'upstream_invoice_id' => $upstreamInvoiceId,
                            'last_renew_attempt_at' => now()->format('Y-m-d H:i:s'),
                            'renew_error' => null,
                        ]);

                        $fundResponse = $renewal->post($supplier, "/v1/invoices/{$upstreamInvoiceId}/fund", [], $jwt);
                        $this->assertUpstreamSuccess($fundResponse, [200, 1001], '使用供应商余额支付续费账单', $this->resolveProviderKeyForService($service, $effectiveProduct));

                        $hostDetail = [];
                        try {
                            $detailResponse = $renewal->get($supplier, "/v1/hosts/{$hostId}", $jwt);
                            $this->assertUpstreamSuccess($detailResponse, [200], '读取上游续费结果', $this->resolveProviderKeyForService($service, $effectiveProduct));
                            $detailPayload = $this->extractPayload($detailResponse);
                            $hostDetail = is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];
                        } catch (\Throwable $detailException) {
                            Log::warning('[服务续费·账单] 上游已完成续费，但读取实例详情失败，已按本地结果回写', [
                                'invoice_id' => $invoice->id,
                                'invoice_no' => $invoice->invoice_no ?? '',
                                'service_id' => $service->id,
                                'host_id' => $hostId,
                                'upstream_invoice_id' => $upstreamInvoiceId,
                                'message' => $detailException->getMessage(),
                                'exception' => $detailException::class,
                            ]);
                        }
                    }

                    throw_if($upstreamInvoiceId <= 0, new BusinessException('上游未返回续费账单 ID'));

                    $service = $this->markRenewFulfillment($service, $invoice, self::RENEW_FULFILLMENT_PROCESSING, [
                        'upstream_invoice_id' => $upstreamInvoiceId,
                        'renew_recovery_context' => $renewRecoveryContext,
                        'last_renew_attempt_at' => now()->format('Y-m-d H:i:s'),
                        'renew_error' => null,
                    ]);

                    throw_if(
                        ! $paymentCompleted,
                        new BusinessException($upstreamFundError !== ''
                            ? $upstreamFundError
                            : '上游续费账单未支付完成，请检查供应商余额')
                    );

                    $nextExpiresAt = $this->resolveRenewedExpiry($service, $billingCycle, $hostDetail);
                    $provisionData = $this->serviceProvisionData($service);
                    $provisionData['upstream_invoice_id'] = $upstreamInvoiceId;
                    $provisionData['upstream_status'] = (string) ($hostDetail['domainstatus'] ?? ($provisionData['upstream_status'] ?? ''));
                    $provisionData['initiative_renew'] = (int) $service->auto_renew;
                    $provisionData['last_renewed_at'] = now()->format('Y-m-d H:i:s');
                    $provisionData['last_renew_billing_cycle'] = $billingCycle;
                    $provisionData['last_renew_invoice_id'] = (int) $invoice->id;
                    $provisionData['last_renew_invoice_no'] = (string) $invoice->invoice_no;
                    $provisionData['last_renew_source'] = 'upstream';
                    $provisionData['renew_error'] = null;
                    $provisionData[self::RENEW_FULFILLMENT_STATUS_KEY] = self::RENEW_FULFILLMENT_SUCCEEDED;
                    $provisionData['renew_invoice_id'] = (int) $invoice->id;

                    return $this->finalizeRenewInvoiceSuccess($service, $invoice, $provisionData, $nextExpiresAt);
                } catch (\Throwable $exception) {
                    // 恢复：检查上游续费账单是否已生成或已支付（fund 后崩溃保护）
                    $currentService = $service->fresh(['product.supplier']) ?? $service;
                    $currentProvisionData = $this->serviceProvisionData($currentService);
                    $existingUpstreamInvoiceId = (int) ($currentProvisionData['upstream_invoice_id'] ?? 0);
                    $currentRenewInvoiceId = (int) ($currentProvisionData['renew_invoice_id'] ?? 0);
                    $recoveryError = '';

                    if (
                        $existingUpstreamInvoiceId > 0
                        && $currentRenewInvoiceId === (int) $invoice->id
                        && isset($supplier)
                        && $supplier instanceof Supplier
                        && $hostId > 0
                    ) {
                        try {
                            $renewal = $this->resolveRenewalCapability($currentService, $effectiveProduct);

                            if (method_exists($renewal, 'recoverRenewInvoiceWithContext') || method_exists($renewal, 'recoverRenewInvoice')) {
                                $recovery = method_exists($renewal, 'recoverRenewInvoiceWithContext')
                                    ? $renewal->recoverRenewInvoiceWithContext(
                                        $supplier,
                                        $hostId,
                                        $existingUpstreamInvoiceId,
                                        is_array($currentProvisionData['renew_recovery_context'] ?? null)
                                            ? $currentProvisionData['renew_recovery_context']
                                            : [],
                                    )
                                    : $renewal->recoverRenewInvoice($supplier, $hostId, $existingUpstreamInvoiceId);
                                throw_if(! is_array($recovery), new BusinessException('上游续费账单状态尚未确认，请稍后重试'));

                                $recoveryErrorMessage = trim((string) ($recovery['fund_error'] ?? ''));
                                throw_if(
                                    ($recovery['payment_completed'] ?? true) !== true,
                                    new BusinessException($recoveryErrorMessage !== ''
                                        ? $recoveryErrorMessage
                                        : '上游续费账单仍未支付完成，请检查供应商余额')
                                );

                                $recoveryHostDetail = is_array($recovery['host_detail'] ?? null) ? $recovery['host_detail'] : [];
                                $recoveredExpiresAt = $this->resolveRenewedExpiry($currentService, $billingCycle, $recoveryHostDetail);
                                $currentProvisionData['upstream_status'] = (string) ($recoveryHostDetail['domainstatus'] ?? '');
                                $currentProvisionData['renew_error'] = null;
                                $currentProvisionData[self::RENEW_FULFILLMENT_STATUS_KEY] = self::RENEW_FULFILLMENT_SUCCEEDED;
                                $currentProvisionData['last_renewed_at'] = now()->format('Y-m-d H:i:s');
                                $currentProvisionData['last_renew_billing_cycle'] = $billingCycle;
                                $currentProvisionData['last_renew_invoice_id'] = (int) $invoice->id;
                                $currentProvisionData['last_renew_invoice_no'] = (string) $invoice->invoice_no;
                                $currentProvisionData['last_renew_source'] = 'upstream';
                                $currentProvisionData['renew_invoice_id'] = (int) $invoice->id;

                                Log::info('[服务续费·恢复] 上游续费恢复成功，本地完成履约', [
                                    'invoice_id' => $invoice->id,
                                    'upstream_invoice_id' => $existingUpstreamInvoiceId,
                                ]);

                                return $this->finalizeRenewInvoiceSuccess($currentService, $invoice, $currentProvisionData, $recoveredExpiresAt);
                            }

                            $recoveryJwt = $renewal->login($supplier);

                            // 查询上游账单状态
                            $invoiceResponse = $renewal->get($supplier, "/v1/invoices/{$existingUpstreamInvoiceId}", $recoveryJwt);
                            $invoicePayload = $this->extractPayload($invoiceResponse);
                            $upstreamStatus = (string) ($invoicePayload['status'] ?? '');

                            if ($upstreamStatus === 'Paid') {
                                // 上游已支付→本地完成履约
                                $recoveryHostDetail = [];
                                try {
                                    $detailResponse = $renewal->get($supplier, "/v1/hosts/{$hostId}", $recoveryJwt);
                                    $detailPayload = $this->extractPayload($detailResponse);
                                    $recoveryHostDetail = is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];
                                } catch (\Throwable $recoveryDetailException) {
                                    Log::warning('[服务续费·恢复] 上游已支付续费，读取实例详情失败', [
                                        'invoice_id' => $invoice->id,
                                        'upstream_invoice_id' => $existingUpstreamInvoiceId,
                                        'host_id' => $hostId,
                                        'message' => $recoveryDetailException->getMessage(),
                                    ]);
                                }

                                $recoveredExpiresAt = $this->resolveRenewedExpiry($currentService, $billingCycle, $recoveryHostDetail);
                                $currentProvisionData['upstream_status'] = (string) ($recoveryHostDetail['domainstatus'] ?? '');
                                $currentProvisionData['renew_error'] = null;
                                $currentProvisionData[self::RENEW_FULFILLMENT_STATUS_KEY] = self::RENEW_FULFILLMENT_SUCCEEDED;
                                $currentProvisionData['last_renewed_at'] = now()->format('Y-m-d H:i:s');
                                $currentProvisionData['last_renew_billing_cycle'] = $billingCycle;
                                $currentProvisionData['last_renew_invoice_id'] = (int) $invoice->id;
                                $currentProvisionData['last_renew_invoice_no'] = (string) $invoice->invoice_no;
                                $currentProvisionData['last_renew_source'] = 'upstream';
                                $currentProvisionData['renew_invoice_id'] = (int) $invoice->id;

                                Log::info('[服务续费·恢复] 上游续费已支付，本地完成履约', [
                                    'invoice_id' => $invoice->id,
                                    'upstream_invoice_id' => $existingUpstreamInvoiceId,
                                ]);

                                return $this->finalizeRenewInvoiceSuccess($currentService, $invoice, $currentProvisionData, $recoveredExpiresAt);
                            }

                            if ($upstreamStatus === 'Unpaid') {
                                // 上游账单未支付→重试余额支付
                                $fundResponse = $renewal->post($supplier, "/v1/invoices/{$existingUpstreamInvoiceId}/fund", [], $recoveryJwt);
                                $this->assertUpstreamSuccess($fundResponse, [200, 1001], '恢复：重试供应商余额支付续费账单', $this->resolveProviderKeyForService($currentService, $effectiveProduct));

                                $recoveryHostDetail = [];
                                try {
                                    $detailResponse = $renewal->get($supplier, "/v1/hosts/{$hostId}", $recoveryJwt);
                                    $detailPayload = $this->extractPayload($detailResponse);
                                    $recoveryHostDetail = is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];
                                } catch (\Throwable $recoveryDetailException) {
                                    Log::warning('[服务续费·恢复] fund 重试成功，读取实例详情失败', [
                                        'invoice_id' => $invoice->id,
                                        'upstream_invoice_id' => $existingUpstreamInvoiceId,
                                        'host_id' => $hostId,
                                        'message' => $recoveryDetailException->getMessage(),
                                    ]);
                                }

                                $recoveredExpiresAt = $this->resolveRenewedExpiry($currentService, $billingCycle, $recoveryHostDetail);
                                $currentProvisionData['upstream_status'] = (string) ($recoveryHostDetail['domainstatus'] ?? '');
                                $currentProvisionData['renew_error'] = null;
                                $currentProvisionData[self::RENEW_FULFILLMENT_STATUS_KEY] = self::RENEW_FULFILLMENT_SUCCEEDED;
                                $currentProvisionData['last_renewed_at'] = now()->format('Y-m-d H:i:s');
                                $currentProvisionData['last_renew_billing_cycle'] = $billingCycle;
                                $currentProvisionData['last_renew_invoice_id'] = (int) $invoice->id;
                                $currentProvisionData['last_renew_invoice_no'] = (string) $invoice->invoice_no;
                                $currentProvisionData['last_renew_source'] = 'upstream';
                                $currentProvisionData['renew_invoice_id'] = (int) $invoice->id;

                                Log::info('[服务续费·恢复] 上游续费 fund 重试成功，本地完成履约', [
                                    'invoice_id' => $invoice->id,
                                    'upstream_invoice_id' => $existingUpstreamInvoiceId,
                                ]);

                                return $this->finalizeRenewInvoiceSuccess($currentService, $invoice, $currentProvisionData, $recoveredExpiresAt);
                            }
                        } catch (\Throwable $recoveryException) {
                            $recoveryError = $recoveryException instanceof BusinessException
                                ? $recoveryException->getMessage()
                                : '上游续费恢复失败，请稍后重试';
                            Log::warning('[服务续费·恢复] 上游续费恢复尝试失败', [
                                'invoice_id' => $invoice->id,
                                'upstream_invoice_id' => $existingUpstreamInvoiceId,
                                'host_id' => $hostId,
                                'message' => $recoveryException->getMessage(),
                                'exception' => $recoveryException::class,
                            ]);
                        }
                    }

                    $message = $recoveryError !== ''
                        ? $recoveryError
                        : ($exception instanceof BusinessException
                            ? $exception->getMessage()
                            : '上游续费失败，请联系管理员处理');

                    $service = $this->markRenewFulfillment($currentService ?? $service, $invoice, self::RENEW_FULFILLMENT_FAILED, [
                        'renew_error' => $message,
                        'last_renew_attempt_at' => now()->format('Y-m-d H:i:s'),
                    ]);
                    $failureProvisionData = $this->serviceProvisionData($service);
                    $this->recordRenewInvoiceAttempt($service, $effectiveProduct, $invoice, $failureProvisionData, 'failed', $message, [
                        'upstream_host_id' => $hostId > 0 ? $hostId : null,
                        'upstream_invoice_id' => (int) ($failureProvisionData['upstream_invoice_id'] ?? 0) > 0
                            ? (int) $failureProvisionData['upstream_invoice_id']
                            : null,
                    ]);

                    Log::error('[服务续费·账单] 上游续费失败', [
                        'invoice_id' => $invoice->id,
                        'invoice_no' => $invoice->invoice_no ?? '',
                        'service_id' => $service->id,
                        'host_id' => $hostId,
                        'message' => $message,
                        'exception' => $exception::class,
                    ]);

                    return $service->fresh(['product.supplier']) ?? $service;
                }
            }
        }

        // 不支持上游续费或条件不满足时，走本地续费
        return $this->completeLocalRenewByInvoice($service, $invoice, $billingCycle);
    }

    private function completeLocalRenewByInvoice(Service $service, Invoice $invoice, string $billingCycle): Service
    {
        $nextExpiresAt = $this->resolveRenewedExpiry($service, $billingCycle);
        $provisionData = $this->serviceProvisionData($service);
        $provisionData['last_renewed_at'] = now()->format('Y-m-d H:i:s');
        $provisionData['last_renew_billing_cycle'] = $billingCycle;
        $provisionData['last_renew_invoice_id'] = (int) $invoice->id;
        $provisionData['last_renew_invoice_no'] = (string) $invoice->invoice_no;
        $provisionData['last_renew_source'] = 'local_invoice';
        $provisionData['renew_error'] = null;
        $provisionData[self::RENEW_FULFILLMENT_STATUS_KEY] = self::RENEW_FULFILLMENT_SUCCEEDED;
        $provisionData['renew_invoice_id'] = (int) $invoice->id;

        return $this->finalizeRenewInvoiceSuccess($service, $invoice, $provisionData, $nextExpiresAt);
    }

    private function finalizeRenewInvoiceSuccess(Service $service, Invoice $invoice, array $provisionData, ?Carbon $nextExpiresAt): Service
    {
        $previousStatus = (int) $service->status;
        $resolvedStatus = $this->resolveRenewedStatus($previousStatus);
        $provisionData[self::RENEW_FULFILLMENT_STATUS_KEY] = self::RENEW_FULFILLMENT_SUCCEEDED;
        $provisionData['renew_invoice_id'] = (int) $invoice->id;
        $provisionData['renew_invoice_no'] = (string) $invoice->invoice_no;
        unset($provisionData[self::EXPIRED_SUSPENDED_AT_KEY]);

        DB::transaction(function () use ($service, $invoice, $provisionData, $nextExpiresAt, $resolvedStatus, $previousStatus) {
            $service->forceFill([
                'product_id' => (int) ($invoice->product_id ?: $service->product_id),
                'invoice_id' => (int) $invoice->id,
                'billing_cycle' => (string) ($invoice->billing_cycle ?? $service->billing_cycle),
                'amount' => (float) $invoice->amount,
                'expires_at' => $nextExpiresAt,
                'status' => $resolvedStatus,
                'provision_data' => $provisionData,
                'suspended_reason' => in_array($previousStatus, [ServiceStatus::EXPIRED, ServiceStatus::SUSPENDED], true) ? null : $service->suspended_reason,
            ])->save();

            $this->syncInvoiceRenewFulfillmentSnapshot($invoice, self::RENEW_FULFILLMENT_SUCCEEDED, [
                'renew_invoice_id' => (int) $invoice->id,
                'renew_invoice_no' => (string) $invoice->invoice_no,
                'renew_error' => null,
            ]);
        });

        $updatedService = $service->fresh(['product.supplier']) ?? $service;
        $this->serviceBindingWriter()->syncServiceState($updatedService, $updatedService->product, $provisionData);
        $this->recordRenewInvoiceAttempt($updatedService, $updatedService->product, $invoice, $provisionData, 'success', null, [
            'expires_at' => $nextExpiresAt?->format('Y-m-d H:i:s'),
            'service_status' => (int) $updatedService->status,
        ]);
        $this->sendUnsuspendNotificationIfNeeded($updatedService, $previousStatus);

        return $updatedService;
    }

    private function completeLocalRenewal(Service $service, Order $order): Service
    {
        $nextExpiresAt = $this->resolveRenewedExpiry($service, (string) $order->billing_cycle);
        $provisionData = $this->serviceProvisionData($service);
        $provisionData['last_renewed_at'] = now()->format('Y-m-d H:i:s');
        $provisionData['last_renew_billing_cycle'] = (string) $order->billing_cycle;
        $provisionData['last_renew_order_id'] = (int) $order->id;
        $provisionData['last_renew_order_no'] = (string) $order->order_no;
        $provisionData['last_renew_source'] = 'local';
        $provisionData['renew_error'] = null;

        return $this->finalizeRenewSuccess($service, $order, $provisionData, $nextExpiresAt);
    }

    private function buildRenewConfig(Service $service): array
    {
        $effectiveProduct = $this->resolveEffectiveProduct($service) ?? $service->product;
        $cycles = [];
        $defaultCycle = trim((string) $service->billing_cycle);
        $hostId = $this->resolveUpstreamHostId($service);
        $supportsUpstream = $this->supportsUpstreamRenew($service, $effectiveProduct);
        $localPricing = Service::extractSupportedRenewPricing(
            is_array($effectiveProduct?->pricing ?? null) ? $effectiveProduct->pricing : []
        );

        foreach (Service::SUPPORTED_RENEW_BILLING_CYCLES as $cycle => $label) {
            $cycleConfig = $service->getRenewPricingCycle($cycle, $localPricing);
            if (! is_array($cycleConfig) || empty($cycleConfig['enabled'])) {
                continue;
            }

            $localAmount = (float) ($cycleConfig['effective_amount'] ?? 0);
            if ($localAmount <= 0) {
                continue;
            }

            $cycles[] = [
                'billing_cycle' => $cycle,
                'billing_cycle_label' => $label,
                'amount' => number_format($localAmount, 2, '.', ''),
                'upstream_amount' => '',
            ];
        }

        usort($cycles, fn (array $left, array $right) => (self::CYCLE_SORT_MAP[$left['billing_cycle']] ?? 999) <=> (self::CYCLE_SORT_MAP[$right['billing_cycle']] ?? 999));

        if (! collect($cycles)->contains(fn (array $item) => $item['billing_cycle'] === $defaultCycle)) {
            $defaultCycle = (string) ($cycles[0]['billing_cycle'] ?? $defaultCycle);
        }

        return [
            'cycles' => array_values($cycles),
            'default_cycle' => $defaultCycle,
            'supports_upstream' => $supportsUpstream,
            'host_id' => $hostId,
        ];
    }

    private function resolveRenewedExpiry(Service $service, string $billingCycle, array $hostDetail = []): ?Carbon
    {
        $nextDueDate = $hostDetail['nextduedate'] ?? null;
        if (is_numeric($nextDueDate) && (int) $nextDueDate > 0) {
            return Carbon::createFromTimestamp((int) $nextDueDate);
        }

        $base = $service->expires_at instanceof Carbon && $service->expires_at->isFuture()
            ? $service->expires_at->copy()
            : now();

        return match ($billingCycle) {
            'monthly' => $base->addMonth(),
            'quarterly' => $base->addMonths(3),
            'semiannually' => $base->addMonths(6),
            'annually' => $base->addYear(),
            'biennially' => $base->addYears(2),
            'triennially' => $base->addYears(3),
            default => $base,
        };
    }

    private function resolveRenewedStatus(int $status): int
    {
        return in_array($status, [ServiceStatus::ACTIVE, ServiceStatus::PENDING, ServiceStatus::SUSPENDED, ServiceStatus::EXPIRED], true)
            ? ServiceStatus::ACTIVE
            : $status;
    }

    private function finalizeRenewSuccess(Service $service, Order $order, array $provisionData, ?Carbon $nextExpiresAt): Service
    {
        $previousStatus = (int) $service->status;
        $resolvedStatus = $this->resolveRenewedStatus($previousStatus);
        unset($provisionData[self::EXPIRED_SUSPENDED_AT_KEY]);

        DB::transaction(function () use ($service, $order, $provisionData, $nextExpiresAt, $resolvedStatus, $previousStatus) {
            $service->forceFill([
                'product_id' => (int) ($order->product_id ?: $service->product_id),
                'order_id' => (int) $order->id,
                'billing_cycle' => (string) $order->billing_cycle,
                'amount' => (float) $order->amount,
                'expires_at' => $nextExpiresAt,
                'status' => $resolvedStatus,
                'provision_data' => $provisionData,
                'suspended_reason' => in_array($previousStatus, [ServiceStatus::EXPIRED, ServiceStatus::SUSPENDED], true) ? null : $service->suspended_reason,
            ])->save();

            $order->forceFill([
                'service_id' => (int) $service->id,
                'status' => OrderStatus::COMPLETED,
            ])->save();
        });

        $updatedService = $service->fresh(['product.supplier', 'order']) ?? $service;
        $this->serviceBindingWriter()->syncServiceState($updatedService, $updatedService->product, $provisionData);
        $this->recordRenewOrderAttempt($updatedService, $updatedService->product, $order, $provisionData, 'success', null, [
            'expires_at' => $nextExpiresAt?->format('Y-m-d H:i:s'),
            'service_status' => (int) $updatedService->status,
        ]);
        $this->sendUnsuspendNotificationIfNeeded($updatedService, $previousStatus);

        return $updatedService;
    }

    private function isRenewOrderAlreadyCompleted(Order $order, Service $service): bool
    {
        if ((int) $order->status === OrderStatus::COMPLETED) {
            return true;
        }

        $provisionData = $this->serviceProvisionData($service);

        return (int) ($provisionData['last_renew_order_id'] ?? 0) === (int) $order->id;
    }

    public function isRenewInvoiceFulfilled(Invoice $invoice, ?Service $service = null): bool
    {
        $service ??= $invoice->service instanceof Service
            ? $invoice->service
            : Service::query()->find((int) ($invoice->service_id ?? 0));

        if (! $service instanceof Service) {
            return false;
        }

        $provisionData = $this->serviceProvisionData($service);
        if ((int) ($provisionData['last_renew_invoice_id'] ?? 0) === (int) $invoice->id) {
            return true;
        }

        $configSnapshot = is_array($invoice->config_snapshot ?? null) ? $invoice->config_snapshot : [];

        return (int) ($provisionData['renew_invoice_id'] ?? 0) === (int) $invoice->id
            && (string) ($provisionData[self::RENEW_FULFILLMENT_STATUS_KEY] ?? $configSnapshot[self::RENEW_FULFILLMENT_STATUS_KEY] ?? '') === self::RENEW_FULFILLMENT_SUCCEEDED;
    }

    private function findBlockingPaidRenewInvoice(User $user, Service $service, string $cycle, int $userCouponId): ?Invoice
    {
        $latestPaidInvoice = Invoice::query()
            ->where('user_id', $user->id)
            ->where('service_id', $service->id)
            ->where('type', OrderType::RENEW)
            ->where('billing_cycle', $cycle)
            ->where('user_coupon_id', $userCouponId > 0 ? $userCouponId : null)
            ->where('status', InvoiceStatus::PAID)
            ->latest('id')
            ->first();

        if (! $latestPaidInvoice instanceof Invoice) {
            return null;
        }

        return $this->isRenewInvoiceFulfilled($latestPaidInvoice, $service)
            ? null
            : $latestPaidInvoice;
    }

    private function findNewerPaidRenewInvoice(Invoice $invoice, ?Service $service = null): ?Invoice
    {
        $serviceId = (int) ($invoice->service_id ?: ($service?->id ?? 0));
        if ($serviceId <= 0) {
            return null;
        }

        return Invoice::query()
            ->where('user_id', (int) $invoice->user_id)
            ->where('service_id', $serviceId)
            ->where('type', OrderType::RENEW)
            ->where('status', InvoiceStatus::PAID)
            ->where('id', '>', (int) $invoice->id)
            ->latest('id')
            ->first();
    }

    private function markRenewFulfillment(Service $service, Invoice $invoice, string $status, array $payload = []): Service
    {
        $statusPayload = array_merge([
            self::RENEW_FULFILLMENT_STATUS_KEY => $status,
            'renew_invoice_id' => (int) $invoice->id,
            'renew_invoice_no' => (string) $invoice->invoice_no,
        ], $payload);

        $provisionData = $this->serviceProvisionData($service);
        $service->forceFill([
            'provision_data' => array_merge($provisionData, $statusPayload),
        ])->save();
        $updatedService = $service->fresh(['product.supplier']) ?? $service;
        $this->serviceBindingWriter()->syncServiceState($updatedService, $updatedService->product, array_merge($provisionData, $statusPayload));

        $this->syncInvoiceRenewFulfillmentSnapshot($invoice, $status, $statusPayload);

        return $updatedService;
    }

    private function syncInvoiceRenewFulfillmentSnapshot(Invoice $invoice, string $status, array $payload = []): void
    {
        $configSnapshot = is_array($invoice->config_snapshot ?? null) ? $invoice->config_snapshot : [];
        $invoice->forceFill([
            'config_snapshot' => array_merge($configSnapshot, [
                self::RENEW_FULFILLMENT_STATUS_KEY => $status,
            ], $payload),
        ])->save();
    }

    private function recordRenewInvoiceAttempt(
        Service $service,
        ?Product $product,
        Invoice $invoice,
        array $provisionData,
        string $attemptStatus,
        ?string $errorMessage = null,
        array $responseMeta = []
    ): void {
        $this->serviceBindingWriter()->recordProvisionAttempt(
            $service,
            $product,
            $provisionData,
            $attemptStatus,
            $errorMessage,
            $this->filterAttemptMeta([
                'invoice_id' => (int) $invoice->id,
                'invoice_no' => (string) $invoice->invoice_no,
                'billing_cycle' => (string) ($invoice->billing_cycle ?? ''),
                'source' => (string) ($provisionData['last_renew_source'] ?? ''),
                'trace_id' => (string) ($invoice->trace_id ?? ''),
                'auto_renew' => (int) ($provisionData['initiative_renew'] ?? 0),
            ]),
            $this->filterAttemptMeta(array_merge([
                'renew_invoice_id' => $provisionData['renew_invoice_id'] ?? null,
                'renew_invoice_no' => $provisionData['renew_invoice_no'] ?? null,
                'upstream_invoice_id' => $provisionData['upstream_invoice_id'] ?? null,
                'last_renewed_at' => $provisionData['last_renewed_at'] ?? null,
                'renew_fulfillment_status' => $provisionData[self::RENEW_FULFILLMENT_STATUS_KEY] ?? null,
            ], $responseMeta)),
            self::RENEW_ATTEMPT_ACTION
        );
    }

    private function recordRenewOrderAttempt(
        Service $service,
        ?Product $product,
        Order $order,
        array $provisionData,
        string $attemptStatus,
        ?string $errorMessage = null,
        array $responseMeta = []
    ): void {
        $this->serviceBindingWriter()->recordProvisionAttempt(
            $service,
            $product,
            $provisionData,
            $attemptStatus,
            $errorMessage,
            $this->filterAttemptMeta([
                'order_id' => (int) $order->id,
                'order_no' => (string) $order->order_no,
                'billing_cycle' => (string) ($order->billing_cycle ?? ''),
                'source' => (string) ($provisionData['last_renew_source'] ?? ''),
                'trace_id' => (string) ($order->trace_id ?? ''),
            ]),
            $this->filterAttemptMeta(array_merge([
                'last_renewed_at' => $provisionData['last_renewed_at'] ?? null,
            ], $responseMeta)),
            self::RENEW_ATTEMPT_ACTION
        );
    }

    private function filterAttemptMeta(array $payload): array
    {
        return array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function supportsUpstreamRenew(Service $service, ?Product $product = null): bool
    {
        return $this->resolveUpstreamHostId($service) > 0
            && $this->providerResolver->resolveForService($service)->supports(ProvidesRenewal::class);
    }

    private function resolveEffectiveProduct(Service $service): ?Product
    {
        $boundProductId = (int) (($this->pluginBindingResolver()->productIdForService($service) ?? 0) ?: 0);
        if ($boundProductId > 0) {
            $boundProduct = Product::query()->with('supplier')->find($boundProductId);
            if ($boundProduct instanceof Product) {
                return $boundProduct;
            }
        }

        $currentProduct = $service->product;
        $provisionData = $this->serviceProvisionData($service);
        $supplierId = (int) (
            ($this->pluginBindingResolver()->supplierIdForService($service) ?? 0)
            ?: ($currentProduct instanceof Product ? ($this->pluginBindingResolver()->supplierIdForProduct($currentProduct) ?? 0) : 0)
        );
        $upstreamProductId = (string) (
            $this->pluginBindingResolver()->upstreamProductIdForService($service)
            ?? ($currentProduct instanceof Product ? $this->pluginBindingResolver()->upstreamProductIdForProduct($currentProduct) : null)
            ?? ($provisionData['upstream_product_id'] ?? '')
        );

        if ($supplierId <= 0 || trim($upstreamProductId) === '') {
            return $currentProduct;
        }

        $boundProductId = $this->pluginBindingResolver()->productIdForSupplierAndUpstreamProduct($supplierId, $upstreamProductId);
        if ($boundProductId !== null) {
            $boundProduct = Product::query()
                ->with('supplier')
                ->whereKey($boundProductId)
                ->where('status', 1)
                ->first();

            if ($boundProduct instanceof Product) {
                return $boundProduct;
            }
        }

        return $currentProduct;
    }

    private function healServiceProductMapping(Service $service): Service
    {
        $matchedProduct = $this->resolveEffectiveProduct($service);

        if (! $matchedProduct instanceof Product || (int) $matchedProduct->id === (int) $service->product_id) {
            return $service;
        }

        $service->forceFill([
            'product_id' => (int) $matchedProduct->id,
        ])->save();

        $service->setRelation('product', $matchedProduct);

        return $service;
    }

    private function resolveUpstreamHostId(Service $service): int
    {
        return (int) (($this->pluginBindingResolver()->upstreamServiceIdForService($service) ?? '') ?: 0);
    }

    private function resolveProviderKeyForService(Service $service, ?Product $product = null): string
    {
        if ($product instanceof Product) {
            $productKey = $this->providerResolver->resolveForProduct($product)->key();
            if ($productKey !== null && trim($productKey) !== '') {
                return trim($productKey);
            }
        }

        $serviceKey = $this->providerResolver->resolveForService($service)->key();

        return $serviceKey !== null ? trim($serviceKey) : '';
    }

    private function findUserService(User $user, int $serviceId): Service
    {
        return Service::query()
            ->with(['product.supplier', 'order'])
            ->where('user_id', $user->id)
            ->findOrFail($serviceId);
    }

    private function resolveBillingCycleLabel(string $billingCycle, string $fallback = ''): string
    {
        if ($fallback !== '') {
            return $fallback;
        }

        return [
            'monthly' => '月付',
            'quarterly' => '季付',
            'semiannually' => '半年付',
            'annually' => '年付',
            'biennially' => '两年付',
            'triennially' => '三年付',
            'one_time' => '一次性',
            'onetime' => '一次性',
        ][$billingCycle] ?? $billingCycle;
    }

    private function normalizeAmount(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function pluginBindingResolver(): PluginBindingResolver
    {
        return $this->bindingResolver ??= new PluginBindingResolver;
    }

    private function supplierWithRuntimeCredentials(?Supplier $supplier): ?Supplier
    {
        return $supplier instanceof Supplier
            ? $this->pluginBindingResolver()->supplierWithRuntimeCredentials($supplier)
            : null;
    }

    private function serviceBindingWriter(): ServiceUpstreamBindingWriter
    {
        return $this->serviceBindingWriter ??= app(ServiceUpstreamBindingWriter::class);
    }

    private function serviceProvisionData(Service $service, bool $includeSecrets = false): array
    {
        $legacy = is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $projection = $this->pluginBindingResolver()->serviceProvisionProjection($service, $includeSecrets);

        return $projection === [] ? $legacy : array_replace($legacy, $projection);
    }

    private function extractPayload(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    private function assertUpstreamSuccess(array $response, array $allowedStatuses, string $action, string $providerKey = ''): void
    {
        $status = (int) ($response['status'] ?? $response['code'] ?? $response['status_code'] ?? 200);
        if (in_array($status, $allowedStatuses, true)) {
            return;
        }

        $message = trim((string) ($response['msg'] ?? $response['message'] ?? ''));
        Log::warning('[上游续费] 返回失败', [
            'action' => $action,
            'status' => $status,
            'message' => SensitiveDataSanitizer::sanitizeText($message),
        ]);

        throw new BusinessException(app(ProviderErrorMapper::class)->toUserMessage($providerKey, $action, $message));
    }

    private function resolveRenewalCapability(Service $service, ?Product $product = null): object
    {
        if ($product instanceof Product) {
            $resolved = $this->providerResolver->resolveForProduct($product);
            if ($resolved->supports(ProvidesRenewal::class)) {
                return $resolved->require(ProvidesRenewal::class, '当前服务未接入可续费的供应商接口');
            }
        }

        return $this->providerResolver
            ->resolveForService($service)
            ->require(ProvidesRenewal::class, '当前服务未接入可续费的供应商接口');
    }

    private function sendUnsuspendNotificationIfNeeded(Service $service, int $previousStatus): void
    {
        $config = $this->settingService->getAutomationConfig();
        if (! $config['expire_unsuspend_notify_enabled']) {
            return;
        }

        if (! in_array($previousStatus, [ServiceStatus::SUSPENDED, ServiceStatus::EXPIRED], true) || (int) $service->status !== ServiceStatus::ACTIVE) {
            return;
        }

        $service->loadMissing('user:id,email,nickname');
        $email = trim((string) ($service->user?->email ?? ''));
        if ($email === '') {
            return;
        }

        try {
            $this->notificationService->sendTemplateEmail($email, NotificationService::TEMPLATE_SERVICE_RESTORED, [
                'display_name' => (string) ($service->user?->display_name ?? '客户'),
                'service_name' => (string) $service->name,
                'expires_at' => $service->expires_at?->format('Y-m-d H:i:s') ?? '-',
            ]);
        } catch (\Throwable $exception) {
            Log::warning('[服务续费] 恢复通知发送失败', [
                'service_id' => $service->id,
                'email' => $email,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
