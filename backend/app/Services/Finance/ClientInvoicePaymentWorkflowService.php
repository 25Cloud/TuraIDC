<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Constants\InvoiceStatus;
use App\Constants\PaymentGatewayCode;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use App\Support\VersionedJson;

class ClientInvoicePaymentWorkflowService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly PaymentGatewayManager $paymentGateways,
        private readonly CheckoutSecurityService $checkoutSecurity,
        private readonly CheckoutService $checkout,
    ) {}

    /**
     * @param  array<string, mixed>  $expiredContext
     * @return array<string, int|string>
     */
    public function summary(User $user, array $expiredContext): array
    {
        $this->checkout->cancelExpiredUnpaidInvoicesForUser((int) $user->id, $expiredContext);

        $row = Invoice::query()
            ->where('user_id', $user->id)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS unpaid', [InvoiceStatus::UNPAID])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS paid', [InvoiceStatus::PAID])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS overdue', [InvoiceStatus::OVERDUE])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status IN (?,?) THEN amount - COALESCE(paid_amount,0) ELSE 0 END), 0) AS unpaid_amount',
                [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE]
            )
            ->first();

        return [
            'total' => (int) ($row?->total ?? 0),
            'unpaid' => (int) ($row?->unpaid ?? 0),
            'paid' => (int) ($row?->paid ?? 0),
            'overdue' => (int) ($row?->overdue ?? 0),
            'unpaid_amount' => number_format((float) ($row?->unpaid_amount ?? 0), 2, '.', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function create(User $user, array $payload, string $idempotencyKey, array $context): array
    {
        $invoice = $this->checkout->create((int) $user->id, $payload, array_merge($context, [
            'idempotency_key' => $idempotencyKey,
        ]));
        $invoice->loadMissing($this->invoiceRelations());

        return $this->invoicePayload($invoice);
    }

    /**
     * @param  array<string, mixed>  $operationContext
     * @param  array<string, mixed>  $expiredContext
     * @return array<string, mixed>
     */
    public function payByBalance(
        User $user,
        int $invoiceId,
        string $paymentSessionToken,
        array $operationContext,
        array $expiredContext,
    ): array {
        $invoice = $this->cancelExpiredInvoice($user, $invoiceId, $expiredContext);
        $this->checkoutSecurity->assertInvoicePaymentSessionToken($paymentSessionToken, $invoice, (int) $user->id);

        $paidInvoice = $this->payments->payByBalance($invoice, $user, $operationContext);
        $invoice->refresh()->load($this->invoiceRelations());
        $user->refresh();

        return [
            'gateway' => 'balance',
            'amount' => number_format((float) $paidInvoice->paid_amount, 2, '.', ''),
            'paid_at' => $paidInvoice->paid_at?->format('Y-m-d H:i:s'),
            'cash_balance' => number_format((float) $user->balance, 2, '.', ''),
            'invoice' => $this->invoicePayload($invoice),
        ];
    }

    /**
     * @param  array<string, mixed>  $operationContext
     * @param  array<string, mixed>  $expiredContext
     * @return array<string, mixed>
     */
    public function payByBalanceAndAlipay(
        User $user,
        int $invoiceId,
        string $paymentSessionToken,
        float $balanceAmount,
        array $operationContext,
        array $expiredContext,
        string $ipAddress,
    ): array {
        $invoice = $this->cancelExpiredInvoice($user, $invoiceId, $expiredContext);
        $this->checkoutSecurity->assertInvoicePaymentSessionToken($paymentSessionToken, $invoice, (int) $user->id);

        $result = $this->payments->payByBalanceAndAlipay($invoice, $user, $balanceAmount, $operationContext);
        $invoice->refresh()->load($this->invoiceRelations());
        $payment = $this->findInvoicePayment(
            $user,
            $invoice,
            (string) ($result['payment_no'] ?? ''),
            PaymentGatewayCode::ALIPAY,
        );

        return [
            ...$result,
            ...$this->checkoutSecurity->issueInvoicePaymentPollToken($payment, $invoice, (int) $user->id, $ipAddress),
            'invoice' => $this->invoicePayload($invoice),
        ];
    }

    /**
     * @param  array<string, mixed>  $operationContext
     * @param  array<string, mixed>  $expiredContext
     * @return array<string, mixed>
     */
    public function payByGateway(
        User $user,
        int $invoiceId,
        string $paymentSessionToken,
        string $gateway,
        string $paymentType,
        array $operationContext,
        array $expiredContext,
        string $ipAddress,
    ): array {
        $invoice = $this->cancelExpiredInvoice($user, $invoiceId, $expiredContext);
        $this->checkoutSecurity->assertInvoicePaymentSessionToken($paymentSessionToken, $invoice, (int) $user->id);

        $gateway = PaymentGatewayCode::normalize($gateway) ?: PaymentGatewayCode::ALIPAY;
        $selectedOption = $this->gatewayOption($gateway, $paymentType);
        if ($selectedOption === null) {
            throw new BusinessException('当前没有可用支付方式，请联系管理员开启支付渠道');
        }

        $gatewayContext = $paymentType !== '' ? ['payment_type' => $paymentType] : [];
        $result = $this->payments->payByGateway(
            $invoice,
            $user,
            $gateway,
            array_merge($operationContext, $gatewayContext),
        );
        $payment = $this->findInvoicePayment($user, $invoice, (string) ($result['payment_no'] ?? ''), $gateway);

        $payload = [
            'gateway' => $gateway,
            'gateway_key' => $gateway,
            'gateway_label' => (string) ($selectedOption['name'] ?? PaymentGatewayCode::label($gateway)),
        ];
        if ($paymentType !== '') {
            $payload['payment_type'] = $paymentType;
            $payload['payment_type_label'] = (string) ($selectedOption['label'] ?? '');
        }

        return array_merge(
            $payload,
            $result,
            $this->checkoutSecurity->issueInvoicePaymentPollToken($payment, $invoice, (int) $user->id, $ipAddress),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function queryGatewayStatus(
        User $user,
        int $invoiceId,
        string $paymentNo,
        string $gateway,
        string $pollToken,
        string $ipAddress,
    ): array {
        $invoice = $this->findInvoiceForUser($user, $invoiceId);
        $gateway = PaymentGatewayCode::normalize($gateway);
        $paymentQuery = Payment::query()
            ->where('payment_no', $paymentNo)
            ->where('invoice_id', $invoice->id)
            ->where('user_id', $user->id);

        if ($gateway !== '') {
            $paymentQuery->whereGatewayKey($gateway);
        } else {
            $paymentQuery->whereGatewayKeyIn(PaymentGatewayCode::thirdPartyGateways());
        }

        $payment = $paymentQuery->first();
        if (! $payment) {
            throw new BusinessException('未找到支付记录', 40400, 404);
        }

        $this->checkoutSecurity->assertInvoicePaymentPollToken(
            $pollToken,
            $payment,
            $invoice,
            (int) $user->id,
            $ipAddress,
        );

        $result = $this->payments->queryGatewayPaymentStatus($payment);
        if (($result['paid'] ?? false) !== true) {
            return $result;
        }

        $invoice->refresh()->load($this->invoiceRelations());

        return [
            ...$result,
            'invoice' => $this->invoicePayload($invoice),
        ];
    }

    /**
     * @param  array<string, mixed>  $expiredContext
     */
    private function cancelExpiredInvoice(User $user, int $invoiceId, array $expiredContext): Invoice
    {
        return $this->checkout->cancelExpiredUnpaidInvoice(
            $this->findInvoiceForUser($user, $invoiceId),
            $expiredContext,
        );
    }

    private function findInvoiceForUser(User $user, int $invoiceId): Invoice
    {
        return Invoice::with($this->invoiceRelations())
            ->where('user_id', $user->id)
            ->findOrFail($invoiceId);
    }

    private function findInvoicePayment(User $user, Invoice $invoice, string $paymentNo, string $gateway): Payment
    {
        $payment = Payment::query()
            ->where('payment_no', $paymentNo)
            ->where('invoice_id', $invoice->id)
            ->where('user_id', $user->id)
            ->whereGatewayKey($gateway)
            ->first();

        if (! $payment) {
            throw new BusinessException('支付记录不存在', 40400, 404);
        }

        return $payment;
    }

    /**
     * @return list<string>
     */
    private function invoiceRelations(): array
    {
        return [
            'product:id,product_type,product_group_id,service_type_code,remark,config_options,purchase_requires',
            'order.product:id,product_type,product_group_id,service_type_code,remark,config_options,purchase_requires',
            'service',
            'payments',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(Invoice $invoice): array
    {
        $detail = $this->invoices->clientDetail($invoice);
        $payableAmount = (string) ($detail['payable_amount'] ?? number_format($this->payableAmount($invoice), 2, '.', ''));
        $paymentSecurity = $this->checkoutSecurity->issueInvoicePaymentSession($invoice, (int) $invoice->user_id);
        $sessionToken = (string) ($paymentSecurity['session_token'] ?? '');
        $canCancel = in_array((int) $invoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true);

        $configSnapshot = VersionedJson::withoutMeta(is_array($invoice->config_snapshot) ? $invoice->config_snapshot : []) ?? [];
        $configPricingSnapshot = is_array($invoice->config_pricing_snapshot) && $invoice->config_pricing_snapshot !== []
            ? (VersionedJson::withoutMeta($invoice->config_pricing_snapshot) ?? [])
            : [];
        $couponSnapshot = VersionedJson::withoutMeta(is_array($invoice->coupon_snapshot) ? $invoice->coupon_snapshot : []) ?? [];

        return [
            ...$detail,
            'service_id' => (int) ($detail['service']['id'] ?? $invoice->service_id ?? 0),
            'pay_methods' => $this->availablePaymentMethods((float) $payableAmount),
            'can_cancel' => $canCancel,
            'payable_amount' => $payableAmount,
            'product' => array_merge(is_array($detail['product'] ?? null) ? $detail['product'] : [], [
                'config_options' => (array) ($invoice->product?->config_options ?? $invoice->order?->product?->config_options ?? []),
            ]),
            'config_snapshot' => $configSnapshot,
            'config_pricing_snapshot' => $configPricingSnapshot,
            'coupon' => (int) ($invoice->coupon_id ?? 0) > 0 ? [
                'id' => (int) $invoice->coupon_id,
                'name' => (string) ($couponSnapshot['name'] ?? ''),
                'description' => (string) ($couponSnapshot['description'] ?? ''),
                'discount_label' => (string) ($couponSnapshot['discount_label'] ?? ''),
                'discount_amount' => number_format((float) ($invoice->discount ?? 0), 2, '.', ''),
            ] : null,
            'payment_security' => [
                'can_pay' => $canCancel && $sessionToken !== '',
                'session_token' => $sessionToken,
                'expires_at' => $paymentSecurity['expires_at'] ?? null,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availablePaymentMethods(float $payableAmount): array
    {
        if ($payableAmount <= 0) {
            return [['key' => 'free', 'name' => '确认支付', 'label' => '零元账单', 'option_key' => 'free']];
        }

        return [
            ['key' => 'balance', 'name' => '余额支付', 'label' => '账户余额', 'option_key' => 'balance'],
            ...$this->paymentGateways->availableThirdPartyGatewayOptions(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function gatewayOption(string $gateway, string $paymentType): ?array
    {
        foreach ($this->paymentGateways->availableThirdPartyGatewayOptions() as $option) {
            if ((string) ($option['key'] ?? '') !== $gateway) {
                continue;
            }

            if ($paymentType !== '' && $paymentType !== trim((string) ($option['payment_type'] ?? ''))) {
                continue;
            }

            return $option;
        }

        return null;
    }

    private function payableAmount(Invoice $invoice): float
    {
        return round(max((float) ($invoice->amount ?? 0) - (float) ($invoice->paid_amount ?? 0), 0), 2);
    }
}
