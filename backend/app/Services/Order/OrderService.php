<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CouponService;
use App\Services\Finance\PaymentService;
use App\Services\Order\Concerns\HandlesOrderCalculation;
use App\Services\System\NotificationService;
use App\Services\System\OperationLogService;
use App\Support\StockReservation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    use HandlesOrderCalculation;

    // RANGE_TYPES / OS_TYPES / BILLING_CYCLE_MONTHS / TYPE_FIELD_MAP 已移入 HandlesOrderCalculation Trait

    public function __construct(
        private PaymentService $paymentService,
        private CouponService $couponService,
        private CheckoutSecurityService $checkoutSecurityService,
        private OperationLogService $operationLogService,
        private NotificationService $notificationService,
    ) {}

    /**
     * 取消订单
     */
    public function cancel(Order $order, array $context = []): Order
    {
        $updatedOrder = DB::transaction(function () use ($order, $context) {
            // 与 CheckoutService::cancel 保持一致的锁顺序：先锁 Invoice，再锁 Order，
            // 避免同一笔订单/账单经双入口并发取消时发生行锁死锁。
            $invoice = Invoice::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->id);

            throw_if(
                (int) $lockedOrder->status !== OrderStatus::PENDING,
                new BusinessException('仅待付款订单可取消')
            );

            $lockedOrder->setRelation('invoice', $invoice);
            if ($invoice instanceof Invoice) {
                throw_if(
                    ! in_array((int) $invoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE, InvoiceStatus::CANCELLED], true),
                    new BusinessException('当前账单状态不支持取消订单')
                );

                $pendingPayments = Payment::query()
                    ->where('invoice_id', $invoice->id)
                    ->where('status', PaymentStatus::PENDING)
                    ->lockForUpdate()
                    ->get();

                foreach ($pendingPayments as $pendingPayment) {
                    // 组合支付（余额+网关）先行扣除了余额，取消时需把预扣余额退回。
                    if ($this->paymentService->restoreReservedMixBalance($pendingPayment, [
                        'trace_id' => (string) ($context['trace_id'] ?? ''),
                        'closed_reason' => 'order_cancelled',
                    ])) {
                        continue;
                    }

                    $callbackRaw = (array) ($pendingPayment->callback_raw ?? []);
                    $callbackRaw['closed_reason'] = 'order_cancelled';
                    $callbackRaw['closed_by'] = (string) ($context['actor_type'] ?? 'system');
                    $callbackRaw['trace_id'] = (string) ($context['trace_id'] ?? '');

                    $pendingPayment->forceFill([
                        'status' => PaymentStatus::FAILED,
                        'callback_raw' => $callbackRaw,
                    ])->save();
                    $this->paymentService->syncProjection($pendingPayment);
                }

                if ((int) $invoice->status !== InvoiceStatus::CANCELLED) {
                    $invoice->forceFill([
                        'status' => InvoiceStatus::CANCELLED,
                    ])->save();
                }
            }

            $lockedOrder->forceFill([
                'status' => OrderStatus::CANCELLED,
            ])->save();

            if ($invoice instanceof Invoice) {
                $this->couponService->syncInvoiceCouponUsage($invoice);
            }

            // 仅新购订单在创建时预扣库存，取消时按创建时实际预扣量对称恢复。
            if ((string) $lockedOrder->type === OrderType::NEW && $lockedOrder->product_id) {
                $product = Product::query()
                    ->lockForUpdate()
                    ->find($lockedOrder->product_id);

                if ($product instanceof Product) {
                    StockReservation::restore($product, $lockedOrder->config_snapshot, (int) ($lockedOrder->quantity ?? 1));
                }
            }

            return $lockedOrder->fresh(['user:id,email,nickname', 'product', 'invoice', 'service']) ?? $lockedOrder;
        });

        $actorType = trim((string) ($context['actor_type'] ?? 'system')) ?: 'system';
        $actorUserId = isset($context['actor_user_id']) ? (int) ($context['actor_user_id'] ?? 0) : 0;

        if ($actorType === 'client' && $actorUserId <= 0) {
            $actorUserId = (int) $updatedOrder->user_id;
        }

        $this->operationLogService->write(
            userId: $actorUserId > 0 ? $actorUserId : null,
            userType: $actorType,
            action: 'order.cancel',
            module: 'order',
            targetId: (int) $updatedOrder->id,
            detail: [
                'order_no' => (string) $updatedOrder->order_no,
                'order_type' => (string) $updatedOrder->type,
                'invoice_id' => (int) ($updatedOrder->invoice?->id ?? 0),
                'invoice_no' => (string) ($updatedOrder->invoice?->invoice_no ?? ''),
                'product_id' => (int) ($updatedOrder->product_id ?? 0),
                'product_name' => (string) $updatedOrder->display_product_name,
                'actor_name' => (string) ($context['actor_name'] ?? ''),
                'reason' => (string) ($context['reason'] ?? ''),
                'trace_id' => (string) ($context['trace_id'] ?? ''),
            ],
            ipAddress: ($context['ip_address'] ?? null) ? (string) $context['ip_address'] : null,
        );

        return $updatedOrder;
    }

    public function cancelExpiredPendingOrder(Order $order, array $context = []): Order
    {
        $freshOrder = $order->fresh(['invoice']) ?? $order;

        if ((int) $freshOrder->status !== OrderStatus::PENDING) {
            return $freshOrder;
        }

        if (! $this->checkoutSecurityService->isPaymentSessionExpired($freshOrder)) {
            return $freshOrder;
        }

        if (
            $freshOrder->invoice instanceof Invoice
            && ! in_array((int) $freshOrder->invoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE, InvoiceStatus::CANCELLED], true)
        ) {
            return $freshOrder;
        }

        return $this->cancel($freshOrder, array_merge([
            'actor_type' => 'system',
            'actor_name' => 'payment-window-expired',
            'reason' => 'payment_window_expired',
        ], $context));
    }

    public function cancelExpiredPendingOrdersForUser(?int $userId = null, array $context = []): int
    {
        $threshold = now()->subSeconds(CheckoutSecurityService::paymentSessionTtlSeconds());
        $query = Order::query()
            ->where('status', OrderStatus::PENDING)
            ->where('created_at', '<=', $threshold);

        if ($userId !== null && $userId > 0) {
            $query->where('user_id', $userId);
        }

        $count = 0;

        foreach ($query->get() as $order) {
            // 管理员手动开通产生的挂账订单按 due_date 计费，不受 5 分钟支付会话窗口约束；
            // 且这类订单从未扣过库存，被取消会触发 stock+1 造成库存虚增。
            if ($this->isAdminManualOrder($order)) {
                continue;
            }

            $updated = $this->cancelExpiredPendingOrder($order, $context);
            if ((int) $updated->status === OrderStatus::CANCELLED) {
                $count++;
            }
        }

        return $count;
    }

    private function isAdminManualOrder(Order $order): bool
    {
        $snapshot = $order->config_snapshot;
        if (! is_array($snapshot)) {
            return false;
        }

        return filter_var($snapshot['admin_manual'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * 查询订单列表 (管理端)
     */
    public function adminList(array $filters, int $perPage = 20)
    {
        $query = Order::with(['user:id,email,nickname', 'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires']);

        if (! empty($filters['order_no'])) {
            $query->where('order_no', $filters['order_no']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function adminDetail(int $id): Order
    {
        return $this->loadAdminOrder($id);
    }

    public function updateManualPaymentStatus(Order $order, array $payload, array $context = []): Order
    {
        $order->loadMissing(['invoice', 'product.supplier', 'service', 'user.referrer']);

        return match ((string) ($payload['action'] ?? '')) {
            'mark_paid' => $this->markPaidManually($order, $payload, $context),
            'mark_unpaid' => $this->markUnpaidManually($order, $payload, $context),
            'refund' => $this->refundByPaymentMethod($order, $payload, $context),
            default => throw new BusinessException('不支持的支付状态操作'),
        };
    }

    private function markPaidManually(Order $order, array $payload, array $context): Order
    {
        $invoice = $order->invoice;

        throw_if(! $invoice instanceof Invoice, new BusinessException('订单未关联账单，无法修改支付状态'));
        throw_if((int) $order->status !== OrderStatus::PENDING, new BusinessException('仅待付款订单支持手动设为已支付'));
        throw_if(
            ! in_array((int) $invoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true),
            new BusinessException('当前账单状态不支持手动设为已支付')
        );

        $paidAt = ! empty($payload['paid_at'])
            ? Carbon::parse((string) $payload['paid_at'])
            : now();
        $requestedAmount = round((float) ($payload['amount'] ?? $invoice->amount), 2);
        $payableAmount = round((float) $invoice->amount - (float) ($invoice->paid_amount ?? 0), 2);
        $paymentGateway = trim((string) ($payload['payment_gateway'] ?? 'manual')) ?: 'manual';
        $tradeNo = trim((string) ($payload['trade_no'] ?? ''));
        $sendEmail = (bool) ($payload['send_email'] ?? false);
        $remark = trim((string) ($payload['remark'] ?? ''));
        $syncBusinessFlow = (bool) ($payload['sync_business_flow'] ?? false);
        // paid_amount 记录账单累计实收额，$payableAmount 仅表示本次补款金额。
        $paidAmount = $invoice->amount;

        throw_if($payableAmount <= 0, new BusinessException('当前账单无需再入账'));
        throw_if(abs($requestedAmount - $payableAmount) > 0.00001, new BusinessException('当前仅支持按账单应付金额全额入账'));

        DB::transaction(function () use ($order, $invoice, $paidAt, $paidAmount, $payableAmount, $paymentGateway, $tradeNo, $remark, $context) {
            Payment::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', PaymentStatus::PENDING)
                ->update([
                    'status' => PaymentStatus::FAILED,
                ]);

            // 补一条 manual Payment 审计记录，保留 trade_no 与入账信息，
            // 供 markUnpaidManually 回退判定与财务对账追溯。
            // amount 记录本次实收（$payableAmount），累计口径只投影到 invoice/order.paid_amount，
            // 避免先期已入账 + 本次补款时按 Payment.amount 汇总虚增。
            $manualPayment = new Payment([
                'payment_no' => Payment::generatePaymentNo(),
                'user_id' => (int) $order->user_id,
                'order_id' => (int) $order->id,
                'invoice_id' => (int) $invoice->id,
                'gateway' => PaymentGatewayCode::MANUAL,
                'trade_no' => $tradeNo !== '' ? $tradeNo : null,
                'amount' => $payableAmount,
                'status' => PaymentStatus::SUCCESS,
                'paid_at' => $paidAt,
                'trace_id' => (string) ($context['trace_id'] ?? ''),
                'callback_raw' => [
                    'source' => 'admin_manual_entry',
                    'payment_gateway' => $paymentGateway,
                    'operator_id' => (int) ($context['operator_id'] ?? 0),
                    'operator_name' => (string) ($context['operator_name'] ?? ''),
                    'remark' => $remark,
                    'trade_no' => $tradeNo,
                ],
            ]);
            $manualPayment->allowNonThirdPartyGateway = true;
            $manualPayment->save();

            $invoice->forceFill([
                'status' => InvoiceStatus::PAID,
                'paid_amount' => $paidAmount,
                'paid_at' => $paidAt,
            ])->save();

            $order->forceFill([
                'status' => OrderStatus::PAID,
                'paid_amount' => $paidAmount,
                'paid_at' => $paidAt,
            ])->save();
        });

        if ($syncBusinessFlow) {
            $this->paymentService->handlePaidInvoice($invoice, $this->buildManualTraceId($order, $context, 'paid'));
        }

        $updatedOrder = $this->freshAdminOrder($order);

        if ($sendEmail) {
            $this->sendManualPaymentEmail($updatedOrder, $remark, $paymentGateway, $tradeNo !== '' ? $tradeNo : null);
        }

        $this->operationLogService->write(
            userId: ((int) ($context['operator_id'] ?? 0)) ?: null,
            userType: 'admin',
            action: 'order.payment.mark_paid',
            module: 'order',
            targetId: $updatedOrder->id,
            detail: [
                'order_no' => $updatedOrder->order_no,
                'invoice_id' => $invoice->id,
                'paid_amount' => $paidAmount,
                'paid_at' => $paidAt->format('Y-m-d H:i:s'),
                'payment_gateway' => $paymentGateway,
                'trade_no' => $tradeNo,
                'send_email' => $sendEmail,
                'sync_business_flow' => $syncBusinessFlow,
                'remark' => $remark,
                'operator_name' => (string) ($context['operator_name'] ?? ''),
            ],
            ipAddress: (string) ($context['ip_address'] ?? ''),
        );

        return $updatedOrder;
    }

    private function markUnpaidManually(Order $order, array $payload, array $context): Order
    {
        $invoice = $order->invoice;

        throw_if(! $invoice instanceof Invoice, new BusinessException('订单未关联账单，无法修改支付状态'));
        throw_if((int) $order->status !== OrderStatus::PAID, new BusinessException('仅已付款订单支持恢复为未支付'));
        throw_if((int) ($order->service_id ?? 0) > 0, new BusinessException('订单已进入服务流程，不支持恢复为未支付'));

        $hasRealSuccessPayment = Payment::query()
            ->where(function ($query) use ($order, $invoice) {
                $query->where('order_id', $order->id)
                    ->orWhere('invoice_id', $invoice->id);
            })
            ->where('status', PaymentStatus::SUCCESS)
            ->whereGatewayKeyIn(PaymentGatewayCode::thirdPartyGateways())
            ->exists();

        throw_if($hasRealSuccessPayment, new BusinessException('存在真实支付记录，不能直接恢复为未支付'));

        $manualSuccessPayments = Payment::query()
            ->where(function ($query) use ($order, $invoice) {
                $query->where('order_id', $order->id)
                    ->orWhere('invoice_id', $invoice->id);
            })
            ->whereGatewayKey(PaymentGatewayCode::MANUAL)
            ->where('status', PaymentStatus::SUCCESS);

        // 仅允许回退后台手动入账（markPaidManually 已补 manual Payment 记录）；
        // 删除旧的无 manual 记录时的宽泛兜底分支，避免误回退余额/免支付等真实入账。
        throw_if(
            ! $manualSuccessPayments->exists(),
            new BusinessException('仅支持回退后台手动设为已支付的订单')
        );

        $remark = trim((string) ($payload['remark'] ?? ''));

        DB::transaction(function () use ($order, $invoice, $manualSuccessPayments) {
            $payments = $manualSuccessPayments->get();
            $manualSuccessPayments->update([
                'status' => PaymentStatus::FAILED,
                'paid_at' => null,
            ]);
            foreach ($payments as $payment) {
                $payment->refresh();
                $this->paymentService->syncProjection($payment);
            }

            $invoice->forceFill([
                'status' => InvoiceStatus::UNPAID,
                'paid_amount' => 0,
                'paid_at' => null,
            ])->save();

            $order->forceFill([
                'status' => OrderStatus::PENDING,
                'paid_amount' => 0,
                'paid_at' => null,
            ])->save();
        });

        $this->couponService->syncInvoiceCouponUsage($invoice->fresh() ?? $invoice);

        $updatedOrder = $this->freshAdminOrder($order);

        $this->operationLogService->write(
            userId: ((int) ($context['operator_id'] ?? 0)) ?: null,
            userType: 'admin',
            action: 'order.payment.mark_unpaid',
            module: 'order',
            targetId: $updatedOrder->id,
            detail: [
                'order_no' => $updatedOrder->order_no,
                'invoice_id' => $invoice->id,
                'remark' => $remark,
                'operator_name' => (string) ($context['operator_name'] ?? ''),
            ],
            ipAddress: (string) ($context['ip_address'] ?? ''),
        );

        return $updatedOrder;
    }

    private function refundByPaymentMethod(Order $order, array $payload, array $context): Order
    {
        $result = $this->paymentService->refundOrder($order, $payload, $context);
        $updatedOrder = $this->freshAdminOrder($order);

        if (($result['already_refunded'] ?? false) !== true) {
            $refund = (array) ($result['refund'] ?? []);

            $this->operationLogService->write(
                userId: ((int) ($context['operator_id'] ?? 0)) ?: null,
                userType: 'admin',
                action: 'order.payment.refund',
                module: 'order',
                targetId: $updatedOrder->id,
                detail: [
                    'order_no' => $updatedOrder->order_no,
                    'invoice_id' => (int) ($updatedOrder->invoice?->id ?? 0),
                    'payment_id' => (int) ($result['payment_id'] ?? 0),
                    'refund_method' => (string) ($refund['refund_method'] ?? ''),
                    'refund_method_label' => (string) ($refund['refund_method_label'] ?? ''),
                    'refund_amount' => (string) ($refund['refund_amount'] ?? ''),
                    'refund_reason' => (string) ($refund['refund_reason'] ?? ''),
                    'out_request_no' => (string) ($refund['out_request_no'] ?? ''),
                    'trade_no' => (string) ($refund['trade_no'] ?? ''),
                    'operator_name' => (string) ($context['operator_name'] ?? ''),
                ],
                ipAddress: (string) ($context['ip_address'] ?? ''),
            );
        }

        return $updatedOrder;
    }

    private function freshAdminOrder(Order $order): Order
    {
        return $this->loadAdminOrder((int) $order->id);
    }

    private function buildManualTraceId(Order $order, array $context, string $suffix): string
    {
        $traceId = trim((string) ($context['trace_id'] ?? ''));

        if ($traceId !== '') {
            return "manual:{$suffix}:{$traceId}";
        }

        return "manual:{$suffix}:order:{$order->id}";
    }

    private function buildAutoPaidTraceId(Order $order, array $context): string
    {
        $traceId = trim((string) ($context['trace_id'] ?? ''));

        if ($traceId !== '') {
            return "free:{$traceId}";
        }

        return "free:order:{$order->id}";
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable) {
        }
    }

    private function sendManualPaymentEmail(Order $order, string $remark, string $paymentGateway, ?string $tradeNo = null): void
    {
        $order->loadMissing(['user', 'invoice']);

        $email = trim((string) ($order->user?->email ?? ''));
        $invoiceNo = trim((string) ($order->invoice?->invoice_no ?? ''));

        if ($email === '' || $invoiceNo === '') {
            return;
        }

        try {
            $this->notificationService->sendTemplateEmail($email, NotificationService::TEMPLATE_MANUAL_PAYMENT_CONFIRM, [
                'invoice_no' => $invoiceNo,
                'order_no' => (string) $order->order_no,
                'paid_amount' => number_format((float) ($order->paid_amount ?? 0), 2, '.', ''),
                'payment_method' => $this->resolvePaymentGatewayLabel($paymentGateway),
                'paid_at' => $order->paid_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                'trade_no' => $tradeNo ?? '',
                'remark' => $remark,
            ]);
        } catch (\Throwable $exception) {
            $this->safeLog('warning', '[订单手动入账] 支付确认邮件发送失败', [
                'order_id' => $order->id,
                'invoice_id' => $order->invoice?->id,
                'email' => $email,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function resolvePaymentGatewayLabel(string $gateway): string
    {
        return match ($gateway) {
            'alipay' => '支付宝支付',
            'yipay' => '易支付',
            'wechat' => '微信支付',
            'balance' => '余额支付',
            'free' => '免支付',
            'bank_transfer' => '银行转账',
            'offline' => '线下支付',
            default => '手动入账',
        };
    }

    private function loadAdminOrder(int $orderId): Order
    {
        $order = Order::query()
            ->with([
                'user:id,email,nickname',
                'product',
                'payments' => fn ($query) => $query->orderByDesc('id'),
                'invoice.payments' => fn ($query) => $query->orderByDesc('id'),
                'service',
            ])
            ->findOrFail($orderId);

        $order->setAttribute('payment_summary', $this->buildPaymentSummary($order));
        $order->invoice?->unsetRelation('payments');

        return $order;
    }

    private function buildPaymentSummary(Order $order): ?array
    {
        $payments = $order->relationLoaded('payments')
            ? $order->payments
            : collect();

        if ($payments->isEmpty() && $order->invoice?->relationLoaded('payments')) {
            $payments = $order->invoice->payments;
        }

        $payments = collect($payments)
            ->filter(fn (Payment $payment) => $payment->isThirdPartyGateway())
            ->values();

        $payment = $payments
            ->first(fn (Payment $item) => ! (bool) data_get((array) ($item->callback_raw ?? []), 'duplicate_paid', false)
                && in_array((int) $item->status, [PaymentStatus::SUCCESS, PaymentStatus::REFUNDED], true))
            ?? $payments->first(fn (Payment $item) => in_array((int) $item->status, [PaymentStatus::SUCCESS, PaymentStatus::REFUNDED], true));

        if (! $payment instanceof Payment) {
            return null;
        }

        $refund = (array) data_get((array) ($payment->callback_raw ?? []), 'refund', []);

        return [
            'id' => (int) $payment->id,
            'payment_no' => (string) $payment->payment_no,
            'trade_no' => (string) ($payment->trade_no ?? ''),
            'gateway' => $payment->gatewayKey(),
            'gateway_key' => $payment->gatewayKey(),
            'gateway_label' => $this->resolvePaymentGatewayLabel($payment->gatewayKey()),
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'status' => (int) $payment->status,
            'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'refund_method' => (string) ($refund['refund_method'] ?? ''),
            'refund_method_label' => (string) ($refund['refund_method_label'] ?? ''),
            'refund_amount' => (string) ($refund['refund_amount'] ?? ''),
            'refund_reason' => (string) ($refund['refund_reason'] ?? ''),
            'refunded_at' => (string) ($refund['refunded_at'] ?? ($refund['gmt_refund_pay'] ?? '')),
            'out_request_no' => (string) ($refund['out_request_no'] ?? ''),
        ];
    }
}
