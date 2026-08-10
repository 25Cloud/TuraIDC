<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\Order\Concerns\HandlesOrderCalculation;
use App\Services\ProductCatalog\ProductCatalogService;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\ProductCatalog\ProductFullPathResolver;
use App\Services\System\OperationLogService;
use App\Support\OrderInvoiceNoGenerator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 账单购买结算服务（Invoice-first Checkout）
 * 直接创建 Invoice，Order 仅作为内部开通投影，不对用户展示为交易主模型。
 */
class CheckoutService
{
    use HandlesOrderCalculation;

    // RANGE_TYPES / OS_TYPES / BILLING_CYCLE_MONTHS / TYPE_FIELD_MAP 已移入 HandlesOrderCalculation Trait

    public function __construct(
        private InvoiceService $invoiceService,
        private PaymentService $paymentService,
        private ProductCatalogService $productCatalogService,
        private CheckoutSecurityService $checkoutSecurityService,
        private CouponService $couponService,
        private OperationLogService $operationLogService,
        private AdminOrderNotificationService $adminOrderNotificationService,
        private ?ProductDisplayNameResolver $productDisplayNameResolver = null,
    ) {}

    /**
     * 创建新购账单
     */
    public function create(int $userId, array $data, array $context = []): Invoice
    {
        $productId = (int) ($data['product_id'] ?? 0);
        $billingCycle = trim((string) ($data['billing_cycle'] ?? ''));
        $quantity = max((int) ($data['quantity'] ?? 1), 1);
        $rawConfig = (array) ($data['config'] ?? []);
        $quoteToken = trim((string) ($data['quote_token'] ?? ''));
        $userCouponId = (int) ($data['user_coupon_id'] ?? 0);
        $idempotencyKey = trim((string) ($context['idempotency_key'] ?? ''));
        $startedAt = microtime(true);

        throw_if($productId <= 0, new BusinessException('商品信息错误'));
        throw_if($billingCycle === '', new BusinessException('计费周期不能为空'));
        throw_if($quantity !== 1, new BusinessException('当前暂不支持一次购买多个服务实例，请分次下单'));
        throw_if($quoteToken === '', new BusinessException('报价凭证已失效，请刷新配置后重试'));
        throw_if($idempotencyKey === '', new BusinessException('请求缺少幂等标识，请刷新页面后重试'));

        $lockKey = 'lock:invoice:create:'.$userId.':'.sha1($idempotencyKey);

        try {
            $invoice = Cache::lock($lockKey, 20)->block(5, function () use (
                $userId,
                $productId,
                $billingCycle,
                $quantity,
                $rawConfig,
                $quoteToken,
                $userCouponId,
                $idempotencyKey,
                $context
            ) {
                $idempotentInvoiceId = $this->checkoutSecurityService->resolveIdempotentInvoiceId($userId, $idempotencyKey);
                if ($idempotentInvoiceId) {
                    $existing = $this->freshCheckoutInvoice($idempotentInvoiceId);
                    if ($existing) {
                        return $existing;
                    }
                }

                return DB::transaction(function () use (
                    $userId,
                    $productId,
                    $billingCycle,
                    $quantity,
                    $rawConfig,
                    $quoteToken,
                    $userCouponId,
                    $idempotencyKey,
                    $context
                ) {
                    $product = Product::query()->lockForUpdate()->findOrFail($productId);
                    throw_if($product->status !== 1, new BusinessException('产品已下架'));
                    $product->loadMissing('supplier');
                    $this->productCatalogService->assertProductCanBeProvisioned($product, $quantity);
                    $this->assertPurchaseRequires($product, $userId);

                    $normalizedConfig = $this->normalizeConfig($product, $rawConfig);
                    $fingerprint = $this->checkoutSecurityService->buildCheckoutFingerprint(
                        $product->id, $billingCycle, $quantity, $normalizedConfig, $userCouponId
                    );

                    $recentInvoiceId = $this->checkoutSecurityService->resolveFingerprintInvoiceId($userId, $fingerprint);
                    if ($recentInvoiceId) {
                        $recent = $this->freshCheckoutInvoice($recentInvoiceId);
                        if ($recent) {
                            $this->checkoutSecurityService->rememberCreatedInvoice($userId, $idempotencyKey, $fingerprint, (int) $recent->id);

                            return $recent;
                        }
                    }

                    $quote = $this->quote($product, $billingCycle, $normalizedConfig, $quantity);
                    $amount = (float) ($quote['total_amount'] ?? 0);
                    $configPricingSnapshot = $this->buildConfigPricingSnapshot($product, $billingCycle, $normalizedConfig, $quantity);
                    $displayNamePayload = $this->resolveProductDisplayNameResolver()->resolveForProduct($product, $normalizedConfig);
                    $productDisplayName = $this->resolveCheckoutProductDisplayName($displayNamePayload);
                    $invoiceConfigSnapshot = $this->withProductDisplaySnapshot($product, $normalizedConfig, $productDisplayName);
                    throw_if($amount <= 0, new BusinessException('无效的计费周期'));

                    $couponPayload = $this->couponService->reserveOwnedCouponForInvoice(
                        $userCouponId, $userId, $product, $billingCycle, $amount, InvoiceType::NEW_PURCHASE
                    );
                    $discountAmount = (float) ($couponPayload['discount_amount'] ?? 0);
                    $payableAmount = max($amount - $discountAmount, 0);

                    $this->checkoutSecurityService->assertQuoteToken(
                        $quoteToken, $product->id, $billingCycle, $quantity, $normalizedConfig,
                        $this->formatAmount($amount), $this->formatAmount($payableAmount),
                        $couponPayload['user_coupon_id'] ?? $userCouponId
                    );

                    $invoice = Invoice::query()->create([
                        'invoice_no' => Invoice::generateInvoiceNo(),
                        'user_id' => $userId,
                        'product_id' => $product->id,
                        'product_spec_snapshot' => $productDisplayName,
                        'product_type_snapshot' => (string) $product->product_type,
                        'coupon_id' => $couponPayload['id'] ?? null,
                        'user_coupon_id' => $couponPayload['user_coupon_id'] ?? null,
                        'coupon_code' => $couponPayload['code'] ?? null,
                        'type' => InvoiceType::NEW_PURCHASE,
                        'amount' => $payableAmount,
                        'discount' => $discountAmount,
                        'billing_cycle' => $billingCycle,
                        'quantity' => $quantity,
                        'config_snapshot' => $invoiceConfigSnapshot,
                        'config_pricing_snapshot' => $configPricingSnapshot,
                        'coupon_snapshot' => $couponPayload,
                        'status' => InvoiceStatus::UNPAID,
                        'due_date' => now()->addDays(7),
                        'trace_id' => (string) ($context['trace_id'] ?? ''),
                    ]);
                    $this->invoiceService->syncProjection($invoice);

                    // 创建 shadow Order 记录：不对用户展示，仅供上游开通链路使用。
                    // Invoice 是用户侧唯一业务凭证；Order 是内部基础设施。
                    $this->createShadowOrderForInvoice(
                        $invoice,
                        $userId,
                        $product,
                        $billingCycle,
                        $quantity,
                        $invoiceConfigSnapshot,
                        $configPricingSnapshot,
                        $productDisplayName,
                        $amount,
                        $discountAmount,
                        $payableAmount,
                        $couponPayload
                    );

                    if ((int) $product->stock > 0) {
                        $product->decrement('stock', $quantity);
                    }

                    $this->checkoutSecurityService->rememberCreatedInvoice(
                        $userId, $idempotencyKey, $fingerprint, (int) $invoice->id
                    );

                    $this->operationLogService->write(
                        userId: $userId,
                        userType: 'client',
                        action: 'invoice.create',
                        module: 'invoice',
                        targetId: (int) $invoice->id,
                        detail: [
                            'invoice_no' => (string) $invoice->invoice_no,
                            'product_id' => (int) $product->id,
                            'product_name' => $productDisplayName,
                            'product_full_path' => (string) ($invoiceConfigSnapshot['product_full_path'] ?? ''),
                            'billing_cycle' => $billingCycle,
                            'quantity' => $quantity,
                            'amount' => $this->formatAmount($amount),
                            'discount' => $this->formatAmount($discountAmount),
                            'coupon_code' => (string) ($couponPayload['code'] ?? ''),
                            'quote_token_hash' => substr(hash('sha256', $quoteToken), 0, 16),
                            'idempotency_key_hash' => substr(hash('sha256', $idempotencyKey), 0, 16),
                            'trace_id' => (string) ($context['trace_id'] ?? ''),
                        ],
                        ipAddress: (string) ($context['ip_address'] ?? ''),
                    );

                    return $invoice->load('product');
                });
            });

            if ($invoice instanceof Invoice && $invoice->wasRecentlyCreated) {
                // 使用 invoice-only 通知通道
                $this->adminOrderNotificationService->notifyInvoicePaidAfterResponse($invoice);
            }

            $this->safeLog('info', '[购买链路] 下单耗时', [
                'result' => $invoice->wasRecentlyCreated ? 'created' : 'reused',
                'user_id' => $userId,
                'product_id' => $productId,
                'invoice_id' => (int) $invoice->id,
                'invoice_no' => (string) $invoice->invoice_no,
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
            ]);

            return $invoice;
        } catch (LockTimeoutException) {
            throw new BusinessException('订单正在处理中，请勿重复提交');
        }
    }

