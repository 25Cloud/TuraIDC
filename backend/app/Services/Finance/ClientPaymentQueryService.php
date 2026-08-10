<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class ClientPaymentQueryService
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $expiredContext
     * @return array<string, mixed>
     */
    public function paginate(int $userId, array $filters, array $expiredContext): array
    {
        $this->payments->cancelExpiredPendingRechargesForUser($userId, $expiredContext);

        $query = Payment::with(['invoice:id,invoice_no,status,amount,type'])
            ->where('user_id', $userId)
            ->whereGatewayKeyIn(PaymentGatewayCode::thirdPartyGateways())
            ->orderByDesc('id');

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', (int) $filters['status']);
        }
        $gateway = trim((string) ($filters['type'] ?? $filters['gateway'] ?? ''));
        if ($gateway !== '') {
            $query->whereGatewayKey($gateway);
        }
        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('payment_no', 'like', '%'.$keyword.'%')
                    ->orWhere('trade_no', 'like', '%'.$keyword.'%')
                    ->orWhereHas('invoice', fn ($invoiceQuery) => $invoiceQuery->where('invoice_no', 'like', '%'.$keyword.'%'));
            });
        }
        $this->applyDateFilter($query, $filters);

        $paginator = $query->paginate((int) ($filters['page_size'] ?? 15));

        return [
            'list' => collect($paginator->items())
                ->map(fn (Payment $payment): array => $this->listItem($payment))
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    /**
     * @param  array<string, mixed>  $expiredContext
     * @return array<string, int>
     */
    public function summary(int $userId, array $expiredContext): array
    {
        $this->payments->cancelExpiredPendingRechargesForUser($userId, $expiredContext);

        $row = Payment::query()
            ->where('user_id', $userId)
            ->whereGatewayKeyIn(PaymentGatewayCode::thirdPartyGateways())
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending', [PaymentStatus::PENDING])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS success', [PaymentStatus::SUCCESS])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS refunded', [PaymentStatus::REFUNDED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled', [PaymentStatus::CANCELLED])
            ->first();

        return [
            'total' => (int) ($row?->total ?? 0),
            'pending' => (int) ($row?->pending ?? 0),
            'success' => (int) ($row?->success ?? 0),
            'refunded' => (int) ($row?->refunded ?? 0),
            'cancelled' => (int) ($row?->cancelled ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $expiredContext
     * @return array<string, mixed>
     */
    public function detail(int $userId, int $paymentId, array $expiredContext): array
    {
        $payment = Payment::with(['invoice:id,invoice_no,status,amount,paid_amount,type,created_at'])
            ->where('user_id', $userId)
            ->whereGatewayKeyIn(PaymentGatewayCode::thirdPartyGateways())
            ->findOrFail($paymentId);

        $payment = $this->payments->cancelExpiredPendingRecharge($payment, $expiredContext)
            ->fresh(['invoice:id,invoice_no,status,amount,paid_amount,type,created_at']) ?? $payment;

        return [
            'id' => (int) $payment->id,
            'payment_no' => (string) $payment->payment_no,
            'trade_no' => (string) ($payment->trade_no ?? ''),
            'gateway' => $payment->gatewayKey(),
            'gateway_key' => $payment->gatewayKey(),
            'gateway_label' => $this->gatewayLabel($payment->gatewayKey()),
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'status' => (int) $payment->status,
            'status_label' => PaymentStatus::$labels[(int) $payment->status] ?? '未知',
            'invoice' => $payment->invoice ? [
                'id' => (int) $payment->invoice->id,
                'invoice_no' => (string) $payment->invoice->invoice_no,
                'status' => (int) $payment->invoice->status,
                'amount' => number_format((float) $payment->invoice->amount, 2, '.', ''),
                'paid_amount' => number_format((float) $payment->invoice->paid_amount, 2, '.', ''),
                'type' => (string) ($payment->invoice->type ?? ''),
            ] : null,
            'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listItem(Payment $payment): array
    {
        $gateway = $payment->gatewayKey();

        return [
            'id' => (int) $payment->id,
            'payment_no' => (string) $payment->payment_no,
            'trade_no' => (string) ($payment->trade_no ?? ''),
            'gateway' => $gateway,
            'gateway_key' => $gateway,
            'gateway_label' => $this->gatewayLabel($gateway),
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'status' => (int) $payment->status,
            'status_label' => PaymentStatus::$labels[(int) $payment->status] ?? '未知',
            'invoice_id' => (int) ($payment->invoice?->id ?? 0),
            'invoice_no' => (string) ($payment->invoice?->invoice_no ?? ''),
            'invoice_type' => (string) ($payment->invoice?->type ?? ''),
            'invoice_status' => $payment->invoice ? (int) $payment->invoice->status : null,
            'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function gatewayLabel(string $gateway): string
    {
        return match ($gateway) {
            PaymentGatewayCode::ALIPAY => '支付宝',
            PaymentGatewayCode::YIPAY => PaymentGatewayCode::label(PaymentGatewayCode::YIPAY),
            PaymentGatewayCode::WECHAT => PaymentGatewayCode::label(PaymentGatewayCode::WECHAT),
            default => $gateway,
        };
    }

    /**
     * @param  Builder<Payment>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilter($query, array $filters): void
    {
        $start = trim((string) ($filters['start_date'] ?? ''));
        $end = trim((string) ($filters['end_date'] ?? ''));

        if ($start === '' && $end === '') {
            return;
        }

        if ($start !== '' && $end !== '') {
            $query->whereBetween('created_at', [
                CarbonImmutable::parse($start)->startOfDay(),
                CarbonImmutable::parse($end)->endOfDay(),
            ]);

            return;
        }

        if ($start !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse($start)->startOfDay());

            return;
        }

        $query->where('created_at', '<=', CarbonImmutable::parse($end)->endOfDay());
    }
}
