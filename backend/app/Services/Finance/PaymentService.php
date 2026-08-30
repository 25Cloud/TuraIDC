<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Constants\FinanceLedgerEventType;
use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Constants\OrderStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Constants\UserNotificationType;
use App\Contracts\Integrations\Payments\PaymentGatewayInterface;
use App\Exceptions\BusinessException;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceTrafficPackageService;
use App\Services\ClientServiceConsole\ServiceUpgradeService;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use App\Services\Integrations\Payments\PaymentGatewayOperationService;
use App\Services\Integrations\Plugins\PaymentGatewayBindingResolver;
use App\Services\Notification\UserNotificationService;
use App\Services\Order\PaidOrderBusinessFlowDispatcher;
use App\Services\Provisioning\ProvisionService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\Referral\ReferralService;
use App\Services\User\AccountService;
use App\Support\DatabaseSchema;
use App\Support\VersionedJson;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PaymentService
{
    private ?PaymentGatewayOperationService $gatewayOperations = null;

    public function __construct(
        private ProvisionService $provisionService,
        private PaymentGatewayManager $paymentGatewayManager,
        private ServiceRenewService $serviceRenewService,
        private ReferralService $referralService,
        private PaidOrderBusinessFlowDispatcher $paidOrderBusinessFlowDispatcher,
        private AdminOrderNotificationService $adminOrderNotificationService,
        private CouponService $couponService,
        private InvoiceService $invoiceService,
        private ?AccountService $accountService = null,
        private ?PaymentGatewayBindingResolver $paymentGatewayBindingResolver = null,
        private ?FinanceDocumentService $financeDocumentService = null,
    ) {}

    /**
     * 余额支付（不产生 Payment 记录）
     */
    public function payByBalance(Invoice $invoice, User $user, array $context = []): Invoice
    {
        $traceId = trim((string) ($context['trace_id'] ?? ''));
        $lockKey = "lock:pay:invoice:{$invoice->id}";

        $paidInvoice = $this->withLock($lockKey, 30, function () use ($invoice, $user, $traceId, $context) {
            return DB::transaction(function () use ($invoice, $user, $traceId, $context) {
                $lockedInvoice = Invoice::query()
                    ->lockForUpdate()
                    ->with('order')
                    ->findOrFail($invoice->id);
                $lockedUser = User::query()
                    ->lockForUpdate()
                    ->findOrFail($user->id);

                throw_if(
                    ! in_array((int) $lockedInvoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true),
                    new BusinessException('账单状态异常，无法支付')
                );

                $amount = round((float) $lockedInvoice->amount - (float) ($lockedInvoice->paid_amount ?? 0), 2);
                $currentBalance = $this->getUserBalance($lockedUser);
                throw_if($amount <= 0, new BusinessException('当前账单无需支付'));
                throw_if($currentBalance < $amount, new BusinessException('余额不足'));

                $balanceAfter = $this->setUserBalance($lockedUser, $currentBalance - $amount);
                $this->createBalanceLog(
                    (int) $lockedUser->id,
                    FinanceLedgerEventType::INVOICE_PAYMENT,
                    -$amount,
                    $balanceAfter,
                    (int) $lockedInvoice->id,
                    '支付账单 '.(string) $lockedInvoice->invoice_no,
                    [
                        'operator' => trim((string) ($context['operator'] ?? $context['operator_name'] ?? '')),
                        'trace_id' => $traceId,
                    ]
                );

                $lockedInvoice->forceFill([
                    'status' => InvoiceStatus::PAID,
                    'paid_amount' => $lockedInvoice->amount,
                    'paid_at' => now(),
                    'trace_id' => $traceId !== '' ? $traceId : $lockedInvoice->trace_id,
                ])->save();

                $lockedInvoice->order?->forceFill([
                    'status' => OrderStatus::PAID,
                    // 累计实付：混合支付（第三方网关先行支付）时账单已含此前已付部分，
                    // 这里记全额而非本次余额支付额，避免推荐奖励等按 order.paid_amount 计发时少算。
                    'paid_amount' => $lockedInvoice->amount,
                    'paid_at' => now(),
                ])->save();

                // 账单已付：预扣余额已被账单消费，不可再退。
                $this->closeOtherPendingPayments($lockedInvoice, 0, 'invoice_paid_by_balance', false);

                return $lockedInvoice;
            });
        }, '支付请求处理中，请勿重复提交');

        $this->handlePaidInvoice($invoice, $traceId !== '' ? 'balance:'.$traceId : 'balance:'.$invoice->id);

        return $paidInvoice->fresh() ?? $paidInvoice;
    }

    public function payOrderByBalance(Order $order, User $user, array $context = []): Invoice
    {
        $traceId = trim((string) ($context['trace_id'] ?? ''));
        $lockKey = "lock:pay:order:{$order->id}";

        $paidInvoice = $this->withLock($lockKey, 30, function () use ($order, $user, $traceId, $context) {
            return DB::transaction(function () use ($order, $user, $traceId, $context) {
                $lockedOrder = Order::query()
                    ->lockForUpdate()
                    ->with('invoice')
                    ->findOrFail($order->id);
                $lockedUser = User::query()
                    ->lockForUpdate()
                    ->findOrFail($user->id);

                throw_if(
                    (int) $lockedOrder->status !== OrderStatus::PENDING,
                    new BusinessException('当前订单状态不支持支付')
                );

                $amount = $this->resolveOrderPayableAmount($lockedOrder);
                throw_if(
                    $amount <= 0 && $this->isAutoRenewBalancePayment($lockedOrder, $context),
                    new BusinessException('自动续费金额异常，已拦截本次续费')
                );

                if ($amount > 0) {
                    $currentBalance = $this->getUserBalance($lockedUser);
                    throw_if($currentBalance < $amount, new BusinessException('余额不足'));

                    $balanceAfter = $this->setUserBalance($lockedUser, $currentBalance - $amount);
                    $this->createBalanceLog(
                        (int) $lockedUser->id,
                        FinanceLedgerEventType::INVOICE_PAYMENT,
                        -$amount,
                        $balanceAfter,
                        (int) $lockedOrder->id,
                        '支付订单 '.(string) $lockedOrder->order_no,
                        [
                            'trace_id' => $traceId,
                        ]
                    );
                }

                $lockedOrder->forceFill([
                    'status' => OrderStatus::PAID,
                    'paid_amount' => $amount,
                    'paid_at' => now(),
                ])->save();

                if ($lockedOrder->invoice instanceof Invoice) {
                    $configSnapshot = is_array($lockedOrder->invoice->config_snapshot ?? null)
                        ? $lockedOrder->invoice->config_snapshot : [];
                    $configSnapshot['fulfillment_pending'] = true;
                    $configSnapshot['fulfillment_type'] = (string) $lockedOrder->type;

                    $lockedOrder->invoice->forceFill([
                        'status' => InvoiceStatus::PAID,
                        'paid_amount' => $lockedOrder->invoice->amount,
                        'paid_at' => now(),
                        'trace_id' => $traceId !== '' ? $traceId : $lockedOrder->invoice->trace_id,
                        'config_snapshot' => $configSnapshot,
                    ])->save();
                }

                $this->closeOtherPendingPaymentsForOrder($lockedOrder, 0, 'order_paid_by_balance');

                return $lockedOrder->invoice;
            });
        }, '支付请求处理中，请勿重复提交');

        $order = $order->fresh(['invoice']) ?? $order;
        if ($order->invoice instanceof Invoice) {
            $this->handlePaidInvoice($order->invoice, $traceId !== '' ? 'balance:'.$traceId : 'balance:'.$order->id);
        }

        return $paidInvoice?->fresh() ?? $paidInvoice ?? $order->invoice;
    }

    /**
     * 资金调整（管理员手动，正数增加、负数扣减）
     */
    public function adjustBalance(User $user, float $amount, string $remark = '管理员手动调整', array $context = []): array
    {
        throw_if($amount == 0, new BusinessException('调整金额不能为 0'));

        return DB::transaction(function () use ($user, $amount, $remark, $context): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $currentBalance = $this->getUserBalance($lockedUser);
            $newBalance = $currentBalance + $amount;

            throw_if($newBalance < 0, new BusinessException(
                '扣减后余额不足，当前余额 ¥'.number_format($currentBalance, 2).'，扣减 ¥'.number_format(abs($amount), 2)
            ));

            $balanceAfter = $this->setUserBalance($lockedUser, $newBalance);

            $eventType = $amount > 0
                ? FinanceLedgerEventType::MANUAL_RECHARGE
                : FinanceLedgerEventType::MANUAL_DEDUCTION;
            $transaction = $this->createBalanceLog(
                (int) $lockedUser->id,
                $eventType,
                $amount,
                $balanceAfter,
                (int) $lockedUser->id,
                $remark,
                [
                    'operator' => trim((string) ($context['operator_name'] ?? '')),
                    'trace_id' => trim((string) ($context['trace_id'] ?? '')),
                ]
            );

            $operatorName = trim((string) ($context['operator_name'] ?? $context['operator'] ?? ''));
            $invoice = $amount > 0
                ? $this->invoiceService->createForRecharge($lockedUser, abs($amount), null, $remark, trim((string) ($context['trace_id'] ?? '')))
                : $this->invoiceService->createForDeduction($lockedUser, abs($amount), $remark, trim((string) ($context['trace_id'] ?? '')));

            $invoice->forceFill([
                'remark' => $remark,
                'operator' => $operatorName !== '' ? $operatorName : null,
            ])->save();

            $rechargeRecord = null;
            if ($amount > 0) {
                $rechargeRecord = $this->financeDocuments()->recordRecharge(
                    $invoice,
                    null,
                    $transaction,
                    'admin_recharge',
                    [
                        'record_remark' => '管理员手工充值',
                        'operator_type' => (string) ($context['operator_type'] ?? 'admin'),
                        'operator_id' => $context['operator_id'] ?? null,
                        'operator_name' => $operatorName,
                        'trace_id' => (string) ($context['trace_id'] ?? ''),
                    ],
                );
            }

            return [
                'invoice' => $invoice->fresh(),
                'transaction' => $transaction,
                'recharge_record' => $rechargeRecord,
            ];
        });
    }

    /**
     * 充值（管理员手动）—— 兼容旧调用
     */
    public function recharge(User $user, float $amount, string $remark = '手动充值'): void
    {
        $this->adjustBalance($user, abs($amount), $remark);
    }

    /**
     * 支付宝充值 — 预下单
     */
    public function rechargeByAlipay(User $user, float $amount, array $context = []): array
    {
        $traceId = $this->resolveTraceId($context, 'recharge:user:'.$user->id.':'.now()->format('YmdHis'));
        $this->assertVerifiedUser($user);

        throw_if(
            ! $this->alipayGateway()->isEnabled(),
            new BusinessException('支付宝支付未启用')
        );
        throw_if($amount < 1, new BusinessException('充值金额不能小于 1 元'));
        throw_if($amount > 50000, new BusinessException('单笔充值不能超过 50000 元'));

        $normalizedAmount = round($amount, 2);
        $lockKey = 'lock:recharge:create:'.$user->id.':'.md5(number_format($normalizedAmount, 2, '.', ''));

        $payment = $this->withLock($lockKey, 20, function () use ($user, $normalizedAmount, $traceId) {
            return DB::transaction(function () use ($user, $normalizedAmount, $traceId) {
                $payment = Payment::query()
                    ->where('user_id', $user->id)
                    ->whereNull('invoice_id')
                    ->whereGatewayKey(PaymentGatewayCode::ALIPAY)
                    ->where('status', PaymentStatus::PENDING)
                    ->where('amount', $normalizedAmount)
                    ->where('created_at', '>=', now()->subSeconds(CheckoutSecurityService::paymentSessionTtlSeconds()))
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($payment) {
                    $this->ensurePaymentGatewayAudit($payment, PaymentGatewayCode::ALIPAY, $traceId);

                    return $payment;
                }

                return Payment::query()->create($this->paymentCreatePayload([
                    'payment_no' => Payment::generatePaymentNo(),
                    'user_id' => $user->id,
                    'invoice_id' => null,
                    'gateway' => PaymentGatewayCode::ALIPAY,
                    'amount' => $normalizedAmount,
                    'status' => PaymentStatus::PENDING,
                    'trace_id' => $traceId,
                ]));
            });
        }, '充值请求处理中，请勿重复提交');

        $subject = config('app.name', 'IDC').' - 账户充值 ¥'.number_format($normalizedAmount, 2, '.', '');
        $result = $this->precreateAlipayPayment(
            $payment->payment_no,
            $normalizedAmount,
            $subject,
            $this->resolvePaymentTimeoutExpress($payment)
        );

        $payment->forceFill([
            'callback_raw' => array_merge((array) ($payment->callback_raw ?? []), [
                'source' => 'alipay_recharge_precreate',
                'trace_id' => $traceId,
            ]),
        ])->save();
        $this->syncProjection($payment);

        return [
            'payment_no' => $payment->payment_no,
            'qr_code' => $result['qr_code'],
            'amount' => number_format($normalizedAmount, 2, '.', ''),
        ];
    }

    /**
     * 第三方网关充值（通用入口）
     */
    public function rechargeByGateway(User $user, float $amount, string $gateway, array $context = []): array
    {
        $traceId = $this->resolveTraceId($context, "{$gateway}:user:{$user->id}:".now()->format('YmdHis'));
        $this->assertVerifiedUser($user);

        $resolvedGateway = $this->resolveGateway($gateway);

        throw_if(! $resolvedGateway->isEnabled(), new BusinessException(
            PaymentGatewayCode::label($gateway).'支付未启用'
        ));
        throw_if($amount < 1, new BusinessException('充值金额不能小于 1 元'));
        throw_if($amount > 50000, new BusinessException('单笔充值不能超过 50000 元'));

        $normalizedAmount = round($amount, 2);
        $gatewayContext = $this->rechargeGatewayContext($context);
        $lockKey = "lock:recharge:create:{$user->id}:".md5(implode('|', [
            $gateway,
            $this->rechargeGatewayContextKey($gatewayContext),
            number_format($normalizedAmount, 2, '.', ''),
        ]));

        $payment = $this->withLock($lockKey, 20, function () use ($user, $normalizedAmount, $traceId, $gateway, $gatewayContext) {
            return DB::transaction(function () use ($user, $normalizedAmount, $traceId, $gateway, $gatewayContext) {
                $payment = Payment::query()
                    ->where('user_id', $user->id)
                    ->whereNull('invoice_id')
                    ->whereGatewayKey($gateway)
                    ->where('status', PaymentStatus::PENDING)
                    ->where('amount', $normalizedAmount)
                    ->where('created_at', '>=', now()->subSeconds(CheckoutSecurityService::paymentSessionTtlSeconds()))
                    ->lockForUpdate()
                    ->latest('id')
                    ->get()
                    ->first(fn (Payment $payment): bool => $this->rechargePaymentMatchesGatewayContext($payment, $gatewayContext));

                if ($payment) {
                    $this->ensurePaymentGatewayAudit($payment, $gateway, $traceId);

                    return $payment;
                }

                return Payment::query()->create($this->paymentCreatePayload([
                    'payment_no' => Payment::generatePaymentNo(),
                    'user_id' => $user->id,
                    'invoice_id' => null,
                    'gateway' => $gateway,
                    'amount' => $normalizedAmount,
                    'status' => PaymentStatus::PENDING,
                    'callback_raw' => $this->rechargeGatewayCallbackRaw($gatewayContext),
                    'trace_id' => $traceId,
                ]));
            });
        }, '充值请求处理中，请勿重复提交');

        $subject = config('app.name', 'IDC').' - 账户充值 ¥'.number_format($normalizedAmount, 2, '.', '');
        $result = $this->precreateGatewayPayment(
            $gateway,
            $payment->payment_no,
            $normalizedAmount,
            $subject,
            $this->resolvePaymentTimeoutExpress($payment),
            $context
        );

        $payment->forceFill([
            'callback_raw' => array_merge((array) ($payment->callback_raw ?? []), [
                'source' => "{$gateway}_recharge_precreate",
                'trace_id' => $traceId,
            ], $this->rechargeGatewayCallbackRaw($gatewayContext)),
        ])->save();
        $this->syncProjection($payment);

        return [
            'payment_no' => $payment->payment_no,
            'qr_code' => $result['qr_code'],
            'amount' => number_format($normalizedAmount, 2, '.', ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function rechargeGatewayContext(array $context): array
    {
        $paymentType = trim((string) ($context['payment_type'] ?? ''));

        return $paymentType !== '' ? ['payment_type' => $paymentType] : [];
    }

    /**
     * @param  array<string, string>  $gatewayContext
     */
    private function rechargeGatewayContextKey(array $gatewayContext): string
    {
        if ($gatewayContext === []) {
            return 'default';
        }

        ksort($gatewayContext, SORT_STRING);

        return md5(json_encode($gatewayContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    /**
     * @param  array<string, string>  $gatewayContext
     */
    private function rechargePaymentMatchesGatewayContext(Payment $payment, array $gatewayContext): bool
    {
        $callbackRaw = (array) ($payment->callback_raw ?? []);
        $storedContext = $callbackRaw['payment_context'] ?? null;
        $storedContext = is_array($storedContext) ? $storedContext : $callbackRaw;

        return $this->rechargeGatewayContext($storedContext) === $gatewayContext;
    }

    /**
     * @param  array<string, string>  $gatewayContext
     * @return array<string, mixed>
     */
    private function rechargeGatewayCallbackRaw(array $gatewayContext): array
    {
        if ($gatewayContext === []) {
            return [];
        }

        return array_merge($gatewayContext, [
            'payment_context' => $gatewayContext,
        ]);
    }

    private function assertVerifiedUser(User $user): void
    {
        throw_if(
            ! $user->hasCompletedVerification(),
            new BusinessException('请先完成实名认证后再继续操作', 40301)
        );
    }

    /**
     * 轮询充值支付状态
     */
    public function queryRechargeStatus(Payment $payment): array
    {
        if ((int) $payment->status === PaymentStatus::SUCCESS) {
            $this->ensureRechargeInvoiceProjection($payment);

            return ['paid' => true, 'trade_no' => $payment->trade_no];
        }

        $gateway = $payment->gatewayKey();
        $result = $this->queryGatewayPayment($gateway, $payment->payment_no);

        if (in_array($result['trade_status'], ['TRADE_SUCCESS', 'TRADE_FINISHED', 'SUCCESS'], true)) {
            $this->completeRechargePayment($payment, $result['trade_no'], $result['raw']);

            return ['paid' => true, 'trade_no' => $result['trade_no']];
        }

        $payment = $this->cancelExpiredPendingRecharge($payment, [
            'reason' => 'payment_window_expired',
            'actor_name' => 'recharge-status-poll',
        ]);

        return [
            'paid' => false,
            'trade_status' => $result['trade_status'],
            'status' => (int) $payment->status,
            'status_label' => PaymentStatus::$labels[(int) $payment->status] ?? '未知',
        ];
    }

    public function cancelExpiredPendingRecharge(Payment $payment, array $context = []): Payment
    {
        return DB::transaction(function () use ($payment, $context): Payment {
            $lockedPayment = Payment::query()
                ->lockForUpdate()
                ->findOrFail((int) $payment->id);

            if ((int) ($lockedPayment->invoice_id ?? 0) > 0 || (int) $lockedPayment->status !== PaymentStatus::PENDING) {
                return $lockedPayment;
            }

            if (! $this->isPaymentWindowExpired($lockedPayment)) {
                return $lockedPayment;
            }

            $callbackRaw = (array) ($lockedPayment->callback_raw ?? []);
            $callbackRaw['closed_reason'] = (string) ($context['reason'] ?? 'payment_window_expired');
            $callbackRaw['closed_by'] = (string) ($context['actor_type'] ?? 'system');
            $callbackRaw['closed_at'] = now()->toDateTimeString();
            $callbackRaw['trace_id'] = (string) ($context['trace_id'] ?? ($lockedPayment->trace_id ?? ''));
            $callbackRaw['payment_window_expired'] = true;

            $lockedPayment->forceFill([
                'status' => PaymentStatus::CANCELLED,
                'callback_raw' => $callbackRaw,
            ])->save();
            $this->syncProjection($lockedPayment);

            return $lockedPayment;
        });
    }

    public function cancelExpiredPendingRechargesForUser(?int $userId = null, array $context = []): int
    {
        $threshold = now()->subSeconds(CheckoutSecurityService::paymentSessionTtlSeconds());
        $query = Payment::query()
            ->whereNull('invoice_id')
            ->where('status', PaymentStatus::PENDING)
            ->where('created_at', '<=', $threshold);

        if ($userId !== null && $userId > 0) {
            $query->where('user_id', $userId);
        }

        $count = 0;

        foreach ($query->get() as $payment) {
            $updated = $this->cancelExpiredPendingRecharge($payment, $context);
            if ((int) $updated->status === PaymentStatus::CANCELLED) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 完成充值到账
     */
    private function completeRechargePayment(Payment $payment, string $tradeNo, array $raw = []): void
    {
        $lockKey = "lock:recharge:payment:{$payment->id}";

        Cache::lock($lockKey, 30)->block(5, function () use ($payment, $tradeNo, $raw) {
            DB::transaction(function () use ($payment, $tradeNo, $raw) {
                $lockedPayment = Payment::query()
                    ->lockForUpdate()
                    ->findOrFail($payment->id);

                if ((int) $lockedPayment->status === PaymentStatus::SUCCESS) {
                    if (! $lockedPayment->invoice_id) {
                        $user = User::query()->lockForUpdate()->findOrFail($lockedPayment->user_id);
                        $this->invoiceService->createForRecharge($user, (float) $lockedPayment->amount, $lockedPayment, null, (string) ($lockedPayment->trace_id ?? ''));
                    }

                    return;
                }

                $traceId = $this->resolveTraceId(
                    ['trace_id' => $lockedPayment->trace_id],
                    'recharge:payment:'.$lockedPayment->id
                );
                $callbackRaw = $raw;
                $callbackRaw['trace_id'] = $traceId;

                $this->recordPaymentCallback($lockedPayment, 'payment', $callbackRaw, true, $tradeNo, $traceId);

                $lockedPayment->forceFill([
                    'trade_no' => $tradeNo,
                    'status' => PaymentStatus::SUCCESS,
                    'callback_raw' => $callbackRaw,
                    'paid_at' => now(),
                    'trace_id' => $traceId,
                ])->save();
                $this->syncProjection($lockedPayment);

                $user = User::query()->lockForUpdate()->findOrFail($lockedPayment->user_id);
                $balanceAfter = $this->setUserBalance($user, $this->getUserBalance($user) + (float) $lockedPayment->amount);

                $transaction = $this->createBalanceLog(
                    (int) $user->id,
                    FinanceLedgerEventType::RECHARGE,
                    (float) $lockedPayment->amount,
                    $balanceAfter,
                    (int) $lockedPayment->id,
                    '支付宝充值 '.(string) $lockedPayment->payment_no,
                    [
                        'trace_id' => (string) ($lockedPayment->trace_id ?? ''),
                    ]
                );

                $invoice = $this->invoiceService->createForRecharge(
                    $user,
                    (float) $lockedPayment->amount,
                    $lockedPayment,
                    null,
                    (string) ($lockedPayment->trace_id ?? ''),
                );
                $this->financeDocuments()->recordRecharge(
                    $invoice,
                    $lockedPayment,
                    $transaction,
                    'user_recharge',
                    [
                        'trace_id' => (string) ($lockedPayment->trace_id ?? ''),
                    ],
                );
            });
        });
    }

    /**
     * 支付宝当面付 — 预下单，返回二维码
     */
    public function payByAlipay(Invoice $invoice, User $user, array $context = []): array
    {
        throw_if(
            ! $this->alipayGateway()->isEnabled(),
            new BusinessException('支付宝支付未启用')
        );

        $traceId = $this->resolveTraceId($context, 'alipay:invoice:'.$invoice->id);
        $lockKey = "lock:pay:alipay:invoice:{$invoice->id}";

        $payload = $this->withLock($lockKey, 20, function () use ($invoice, $user, $traceId) {
            return DB::transaction(function () use ($invoice, $user, $traceId) {
                $lockedInvoice = Invoice::query()
                    ->lockForUpdate()
                    ->with('order')
                    ->findOrFail($invoice->id);

                throw_if(
                    ! in_array((int) $lockedInvoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true),
                    new BusinessException('账单状态异常，无法支付')
                );

                $amount = round((float) $lockedInvoice->amount - (float) ($lockedInvoice->paid_amount ?? 0), 2);
                throw_if($amount <= 0, new BusinessException('无需支付'));

                $payment = Payment::query()
                    ->where('invoice_id', $lockedInvoice->id)
                    ->whereGatewayKey(PaymentGatewayCode::ALIPAY)
                    ->where('status', PaymentStatus::PENDING)
                    ->latest('id')
                    ->first();

                if (! $payment) {
                    $payment = Payment::query()->create($this->paymentCreatePayload([
                        'payment_no' => Payment::generatePaymentNo(),
                        'user_id' => $user->id,
                        'order_id' => (int) ($lockedInvoice->order?->id ?? 0) ?: null,
                        'invoice_id' => $lockedInvoice->id,
                        'gateway' => PaymentGatewayCode::ALIPAY,
                        'amount' => $amount,
                        'status' => PaymentStatus::PENDING,
                        'trace_id' => $traceId,
                        'callback_raw' => [
                            'source' => 'alipay_precreate',
                            'trace_id' => $traceId,
                        ],
                    ]));
                    $this->syncProjection($payment);
                } else {
                    $this->ensurePaymentGatewayAudit($payment, PaymentGatewayCode::ALIPAY, $traceId);
                }

                return [
                    'invoice' => $lockedInvoice,
                    'payment' => $payment,
                ];
            });
        }, '支付二维码生成中，请稍后重试');

        /** @var Invoice $lockedInvoice */
        $lockedInvoice = $payload['invoice'];
        /** @var Payment $payment */
        $payment = $payload['payment'];

        $subject = config('app.name', 'IDC').' - 账单 '.$lockedInvoice->invoice_no;
        $result = $this->precreateAlipayPayment(
            $payment->payment_no,
            (float) $payment->amount,
            $subject,
            $this->resolveInvoicePaymentTimeoutExpress($lockedInvoice)
        );

        return [
            'payment_no' => $payment->payment_no,
            'qr_code' => $result['qr_code'],
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
        ];
    }

    /**
     * 第三方网关支付（通用入口，支持 Invoice）
     */
    public function payByGateway(Invoice $invoice, User $user, string $gateway, array $context = []): array
    {
        $resolvedGateway = $this->resolveGateway($gateway);

        throw_if(! $resolvedGateway->isEnabled(), new BusinessException(
            PaymentGatewayCode::label($gateway).'支付未启用'
        ));

        $traceId = $this->resolveTraceId($context, "{$gateway}:invoice:{$invoice->id}");
        $lockKey = "lock:pay:{$gateway}:invoice:{$invoice->id}";

        $payload = $this->withLock($lockKey, 20, function () use ($invoice, $user, $traceId, $gateway) {
            return DB::transaction(function () use ($invoice, $user, $traceId, $gateway) {
                $lockedInvoice = Invoice::query()
                    ->lockForUpdate()
                    ->with('order')
                    ->findOrFail($invoice->id);

                throw_if(
                    ! in_array((int) $lockedInvoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true),
                    new BusinessException('账单状态异常，无法支付')
                );

                $amount = round((float) $lockedInvoice->amount - (float) ($lockedInvoice->paid_amount ?? 0), 2);
                throw_if($amount <= 0, new BusinessException('无需支付'));

                $payment = Payment::query()
                    ->where('invoice_id', $lockedInvoice->id)
                    ->whereGatewayKey($gateway)
                    ->where('status', PaymentStatus::PENDING)
                    ->latest('id')
                    ->first();

                if (! $payment) {
                    $payment = Payment::query()->create($this->paymentCreatePayload([
                        'payment_no' => Payment::generatePaymentNo(),
                        'user_id' => $user->id,
                        'order_id' => (int) ($lockedInvoice->order?->id ?? 0) ?: null,
                        'invoice_id' => $lockedInvoice->id,
                        'gateway' => $gateway,
                        'amount' => $amount,
                        'status' => PaymentStatus::PENDING,
                        'trace_id' => $traceId,
                        'callback_raw' => [
                            'source' => "{$gateway}_precreate",
                            'trace_id' => $traceId,
                        ],
                    ]));
                    $this->syncProjection($payment);
                } else {
                    $this->ensurePaymentGatewayAudit($payment, $gateway, $traceId);
                }

                return [
                    'invoice' => $lockedInvoice,
                    'payment' => $payment,
                ];
            });
        }, '支付二维码生成中，请稍后重试');

        /** @var Invoice $lockedInvoice */
        $lockedInvoice = $payload['invoice'];
        /** @var Payment $payment */
        $payment = $payload['payment'];

        $subject = config('app.name', 'IDC').' - 账单 '.$lockedInvoice->invoice_no;
        $result = $this->precreateGatewayPayment(
            $gateway,
            $payment->payment_no,
            (float) $payment->amount,
            $subject,
            $this->resolveInvoicePaymentTimeoutExpress($lockedInvoice),
            $context
        );

        return [
            'payment_no' => $payment->payment_no,
            'qr_code' => $result['qr_code'],
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
        ];
    }

    /**
     * 第三方网关支付（通用入口，支持 Order）
     */
    public function payOrderByGateway(Order $order, User $user, string $gateway, array $context = []): array
    {
        $resolvedGateway = $this->resolveGateway($gateway);

        throw_if(! $resolvedGateway->isEnabled(), new BusinessException(
            PaymentGatewayCode::label($gateway).'支付未启用'
        ));

        $traceId = $this->resolveTraceId($context, "{$gateway}:order:{$order->id}");
        $lockKey = "lock:pay:{$gateway}:order:{$order->id}";

        $payload = $this->withLock($lockKey, 20, function () use ($order, $user, $traceId, $gateway) {
            return DB::transaction(function () use ($order, $user, $traceId, $gateway) {
                $lockedOrder = Order::query()
                    ->lockForUpdate()
                    ->with('invoice')
                    ->findOrFail($order->id);

                throw_if(
                    (int) $lockedOrder->status !== OrderStatus::PENDING,
                    new BusinessException('当前订单状态不支持支付')
                );

                $amount = $this->resolveOrderPayableAmount($lockedOrder);
                throw_if($amount <= 0, new BusinessException('当前订单无需支付'));

                $payment = Payment::query()
                    ->where('order_id', $lockedOrder->id)
                    ->whereGatewayKey($gateway)
                    ->where('status', PaymentStatus::PENDING)
                    ->latest('id')
                    ->first();

                if (! $payment) {
                    $payment = Payment::query()->create($this->paymentCreatePayload([
                        'payment_no' => Payment::generatePaymentNo(),
                        'user_id' => $user->id,
                        'order_id' => (int) $lockedOrder->id,
                        'invoice_id' => (int) ($lockedOrder->invoice?->id ?? 0) ?: null,
                        'gateway' => $gateway,
                        'amount' => $amount,
                        'status' => PaymentStatus::PENDING,
                        'trace_id' => $traceId,
                        'callback_raw' => [
                            'source' => "{$gateway}_precreate",
                            'trace_id' => $traceId,
                        ],
                    ]));
                    $this->syncProjection($payment);
                } else {
                    $this->ensurePaymentGatewayAudit($payment, $gateway, $traceId);
                }

                return [
                    'order' => $lockedOrder,
                    'payment' => $payment,
                ];
            });
        }, '支付二维码生成中，请稍后重试');

        /** @var Order $lockedOrder */
        $lockedOrder = $payload['order'];
        /** @var Payment $payment */
        $payment = $payload['payment'];

        $subject = config('app.name', 'IDC').' - 订单 '.$lockedOrder->order_no;
        $timeoutExpress = $lockedOrder->invoice instanceof Invoice
            ? $this->resolveInvoicePaymentTimeoutExpress($lockedOrder->invoice)
            : $this->resolveOrderPaymentTimeoutExpress($lockedOrder);
        $result = $this->precreateGatewayPayment(
            $gateway,
            $payment->payment_no,
            (float) $payment->amount,
            $subject,
            $timeoutExpress,
            $context
        );

        return [
            'payment_no' => $payment->payment_no,
            'qr_code' => $result['qr_code'],
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
        ];
    }

    /**
     * 先扣余额，再为剩余金额生成支付宝二维码。
     */
    public function payByBalanceAndAlipay(Invoice $invoice, User $user, float $balanceAmount, array $context = []): array
    {
        throw_if(
            ! $this->alipayGateway()->isEnabled(),
            new BusinessException('支付宝支付未启用')
        );

        $traceId = $this->resolveTraceId($context, 'mix:invoice:'.$invoice->id);
        $lockKey = "lock:pay:mix:invoice:{$invoice->id}";
        $normalizedBalanceAmount = round(max($balanceAmount, 0), 2);

        $payload = $this->withLock($lockKey, 20, function () use ($invoice, $user, $traceId, $normalizedBalanceAmount) {
            return DB::transaction(function () use ($invoice, $user, $traceId, $normalizedBalanceAmount) {
                $lockedInvoice = Invoice::query()
                    ->lockForUpdate()
                    ->with('order')
                    ->findOrFail($invoice->id);
                $lockedUser = User::query()
                    ->lockForUpdate()
                    ->findOrFail($user->id);

                throw_if(
                    ! in_array((int) $lockedInvoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true),
                    new BusinessException('账单状态异常，无法支付')
                );

                $remainingAmount = round((float) $lockedInvoice->amount - (float) ($lockedInvoice->paid_amount ?? 0), 2);
                throw_if($remainingAmount <= 0, new BusinessException('当前账单无需支付'));
                throw_if($normalizedBalanceAmount <= 0, new BusinessException('余额支付金额必须大于 0'));
                throw_if($normalizedBalanceAmount >= $remainingAmount, new BusinessException('余额支付金额需小于待支付金额'));

                $currentBalance = $this->getUserBalance($lockedUser);
                throw_if($currentBalance < $normalizedBalanceAmount, new BusinessException('余额不足'));

                $balanceAfter = $this->setUserBalance($lockedUser, $currentBalance - $normalizedBalanceAmount);
                $this->createBalanceLog(
                    (int) $lockedUser->id,
                    FinanceLedgerEventType::INVOICE_PAYMENT,
                    -$normalizedBalanceAmount,
                    $balanceAfter,
                    (int) $lockedInvoice->id,
                    '账单余额支付 '.(string) $lockedInvoice->invoice_no,
                    [
                        'trace_id' => $traceId,
                    ]
                );

                $nextPaidAmount = round((float) ($lockedInvoice->paid_amount ?? 0) + $normalizedBalanceAmount, 2);
                $lockedInvoice->forceFill([
                    'paid_amount' => $nextPaidAmount,
                    'trace_id' => $traceId !== '' ? $traceId : $lockedInvoice->trace_id,
                ])->save();

                return [
                    'invoice' => $lockedInvoice,
                    'remaining_amount' => round(max((float) $lockedInvoice->amount - $nextPaidAmount, 0), 2),
                ];
            });
        }, '支付请求处理中，请勿重复提交');

        /** @var Invoice $lockedInvoice */
        $lockedInvoice = $payload['invoice'];
        $remainingAmount = (float) ($payload['remaining_amount'] ?? 0);

        throw_if($remainingAmount <= 0, new BusinessException('当前账单无需继续发起支付宝支付'));

        $alipayPayment = DB::transaction(function () use ($lockedInvoice, $user, $remainingAmount, $traceId, $normalizedBalanceAmount) {
            $existingPayment = Payment::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->whereGatewayKey(PaymentGatewayCode::ALIPAY)
                ->where('status', PaymentStatus::PENDING)
                ->where('amount', $remainingAmount)
                ->latest('id')
                ->first();

            if ($existingPayment) {
                $this->ensurePaymentGatewayAudit($existingPayment, PaymentGatewayCode::ALIPAY, $traceId);

                return $existingPayment;
            }

            $payment = Payment::query()->create($this->paymentCreatePayload([
                'payment_no' => Payment::generatePaymentNo(),
                'user_id' => $user->id,
                'order_id' => (int) ($lockedInvoice->order?->id ?? 0) ?: null,
                'invoice_id' => $lockedInvoice->id,
                'gateway' => PaymentGatewayCode::ALIPAY,
                'amount' => $remainingAmount,
                'status' => PaymentStatus::PENDING,
                'trace_id' => $traceId,
                'callback_raw' => [
                    'source' => 'alipay_precreate_mix',
                    'trace_id' => $traceId,
                    'mix_payment' => true,
                    'balance_amount' => $normalizedBalanceAmount,
                ],
            ]));
            $this->syncProjection($payment);

            return $payment;
        });

        $subject = config('app.name', 'IDC').' - 账单 '.$lockedInvoice->invoice_no;
        try {
            $result = $this->precreateAlipayPayment(
                $alipayPayment->payment_no,
                $remainingAmount,
                $subject,
                $this->resolveInvoicePaymentTimeoutExpress($lockedInvoice)
            );
        } catch (\Throwable $exception) {
            $this->restoreReservedMixBalance($alipayPayment, [
                'trace_id' => $traceId,
                'closed_reason' => 'alipay_precreate_failed',
                'suppress_logs' => true,
            ]);

            throw $exception;
        }

        return [
            'balance_amount' => number_format($normalizedBalanceAmount, 2, '.', ''),
            'payment_no' => $alipayPayment->payment_no,
            'qr_code' => $result['qr_code'],
            'amount' => number_format($remainingAmount, 2, '.', ''),
            'paid_amount' => number_format((float) $lockedInvoice->paid_amount, 2, '.', ''),
            'payable_amount' => number_format($remainingAmount, 2, '.', ''),
        ];
    }

    /**
     * 先扣余额，再为剩余金额通过指定第三方网关生成二维码（通用入口）
     */
    public function payByBalanceAndGateway(Invoice $invoice, User $user, float $balanceAmount, string $gateway, array $context = []): array
    {
        $resolvedGateway = $this->resolveGateway($gateway);

        throw_if(! $resolvedGateway->isEnabled(), new BusinessException(
            PaymentGatewayCode::label($gateway).'支付未启用'
        ));

        $traceId = $this->resolveTraceId($context, "mix:invoice:{$invoice->id}");
        $lockKey = "lock:pay:mix:invoice:{$invoice->id}";
        $normalizedBalanceAmount = round(max($balanceAmount, 0), 2);

        $payload = $this->withLock($lockKey, 20, function () use ($invoice, $user, $traceId, $normalizedBalanceAmount) {
            return DB::transaction(function () use ($invoice, $user, $traceId, $normalizedBalanceAmount) {
                $lockedInvoice = Invoice::query()
                    ->lockForUpdate()
                    ->with('order')
                    ->findOrFail($invoice->id);
                $lockedUser = User::query()
                    ->lockForUpdate()
                    ->findOrFail($user->id);

                throw_if(
                    ! in_array((int) $lockedInvoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true),
                    new BusinessException('账单状态异常，无法支付')
                );

                $remainingAmount = round((float) $lockedInvoice->amount - (float) ($lockedInvoice->paid_amount ?? 0), 2);
                throw_if($remainingAmount <= 0, new BusinessException('当前账单无需支付'));
                throw_if($normalizedBalanceAmount <= 0, new BusinessException('余额支付金额必须大于 0'));
                throw_if($normalizedBalanceAmount >= $remainingAmount, new BusinessException('余额支付金额需小于待支付金额'));

                $currentBalance = $this->getUserBalance($lockedUser);
                throw_if($currentBalance < $normalizedBalanceAmount, new BusinessException('余额不足'));

                $balanceAfter = $this->setUserBalance($lockedUser, $currentBalance - $normalizedBalanceAmount);
                $this->createBalanceLog(
                    (int) $lockedUser->id,
                    FinanceLedgerEventType::INVOICE_PAYMENT,
                    -$normalizedBalanceAmount,
                    $balanceAfter,
                    (int) $lockedInvoice->id,
                    '账单余额支付 '.(string) $lockedInvoice->invoice_no,
                    [
                        'trace_id' => $traceId,
                    ]
                );

                $nextPaidAmount = round((float) ($lockedInvoice->paid_amount ?? 0) + $normalizedBalanceAmount, 2);
                $lockedInvoice->forceFill([
                    'paid_amount' => $nextPaidAmount,
                    'trace_id' => $traceId !== '' ? $traceId : $lockedInvoice->trace_id,
                ])->save();

                return [
                    'invoice' => $lockedInvoice,
                    'remaining_amount' => round(max((float) $lockedInvoice->amount - $nextPaidAmount, 0), 2),
                ];
            });
        }, '支付请求处理中，请勿重复提交');

        /** @var Invoice $lockedInvoice */
        $lockedInvoice = $payload['invoice'];
        $remainingAmount = (float) ($payload['remaining_amount'] ?? 0);

        throw_if($remainingAmount <= 0, new BusinessException('当前账单无需继续发起'.PaymentGatewayCode::label($gateway).'支付'));

        $gatewayPayment = DB::transaction(function () use ($lockedInvoice, $user, $remainingAmount, $traceId, $normalizedBalanceAmount, $gateway) {
            $existingPayment = Payment::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->whereGatewayKey($gateway)
                ->where('status', PaymentStatus::PENDING)
                ->where('amount', $remainingAmount)
                ->latest('id')
                ->first();

            if ($existingPayment) {
                $this->ensurePaymentGatewayAudit($existingPayment, $gateway, $traceId);

                return $existingPayment;
            }

            $payment = Payment::query()->create($this->paymentCreatePayload([
                'payment_no' => Payment::generatePaymentNo(),
                'user_id' => $user->id,
                'order_id' => (int) ($lockedInvoice->order?->id ?? 0) ?: null,
                'invoice_id' => $lockedInvoice->id,
                'gateway' => $gateway,
                'amount' => $remainingAmount,
                'status' => PaymentStatus::PENDING,
                'trace_id' => $traceId,
                'callback_raw' => [
                    'source' => "{$gateway}_precreate_mix",
                    'trace_id' => $traceId,
                    'mix_payment' => true,
                    'balance_amount' => $normalizedBalanceAmount,
                ],
            ]));
            $this->syncProjection($payment);

            return $payment;
        });

        $subject = config('app.name', 'IDC').' - 账单 '.$lockedInvoice->invoice_no;
        try {
            $result = $this->precreateGatewayPayment(
                $gateway,
                $gatewayPayment->payment_no,
                $remainingAmount,
                $subject,
                $this->resolveInvoicePaymentTimeoutExpress($lockedInvoice),
                $context
            );
        } catch (\Throwable $exception) {
            $this->restoreReservedMixBalance($gatewayPayment, [
                'trace_id' => $traceId,
                'closed_reason' => "{$gateway}_precreate_failed",
                'suppress_logs' => true,
            ]);

            throw $exception;
        }

        return [
            'balance_amount' => number_format($normalizedBalanceAmount, 2, '.', ''),
            'payment_no' => $gatewayPayment->payment_no,
            'qr_code' => $result['qr_code'],
            'amount' => number_format($remainingAmount, 2, '.', ''),
            'paid_amount' => number_format((float) $lockedInvoice->paid_amount, 2, '.', ''),
            'payable_amount' => number_format($remainingAmount, 2, '.', ''),
        ];
    }

    public function payOrderByAlipay(Order $order, User $user, array $context = []): array
    {
        throw_if(
            ! $this->alipayGateway()->isEnabled(),
            new BusinessException('支付宝支付未启用')
        );

        $traceId = $this->resolveTraceId($context, 'alipay:order:'.$order->id);
        $lockKey = "lock:pay:alipay:order:{$order->id}";

        $payload = $this->withLock($lockKey, 20, function () use ($order, $user, $traceId) {
            return DB::transaction(function () use ($order, $user, $traceId) {
                $lockedOrder = Order::query()
                    ->lockForUpdate()
                    ->with('invoice')
                    ->findOrFail($order->id);

                throw_if(
                    (int) $lockedOrder->status !== OrderStatus::PENDING,
                    new BusinessException('当前订单状态不支持支付')
                );

                $amount = $this->resolveOrderPayableAmount($lockedOrder);
                throw_if($amount <= 0, new BusinessException('当前订单无需支付'));

                $payment = Payment::query()
                    ->where('order_id', $lockedOrder->id)
                    ->whereGatewayKey(PaymentGatewayCode::ALIPAY)
                    ->where('status', PaymentStatus::PENDING)
                    ->latest('id')
                    ->first();

                if (! $payment) {
                    $payment = Payment::query()->create($this->paymentCreatePayload([
                        'payment_no' => Payment::generatePaymentNo(),
                        'user_id' => $user->id,
                        'order_id' => (int) $lockedOrder->id,
                        'invoice_id' => (int) ($lockedOrder->invoice?->id ?? 0) ?: null,
                        'gateway' => PaymentGatewayCode::ALIPAY,
                        'amount' => $amount,
                        'status' => PaymentStatus::PENDING,
                        'trace_id' => $traceId,
                        'callback_raw' => [
                            'source' => 'alipay_precreate',
                            'trace_id' => $traceId,
                        ],
                    ]));
                    $this->syncProjection($payment);
                } else {
                    $this->ensurePaymentGatewayAudit($payment, PaymentGatewayCode::ALIPAY, $traceId);
                }

                return [
                    'order' => $lockedOrder,
                    'payment' => $payment,
                ];
            });
        }, '支付二维码生成中，请稍后重试');

        /** @var Order $lockedOrder */
        $lockedOrder = $payload['order'];
        /** @var Payment $payment */
        $payment = $payload['payment'];

        $subject = config('app.name', 'IDC').' - 订单 '.$lockedOrder->order_no;
        $timeoutExpress = $lockedOrder->invoice instanceof Invoice
            ? $this->resolveInvoicePaymentTimeoutExpress($lockedOrder->invoice)
            : $this->resolveOrderPaymentTimeoutExpress($lockedOrder);
        $result = $this->precreateAlipayPayment($payment->payment_no, (float) $payment->amount, $subject, $timeoutExpress);

        return [
            'payment_no' => $payment->payment_no,
            'qr_code' => $result['qr_code'],
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
        ];
    }

    /**
     * 第三方网关异步通知处理（通用入口）
     */
    public function handleGatewayNotify(string $gateway, array $params): bool
    {
        $resolvedGateway = $this->resolveGateway($gateway);
        $gatewayLabel = PaymentGatewayCode::label($gateway);

        if (! $resolvedGateway->verifyNotify($params)) {
            Log::warning("[{$gatewayLabel}回调] 签名验证失败", [
                'gateway' => $gateway,
                'payment_no' => (string) ($params['out_trade_no'] ?? ''),
                'trade_no' => (string) ($params['trade_no'] ?? ''),
                'trade_status' => (string) ($params['trade_status'] ?? ''),
            ]);

            return false;
        }

        $paymentNo = $params['out_trade_no'] ?? '';
        $tradeStatus = $params['trade_status'] ?? '';
        $tradeNo = $params['trade_no'] ?? '';

        $payment = Payment::where('payment_no', $paymentNo)->first();
        if (! $payment) {
            Log::warning("[{$gatewayLabel}回调] 支付记录不存在", ['payment_no' => $paymentNo]);

            return false;
        }

        if (! $this->paymentBelongsToGateway($payment, $gateway)) {
            Log::warning("[{$gatewayLabel}回调] 回调网关与支付记录渠道不一致，已拒绝入账", [
                'payment_no' => $paymentNo,
                'callback_gateway' => PaymentGatewayCode::normalize(trim($gateway)),
                'payment_gateway' => $payment->gatewayKey(),
            ]);

            return false;
        }

        // 商户号校验（支付宝: app_id, 微信: mch_id, 易支付: pid）
        $merchantId = $params['app_id'] ?? $params['mch_id'] ?? $params['pid'] ?? '';
        if ($merchantId !== '' && ! $resolvedGateway->matchesMerchantId($merchantId)) {
            Log::warning("[{$gatewayLabel}回调] 商户号不匹配", [
                'payment_no' => $paymentNo,
                'merchant_id' => $merchantId,
            ]);

            return false;
        }

        $notifyAmount = round((float) ($params['total_amount'] ?? $params['amount'] ?? $params['money'] ?? 0), 2);
        $expectedAmount = round((float) $payment->amount, 2);
        if ($notifyAmount <= 0 || abs($notifyAmount - $expectedAmount) > 0.0001) {
            Log::warning("[{$gatewayLabel}回调] 金额校验失败", [
                'payment_no' => $paymentNo,
                'expected_amount' => $expectedAmount,
                'notify_amount' => $notifyAmount,
            ]);

            return false;
        }

        $params['trace_id'] = $this->resolveTraceId(
            ['trace_id' => $params['trace_id'] ?? $payment->trace_id],
            "{$gateway}:callback:{$payment->id}"
        );
        $this->recordPaymentCallback($payment, 'payment', $params, true, $tradeNo);

        // 各网关成功状态不同，由 queryGatewayPayment 的结果判断
        if (! in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED', 'SUCCESS'], true)) {
            Log::info("[{$gatewayLabel}回调] 非成功状态，已记录回调并跳过业务入账", [
                'payment_no' => $paymentNo,
                'trade_status' => $tradeStatus,
            ]);

            return true;
        }

        // 幂等：已处理过
        if ((int) $payment->status === PaymentStatus::SUCCESS) {
            $this->ensureRechargeInvoiceProjection($payment);

            return true;
        }

        // 充值类（无 invoice_id）走充值到账逻辑
        if (! $payment->invoice_id) {
            $this->completeRechargePayment($payment, $tradeNo, $params);

            return true;
        }

        $lockKey = "lock:{$gateway}:payment:{$payment->id}";

        try {
            $result = $this->withLock($lockKey, 30, function () use ($payment, $tradeNo, $params, $gatewayLabel) {
                return DB::transaction(function () use ($payment, $tradeNo, $params, $gatewayLabel) {
                    $lockedPayment = Payment::query()
                        ->lockForUpdate()
                        ->with(['invoice.order'])
                        ->find($payment->id);

                    if (! $lockedPayment) {
                        return ['dispatch' => false, 'invoice' => null, 'payment_no' => ''];
                    }

                    if ((int) $lockedPayment->status === PaymentStatus::SUCCESS) {
                        return [
                            'dispatch' => false,
                            'invoice' => $lockedPayment->invoice,
                            'payment_no' => (string) $lockedPayment->payment_no,
                        ];
                    }

                    $invoice = $lockedPayment->invoice_id
                        ? Invoice::query()->lockForUpdate()->with('order')->find($lockedPayment->invoice_id)
                        : null;

                    if (! $invoice) {
                        return ['dispatch' => false, 'invoice' => null, 'payment_no' => (string) $lockedPayment->payment_no];
                    }

                    if ((int) $invoice->status === InvoiceStatus::PAID) {
                        $this->creditCapturedPaymentToBalance($lockedPayment, $invoice, $tradeNo, $params, 'invoice_already_paid');

                        Log::warning("[{$gatewayLabel}回调] 检测到重复支付回调，已拦截二次入账", [
                            'payment_no' => $lockedPayment->payment_no,
                            'invoice_id' => $invoice->id,
                            'order_id' => $invoice->order?->id,
                        ]);

                        return ['dispatch' => false, 'invoice' => $invoice, 'payment_no' => (string) $lockedPayment->payment_no];
                    }

                    if ((int) $invoice->status === InvoiceStatus::CANCELLED) {
                        $this->restoreReservedMixBalance($lockedPayment, [
                            'closed_reason' => 'cancelled_invoice_captured',
                            'mark_payment_failed' => false,
                            'trace_id' => (string) ($params['trace_id'] ?? ''),
                        ]);
                        $lockedPayment->refresh();
                        $this->creditCapturedPaymentToBalance($lockedPayment, $invoice, $tradeNo, $params, 'cancelled_invoice');

                        Log::warning("[{$gatewayLabel}回调] 已取消账单收到支付回调，已拦截入账", [
                            'payment_no' => $lockedPayment->payment_no,
                            'invoice_id' => $invoice->id,
                            'order_id' => $invoice->order?->id,
                        ]);

                        return ['dispatch' => false, 'invoice' => $invoice, 'payment_no' => (string) $lockedPayment->payment_no];
                    }

                    if ($this->invoicePaymentWindowExpired($invoice)) {
                        $this->restoreReservedMixBalance($lockedPayment, [
                            'closed_reason' => 'payment_window_expired_captured',
                            'mark_payment_failed' => false,
                            'trace_id' => (string) ($params['trace_id'] ?? ''),
                        ]);
                        $lockedPayment->refresh();
                        $this->creditCapturedPaymentToBalance($lockedPayment, $invoice, $tradeNo, $params, 'payment_window_expired');
                        $this->cancelExpiredInvoiceAfterCapturedPayment($invoice, $lockedPayment);

                        Log::warning("[{$gatewayLabel}回调] 账单支付窗口已过期，已取消账单并拦截入账", [
                            'payment_no' => $lockedPayment->payment_no,
                            'invoice_id' => $invoice->id,
                            'order_id' => $invoice->order?->id,
                        ]);

                        return ['dispatch' => false, 'invoice' => $invoice, 'payment_no' => (string) $lockedPayment->payment_no];
                    }

                    throw_if(
                        ! in_array((int) $invoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true),
                        new BusinessException('账单状态异常，无法处理支付回调')
                    );

                    $invoice = $this->restoreConflictingMixBalancesForCapturedPayment(
                        $invoice,
                        $lockedPayment,
                        'captured_payment_superseded_mix_balance',
                        $params
                    );

                    if (abs(round((float) $lockedPayment->amount, 2) - $this->invoicePayableAmount($invoice)) > 0.0001) {
                        $this->creditCapturedPaymentToBalance($lockedPayment, $invoice, $tradeNo, $params, 'payable_amount_mismatch');

                        Log::warning("[{$gatewayLabel}回调] 支付金额与账单当前应付不匹配，已转入余额", [
                            'payment_no' => $lockedPayment->payment_no,
                            'invoice_id' => $invoice->id,
                            'payment_amount' => number_format((float) $lockedPayment->amount, 2, '.', ''),
                            'payable_amount' => number_format($this->invoicePayableAmount($invoice), 2, '.', ''),
                        ]);

                        return ['dispatch' => false, 'invoice' => $invoice, 'payment_no' => (string) $lockedPayment->payment_no];
                    }

                    $lockedPayment->forceFill([
                        'trade_no' => $tradeNo,
                        'status' => PaymentStatus::SUCCESS,
                        'callback_raw' => $params,
                        'paid_at' => now(),
                        'trace_id' => (string) ($params['trace_id'] ?? $lockedPayment->trace_id),
                    ])->save();
                    $this->syncProjection($lockedPayment);

                    $invoice->forceFill([
                        'status' => InvoiceStatus::PAID,
                        'paid_amount' => $invoice->amount,
                        'paid_at' => now(),
                        'trace_id' => (string) ($params['trace_id'] ?? $invoice->trace_id),
                    ])->save();

                    $invoice->order?->forceFill([
                        'status' => OrderStatus::PAID,
                        'paid_amount' => $invoice->amount,
                        'paid_at' => now(),
                    ])->save();

                    // 账单已付：预扣余额已被账单消费，不可再退。
                    $this->closeOtherPendingPayments($invoice, (int) $lockedPayment->id, 'invoice_paid_by_gateway', false);
                    $this->recordSuccessfulInvoicePayment($lockedPayment, $invoice);

                    return [
                        'dispatch' => true,
                        'invoice' => $invoice,
                        'payment_no' => (string) $lockedPayment->payment_no,
                    ];
                });
            }, '支付回调处理中，请稍后重试');
        } catch (BusinessException $exception) {
            Log::warning("[{$gatewayLabel}回调] 处理失败", [
                'payment_no' => $paymentNo,
                'trade_no' => $tradeNo,
                'message' => $exception->getMessage(),
            ]);

            return false;
        } catch (\Throwable $exception) {
            Log::error("[{$gatewayLabel}回调] 处理异常", [
                'payment_no' => $paymentNo,
                'trade_no' => $tradeNo,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return false;
        }

        if (($result['dispatch'] ?? false) && ($result['invoice'] ?? null) instanceof Invoice) {
            $this->handlePaidInvoice($result['invoice'], "{$gateway}:".($result['payment_no'] ?? $paymentNo));
        }

        return true;
    }

    /**
     * 支付宝异步通知处理
     */
    public function handleAlipayNotify(array $params): bool
    {
        if (! $this->alipayGateway()->verifyNotify($params)) {
            Log::warning('[支付宝回调] 签名验证失败', [
                'payment_no' => (string) ($params['out_trade_no'] ?? ''),
                'trade_no' => (string) ($params['trade_no'] ?? ''),
                'trade_status' => (string) ($params['trade_status'] ?? ''),
                'app_id' => (string) ($params['app_id'] ?? ''),
            ]);

            return false;
        }

        $paymentNo = $params['out_trade_no'] ?? '';
        $tradeStatus = $params['trade_status'] ?? '';
        $tradeNo = $params['trade_no'] ?? '';

        $payment = Payment::where('payment_no', $paymentNo)->first();
        if (! $payment) {
            Log::warning('[支付宝回调] 支付记录不存在', ['payment_no' => $paymentNo]);

            return false;
        }

        if (! $this->paymentBelongsToGateway($payment, PaymentGatewayCode::ALIPAY)) {
            Log::warning('[支付宝回调] 支付记录不属于支付宝渠道，已拒绝入账', [
                'payment_no' => $paymentNo,
                'payment_gateway' => $payment->gatewayKey(),
            ]);

            return false;
        }

        if (! $this->alipayGateway()->matchesMerchantId($params['app_id'] ?? '')) {
            Log::warning('[支付宝回调] app_id 不匹配', [
                'payment_no' => $paymentNo,
                'app_id' => (string) ($params['app_id'] ?? ''),
            ]);

            return false;
        }

        $notifyAmount = round((float) ($params['total_amount'] ?? 0), 2);
        $expectedAmount = round((float) $payment->amount, 2);
        if ($notifyAmount <= 0 || abs($notifyAmount - $expectedAmount) > 0.0001) {
            Log::warning('[支付宝回调] 金额校验失败', [
                'payment_no' => $paymentNo,
                'expected_amount' => $expectedAmount,
                'notify_amount' => $notifyAmount,
            ]);

            return false;
        }

        $params['trace_id'] = $this->resolveTraceId(
            ['trace_id' => $params['trace_id'] ?? $payment->trace_id],
            'alipay:callback:'.$payment->id
        );
        $this->recordPaymentCallback($payment, 'payment', $params, true, $tradeNo);

        if (! in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
            Log::info('[支付宝回调] 非成功状态，已记录回调并跳过业务入账', [
                'payment_no' => $paymentNo,
                'trade_status' => $tradeStatus,
            ]);

            return true;
        }

        // 幂等：已处理过
        if ((int) $payment->status === PaymentStatus::SUCCESS) {
            $this->ensureRechargeInvoiceProjection($payment);

            return true;
        }

        // 充值类（无 invoice_id）走充值到账逻辑
        if (! $payment->invoice_id) {
            $this->completeRechargePayment($payment, $tradeNo, $params);

            return true;
        }

        $lockKey = "lock:alipay:payment:{$payment->id}";

        try {
            $result = $this->withLock($lockKey, 30, function () use ($payment, $tradeNo, $params) {
                return DB::transaction(function () use ($payment, $tradeNo, $params) {
                    $lockedPayment = Payment::query()
                        ->lockForUpdate()
                        ->with(['invoice.order'])
                        ->find($payment->id);

                    if (! $lockedPayment) {
                        return [
                            'dispatch' => false,
                            'invoice' => null,
                            'payment_no' => '',
                        ];
                    }

                    if ((int) $lockedPayment->status === PaymentStatus::SUCCESS) {
                        return [
                            'dispatch' => false,
                            'invoice' => $lockedPayment->invoice,
                            'payment_no' => (string) $lockedPayment->payment_no,
                        ];
                    }

                    $invoice = $lockedPayment->invoice_id
                        ? Invoice::query()->lockForUpdate()->with('order')->find($lockedPayment->invoice_id)
                        : null;

                    if (! $invoice) {
                        return [
                            'dispatch' => false,
                            'invoice' => null,
                            'payment_no' => (string) $lockedPayment->payment_no,
                        ];
                    }

                    if ((int) $invoice->status === InvoiceStatus::PAID) {
                        $this->creditCapturedPaymentToBalance($lockedPayment, $invoice, $tradeNo, $params, 'invoice_already_paid');

                        Log::warning('[支付宝回调] 检测到重复支付回调，已拦截二次入账', [
                            'payment_no' => $lockedPayment->payment_no,
                            'invoice_id' => $invoice->id,
                            'order_id' => $invoice->order?->id,
                        ]);

                        return [
                            'dispatch' => false,
                            'invoice' => $invoice,
                            'payment_no' => (string) $lockedPayment->payment_no,
                        ];
                    }

                    if ((int) $invoice->status === InvoiceStatus::CANCELLED) {
                        $this->restoreReservedMixBalance($lockedPayment, [
                            'closed_reason' => 'cancelled_invoice_captured',
                            'mark_payment_failed' => false,
                            'trace_id' => (string) ($params['trace_id'] ?? ''),
                        ]);
                        $lockedPayment->refresh();
                        $this->creditCapturedPaymentToBalance($lockedPayment, $invoice, $tradeNo, $params, 'cancelled_invoice');

                        Log::warning('[支付宝回调] 已取消账单收到支付回调，已拦截入账', [
                            'payment_no' => $lockedPayment->payment_no,
                            'invoice_id' => $invoice->id,
                            'order_id' => $invoice->order?->id,
                        ]);

                        return [
                            'dispatch' => false,
                            'invoice' => $invoice,
                            'payment_no' => (string) $lockedPayment->payment_no,
                        ];
                    }

                    if ($this->invoicePaymentWindowExpired($invoice)) {
                        $this->restoreReservedMixBalance($lockedPayment, [
                            'closed_reason' => 'payment_window_expired_captured',
                            'mark_payment_failed' => false,
                            'trace_id' => (string) ($params['trace_id'] ?? ''),
                        ]);
                        $lockedPayment->refresh();
                        $this->creditCapturedPaymentToBalance($lockedPayment, $invoice, $tradeNo, $params, 'payment_window_expired');
                        $this->cancelExpiredInvoiceAfterCapturedPayment($invoice, $lockedPayment);

                        Log::warning('[支付宝回调] 账单支付窗口已过期，已取消账单并拦截入账', [
                            'payment_no' => $lockedPayment->payment_no,
                            'invoice_id' => $invoice->id,
                            'order_id' => $invoice->order?->id,
                        ]);

                        return [
                            'dispatch' => false,
                            'invoice' => $invoice,
                            'payment_no' => (string) $lockedPayment->payment_no,
                        ];
                    }

                    throw_if(
                        ! in_array((int) $invoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true),
                        new BusinessException('账单状态异常，无法处理支付回调')
                    );

                    $invoice = $this->restoreConflictingMixBalancesForCapturedPayment(
                        $invoice,
                        $lockedPayment,
                        'captured_payment_superseded_mix_balance',
                        $params
                    );

                    if (abs(round((float) $lockedPayment->amount, 2) - $this->invoicePayableAmount($invoice)) > 0.0001) {
                        $this->creditCapturedPaymentToBalance($lockedPayment, $invoice, $tradeNo, $params, 'payable_amount_mismatch');

                        Log::warning('[支付宝回调] 支付金额与账单当前应付不匹配，已转入余额', [
                            'payment_no' => $lockedPayment->payment_no,
                            'invoice_id' => $invoice->id,
                            'payment_amount' => number_format((float) $lockedPayment->amount, 2, '.', ''),
                            'payable_amount' => number_format($this->invoicePayableAmount($invoice), 2, '.', ''),
                        ]);

                        return [
                            'dispatch' => false,
                            'invoice' => $invoice,
                            'payment_no' => (string) $lockedPayment->payment_no,
                        ];
                    }

                    $lockedPayment->forceFill([
                        'trade_no' => $tradeNo,
                        'status' => PaymentStatus::SUCCESS,
                        'callback_raw' => $params,
                        'paid_at' => now(),
                        'trace_id' => (string) ($params['trace_id'] ?? $lockedPayment->trace_id),
                    ])->save();
                    $this->syncProjection($lockedPayment);

                    $invoice->forceFill([
                        'status' => InvoiceStatus::PAID,
                        'paid_amount' => $invoice->amount,
                        'paid_at' => now(),
                        'trace_id' => (string) ($params['trace_id'] ?? $invoice->trace_id),
                    ])->save();

                    $invoice->order?->forceFill([
                        'status' => OrderStatus::PAID,
                        'paid_amount' => $invoice->amount,
                        'paid_at' => now(),
                    ])->save();

                    // 账单已付：预扣余额已被账单消费，不可再退。
                    $this->closeOtherPendingPayments($invoice, (int) $lockedPayment->id, 'invoice_paid_by_alipay', false);
                    $this->recordSuccessfulInvoicePayment($lockedPayment, $invoice);

                    return [
                        'dispatch' => true,
                        'invoice' => $invoice,
                        'payment_no' => (string) $lockedPayment->payment_no,
                    ];
                });
            }, '支付回调处理中，请稍后重试');
        } catch (BusinessException $exception) {
            Log::warning('[支付宝回调] 处理失败', [
                'payment_no' => $paymentNo,
                'trade_no' => $tradeNo,
                'message' => $exception->getMessage(),
            ]);

            return false;
        } catch (\Throwable $exception) {
            Log::error('[支付宝回调] 处理异常', [
                'payment_no' => $paymentNo,
                'trade_no' => $tradeNo,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return false;
        }

        if (($result['dispatch'] ?? false) && ($result['invoice'] ?? null) instanceof Invoice) {
            $this->handlePaidInvoice($result['invoice'], 'alipay:'.($result['payment_no'] ?? $payment->payment_no));
        }

        return true;
    }

    /**
     * 轮询第三方网关支付状态（通用入口）
     *
     * 注意：主动查询的响应数据格式与异步通知不同，
     *       不能复用 handleGatewayNotify() 的签名验证逻辑，
     *       需要独立处理支付成功的入账流程。
     */
    public function queryGatewayPaymentStatus(Payment $payment): array
    {
        if ((int) $payment->status === PaymentStatus::SUCCESS) {
            return ['paid' => true, 'trade_no' => $payment->trade_no];
        }

        $gateway = $payment->gatewayKey();
        $result = $this->queryGatewayPayment($gateway, $payment->payment_no);

        if (in_array($result['trade_status'], ['TRADE_SUCCESS', 'TRADE_FINISHED', 'SUCCESS'], true)) {
            // 主动查询确认已支付，直接走入账流程（不经过签名验证）
            $this->completePaymentFromQuery($payment, $result);
            $payment->refresh();

            return [
                'paid' => (int) $payment->status === PaymentStatus::SUCCESS,
                'trade_no' => $payment->trade_no ?: $result['trade_no'],
                'trade_status' => $result['trade_status'],
            ];
        }

        return ['paid' => false, 'trade_status' => $result['trade_status']];
    }

    /**
     * 轮询支付宝订单状态
     *
     * 注意：主动查询（alipay.trade.query）的响应数据格式与异步通知不同，
     *       不能复用 handleAlipayNotify() 的签名验证逻辑，
     *       需要独立处理支付成功的入账流程。
     */
    public function queryAlipayStatus(Payment $payment): array
    {
        if ((int) $payment->status === PaymentStatus::SUCCESS) {
            return ['paid' => true, 'trade_no' => $payment->trade_no];
        }

        $result = $this->queryAlipayPayment($payment->payment_no);

        if (in_array($result['trade_status'], ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
            // 主动查询确认已支付，直接走入账流程（不经过签名验证）
            $this->completePaymentFromQuery($payment, $result);
            $payment->refresh();

            return [
                'paid' => (int) $payment->status === PaymentStatus::SUCCESS,
                'trade_no' => $payment->trade_no ?: $result['trade_no'],
                'trade_status' => $result['trade_status'],
            ];
        }

        return ['paid' => false, 'trade_status' => $result['trade_status']];
    }

    /**
     * 主动查询确认支付成功后的入账处理
     * 与异步通知共享相同的入账逻辑，但跳过签名验证（主动查询响应无签名）
     */
    private function completePaymentFromQuery(Payment $payment, array $queryResult): void
    {
        $tradeNo = $queryResult['trade_no'] ?? '';
        $tradeStatus = $queryResult['trade_status'] ?? '';

        // 金额校验（total_amount 缺失或为零均视为异常，拒绝入账）
        $queryAmount = round((float) ($queryResult['total_amount'] ?? 0), 2);
        $expectedAmount = round((float) $payment->amount, 2);
        if ($queryAmount <= 0 || abs($queryAmount - $expectedAmount) > 0.0001) {
            Log::warning('[支付宝主动查询] 金额校验失败', [
                'payment_no' => $payment->payment_no,
                'expected' => $expectedAmount,
                'query' => $queryAmount,
            ]);

            return;
        }

        // 幂等：已处理过
        if ((int) $payment->status === PaymentStatus::SUCCESS) {
            return;
        }

        $queryPayload = array_merge($queryResult['raw'] ?? [], [
            'out_trade_no' => $payment->payment_no,
            'trade_no' => $tradeNo,
            'trade_status' => $tradeStatus,
            'source' => 'active_query',
            'trace_id' => $this->resolveTraceId(
                ['trace_id' => $queryResult['trace_id'] ?? $payment->trace_id],
                'alipay:query:'.$payment->id
            ),
        ]);
        $this->recordPaymentCallback($payment, 'payment', $queryPayload, true, $tradeNo);

        // 充值类（无 invoice_id）走充值到账逻辑
        if (! $payment->invoice_id) {
            $this->completeRechargePayment($payment, $tradeNo, $queryPayload);

            return;
        }

        $lockKey = "lock:alipay:payment:{$payment->id}";

        try {
            $result = $this->withLock($lockKey, 30, function () use ($payment, $tradeNo, $queryPayload) {
                return DB::transaction(function () use ($payment, $tradeNo, $queryPayload) {
                    $lockedPayment = Payment::query()
                        ->lockForUpdate()
                        ->with(['invoice.order'])
                        ->find($payment->id);

                    if (! $lockedPayment || (int) $lockedPayment->status === PaymentStatus::SUCCESS) {
                        return ['dispatch' => false, 'invoice' => $lockedPayment?->invoice, 'payment_no' => (string) ($lockedPayment?->payment_no ?? '')];
                    }

                    $invoice = $lockedPayment->invoice_id
                        ? Invoice::query()->lockForUpdate()->with('order')->find($lockedPayment->invoice_id)
                        : null;

                    if (! $invoice) {
                        return ['dispatch' => false, 'invoice' => null, 'payment_no' => (string) $lockedPayment->payment_no];
                    }

                    // 已支付账单 → 标记 duplicate_paid
                    if ((int) $invoice->status === InvoiceStatus::PAID) {
                        $this->creditCapturedPaymentToBalance($lockedPayment, $invoice, $tradeNo, $queryPayload, 'invoice_already_paid');

                        Log::warning('[支付宝主动查询] 检测到重复支付，已拦截二次入账', [
                            'payment_no' => $lockedPayment->payment_no,
                            'invoice_id' => $invoice->id,
                        ]);

                        return ['dispatch' => false, 'invoice' => $invoice, 'payment_no' => (string) $lockedPayment->payment_no];
                    }

                    // 已取消账单 → 标记 FAILED
                    if ((int) $invoice->status === InvoiceStatus::CANCELLED) {
                        $this->restoreReservedMixBalance($lockedPayment, [
                            'closed_reason' => 'cancelled_invoice_captured',
                            'mark_payment_failed' => false,
                            'trace_id' => (string) ($queryPayload['trace_id'] ?? ''),
                        ]);
                        $lockedPayment->refresh();
                        $this->creditCapturedPaymentToBalance($lockedPayment, $invoice, $tradeNo, $queryPayload, 'cancelled_invoice');

                        return ['dispatch' => false, 'invoice' => $invoice, 'payment_no' => (string) $lockedPayment->payment_no];
                    }

                    if ($this->invoicePaymentWindowExpired($invoice)) {
                        $this->restoreReservedMixBalance($lockedPayment, [
                            'closed_reason' => 'payment_window_expired_captured',
                            'mark_payment_failed' => false,
                            'trace_id' => (string) ($queryPayload['trace_id'] ?? ''),
                        ]);
                        $lockedPayment->refresh();
                        $this->creditCapturedPaymentToBalance($lockedPayment, $invoice, $tradeNo, $queryPayload, 'payment_window_expired');
                        $this->cancelExpiredInvoiceAfterCapturedPayment($invoice, $lockedPayment);

                        Log::warning('[支付宝主动查询] 账单支付窗口已过期，已取消账单并拦截入账', [
                            'payment_no' => $lockedPayment->payment_no,
                            'invoice_id' => $invoice->id,
                        ]);

                        return ['dispatch' => false, 'invoice' => $invoice, 'payment_no' => (string) $lockedPayment->payment_no];
                    }

                    // 正常未支付 → 完成入账
                    throw_if(
                        ! in_array((int) $invoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true),
                        new BusinessException('账单状态异常，无法处理支付')
                    );

                    $invoice = $this->restoreConflictingMixBalancesForCapturedPayment(
                        $invoice,
                        $lockedPayment,
                        'captured_payment_superseded_mix_balance',
                        $queryPayload
                    );

                    if (abs(round((float) $lockedPayment->amount, 2) - $this->invoicePayableAmount($invoice)) > 0.0001) {
                        $this->creditCapturedPaymentToBalance($lockedPayment, $invoice, $tradeNo, $queryPayload, 'payable_amount_mismatch');

                        Log::warning('[支付宝主动查询] 支付金额与账单当前应付不匹配，已转入余额', [
                            'payment_no' => $lockedPayment->payment_no,
                            'invoice_id' => $invoice->id,
                            'payment_amount' => number_format((float) $lockedPayment->amount, 2, '.', ''),
                            'payable_amount' => number_format($this->invoicePayableAmount($invoice), 2, '.', ''),
                        ]);

                        return ['dispatch' => false, 'invoice' => $invoice, 'payment_no' => (string) $lockedPayment->payment_no];
                    }

                    $lockedPayment->forceFill([
                        'trade_no' => $tradeNo,
                        'status' => PaymentStatus::SUCCESS,
                        'callback_raw' => $queryPayload,
                        'paid_at' => now(),
                        'trace_id' => (string) ($queryPayload['trace_id'] ?? $lockedPayment->trace_id),
                    ])->save();
                    $this->syncProjection($lockedPayment);

                    $invoice->forceFill([
                        'status' => InvoiceStatus::PAID,
                        'paid_amount' => $invoice->amount,
                        'paid_at' => now(),
                        'trace_id' => (string) ($queryPayload['trace_id'] ?? $invoice->trace_id),
                    ])->save();

                    $invoice->order?->forceFill([
                        'status' => OrderStatus::PAID,
                        'paid_amount' => $invoice->amount,
                        'paid_at' => now(),
                    ])->save();

                    // 账单已付：预扣余额已被账单消费，不可再退。
                    $this->closeOtherPendingPayments($invoice, (int) $lockedPayment->id, 'invoice_paid_by_alipay_query', false);
                    $this->recordSuccessfulInvoicePayment($lockedPayment, $invoice);

                    return [
                        'dispatch' => true,
                        'invoice' => $invoice,
                        'payment_no' => (string) $lockedPayment->payment_no,
                    ];
                });
            }, '支付处理中，请稍后重试');
        } catch (\Throwable $exception) {
            Log::error('[支付宝主动查询] 入账处理异常', [
                'payment_no' => $payment->payment_no,
                'trade_no' => $tradeNo,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        if (($result['dispatch'] ?? false) && ($result['invoice'] ?? null) instanceof Invoice) {
            $this->handlePaidInvoice($result['invoice'], 'alipay_query:'.($result['payment_no'] ?? $payment->payment_no));
        }
    }

    /**
     * 后台发起订单退款
     */
    public function refundOrder(Order $order, array $payload = [], array $context = []): array
    {
        $refundMethod = trim((string) ($payload['refund_method'] ?? 'original'));
        $paymentGateway = $this->detectPrimaryPaymentGateway($order);
        $traceId = trim((string) ($context['trace_id'] ?? ''));

        $this->referralService->assertOrderRewardRefundable($order);

        $result = match ($refundMethod) {
            'balance' => $this->refundOrderToBalance($order, $payload, $context, 'balance', '退回余额'),
            'original' => match ($paymentGateway) {
                PaymentGatewayCode::ALIPAY => $this->refundOrderByAlipay($order, $payload, $context),
                'balance' => $this->refundOrderToBalance($order, $payload, $context, 'original', '原路退款'),
                default => throw new BusinessException('当前支付方式不支持原路退款'),
            },
            default => throw new BusinessException('不支持的退款方式'),
        };

        if (($result['already_refunded'] ?? false) !== true) {
            $this->referralService->reverseRewardForRefundedOrder($order, $traceId !== '' ? "refund:{$traceId}" : "refund:order:{$order->id}");
        }

        return $result;
    }

    /**
     * 后台发起支付宝原路退款
     */
    public function refundOrderByAlipay(Order $order, array $payload = [], array $context = []): array
    {
        $lockKey = "lock:refund:alipay:order:{$order->id}";

        return $this->withLock($lockKey, 40, function () use ($order, $payload, $context) {
            $snapshot = DB::transaction(function () use ($order, $payload) {
                $lockedOrder = Order::query()
                    ->lockForUpdate()
                    ->with(['invoice.payments', 'service'])
                    ->findOrFail($order->id);

                $invoice = $lockedOrder->invoice;
                throw_if(! $invoice instanceof Invoice, new BusinessException('订单未关联账单，无法退款'));

                if ((int) $lockedOrder->status === OrderStatus::REFUNDED) {
                    return [
                        'already_refunded' => true,
                        'order_id' => (int) $lockedOrder->id,
                        'payment_id' => 0,
                    ];
                }

                throw_if((int) $invoice->status !== InvoiceStatus::PAID, new BusinessException('当前账单状态不支持支付宝退款'));
                throw_if(
                    ! in_array((int) $lockedOrder->status, [OrderStatus::PAID, OrderStatus::COMPLETED], true),
                    new BusinessException('当前订单状态不支持支付宝退款')
                );

                $payment = $this->resolveRefundableAlipayPayment($invoice);
                throw_if(! $payment instanceof Payment, new BusinessException('未找到可退款的支付宝支付记录'));

                if ((int) $payment->status === PaymentStatus::REFUNDED) {
                    return [
                        'already_refunded' => true,
                        'order_id' => (int) $lockedOrder->id,
                        'payment_id' => (int) $payment->id,
                    ];
                }

                $refundableAmount = $this->resolveBalanceRefundableAmount($invoice, $payment);
                $refundAmount = round((float) ($payload['amount'] ?? $refundableAmount), 2);
                throw_if($refundAmount <= 0, new BusinessException('退款金额不正确'));
                throw_if(abs($refundAmount - $refundableAmount) > 0.00001, new BusinessException('当前仅支持按原支付金额全额退款'));

                $refundReason = trim((string) ($payload['remark'] ?? ''));
                if ($refundReason === '') {
                    $refundReason = '后台发起支付宝原路退款';
                }

                return [
                    'already_refunded' => false,
                    'order_id' => (int) $lockedOrder->id,
                    'payment_id' => (int) $payment->id,
                    'payment_no' => (string) $payment->payment_no,
                    'trade_no' => trim((string) ($payment->trade_no ?? '')),
                    'refund_amount' => $refundAmount,
                    'refund_reason' => $refundReason,
                    'out_request_no' => $this->buildAlipayRefundRequestNo($payment),
                ];
            });

            if (($snapshot['already_refunded'] ?? false) === true) {
                return $snapshot;
            }

            $refundResult = $this->refundAlipayPayment(
                outTradeNo: (string) $snapshot['payment_no'],
                refundAmount: (float) $snapshot['refund_amount'],
                refundReason: (string) $snapshot['refund_reason'],
                tradeNo: (string) ($snapshot['trade_no'] ?? ''),
                outRequestNo: (string) $snapshot['out_request_no'],
            );

            return DB::transaction(function () use ($snapshot, $refundResult, $context) {
                $lockedOrder = Order::query()
                    ->lockForUpdate()
                    ->with('invoice')
                    ->findOrFail((int) $snapshot['order_id']);
                $payment = Payment::query()
                    ->lockForUpdate()
                    ->findOrFail((int) $snapshot['payment_id']);

                if ((int) $lockedOrder->status === OrderStatus::REFUNDED || (int) $payment->status === PaymentStatus::REFUNDED) {
                    $refund = (array) data_get((array) ($payment->callback_raw ?? []), 'refund', []);
                    if ($lockedOrder->invoice instanceof Invoice && (int) $lockedOrder->invoice->status !== InvoiceStatus::REFUNDED) {
                        $this->markInvoiceRefunded(
                            $lockedOrder->invoice,
                            $refund,
                            'alipay',
                            (float) ($refund['refund_amount'] ?? $refund['refund_fee'] ?? 0),
                            $context
                        );
                    }

                    if ((int) $lockedOrder->status !== OrderStatus::REFUNDED) {
                        $lockedOrder->forceFill(['status' => OrderStatus::REFUNDED])->save();
                    }

                    return [
                        'already_refunded' => true,
                        'order_id' => (int) $lockedOrder->id,
                        'payment_id' => (int) $payment->id,
                        'refund' => $refund,
                    ];
                }

                $refundRecord = [
                    'out_request_no' => (string) $snapshot['out_request_no'],
                    'refund_amount' => number_format((float) $snapshot['refund_amount'], 2, '.', ''),
                    'refund_reason' => (string) $snapshot['refund_reason'],
                    'trade_no' => (string) ($refundResult['trade_no'] ?? $payment->trade_no ?? ''),
                    'refund_fee' => number_format((float) ($refundResult['refund_fee'] ?? $snapshot['refund_amount']), 2, '.', ''),
                    'fund_change' => (string) ($refundResult['fund_change'] ?? ''),
                    'gmt_refund_pay' => (string) ($refundResult['gmt_refund_pay'] ?? ''),
                    'operator_id' => (int) ($context['operator_id'] ?? 0),
                    'operator_name' => (string) ($context['operator_name'] ?? ''),
                    'trace_id' => (string) ($context['trace_id'] ?? ''),
                    'refunded_at' => now()->format('Y-m-d H:i:s'),
                    'raw' => (array) ($refundResult['raw'] ?? []),
                ];

                $callbackRaw = (array) ($payment->callback_raw ?? []);
                $callbackRaw['refund'] = $refundRecord;

                $payment->forceFill([
                    'status' => PaymentStatus::REFUNDED,
                    'callback_raw' => $callbackRaw,
                ])->save();
                $this->syncProjection($payment);

                if ($lockedOrder->invoice instanceof Invoice) {
                    $this->markInvoiceRefunded(
                        $lockedOrder->invoice,
                        $refundRecord,
                        'alipay',
                        (float) $snapshot['refund_amount'],
                        $context
                    );
                }

                $lockedOrder->forceFill([
                    'status' => OrderStatus::REFUNDED,
                ])->save();
                $this->restoreOrderProductStockIfNeeded($lockedOrder);

                Log::info('[支付宝退款] 订单退款成功', [
                    'order_id' => $lockedOrder->id,
                    'payment_id' => $payment->id,
                    'payment_no' => $payment->payment_no,
                    'refund_amount' => $refundRecord['refund_amount'],
                    'out_request_no' => $refundRecord['out_request_no'],
                ]);

                return [
                    'already_refunded' => false,
                    'order_id' => (int) $lockedOrder->id,
                    'payment_id' => (int) $payment->id,
                    'refund' => $refundRecord,
                ];
            });
        }, '退款处理中，请勿重复提交');
    }

    /**
     * 后台发起账单退款到用户余额
     */
    public function refundInvoiceToBalance(User $user, Invoice $invoice, array $payload = [], array $context = []): array
    {
        $lockKey = "lock:refund:balance:invoice:{$invoice->id}";

        return $this->withLock($lockKey, 40, function () use ($user, $invoice, $payload, $context) {
            return DB::transaction(function () use ($user, $invoice, $payload, $context) {
                $lockedInvoice = Invoice::query()
                    ->lockForUpdate()
                    ->with(['order', 'payments'])
                    ->findOrFail($invoice->id);
                $lockedUser = User::query()
                    ->lockForUpdate()
                    ->findOrFail($user->id);

                throw_if((int) $lockedInvoice->user_id !== (int) $lockedUser->id, new BusinessException('账单与用户不匹配'));
                throw_if(
                    ! in_array((int) $lockedInvoice->status, [InvoiceStatus::PAID, InvoiceStatus::PARTIALLY_REFUNDED], true),
                    new BusinessException('当前账单状态不支持退款')
                );

                $order = $lockedInvoice->order;

                if ($order && (int) $order->status === OrderStatus::REFUNDED) {
                    return [
                        'already_refunded' => true,
                        'invoice_id' => (int) $lockedInvoice->id,
                        'payment_id' => 0,
                        'refund' => [],
                    ];
                }

                throw_if(
                    $order && ! in_array((int) $order->status, [OrderStatus::PAID, OrderStatus::COMPLETED], true),
                    new BusinessException('当前订单状态不支持退款')
                );

                $payment = $this->resolvePrimaryRefundablePayment($lockedInvoice);

                if ($payment instanceof Payment && (int) $payment->status === PaymentStatus::REFUNDED) {
                    $refund = (array) data_get((array) ($payment->callback_raw ?? []), 'refund', []);
                    if ((int) $lockedInvoice->status !== InvoiceStatus::REFUNDED) {
                        $this->markInvoiceRefunded(
                            $lockedInvoice,
                            $refund,
                            (string) ($refund['refund_method'] ?? 'balance'),
                            (float) ($refund['refund_amount'] ?? $refund['refund_fee'] ?? 0),
                            $context
                        );
                    }

                    return [
                        'already_refunded' => true,
                        'invoice_id' => (int) $lockedInvoice->id,
                        'payment_id' => (int) $payment->id,
                        'refund' => $refund,
                    ];
                }

                $refundableAmount = $this->remainingRefundableAmount($lockedInvoice, $payment);
                $refundAmount = round((float) ($payload['amount'] ?? $refundableAmount), 2);
                throw_if($refundAmount <= 0, new BusinessException('退款金额不正确'));
                throw_if($refundAmount - $refundableAmount > 0.00001, new BusinessException('退款金额超过原单可退金额'));

                $refundReason = trim((string) ($payload['remark'] ?? ''));
                if ($refundReason === '') {
                    $refundReason = '后台退回用户余额';
                }

                $refundMethod = trim((string) ($payload['refund_method'] ?? 'balance')) ?: 'balance';
                $refundMethodLabel = trim((string) ($payload['refund_method_label'] ?? ''));

                if ($refundMethodLabel === '') {
                    $refundMethodLabel = $refundMethod === 'original' ? '原路退款' : '退回余额';
                }

                $balanceAfter = $this->setUserBalance($lockedUser, $this->getUserBalance($lockedUser) + $refundAmount);
                $transaction = $this->createBalanceLog(
                    (int) $lockedUser->id,
                    FinanceLedgerEventType::INVOICE_REFUND,
                    $refundAmount,
                    $balanceAfter,
                    (int) $lockedInvoice->id,
                    '账单退款 '.(string) $lockedInvoice->invoice_no,
                    [
                        'operator' => (string) ($context['operator_name'] ?? ''),
                        'trace_id' => (string) ($context['trace_id'] ?? ''),
                    ],
                );

                $refundRecord = [
                    'refund_method' => $refundMethod,
                    'refund_method_label' => $refundMethodLabel,
                    'refund_amount' => number_format($refundAmount, 2, '.', ''),
                    'refund_reason' => $refundReason,
                    'trade_no' => (string) ($payment?->trade_no ?? ''),
                    'original_gateway' => $payment?->gatewayKey() ?? 'balance',
                    'original_gateway_label' => $payment instanceof Payment
                        ? $this->resolvePaymentGatewayLabel($payment->gatewayKey())
                        : '余额支付',
                    'operator_id' => (int) ($context['operator_id'] ?? 0),
                    'operator_name' => (string) ($context['operator_name'] ?? ''),
                    'trace_id' => (string) ($context['trace_id'] ?? ''),
                    'refunded_at' => now()->format('Y-m-d H:i:s'),
                ];

                $documents = $this->financeDocuments()->createBalanceRefundDocuments(
                    $lockedInvoice,
                    $payment,
                    $transaction,
                    $refundAmount,
                    $refundReason,
                    array_merge($context, ['operator_type' => $context['operator_type'] ?? 'admin']),
                );
                $isFullyRefunded = abs($refundAmount - $refundableAmount) <= 0.00001;

                if ($payment instanceof Payment) {
                    $callbackRaw = (array) ($payment->callback_raw ?? []);
                    $callbackRaw['refund'] = $refundRecord;
                    $callbackRaw['refunds'] = array_values(array_merge(
                        (array) ($callbackRaw['refunds'] ?? []),
                        [$refundRecord]
                    ));

                    $paymentPayload = [
                        'callback_raw' => $callbackRaw,
                    ];
                    if ($isFullyRefunded) {
                        $paymentPayload['status'] = PaymentStatus::REFUNDED;
                    }
                    $payment->forceFill($paymentPayload)->save();
                    $this->syncProjection($payment);
                }

                if ($isFullyRefunded) {
                    $this->markInvoiceRefunded($lockedInvoice, $refundRecord, $refundMethod, $refundAmount, $context);
                } else {
                    $this->markInvoicePartiallyRefunded(
                        $lockedInvoice,
                        $refundMethod,
                        $this->completedRefundAmount($lockedInvoice),
                        $context,
                    );
                }

                $scope = (array) ($payload['scope'] ?? ['order', 'payment']);

                if ($isFullyRefunded && $order && in_array('order', $scope, true)) {
                    $order->forceFill([
                        'status' => OrderStatus::REFUNDED,
                    ])->save();

                    $this->restoreOrderProductStockIfNeeded($order);
                }

                Log::info('[账单退款] 已退回用户余额', [
                    'invoice_id' => $lockedInvoice->id,
                    'invoice_no' => $lockedInvoice->invoice_no,
                    'payment_id' => $payment?->id,
                    'refund_amount' => $refundRecord['refund_amount'],
                    'user_id' => $lockedUser->id,
                ]);

                return [
                    'already_refunded' => false,
                    'invoice_id' => (int) $lockedInvoice->id,
                    'payment_id' => (int) ($payment?->id ?? 0),
                    'refund_id' => (int) $documents['refund']->id,
                    'refund_invoice_id' => (int) $documents['refund_invoice']->id,
                    'recharge_record_id' => $documents['recharge_record']?->id,
                    'refund' => $refundRecord,
                ];
            });
        }, '退款处理中，请勿重复提交');
    }

    private function refundOrderToBalance(
        Order $order,
        array $payload,
        array $context,
        string $refundMethod,
        string $refundMethodLabel,
    ): array {
        $order->loadMissing(['invoice', 'user']);

        throw_if(! $order->invoice instanceof Invoice, new BusinessException('订单未关联账单，无法退款'));
        throw_if(! $order->user instanceof User, new BusinessException('订单未关联用户，无法退款'));

        return $this->refundInvoiceToBalance($order->user, $order->invoice, array_merge($payload, [
            'refund_method' => $refundMethod,
            'refund_method_label' => $refundMethodLabel,
        ]), $context);
    }

    public function handlePaidInvoice(Invoice $invoice, ?string $traceId = null): void
    {
        $startedAt = microtime(true);
        $invoice = $invoice->fresh(['order.product']) ?? $invoice;

        if ((int) $invoice->status !== InvoiceStatus::PAID) {
            return;
        }

        $orderId = (int) ($invoice->order?->id ?? 0);
        $invoiceType = (string) ($invoice->type ?? $invoice->order?->type ?? '');

        if ($this->shouldTrackFulfillment($invoice, $invoiceType)) {
            $invoice = $this->markFulfillmentPending($invoice, $invoiceType);
        }

        $latency = [
            'coupon_sync_schedule_ms' => 0,
            'admin_notify_schedule_ms' => 0,
            'business_flow_dispatch_ms' => 0,
        ];

        // 开通 / 履约：优先走 Order 路径（包含完整上游开通链路），降级走 Invoice-only
        $stepStartedAt = microtime(true);
        if ($orderId > 0) {
            $this->paidOrderBusinessFlowDispatcher->dispatchPaidInvoice($invoice, $traceId);
        } else {
            if ($this->provisionPaidInvoice($invoice)) {
                $this->clearFulfillmentPending($invoice);
            }

            if (! in_array($invoiceType, ['renew', 'upgrade'], true)) {
                $this->dispatchInvoiceOnlyReferralReward($invoice, $traceId);
            }
        }
        $latency['business_flow_dispatch_ms'] = $this->elapsedMilliseconds($stepStartedAt);

        // 优惠券同步统一走 invoice 状态机，具体执行交给 coupon 队列。
        $stepStartedAt = microtime(true);
        try {
            $this->couponService->syncInvoiceCouponUsageAfterResponse($invoice);
        } catch (\Throwable $exception) {
            Log::warning('[购买链路] 支付成功后优惠券同步调度失败', [
                'invoice_id' => (int) $invoice->id,
                'invoice_no' => (string) ($invoice->invoice_no ?? ''),
                'trace_id' => (string) ($traceId ?? ''),
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
        $latency['coupon_sync_schedule_ms'] = $this->elapsedMilliseconds($stepStartedAt);

        // 管理员通知：统一走 invoice 入口
        $stepStartedAt = microtime(true);
        try {
            $this->adminOrderNotificationService->notifyInvoicePaidAfterResponse($invoice);
        } catch (\Throwable $exception) {
            Log::warning('[购买链路] 支付成功后管理员通知调度失败', [
                'invoice_id' => (int) $invoice->id,
                'invoice_no' => (string) ($invoice->invoice_no ?? ''),
                'trace_id' => (string) ($traceId ?? ''),
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
        $latency['admin_notify_schedule_ms'] = $this->elapsedMilliseconds($stepStartedAt);

        Log::info('[购买链路] 支付成功后处理耗时', array_merge($latency, [
            'invoice_id' => (int) $invoice->id,
            'invoice_no' => (string) ($invoice->invoice_no ?? ''),
            'invoice_type' => $invoiceType,
            'order_id' => $orderId,
            'trace_id' => (string) ($traceId ?? ''),
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
        ]));
    }

    /**
     * 直接根据账单执行开通履约（无订单场景）
     */
    private function provisionPaidInvoice(Invoice $invoice): bool
    {
        if ((int) $invoice->status !== InvoiceStatus::PAID) {
            return false;
        }

        try {
            $invoiceType = (string) ($invoice->type ?? '');
            if ($invoiceType === 'renew') {
                $service = $this->serviceRenewService->processPaidRenewInvoice($invoice);

                return $this->serviceRenewService->isRenewInvoiceFulfilled($invoice, $service);
            }

            if ($invoiceType === 'upgrade') {
                app(ServiceTrafficPackageService::class)
                    ->processPaidTrafficPackageInvoice($invoice);

                return true;
            }

            $this->provisionService->processPaidInvoice($invoice);

            return false;
        } catch (\Throwable $exception) {
            Log::error('[支付后自动开通] 基于账单的开通失败', [
                'invoice_id' => $invoice->id,
                'invoice_no' => $invoice->invoice_no ?? '',
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * 对无订单的账单触发推荐奖励（after-response / 同步兜底）
     */
    private function dispatchInvoiceOnlyReferralReward(Invoice $invoice, ?string $traceId): void
    {
        $callback = function () use ($invoice, $traceId): void {
            try {
                $this->referralService->rewardForPaidInvoice($invoice, $traceId);
            } catch (\Throwable $exception) {
                Log::error('[支付后推荐奖励] 基于账单的奖励处理失败', [
                    'invoice_id' => $invoice->id,
                    'invoice_no' => $invoice->invoice_no ?? '',
                    'trace_id' => $traceId,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);
            }
        };

        if (app()->runningInConsole()) {
            $callback();

            return;
        }

        app()->terminating($callback);
    }

    public function processPaidOrderReferralRewardById(int $orderId, ?string $traceId = null): void
    {
        $order = $this->loadPayableOrderForBusinessFlow($orderId);

        if (! $order) {
            return;
        }

        try {
            $this->referralService->rewardForPaidOrder($order, $traceId);
        } catch (\Throwable $exception) {
            Log::error('[支付后推荐奖励] 处理失败', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'trace_id' => $traceId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    public function processPaidOrderFulfillmentById(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        try {
            $lock = Cache::lock("lock:paid-order-fulfillment:{$orderId}", 1500);
            $acquired = $lock->get();
        } catch (\Throwable $exception) {
            Log::warning('[支付后自动开通] 订单履约锁不可用，降级继续履约', [
                'order_id' => $orderId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            $this->fulfillPaidOrderById($orderId);

            return;
        }

        if (! $acquired) {
            Log::info('[支付后自动开通] 已有同订单履约正在执行，跳过重复请求', [
                'order_id' => $orderId,
            ]);

            return;
        }

        try {
            $this->fulfillPaidOrderById($orderId);
        } finally {
            $lock->release();
        }
    }

    private function fulfillPaidOrderById(int $orderId): void
    {
        $order = $this->loadPayableOrderForBusinessFlow($orderId);

        if (! $order || ! $order->invoice) {
            return;
        }

        $fulfilled = $this->provisionPaidOrder($order->invoice);
        if (! $fulfilled) {
            throw new BusinessException('支付后履约未完成，等待后续重试');
        }

        $this->clearFulfillmentPending($order->invoice);
    }

    public function processPaidInvoiceAdminNotifyById(int $invoiceId): void
    {
        $invoice = Invoice::query()->find($invoiceId);

        if (! $invoice instanceof Invoice || (int) $invoice->status !== InvoiceStatus::PAID) {
            return;
        }

        $this->adminOrderNotificationService->notifyInvoicePaidAfterResponse($invoice);
    }

    public function processPaidInvoiceCouponSyncById(int $invoiceId): void
    {
        $invoice = Invoice::query()->find($invoiceId);

        if (! $invoice instanceof Invoice || (int) $invoice->status !== InvoiceStatus::PAID) {
            return;
        }

        $this->couponService->syncInvoiceCouponUsage($invoice);
    }

    private function provisionPaidOrder(?Invoice $invoice): bool
    {
        $order = $invoice?->order;

        if (! $order || (int) $invoice->status !== InvoiceStatus::PAID) {
            return true;
        }

        try {
            if ($order->type === 'renew') {
                $service = $this->serviceRenewService->processPaidRenewOrder($order);
                if (! $this->serviceRenewService->isRenewInvoiceFulfilled($invoice, $service)) {
                    return false;
                }

                $this->notifyOrderPaid($invoice, 'renew');

                return true;
            }

            if ($order->type === 'upgrade') {
                $upgradeKind = (string) data_get($order->config_pricing_snapshot ?? [], 'meta.kind', '');

                if ($upgradeKind === 'host_upgrade') {
                    app(ServiceUpgradeService::class)
                        ->processPaidUpgradeOrder($order);
                } else {
                    app(ServiceTrafficPackageService::class)
                        ->processPaidTrafficPackageOrder($order);
                }
                $this->notifyOrderPaid($invoice, 'upgrade');

                return true;
            }

            $service = $this->provisionService->processPaidOrder($order);
            if ($service === null) {
                return true;
            }

            if ((int) $order->status !== OrderStatus::COMPLETED) {
                $provisionData = is_array($service->provision_data ?? null) ? $service->provision_data : [];

                return empty($provisionData['provision_error']);
            }

            $this->notifyOrderPaid($invoice, 'new');

            return true;
        } catch (\Throwable $exception) {
            Log::error('[支付后自动开通] 调用开通服务失败', [
                'invoice_id' => $invoice->id,
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    private function shouldTrackFulfillment(Invoice $invoice, string $invoiceType): bool
    {
        if ($invoiceType === 'renew') {
            return true;
        }

        $order = $invoice->order;
        if ($invoiceType !== 'new' || ! $order instanceof Order) {
            return false;
        }

        $product = $order->product;

        return $product instanceof Product && (int) $product->auto_setup === 1;
    }

    private function markFulfillmentPending(Invoice $invoice, string $invoiceType): Invoice
    {
        $configSnapshot = is_array($invoice->config_snapshot ?? null) ? $invoice->config_snapshot : [];
        if (! empty($configSnapshot['fulfillment_pending']) || ! empty($configSnapshot['fulfillment_cleared_at'])) {
            return $invoice;
        }

        $configSnapshot['fulfillment_pending'] = true;
        $configSnapshot['fulfillment_type'] = $invoiceType;

        $invoice->forceFill(['config_snapshot' => $configSnapshot])->saveQuietly();

        return $invoice->fresh(['order.product']) ?? $invoice;
    }

    private function clearFulfillmentPending(Invoice $invoice): void
    {
        $freshInvoice = $invoice->fresh();
        if (! $freshInvoice instanceof Invoice) {
            return;
        }

        $configSnapshot = is_array($freshInvoice->config_snapshot ?? null)
            ? $freshInvoice->config_snapshot
            : [];

        if (empty($configSnapshot['fulfillment_pending'])) {
            return;
        }

        $configSnapshot['fulfillment_pending'] = false;
        $configSnapshot['fulfillment_cleared_at'] = now()->toDateTimeString();

        $freshInvoice->forceFill(['config_snapshot' => $configSnapshot])->saveQuietly();
    }

    /**
     * 开通成功后写一条「订购提醒」站内信。不影响主流程，异常已在服务内吞掉。
     */
    private function notifyOrderPaid(Invoice $invoice, string $orderType): void
    {
        $userId = (int) ($invoice->user_id ?? $invoice->order?->user_id ?? 0);
        if ($userId <= 0) {
            return;
        }

        $productName = (string) ($invoice->order?->display_product_name ?? '您的服务');
        $title = match ($orderType) {
            'renew' => '续费成功',
            'upgrade' => '升级/加购成功',
            default => '开通成功',
        };

        $serviceId = (int) ($invoice->order?->service_id ?? $invoice->service?->id ?? 0);
        $link = $serviceId > 0 ? '/client/services/'.$serviceId : '/client/services';

        app(UserNotificationService::class)->create(
            $userId,
            UserNotificationType::ORDER_PAID,
            $title,
            "「{$productName}」已处理完成，账单号 {$invoice->invoice_no}。",
            $link,
            [
                'invoice_id' => (int) $invoice->id,
                'order_id' => (int) ($invoice->order?->id ?? 0),
                'order_type' => $orderType,
            ]
        );
    }

    private function loadPayableOrderForBusinessFlow(int $orderId): ?Order
    {
        $relations = ['invoice', 'product.supplier', 'service'];

        static $hasUserReferrals = null;
        if ($hasUserReferrals === null) {
            $hasUserReferrals = Schema::hasTable('user_referrals');
        }
        if ($hasUserReferrals) {
            $relations[] = 'user.referralProfile';
        } else {
            $relations[] = 'user';
        }

        $order = Order::query()
            ->with($relations)
            ->find($orderId);

        if (! $order instanceof Order) {
            return null;
        }

        $invoice = $order->invoice;
        if (! $invoice instanceof Invoice || (int) $invoice->status !== InvoiceStatus::PAID) {
            return null;
        }

        if (! in_array((int) $order->status, [OrderStatus::PAID, OrderStatus::PROCESSING, OrderStatus::COMPLETED], true)) {
            return null;
        }

        return $order;
    }

    /**
     * 关闭同一账单下的其他 PENDING 支付。
     *
     * $restoreReservedBalance 必须由调用方显式声明，因为两种语境的正确行为恰好相反：
     *
     * - **账单已付**（invoice_paid_by_*）传 false。组合支付预扣的余额早已计入
     *   invoice.paid_amount 并被账单消费——账单 100 元、A 垫 30 之后只需再付 70，
     *   那 30 元是换到了服务的。此时再退一次就是重复退款。
     * - **账单作废**（窗口过期）传 true。账单什么都没换到，预扣的余额必须回到用户账上。
     *
     * 原实现在两种语境下一律只置 FAILED，于是账单作废时丢钱：账单 100 元，用户先垫 30
     * 建 A(70)、再垫 20 建 B(50)（第 984 行的复用条件是 amount 相等，所以两笔 mix 能
     * 并存），窗口过期后扫 B 的码付 50 —— 回调退 B 的 20、50 元转入余额、账单 CANCELLED，
     * 而 A 在这里被直接置 FAILED，**A 垫付的 30 元凭空消失**。此后账单已是 CANCELLED，
     * CheckoutService::cancel（只收 UNPAID/OVERDUE）与 OrderService::cancel（要求订单仍
     * PENDING，而过期路径已把订单取消）都进不去，routes/console.php 也没有孤儿 PENDING
     * 支付对账任务，这笔钱永久卡死。
     *
     * 参数刻意不给默认值：漏传会被 PHP 当场拦下；若默认成 true，将来新增的"账单已付"
     * 调用点会静默变成可反复触发的重复退款，那是比丢钱更糟的方向。
     */
    private function closeOtherPendingPayments(
        Invoice $invoice,
        int $excludePaymentId,
        string $reason,
        bool $restoreReservedBalance,
    ): void {
        $query = Payment::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', PaymentStatus::PENDING);

        if ($excludePaymentId > 0) {
            $query->where('id', '!=', $excludePaymentId);
        }

        $pendingPayments = $query->lockForUpdate()->get();

        foreach ($pendingPayments as $pendingPayment) {
            // 与 CheckoutService::cancel / OrderService::cancel 同一套模式：恢复成功即
            // 视为已处置（restoreReservedMixBalance 内部会把该笔置 FAILED），非组合支付
            // 或已无预留时返回 false，落到下面的通用关闭分支。
            if ($restoreReservedBalance && $this->restoreReservedMixBalance($pendingPayment, [
                'closed_reason' => $reason,
                'trace_id' => (string) ($invoice->trace_id ?? ''),
            ])) {
                continue;
            }

            $callbackRaw = (array) ($pendingPayment->callback_raw ?? []);
            $callbackRaw['closed_reason'] = $reason;

            $pendingPayment->forceFill([
                'status' => PaymentStatus::FAILED,
                'callback_raw' => $callbackRaw,
            ])->save();
            $this->syncProjection($pendingPayment);
        }
    }

    private function markExpiredInvoicePaymentFailed(Invoice $invoice, Payment $payment, string $tradeNo, array $raw): void
    {
        if (! $this->restoreReservedMixBalance($payment, [
            'closed_reason' => 'payment_window_expired',
            'suppress_logs' => true,
        ])) {
            $payment->forceFill([
                'trade_no' => $tradeNo,
                'status' => PaymentStatus::FAILED,
                'callback_raw' => array_merge($raw, [
                    'payment_window_expired' => true,
                    'ignored_business_update' => true,
                ]),
            ])->save();
            $this->syncProjection($payment);
        }

        $invoice->forceFill(['status' => InvoiceStatus::CANCELLED])->save();
        $this->cancelLinkedPendingOrderForInvoice($invoice);
        $this->couponService->releaseInvoiceCoupon($invoice);
        $this->restoreStockForCancelledInvoice($invoice);
        // 账单作废：兄弟支付预扣的余额没换到任何服务，必须退回用户。
        $this->closeOtherPendingPayments($invoice, (int) $payment->id, 'payment_window_expired', true);
    }

    private function cancelExpiredInvoiceAfterCapturedPayment(Invoice $invoice, Payment $payment): void
    {
        $invoice->forceFill(['status' => InvoiceStatus::CANCELLED])->save();
        $this->cancelLinkedPendingOrderForInvoice($invoice);
        $this->couponService->releaseInvoiceCoupon($invoice);
        $this->restoreStockForCancelledInvoice($invoice);
        // 账单作废：兄弟支付预扣的余额没换到任何服务，必须退回用户。
        $this->closeOtherPendingPayments($invoice, (int) $payment->id, 'payment_window_expired', true);
    }

    private function restoreConflictingMixBalancesForCapturedPayment(
        Invoice $invoice,
        Payment $capturedPayment,
        string $reason,
        array $context = [],
    ): Invoice {
        $paymentAmount = round((float) $capturedPayment->amount, 2);
        if ($paymentAmount <= $this->invoicePayableAmount($invoice) + 0.0001) {
            return $invoice;
        }

        $pendingPayments = Payment::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', PaymentStatus::PENDING)
            ->where('id', '!=', $capturedPayment->id)
            ->lockForUpdate()
            ->get();

        foreach ($pendingPayments as $pendingPayment) {
            if ($this->resolveMixBalanceAmount($pendingPayment) <= 0) {
                continue;
            }

            $this->restoreReservedMixBalance($pendingPayment, [
                'closed_reason' => $reason,
                'trace_id' => (string) ($context['trace_id'] ?? ''),
            ]);
        }

        return Invoice::query()
            ->lockForUpdate()
            ->with('order')
            ->findOrFail((int) $invoice->id);
    }

    private function creditCapturedPaymentToBalance(
        Payment $payment,
        Invoice $invoice,
        string $tradeNo,
        array $raw,
        string $reason,
    ): void {
        $payment->refresh();
        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return;
        }

        $traceId = (string) ($raw['trace_id'] ?? $payment->trace_id ?? '');
        $lockedUser = User::query()
            ->lockForUpdate()
            ->findOrFail((int) $payment->user_id);
        $balanceAfter = $this->setUserBalance($lockedUser, $this->getUserBalance($lockedUser) + $amount);

        $this->createBalanceLog(
            (int) $lockedUser->id,
            FinanceLedgerEventType::RECHARGE,
            $amount,
            $balanceAfter,
            (int) $payment->id,
            '异常支付转入余额 '.(string) $payment->payment_no,
            [
                'trace_id' => $traceId,
            ]
        );

        $reasonFlags = match ($reason) {
            'invoice_already_paid' => ['duplicate_paid' => true],
            'cancelled_invoice' => ['cancelled_invoice' => true],
            'payment_window_expired' => ['payment_window_expired' => true],
            default => ['payable_amount_mismatch' => true],
        };

        $callbackRaw = array_merge((array) ($payment->callback_raw ?? []), $raw, $reasonFlags, [
            'abnormal_invoice_payment' => true,
            'credited_to_balance' => true,
            'credit_reason' => $reason,
            'credited_amount' => number_format($amount, 2, '.', ''),
            'ignored_business_update' => true,
            'invoice_id' => (int) $invoice->id,
        ]);

        $payment->forceFill([
            'trade_no' => $tradeNo,
            'status' => PaymentStatus::SUCCESS,
            'callback_raw' => $callbackRaw,
            'paid_at' => now(),
            'trace_id' => $traceId !== '' ? $traceId : $payment->trace_id,
        ])->save();
        $this->syncProjection($payment);
    }

    private function invoicePayableAmount(Invoice $invoice): float
    {
        return round(max((float) $invoice->amount - (float) ($invoice->paid_amount ?? 0), 0), 2);
    }

    private function restoreStockForCancelledInvoice(Invoice $invoice): void
    {
        if (! in_array((string) $invoice->type, [InvoiceType::NEW_PURCHASE, 'normal'], true) || ! $invoice->product_id) {
            return;
        }

        $product = Product::query()->lockForUpdate()->find((int) $invoice->product_id);
        if ($product instanceof Product && (int) $product->stock >= 0) {
            $product->increment('stock', max((int) ($invoice->quantity ?? 1), 1));
        }
    }

    private function cancelLinkedPendingOrderForInvoice(Invoice $invoice): void
    {
        $orderId = (int) ($invoice->order_id ?? $invoice->order?->id ?? 0);
        $order = $orderId > 0 ? Order::query()->lockForUpdate()->find($orderId) : null;

        if ($order instanceof Order && (int) $order->status === OrderStatus::PENDING) {
            $order->forceFill(['status' => OrderStatus::CANCELLED])->save();
        }
    }

    private function closeOtherPendingPaymentsForOrder(Order $order, int $excludePaymentId, string $reason): void
    {
        $invoiceId = (int) ($order->invoice?->id ?? 0);

        $query = Payment::query()
            ->where('status', PaymentStatus::PENDING)
            ->where(function ($query) use ($order, $invoiceId) {
                $query->where('order_id', $order->id);

                if ($invoiceId > 0) {
                    $query->orWhere('invoice_id', $invoiceId);
                }
            });

        if ($excludePaymentId > 0) {
            $query->where('id', '!=', $excludePaymentId);
        }

        $pendingPayments = $query->lockForUpdate()->get();

        foreach ($pendingPayments as $pendingPayment) {
            $callbackRaw = (array) ($pendingPayment->callback_raw ?? []);
            $callbackRaw['closed_reason'] = $reason;

            $pendingPayment->forceFill([
                'status' => PaymentStatus::FAILED,
                'callback_raw' => $callbackRaw,
            ])->save();
            $this->syncProjection($pendingPayment);
        }
    }

    private function resolveOrderPayableAmount(Order $order): float
    {
        return round(
            max(
                (float) ($order->amount ?? 0)
                - (float) ($order->discount ?? 0)
                - (float) ($order->paid_amount ?? 0),
                0
            ),
            2
        );
    }

    private function isAutoRenewBalancePayment(Order $order, array $context = []): bool
    {
        if (! empty($context['auto_renew'])) {
            return true;
        }

        $configSnapshot = is_array($order->config_snapshot ?? null) ? $order->config_snapshot : [];

        return ! empty($configSnapshot['auto_renew']);
    }

    private function resolveRefundableAlipayPayment(Invoice $invoice): ?Payment
    {
        return $this->resolvePrimaryRefundablePayment($invoice, [PaymentGatewayCode::ALIPAY]);
    }

    /**
     * 当前仅支持单次全额退款，使用固定退款单号保证后台重复点击时幂等。
     */
    private function buildAlipayRefundRequestNo(Payment $payment): string
    {
        return 'RFD'.$payment->payment_no;
    }

    private function resolveGateway(string $gateway): PaymentGatewayInterface
    {
        return $this->gatewayOperations()->gateway($gateway);
    }

    /**
     * 支付记录必须属于本次回调所声明的网关。
     *
     * 缺这道校验时，回调处理只按 payment_no 查单，任一网关的密钥就等于"确认任意渠道
     * 支付"的权限：站点同时启用 alipay 与 yi_pay，攻击者仅凭易支付的 MD5 商户密钥即可
     * 向易支付回调端点提交一笔 **alipay 渠道** 的 PENDING 单号（金额匹配即可），两道
     * 验签都用易支付的密钥、因此都会通过，账单随即被标记已付并触发开通。商户号那道
     * 校验也挡不住——`$merchantId !== ''` 才比对，攻击者省掉 pid/app_id 字段即跳过。
     *
     * 两侧都过 normalize()：`payments.gateway_key` 存的是归一化值，而 PaymentGatewayCode
     * 里保留着 alipay_f2f / ali_pay / yi_pay 三个别名映射。当前各插件的 key() 返回的都已
     * 是归一化值（目录名 ali_pay，key() 返回 'alipay'），PaymentGatewayRegistry 又按
     * key() 索引，所以能走到这里的 $gateway 恒等于归一化值、normalize 是幂等的；但一旦
     * 某个插件的 key() 改回别名形态（常量 ALIPAY_F2F_PLUGIN 正是为此保留），裸比字符串
     * 就会把该网关的全部正常回调判为不匹配而拒付。
     */
    private function paymentBelongsToGateway(Payment $payment, string $gateway): bool
    {
        $expected = PaymentGatewayCode::normalize(trim($gateway));
        $actual = $payment->gatewayKey();

        return $expected !== '' && $actual !== '' && $expected === $actual;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function paymentCreatePayload(array $payload): array
    {
        return $this->gatewayOperations()->paymentCreatePayload($payload);
    }

    private function ensurePaymentGatewayAudit(Payment $payment, string $gateway, ?string $traceId = null): void
    {
        $this->gatewayOperations()->ensurePaymentAudit($payment, $gateway, $traceId);
    }

    private function paymentGatewayBindingResolver(): PaymentGatewayBindingResolver
    {
        return $this->paymentGatewayBindingResolver ??= app(PaymentGatewayBindingResolver::class);
    }

    private function gatewayOperations(): PaymentGatewayOperationService
    {
        return $this->gatewayOperations ??= new PaymentGatewayOperationService(
            $this->paymentGatewayManager,
            $this->paymentGatewayBindingResolver(),
        );
    }

    /**
     * @deprecated 使用 resolveGateway(PaymentGatewayCode::ALIPAY) 替代
     */
    private function alipayGateway(): PaymentGatewayInterface
    {
        return $this->resolveGateway(PaymentGatewayCode::ALIPAY);
    }

    private function precreateGatewayPayment(string $gateway, string $outTradeNo, float $amount, string $subject, ?string $timeoutExpress = null, array $context = []): array
    {
        return $this->gatewayOperations()->precreate($gateway, $outTradeNo, $amount, $subject, $timeoutExpress, $context);
    }

    /**
     * @deprecated 使用 precreateGatewayPayment() 替代
     */
    private function precreateAlipayPayment(string $outTradeNo, float $amount, string $subject, ?string $timeoutExpress = null): array
    {
        return $this->precreateGatewayPayment(PaymentGatewayCode::ALIPAY, $outTradeNo, $amount, $subject, $timeoutExpress);
    }

    private function resolvePaymentTimeoutExpress(Payment $payment): string
    {
        throw_if($this->isPaymentWindowExpired($payment), new BusinessException('支付时间已过期，请重新发起支付'));

        $remainingSeconds = $this->paymentDeadline($payment)->diffInSeconds(CarbonImmutable::now(), true);

        return max(1, (int) ceil($remainingSeconds / 60)).'m';
    }

    private function isPaymentWindowExpired(Payment $payment): bool
    {
        return $this->paymentDeadline($payment)->lessThanOrEqualTo(CarbonImmutable::now());
    }

    private function paymentDeadline(Payment $payment): CarbonImmutable
    {
        $createdAt = $payment->created_at;
        $base = $createdAt instanceof \DateTimeInterface
            ? CarbonImmutable::instance($createdAt)
            : ($createdAt ? CarbonImmutable::parse((string) $createdAt) : CarbonImmutable::now());

        return $base->addSeconds(CheckoutSecurityService::paymentSessionTtlSeconds());
    }

    private function resolveOrderPaymentTimeoutExpress(Order $order): string
    {
        throw_if(
            $this->orderPaymentDeadline($order)->lessThanOrEqualTo(CarbonImmutable::now()),
            new BusinessException('支付时间已过期，请重新发起支付')
        );

        $remainingSeconds = $this->orderPaymentDeadline($order)->diffInSeconds(CarbonImmutable::now(), true);

        return max(1, (int) ceil($remainingSeconds / 60)).'m';
    }

    private function orderPaymentDeadline(Order $order): CarbonImmutable
    {
        $createdAt = $order->created_at;
        $base = $createdAt instanceof \DateTimeInterface
            ? CarbonImmutable::instance($createdAt)
            : ($createdAt ? CarbonImmutable::parse((string) $createdAt) : CarbonImmutable::now());

        return $base->addSeconds(CheckoutSecurityService::paymentSessionTtlSeconds());
    }

    private function resolveInvoicePaymentTimeoutExpress(Invoice $invoice): string
    {
        $remainingSeconds = $this->invoicePaymentDeadline($invoice)->diffInSeconds(CarbonImmutable::now(), true);

        throw_if($this->invoicePaymentWindowExpired($invoice), new BusinessException('账单支付时间已过期，请重新创建账单'));

        return max(1, (int) ceil($remainingSeconds / 60)).'m';
    }

    private function invoicePaymentWindowExpired(Invoice $invoice): bool
    {
        return $this->invoicePaymentDeadline($invoice)->lessThanOrEqualTo(CarbonImmutable::now());
    }

    private function invoicePaymentDeadline(Invoice $invoice): CarbonImmutable
    {
        $createdAt = $invoice->created_at;
        $base = $createdAt instanceof \DateTimeInterface
            ? CarbonImmutable::instance($createdAt)
            : ($createdAt ? CarbonImmutable::parse((string) $createdAt) : CarbonImmutable::now());

        return $base->addSeconds(CheckoutSecurityService::paymentSessionTtlSeconds());
    }

    private function queryGatewayPayment(string $gateway, string $outTradeNo): array
    {
        return $this->gatewayOperations()->query($gateway, $outTradeNo);
    }

    /**
     * @deprecated 使用 queryGatewayPayment() 替代
     */
    private function queryAlipayPayment(string $outTradeNo): array
    {
        return $this->queryGatewayPayment(PaymentGatewayCode::ALIPAY, $outTradeNo);
    }

    /**
     * 支付业务仍保留旧数组结构，网关层只负责 provider 请求和响应归一化。
     */
    private function refundGatewayPayment(
        string $gateway,
        string $outTradeNo,
        float $refundAmount,
        string $refundReason,
        ?string $tradeNo,
        string $outRequestNo,
    ): array {
        return $this->gatewayOperations()->refund(
            $gateway,
            $outTradeNo,
            $refundAmount,
            $refundReason,
            $tradeNo,
            $outRequestNo,
        );
    }

    /**
     * @deprecated 使用 refundGatewayPayment() 替代
     */
    private function refundAlipayPayment(
        string $outTradeNo,
        float $refundAmount,
        string $refundReason,
        ?string $tradeNo,
        string $outRequestNo,
    ): array {
        return $this->refundGatewayPayment(PaymentGatewayCode::ALIPAY, $outTradeNo, $refundAmount, $refundReason, $tradeNo, $outRequestNo);
    }

    private function resolvePrimaryRefundablePayment(Invoice $invoice, ?array $gateways = null): ?Payment
    {
        $payments = $invoice->relationLoaded('payments')
            ? $invoice->payments
            : Payment::query()
                ->where('invoice_id', $invoice->id)
                ->whereIn('status', [PaymentStatus::SUCCESS, PaymentStatus::REFUNDED])
                ->orderByDesc('id')
                ->get();

        if (is_array($gateways) && $gateways !== []) {
            $payments = $payments
                ->filter(fn (Payment $payment) => in_array($payment->gatewayKey(), $gateways, true))
                ->values();
        }

        return $payments
            ->first(fn (Payment $payment) => ! (bool) data_get((array) ($payment->callback_raw ?? []), 'duplicate_paid', false)
                && in_array((int) $payment->status, [PaymentStatus::SUCCESS, PaymentStatus::REFUNDED], true))
            ?? $payments->first(fn (Payment $payment) => in_array((int) $payment->status, [PaymentStatus::SUCCESS, PaymentStatus::REFUNDED], true));
    }

    public function restoreReservedMixBalance(Payment $payment, array $context = []): bool
    {
        return DB::transaction(function () use ($payment, $context) {
            $lockedPayment = Payment::query()
                ->lockForUpdate()
                ->find((int) $payment->id);

            if (! $lockedPayment instanceof Payment) {
                return false;
            }

            $balanceAmount = $this->resolveMixBalanceAmount($lockedPayment);
            if ($balanceAmount <= 0 || (int) $lockedPayment->status !== PaymentStatus::PENDING) {
                return false;
            }

            $invoice = $lockedPayment->invoice_id
                ? Invoice::query()->lockForUpdate()->with('order')->find((int) $lockedPayment->invoice_id)
                : null;
            $lockedUser = User::query()
                ->lockForUpdate()
                ->find((int) $lockedPayment->user_id);

            if (! $invoice instanceof Invoice || ! $lockedUser instanceof User) {
                return false;
            }

            $markPaymentFailed = array_key_exists('mark_payment_failed', $context)
                ? (bool) $context['mark_payment_failed']
                : true;
            $preserveInvoicePaidAmount = (bool) ($context['preserve_invoice_paid_amount'] ?? false);

            $balanceAfter = $this->setUserBalance($lockedUser, $this->getUserBalance($lockedUser) + $balanceAmount);
            $suppressLogs = (bool) ($context['suppress_logs'] ?? false);
            $this->createBalanceLog(
                (int) $lockedUser->id,
                FinanceLedgerEventType::INVOICE_REFUND,
                $balanceAmount,
                $balanceAfter,
                (int) $invoice->id,
                $suppressLogs
                    ? '组合支付预下单失败恢复余额 '.(string) $invoice->invoice_no
                    : '组合支付取消退回余额 '.(string) $invoice->invoice_no,
                [
                    'trace_id' => (string) ($context['trace_id'] ?? ''),
                ],
            );

            if (! $preserveInvoicePaidAmount) {
                $nextInvoicePaidAmount = number_format(
                    max(round((float) ($invoice->paid_amount ?? 0) - $balanceAmount, 2), 0),
                    2,
                    '.',
                    ''
                );
                $invoice->forceFill([
                    'paid_amount' => $nextInvoicePaidAmount,
                ])->save();

                if ($invoice->order instanceof Order) {
                    $nextOrderPaidAmount = number_format(
                        max(round((float) ($invoice->order->paid_amount ?? 0) - $balanceAmount, 2), 0),
                        2,
                        '.',
                        ''
                    );
                    $invoice->order->forceFill([
                        'paid_amount' => $nextOrderPaidAmount,
                    ])->save();
                }
            }

            $callbackRaw = (array) ($lockedPayment->callback_raw ?? []);
            $callbackRaw['balance_restored'] = true;
            $callbackRaw['balance_restored_amount'] = number_format($balanceAmount, 2, '.', '');
            $callbackRaw['closed_reason'] = (string) ($context['closed_reason'] ?? 'mix_balance_restored');
            $callbackRaw['trace_id'] = (string) ($context['trace_id'] ?? ($callbackRaw['trace_id'] ?? ''));

            $paymentPayload = [
                'callback_raw' => $callbackRaw,
            ];
            if ($markPaymentFailed) {
                $paymentPayload['status'] = PaymentStatus::FAILED;
            }

            $lockedPayment->forceFill($paymentPayload)->save();
            $this->syncProjection($lockedPayment);

            return true;
        });
    }

    private function resolveMixBalanceAmount(Payment $payment): float
    {
        $callbackRaw = $this->resolvePaymentRawCallback($payment, true);
        if (! (bool) data_get($callbackRaw, 'mix_payment', false)) {
            return 0.0;
        }

        return round(max((float) data_get($callbackRaw, 'balance_amount', 0), 0), 2);
    }

    private function resolvePaymentRawCallback(Payment $payment, bool $allowAuditMirror = false): array
    {
        if ($allowAuditMirror) {
            $raw = $payment->getRawOriginal('callback_raw');
            $decoded = VersionedJson::decodeToArray($raw);
            if ($decoded !== null) {
                return VersionedJson::paymentCallback($decoded, 'payment');
            }
        }

        return (array) ($payment->callback_raw ?? []);
    }

    private function resolveBalanceRefundableAmount(Invoice $invoice, ?Payment $payment): float
    {
        $mixBalanceAmount = $payment instanceof Payment ? $this->resolveMixBalanceAmount($payment) : 0.0;
        if ($mixBalanceAmount > 0 || $this->invoiceHasBalancePayment($invoice)) {
            return round(max((float) ($invoice->paid_amount ?? 0), (float) ($invoice->amount ?? 0)), 2);
        }

        return $payment instanceof Payment ? round((float) $payment->amount, 2) : 0.0;
    }

    private function invoiceHasBalancePayment(Invoice $invoice): bool
    {
        return AccountTransaction::query()
            ->where('user_id', (int) $invoice->user_id)
            ->where('event_type', FinanceLedgerEventType::INVOICE_PAYMENT)
            ->where(function ($query) use ($invoice): void {
                $query->where(function ($sourceQuery) use ($invoice): void {
                    $sourceQuery
                        ->where('source_type', 'invoice')
                        ->where('source_id', (int) $invoice->id);
                });

                if ((int) ($invoice->order_id ?? 0) > 0) {
                    $query->orWhere(function ($sourceQuery) use ($invoice): void {
                        $sourceQuery
                            ->where('source_type', 'invoice')
                            ->where('source_id', (int) $invoice->order_id);
                    });
                }
            })
            ->exists();
    }

    private function remainingRefundableAmount(Invoice $invoice, ?Payment $payment): float
    {
        $refundedAmount = $this->completedRefundAmount($invoice);

        return round(max($this->resolveBalanceRefundableAmount($invoice, $payment) - $refundedAmount, 0), 2);
    }

    private function completedRefundAmount(Invoice $invoice): float
    {
        return round((float) Refund::query()
            ->where('invoice_id', (int) $invoice->id)
            ->where('status', Refund::STATUS_COMPLETED)
            ->sum('amount'), 2);
    }

    private function detectPrimaryPaymentGateway(Order $order): string
    {
        $order->loadMissing(['invoice.payments']);
        $payment = $order->invoice instanceof Invoice
            ? $this->resolvePrimaryRefundablePayment($order->invoice)
            : null;

        return $payment?->gatewayKey() ?? '';
    }

    private function restoreOrderProductStockIfNeeded(Order $order): void
    {
        if ((string) $order->type !== 'new' || (int) ($order->service_id ?? 0) > 0 || ! $order->product_id) {
            return;
        }

        $product = Product::query()
            ->lockForUpdate()
            ->find($order->product_id);

        if ($product instanceof Product && (int) $product->stock >= 0) {
            $product->increment('stock', max((int) ($order->quantity ?? 1), 1));
        }
    }

    private function resolvePaymentGatewayLabel(string $gateway): string
    {
        return match ($gateway) {
            PaymentGatewayCode::ALIPAY => PaymentGatewayCode::label(PaymentGatewayCode::ALIPAY),
            PaymentGatewayCode::YIPAY => PaymentGatewayCode::label(PaymentGatewayCode::YIPAY),
            'wechat' => '微信支付',
            'balance' => '余额支付',
            'bank_transfer' => '银行转账',
            'offline' => '线下支付',
            default => '手动入账',
        };
    }

    private function getUserBalance(User $user): float
    {
        return $this->accounts()->cashBalance($user, true);
    }

    private function setUserBalance(User $user, float $balance): string
    {
        return $this->accounts()->setCashBalance($user, $balance);
    }

    private function accounts(): AccountService
    {
        return $this->accountService ??= app(AccountService::class);
    }

    private function financeDocuments(): FinanceDocumentService
    {
        return $this->financeDocumentService ??= app(FinanceDocumentService::class);
    }

    private function recordSuccessfulInvoicePayment(Payment $payment, Invoice $invoice): void
    {
        if (! in_array(InvoiceType::normalize((string) $invoice->type), [InvoiceType::NEW_PURCHASE, InvoiceType::RENEW], true)) {
            return;
        }

        $this->financeDocuments()->recordThirdPartyPayment($payment, $invoice);
    }

    private function markInvoiceRefunded(
        Invoice $invoice,
        array $refundRecord,
        string $refundMethod,
        float $refundAmount,
        array $context = [],
    ): void {
        $traceId = trim((string) ($refundRecord['trace_id'] ?? $context['trace_id'] ?? ''));
        $refundedAt = trim((string) ($refundRecord['refunded_at'] ?? $refundRecord['gmt_refund_pay'] ?? ''));
        $normalizedRefundAmount = number_format(round(max(
            $refundAmount,
            (float) ($refundRecord['refund_amount'] ?? $refundRecord['refund_fee'] ?? 0)
        ), 2), 2, '.', '');

        $payload = [
            'status' => InvoiceStatus::REFUNDED,
        ];

        if (Schema::hasColumn('invoices', 'refunded_at')) {
            $payload['refunded_at'] = $refundedAt !== '' ? $refundedAt : now();
        }

        if (Schema::hasColumn('invoices', 'refund_amount')) {
            $payload['refund_amount'] = $normalizedRefundAmount;
        }

        if (Schema::hasColumn('invoices', 'refund_method')) {
            $payload['refund_method'] = trim($refundMethod) !== '' ? trim($refundMethod) : 'balance';
        }

        if (Schema::hasColumn('invoices', 'refund_trace_id')) {
            $payload['refund_trace_id'] = $traceId !== '' ? $traceId : null;
        } elseif ($traceId !== '' && Schema::hasColumn('invoices', 'trace_id')) {
            $payload['trace_id'] = $traceId;
        }

        $invoice->forceFill($payload)->save();
    }

    private function markInvoicePartiallyRefunded(
        Invoice $invoice,
        string $refundMethod,
        float $refundAmount,
        array $context = [],
    ): void {
        $payload = [
            'status' => InvoiceStatus::PARTIALLY_REFUNDED,
            'refund_amount' => number_format(round($refundAmount, 2), 2, '.', ''),
            'refund_method' => trim($refundMethod) !== '' ? trim($refundMethod) : 'balance',
            'refund_trace_id' => trim((string) ($context['trace_id'] ?? '')) ?: null,
        ];

        $invoice->forceFill($payload)->save();
    }

    private function createBalanceLog(
        int $userId,
        string $eventType,
        float $changeAmount,
        string $balanceAfter,
        ?int $referenceId = null,
        string $remark = '',
        array $context = [],
    ): AccountTransaction {
        $sourceType = $this->resolveAccountTransactionSourceType($eventType);

        return AccountTransaction::query()->create([
            'user_id' => $userId,
            'account_type' => 'cash',
            'event_type' => $eventType,
            'change_amount' => number_format($changeAmount, 2, '.', ''),
            'balance_after' => $balanceAfter,
            'source_type' => $sourceType,
            'source_id' => $referenceId && $referenceId > 0 ? $referenceId : null,
            'origin_type' => $sourceType,
            'origin_id' => $referenceId && $referenceId > 0 ? $referenceId : null,
            'remark' => $remark,
            'operator' => trim((string) ($context['operator'] ?? '')) ?: null,
            'trace_id' => trim((string) ($context['trace_id'] ?? '')) ?: null,
        ]);
    }

    private function resolveAccountTransactionSourceType(string $eventType): ?string
    {
        return match (FinanceLedgerEventType::normalize(trim($eventType))) {
            FinanceLedgerEventType::RECHARGE => 'payment',
            FinanceLedgerEventType::MANUAL_RECHARGE,
            FinanceLedgerEventType::MANUAL_DEDUCTION,
            FinanceLedgerEventType::SYSTEM_ADJUSTMENT => 'manual_adjustment',
            FinanceLedgerEventType::INVOICE_PAYMENT,
            FinanceLedgerEventType::INVOICE_REFUND => 'invoice',
            FinanceLedgerEventType::REFERRAL_CREDIT_CASH => 'referral_withdrawal',
            default => null,
        };
    }

    private function ensureRechargeInvoiceProjection(Payment $payment): ?Invoice
    {
        if ((int) ($payment->invoice_id ?? 0) > 0) {
            return Invoice::query()->find((int) $payment->invoice_id);
        }

        if ((int) $payment->status !== PaymentStatus::SUCCESS || ! $payment->isThirdPartyGateway()) {
            return null;
        }

        $lockKey = "lock:recharge:payment:{$payment->id}";

        return Cache::lock($lockKey, 30)->block(5, function () use ($payment) {
            return DB::transaction(function () use ($payment) {
                $lockedPayment = Payment::query()
                    ->lockForUpdate()
                    ->findOrFail($payment->id);

                if ((int) ($lockedPayment->invoice_id ?? 0) > 0) {
                    return Invoice::query()->find((int) $lockedPayment->invoice_id);
                }

                if ((int) $lockedPayment->status !== PaymentStatus::SUCCESS || ! $lockedPayment->isThirdPartyGateway()) {
                    return null;
                }

                $user = User::query()->lockForUpdate()->findOrFail($lockedPayment->user_id);

                return $this->invoiceService->createForRecharge($user, (float) $lockedPayment->amount, $lockedPayment, null, (string) ($lockedPayment->trace_id ?? ''));
            });
        });
    }

    private function resolveTraceId(array $context, string $fallback): string
    {
        $traceId = trim((string) ($context['trace_id'] ?? ''));
        if ($traceId !== '') {
            return substr($traceId, 0, 64);
        }

        return substr(trim($fallback), 0, 64);
    }

    private function withLock(string $lockKey, int $seconds, callable $callback, string $timeoutMessage): mixed
    {
        try {
            return Cache::lock($lockKey, $seconds)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw new BusinessException($timeoutMessage);
        }
    }

    public function syncProjection(Payment $payment): Payment
    {
        if (! DatabaseSchema::hasTable('payment_callbacks')) {
            return $payment->fresh() ?? $payment;
        }

        $callbackRaw = $this->resolvePaymentRawCallback($payment, true);
        if ($callbackRaw !== []) {
            $this->recordPaymentCallback(
                $payment,
                'payment',
                $callbackRaw,
                $this->resolveCallbackVerified($payment, $callbackRaw),
                (string) ($callbackRaw['trade_no'] ?? $payment->trade_no ?? ''),
                (string) ($callbackRaw['trace_id'] ?? $payment->trace_id ?? '')
            );

            $refundPayload = is_array($callbackRaw['refund'] ?? null) ? $callbackRaw['refund'] : [];
            if ($refundPayload !== []) {
                $this->recordPaymentCallback(
                    $payment,
                    'refund',
                    $refundPayload,
                    true,
                    (string) ($refundPayload['trade_no'] ?? ''),
                    (string) ($refundPayload['trace_id'] ?? $callbackRaw['trace_id'] ?? $payment->trace_id ?? '')
                );
            }
        }

        return $payment->fresh(['callbacks']) ?? $payment;
    }

    private function recordPaymentCallback(
        Payment $payment,
        string $callbackType,
        array $payload,
        bool $isVerified = true,
        ?string $gatewayTradeNo = null,
        ?string $traceId = null,
    ): void {
        if (! DatabaseSchema::hasTable('payment_callbacks') || ! $payment->exists) {
            return;
        }

        $now = now();
        $resolvedTraceId = trim((string) ($traceId ?? $payload['trace_id'] ?? $payment->trace_id ?? ''));
        if ($resolvedTraceId !== '') {
            $payload['trace_id'] = $resolvedTraceId;
        }

        $row = [
            'gateway_trade_no' => $this->nullableString($gatewayTradeNo ?? $payload['trade_no'] ?? $payment->trade_no ?? null),
            'payload_json' => $this->encodeJson(VersionedJson::paymentCallback($payload, $callbackType)),
            'is_verified' => $isVerified ? 1 : 0,
            'received_at' => $payload['send_pay_date'] ?? $payload['refunded_at'] ?? $payload['gmt_refund_pay'] ?? $now,
            'updated_at' => $now,
        ];
        $gatewayContext = $this->paymentGatewayBindingResolver()->contextForPayment($payment);

        if (DatabaseSchema::hasColumn('payment_callbacks', 'plugin_id')) {
            $row['plugin_id'] = $gatewayContext['plugin_id'];
        }

        if (DatabaseSchema::hasColumn('payment_callbacks', 'gateway_key')) {
            $row['gateway_key'] = $gatewayContext['gateway_key'];
        }

        if (Schema::hasColumn('payment_callbacks', 'trace_id')) {
            $row['trace_id'] = $this->nullableString($resolvedTraceId);
        }

        if (Schema::hasColumn('payment_callbacks', 'operator')) {
            $row['operator'] = $this->nullableString($payload['operator'] ?? null);
        }

        if (Schema::hasColumn('payment_callbacks', 'remark')) {
            $row['remark'] = $this->nullableString($payload['remark'] ?? $payload['refund_reason'] ?? null);
        }

        DB::table('payment_callbacks')->updateOrInsert(
            [
                'payment_id' => (int) $payment->id,
                'callback_type' => $callbackType,
            ],
            array_merge($row, [
                'created_at' => $payment->created_at ?? $now,
            ])
        );
    }

    private function resolveCallbackVerified(Payment $payment, array $callbackRaw): bool
    {
        if (($callbackRaw['code'] ?? null) === '10000') {
            return true;
        }

        if (in_array(trim((string) ($callbackRaw['trade_status'] ?? '')), ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return true;
        }

        return (int) $payment->status === PaymentStatus::SUCCESS;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