    /**
     * 取消账单（未支付的用户端取消）
     */
    public function cancel(Invoice $invoice, array $context = []): Invoice
    {
        $updatedInvoice = DB::transaction(function () use ($invoice, $context) {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            throw_if(
                ! in_array((int) $lockedInvoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true),
                new BusinessException('当前账单状态不支持取消')
            );

            $pendingPayments = Payment::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->where('status', PaymentStatus::PENDING)
                ->lockForUpdate()
                ->get();

            foreach ($pendingPayments as $pending) {
                if ($this->paymentService->restoreReservedMixBalance($pending, [
                    'trace_id' => (string) ($context['trace_id'] ?? ''),
                    'closed_reason' => 'invoice_cancelled',
                ])) {
                    continue;
                }

                $callbackRaw = (array) ($pending->callback_raw ?? []);
                $callbackRaw['closed_reason'] = 'invoice_cancelled';
                $callbackRaw['closed_by'] = (string) ($context['actor_type'] ?? 'system');
                $callbackRaw['trace_id'] = (string) ($context['trace_id'] ?? '');

                $pending->forceFill([
                    'status' => PaymentStatus::FAILED,
                    'callback_raw' => $callbackRaw,
                ])->save();
                $this->paymentService->syncProjection($pending);
            }

            $lockedInvoice->forceFill(['status' => InvoiceStatus::CANCELLED])->save();

            $linkedOrder = $lockedInvoice->order_id
                ? Order::query()->lockForUpdate()->find((int) $lockedInvoice->order_id)
                : null;
            if ($linkedOrder instanceof Order && (int) $linkedOrder->status === OrderStatus::PENDING) {
                $linkedOrder->forceFill(['status' => OrderStatus::CANCELLED])->save();
            }

            $this->couponService->releaseInvoiceCoupon($lockedInvoice);

            // 新购账单取消时恢复库存
            if (in_array((string) $lockedInvoice->type, [InvoiceType::NEW_PURCHASE, 'normal'], true) && $lockedInvoice->product_id) {
                $product = Product::query()->lockForUpdate()->find($lockedInvoice->product_id);
                if ($product instanceof Product && (int) $product->stock >= 0) {
                    $product->increment('stock', max((int) ($lockedInvoice->quantity ?? 1), 1));
                }
            }

            return $lockedInvoice->fresh(['user:id,email,nickname', 'product', 'service']) ?? $lockedInvoice;
        });

        $actorType = trim((string) ($context['actor_type'] ?? 'system')) ?: 'system';
        $actorUserId = isset($context['actor_user_id']) ? (int) ($context['actor_user_id'] ?? 0) : 0;
        if ($actorType === 'client' && $actorUserId <= 0) {
            $actorUserId = (int) $updatedInvoice->user_id;
        }

        $this->operationLogService->write(
            userId: $actorUserId > 0 ? $actorUserId : null,
            userType: $actorType,
            action: 'invoice.cancel',
            module: 'invoice',
            targetId: (int) $updatedInvoice->id,
            detail: [
                'invoice_no' => (string) $updatedInvoice->invoice_no,
                'invoice_type' => (string) $updatedInvoice->type,
                'product_id' => (int) ($updatedInvoice->product_id ?? 0),
                'actor_name' => (string) ($context['actor_name'] ?? ''),
                'reason' => (string) ($context['reason'] ?? ''),
                'trace_id' => (string) ($context['trace_id'] ?? ''),
            ],
            ipAddress: ($context['ip_address'] ?? null) ? (string) $context['ip_address'] : null,
        );

        return $updatedInvoice;
    }

