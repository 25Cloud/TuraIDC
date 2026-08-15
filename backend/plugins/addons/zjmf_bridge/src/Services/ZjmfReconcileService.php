<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Services;

use App\Constants\InvoiceStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;

class ZjmfReconcileService
{
    private const MAX_WINDOW_DAYS = 31;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payments(array $filters): array
    {
        [$from, $to] = $this->dateWindow($filters);

        $paginator = Payment::query()
            ->with(['invoice:id,invoice_no,status,amount,type'])
            ->whereGatewayKeyIn(PaymentGatewayCode::thirdPartyGateways())
            ->whereBetween('created_at', [$from, $to])
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query->where('status', (int) $filters['status']))
            ->when(trim((string) ($filters['gateway'] ?? '')) !== '', fn ($query) => $query->whereGatewayKey((string) $filters['gateway']))
            ->orderByDesc('id')
            ->paginate($this->pageSize($filters), ['*'], 'page', $this->page($filters));

        return [
            'list' => collect($paginator->items())
                ->filter(fn (mixed $payment): bool => $payment instanceof Payment)
                ->map(fn (Payment $payment): array => $this->paymentPayload($payment))
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'window' => [
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
                'max_days' => self::MAX_WINDOW_DAYS,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function invoices(array $filters): array
    {
        [$from, $to] = $this->dateWindow($filters);

        $paginator = Invoice::query()
            ->with(['payments'])
            ->whereBetween('created_at', [$from, $to])
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query->where('status', (int) $filters['status']))
            ->orderByDesc('id')
            ->paginate($this->pageSize($filters), ['*'], 'page', $this->page($filters));

        return [
            'list' => collect($paginator->items())
                ->filter(fn (mixed $invoice): bool => $invoice instanceof Invoice)
                ->map(fn (Invoice $invoice): array => $this->invoicePayload($invoice))
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'window' => [
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
                'max_days' => self::MAX_WINDOW_DAYS,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dateWindow(array $filters): array
    {
        $to = trim((string) ($filters['to'] ?? $filters['end_date'] ?? '')) !== ''
            ? CarbonImmutable::parse((string) ($filters['to'] ?? $filters['end_date']))->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        $from = trim((string) ($filters['from'] ?? $filters['start_date'] ?? '')) !== ''
            ? CarbonImmutable::parse((string) ($filters['from'] ?? $filters['start_date']))->startOfDay()
            : $to->subDays(1)->startOfDay();

        if ($from->greaterThan($to)) {
            throw new BusinessException('对账开始时间不能晚于结束时间', 42200, 422);
        }

        if ($from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            throw new BusinessException('对账查询窗口不能超过 31 天', 42200, 422);
        }

        return [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(Payment $payment): array
    {
        $gateway = $payment->gatewayKey();

        return [
            'id' => (int) $payment->id,
            'paymentid' => (int) $payment->id,
            'payment_no' => (string) $payment->payment_no,
            'trans_id' => (string) $payment->payment_no,
            'trade_no' => (string) ($payment->trade_no ?? ''),
            'gateway' => $gateway,
            'gateway_label' => PaymentGatewayCode::label($gateway),
            'amount' => $this->money($payment->amount ?? 0),
            'status' => (int) $payment->status,
            'status_label' => PaymentStatus::$labels[(int) $payment->status] ?? '未知',
            'invoice_id' => (int) ($payment->invoice?->id ?? 0),
            'invoice_no' => (string) ($payment->invoice?->invoice_no ?? ''),
            'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(Invoice $invoice): array
    {
        $latestPayment = $invoice->payments->sortByDesc('id')->first();

        return [
            'id' => (int) $invoice->id,
            'invoiceid' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'user_id' => (int) $invoice->user_id,
            'type' => (string) ($invoice->type ?? ''),
            'amount' => $this->money($invoice->amount ?? 0),
            'paid_amount' => $this->money($invoice->paid_amount ?? 0),
            'status' => (int) $invoice->status,
            'status_label' => InvoiceStatus::$labels[(int) $invoice->status] ?? '未知',
            'payment_no' => $latestPayment instanceof Payment ? (string) $latestPayment->payment_no : '',
            'paid_at' => $invoice->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $invoice->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function page(array $filters): int
    {
        return max((int) ($filters['page'] ?? 1), 1);
    }

    private function pageSize(array $filters): int
    {
        $value = (int) ($filters['page_size'] ?? $filters['limit'] ?? 100);

        return min(max($value, 1), 100);
    }

    private function money(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : '0.00';
    }
}
