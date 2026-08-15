<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Services;

use App\Constants\InvoiceStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\PaymentService;
use App\Services\Integrations\Payments\PaymentGatewayManager;

class ZjmfFinanceService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function invoices(User $user, array $filters): array
    {
        $paginator = Invoice::query()
            ->with(['payments', 'service:id,name,status,expires_at', 'product:id,product_type,service_type_code'])
            ->where('user_id', (int) $user->id)
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query->where('status', (int) $filters['status']))
            ->when(trim((string) ($filters['keyword'] ?? '')) !== '', function ($query) use ($filters): void {
                $keyword = trim((string) $filters['keyword']);
                $query->where('invoice_no', 'like', '%'.$keyword.'%');
            })
            ->orderByDesc('id')
            ->paginate($this->pageSize($filters, 20, 100), ['*'], 'page', $this->page($filters));

        return [
            'list' => collect($paginator->items())
                ->filter(fn (mixed $invoice): bool => $invoice instanceof Invoice)
                ->map(fn (Invoice $invoice): array => $this->invoicePayload($invoice))
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice(User $user, int $invoiceId): array
    {
        return [
            'invoice' => $this->invoicePayload($this->findInvoice($user, $invoiceId), includeDetail: true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invoiceStatus(User $user, int $invoiceId): array
    {
        $invoice = $this->findInvoice($user, $invoiceId);
        $latestPayment = $invoice->payments
            ->sortByDesc('id')
            ->first();

        return [
            'invoice_id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'status' => (int) $invoice->status,
            'status_label' => InvoiceStatus::$labels[(int) $invoice->status] ?? '未知',
            'paid' => (int) $invoice->status === InvoiceStatus::PAID,
            'zjmf_status' => (int) $invoice->status === InvoiceStatus::PAID ? 1000 : (int) $invoice->status,
            'paid_amount' => $this->money($invoice->paid_amount ?? 0),
            'paid_at' => $invoice->paid_at?->format('Y-m-d H:i:s'),
            'payment' => $latestPayment instanceof Payment ? [
                'id' => (int) $latestPayment->id,
                'payment_no' => (string) $latestPayment->payment_no,
                'trade_no' => (string) ($latestPayment->trade_no ?? ''),
                'gateway' => $latestPayment->gatewayKey(),
                'amount' => $this->money($latestPayment->amount ?? 0),
                'status' => (int) $latestPayment->status,
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function payInvoiceByBalance(User $user, int $invoiceId, array $context = []): array
    {
        $invoice = $this->findInvoice($user, $invoiceId);
        $paidInvoice = $this->payments->payByBalance($invoice, $user, $context);
        $paidInvoice->loadMissing(['payments', 'service:id,name,status,expires_at', 'product:id,product_type,service_type_code']);
        $freshUser = $user->fresh(['account']) ?? $user;

        return [
            'gateway' => 'balance',
            'amount' => $this->money($paidInvoice->paid_amount ?? 0),
            'paid_at' => $paidInvoice->paid_at?->format('Y-m-d H:i:s'),
            'cash_balance' => (string) $freshUser->balance,
            'invoice' => $this->invoicePayload($paidInvoice, includeDetail: true),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function fundTransactions(User $user, array $filters): array
    {
        $paginator = AccountTransaction::query()
            ->where('user_id', (int) $user->id)
            ->orderByDesc('id')
            ->paginate($this->pageSize($filters, 20, 100), ['*'], 'page', $this->page($filters));

        return [
            'list' => collect($paginator->items())
                ->filter(fn (mixed $transaction): bool => $transaction instanceof AccountTransaction)
                ->map(fn (AccountTransaction $transaction): array => [
                    'id' => (int) $transaction->id,
                    'account_type' => (string) $transaction->account_type,
                    'event_type' => (string) $transaction->event_type,
                    'change_amount' => $this->money($transaction->change_amount),
                    'balance_after' => $this->money($transaction->balance_after),
                    'source_type' => (string) ($transaction->source_type ?? ''),
                    'source_id' => (int) ($transaction->source_id ?? 0),
                    'origin_type' => (string) ($transaction->origin_type ?? ''),
                    'origin_id' => (int) ($transaction->origin_id ?? 0),
                    'remark' => (string) ($transaction->remark ?? ''),
                    'created_at' => $transaction->created_at?->format('Y-m-d H:i:s'),
                ])
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    /**
     * 标准 ZJMF cart/credit 接口 — 返回当前用户余额及货币信息。
     *
     * @return array<string, mixed>
     */
    public function credit(User $user): array
    {
        return [
            'credit' => (string) $user->balance,
            'currency' => [
                'code' => 'CNY',
                'prefix' => '¥',
                'suffix' => '',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function funds(User $user, array $filters): array
    {
        return [
            'cash_balance' => (string) $user->balance,
            'gateways' => $this->gateways->availableThirdPartyGatewayOptions(),
            'payments' => $this->payments($user, [
                ...$filters,
                'page_size' => min($this->pageSize($filters, 10, 20), 20),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recharge(User $user, array $payload, array $context = []): array
    {
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new BusinessException('充值金额必须大于 0', 42200, 422);
        }

        $gateway = PaymentGatewayCode::normalize((string) ($payload['gateway'] ?? ''));
        $paymentType = trim((string) ($payload['payment_type'] ?? ''));
        $selectedOption = $this->gatewayOption($gateway, $paymentType);
        if (! $selectedOption) {
            throw new BusinessException('当前没有可用支付方式，请联系管理员开启支付渠道', 42200, 422);
        }

        $gateway = (string) ($selectedOption['key'] ?? $gateway);
        $gatewayContext = $paymentType !== '' ? ['payment_type' => $paymentType] : [];
        $result = $this->payments->rechargeByGateway($user, $amount, $gateway, array_merge($context, $gatewayContext));

        return [
            'gateway' => $gateway,
            'gateway_key' => $gateway,
            'gateway_label' => (string) ($selectedOption['name'] ?? PaymentGatewayCode::label($gateway)),
            'payment_type' => $paymentType,
            ...$result,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payments(User $user, array $filters): array
    {
        $paginator = Payment::query()
            ->with(['invoice:id,invoice_no,status,amount,type'])
            ->where('user_id', (int) $user->id)
            ->whereGatewayKeyIn(PaymentGatewayCode::thirdPartyGateways())
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query->where('status', (int) $filters['status']))
            ->when(trim((string) ($filters['gateway'] ?? $filters['type'] ?? '')) !== '', function ($query) use ($filters): void {
                $gateway = trim((string) ($filters['gateway'] ?? $filters['type'] ?? ''));
                $query->whereGatewayKey($gateway);
            })
            ->when(trim((string) ($filters['keyword'] ?? '')) !== '', function ($query) use ($filters): void {
                $keyword = trim((string) $filters['keyword']);
                $query->where(function ($builder) use ($keyword): void {
                    $builder->where('payment_no', 'like', '%'.$keyword.'%')
                        ->orWhere('trade_no', 'like', '%'.$keyword.'%')
                        ->orWhereHas('invoice', fn ($invoiceQuery) => $invoiceQuery->where('invoice_no', 'like', '%'.$keyword.'%'));
                });
            })
            ->orderByDesc('id')
            ->paginate($this->pageSize($filters, 20, 100), ['*'], 'page', $this->page($filters));

        return [
            'list' => collect($paginator->items())
                ->filter(fn (mixed $payment): bool => $payment instanceof Payment)
                ->map(fn (Payment $payment): array => $this->paymentPayload($payment))
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(User $user, int $paymentId): array
    {
        $payment = Payment::query()
            ->with(['invoice:id,invoice_no,status,amount,type'])
            ->where('user_id', (int) $user->id)
            ->whereGatewayKeyIn(PaymentGatewayCode::thirdPartyGateways())
            ->find($paymentId);

        if (! $payment instanceof Payment) {
            throw new BusinessException('支付记录不存在', 40400, 404);
        }

        return [
            'payment' => $this->paymentPayload($payment, includeDetail: true),
        ];
    }

    private function findInvoice(User $user, int $invoiceId): Invoice
    {
        $invoice = Invoice::query()
            ->with(['payments', 'service:id,name,status,expires_at', 'product:id,product_type,service_type_code'])
            ->where('user_id', (int) $user->id)
            ->find($invoiceId);

        if (! $invoice instanceof Invoice) {
            throw new BusinessException('账单不存在', 40400, 404);
        }

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(Invoice $invoice, bool $includeDetail = false): array
    {
        $payments = $invoice->relationLoaded('payments') ? $invoice->payments : collect();
        $latestPayment = $payments->sortByDesc('id')->first();
        $amount = (float) ($invoice->amount ?? 0);
        $paidAmount = (float) ($invoice->paid_amount ?? 0);
        $payload = [
            'id' => (int) $invoice->id,
            'invoiceid' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'type' => (string) ($invoice->type ?? ''),
            'product_id' => (int) ($invoice->product_id ?? 0),
            'service_id' => (int) ($invoice->service_id ?? 0),
            'product_name' => (string) $invoice->display_product_name,
            'amount' => $this->money($amount),
            'discount' => $this->money($invoice->discount ?? 0),
            'paid_amount' => $this->money($paidAmount),
            'payable_amount' => $this->money(max($amount - $paidAmount, 0)),
            'billing_cycle' => (string) ($invoice->billing_cycle ?? ''),
            'quantity' => (int) ($invoice->quantity ?? 1),
            'status' => (int) $invoice->status,
            'status_label' => InvoiceStatus::$labels[(int) $invoice->status] ?? '未知',
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'paid_at' => $invoice->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $invoice->created_at?->format('Y-m-d H:i:s'),
            'payment' => $latestPayment instanceof Payment ? [
                'id' => (int) $latestPayment->id,
                'payment_no' => (string) $latestPayment->payment_no,
                'gateway' => $latestPayment->gatewayKey(),
                'amount' => $this->money($latestPayment->amount ?? 0),
                'status' => (int) $latestPayment->status,
            ] : null,
        ];

        if ($includeDetail) {
            $payload['config_snapshot'] = $this->removeSensitiveKeys($invoice->config_snapshot ?? []);
            $payload['config_pricing_snapshot'] = $this->removeSensitiveKeys($invoice->config_pricing_snapshot ?? []);
            $payload['coupon_snapshot'] = $this->removeSensitiveKeys($invoice->coupon_snapshot ?? []);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(Payment $payment, bool $includeDetail = false): array
    {
        $gateway = $payment->gatewayKey();
        $payload = [
            'id' => (int) $payment->id,
            'paymentid' => (int) $payment->id,
            'payment_no' => (string) $payment->payment_no,
            'trans_id' => (string) $payment->payment_no,
            'trade_no' => (string) ($payment->trade_no ?? ''),
            'gateway' => $gateway,
            'gateway_key' => $gateway,
            'gateway_label' => PaymentGatewayCode::label($gateway),
            'amount' => $this->money($payment->amount ?? 0),
            'status' => (int) $payment->status,
            'status_label' => PaymentStatus::$labels[(int) $payment->status] ?? '未知',
            'invoice_id' => (int) ($payment->invoice?->id ?? 0),
            'invoice_no' => (string) ($payment->invoice?->invoice_no ?? ''),
            'invoice_status' => $payment->invoice ? (int) $payment->invoice->status : null,
            'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($includeDetail) {
            $payload['callback_raw'] = $this->removeSensitiveKeys($payment->callback_raw ?? []);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function gatewayOption(string $gateway, string $paymentType): ?array
    {
        $options = $this->gateways->availableThirdPartyGatewayOptions();
        if ($gateway === '') {
            return $options[0] ?? null;
        }

        foreach ($options as $option) {
            if ((string) ($option['key'] ?? '') !== $gateway) {
                continue;
            }

            $optionPaymentType = trim((string) ($option['payment_type'] ?? ''));
            if ($paymentType !== '' && $paymentType !== $optionPaymentType) {
                continue;
            }

            return $option;
        }

        return null;
    }

    private function removeSensitiveKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/password|secret|api_key|raw_response|third_party_response/i', $key) === 1) {
                continue;
            }

            $clean[$key] = $this->removeSensitiveKeys($item);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function page(array $filters): int
    {
        return max((int) ($filters['page'] ?? 1), 1);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function pageSize(array $filters, int $default, int $max): int
    {
        $value = (int) ($filters['page_size'] ?? $filters['limit'] ?? $default);

        return min(max($value, 1), $max);
    }

    private function money(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : '0.00';
    }
}