    public function cancelExpiredUnpaidInvoice(Invoice $invoice, array $context = []): Invoice
    {
        $freshInvoice = $invoice->fresh() ?? $invoice;

        if (! in_array((int) $freshInvoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true)) {
            return $freshInvoice;
        }

        if (! $this->checkoutSecurityService->isPaymentSessionExpired($freshInvoice)) {
            return $freshInvoice;
        }

        return $this->cancel($freshInvoice, array_merge([
            'actor_type' => 'system',
            'actor_name' => 'payment-window-expired',
            'reason' => 'payment_window_expired',
        ], $context));
    }

    public function cancelExpiredUnpaidInvoicesForUser(int $userId, array $context = []): int
    {
        $threshold = now()->subSeconds(CheckoutSecurityService::paymentSessionTtlSeconds());
        $invoices = Invoice::query()
            ->where('user_id', $userId)
            ->whereIn('status', [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE])
            ->where('created_at', '<=', $threshold)
            ->get();

        $count = 0;

        foreach ($invoices as $invoice) {
            $updated = $this->cancelExpiredUnpaidInvoice($invoice, $context);
            if ((int) $updated->status === InvoiceStatus::CANCELLED) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 为账单创建影子 Order 记录：仅内部使用，不对用户展示。
     * 目的是让原本依赖 Order 的上游开通链路、优惠券统计、退款等逻辑继续工作。
     *
     * @param  array<string,mixed>  $couponPayload
     * @param  array<string,mixed>  $configSnapshot
     * @param  array<string,mixed>  $configPricingSnapshot
     */
    private function createShadowOrderForInvoice(
        Invoice $invoice,
        int $userId,
        Product $product,
        string $billingCycle,
        int $quantity,
        array $configSnapshot,
        array $configPricingSnapshot,
        string $productDisplayName,
        float $amount,
        float $discountAmount,
        float $payableAmount,
        ?array $couponPayload,
    ): Order {
        $orderNo = OrderInvoiceNoGenerator::deriveOrderNoFromInvoiceNo((string) $invoice->invoice_no)
            ?? Order::generateOrderNo();

        $order = Order::query()->create([
            'order_no' => $orderNo,
            'projection_type' => Order::PROJECTION_TYPE_PROVISIONING,
            'user_id' => $userId,
            'product_id' => $product->id,
            'product_spec_snapshot' => $productDisplayName,
            'product_type_snapshot' => (string) $product->product_type,
            'type' => OrderType::NEW,
            'coupon_id' => $couponPayload['id'] ?? null,
            'user_coupon_id' => $couponPayload['user_coupon_id'] ?? null,
            'coupon_code' => $couponPayload['code'] ?? null,
            'amount' => $payableAmount,
            'discount' => $discountAmount,
            'paid_amount' => 0,
            'billing_cycle' => $billingCycle,
            'quantity' => $quantity,
            'config_snapshot' => $configSnapshot,
            'config_pricing_snapshot' => $configPricingSnapshot,
            'coupon_snapshot' => $couponPayload,
            'status' => OrderStatus::PENDING,
        ]);

        // 双向绑定
        $invoice->forceFill(['order_id' => $order->id])->save();

        return $order;
    }

    /**
     * @param  array<string, mixed>  $displayNamePayload
     */
    private function resolveCheckoutProductDisplayName(array $displayNamePayload): string
    {
        foreach (['combined_display_name', 'product_spec_display', 'product_display_name'] as $key) {
            $value = trim((string) ($displayNamePayload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function assertPurchaseRequires(Product $product, int $userId): void
    {
        $requires = (array) ($product->purchase_requires ?? []);

        $user = User::find($userId);
        if (! $user) {
            return;
        }

        throw_if(
            ! $user->hasCompletedVerification(),
            new BusinessException('该商品需要实名认证后才能购买，请先完成实名认证', 40301)
        );

        if (! empty($requires['require_phone'])) {
            throw_if(
                empty(trim((string) ($user->phone ?? ''))),
                new BusinessException('该商品需要绑定手机号后才能购买，请先添加手机号', 40302)
            );
        }
    }

    private function freshCheckoutInvoice(int $invoiceId): ?Invoice
    {
        return Invoice::query()
            ->with(['product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires', 'service'])
            ->where('status', '!=', InvoiceStatus::CANCELLED)
            ->find($invoiceId);
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function resolveProductDisplayNameResolver(): ProductDisplayNameResolver
    {
        return $this->productDisplayNameResolver ??= new ProductDisplayNameResolver;
    }

    private function withProductDisplaySnapshot(Product $product, array $configSnapshot, string $productDisplayName): array
    {
        return array_merge(
            $configSnapshot,
            (new ProductFullPathResolver($this->resolveProductDisplayNameResolver()))
                ->snapshotForProduct($product, $productDisplayName, $configSnapshot)
        );
    }

    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable) {
        }
    }
}
