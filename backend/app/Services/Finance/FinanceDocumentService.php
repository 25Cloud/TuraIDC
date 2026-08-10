<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Constants\FinanceLedgerEventType;
use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Constants\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RechargeRecord;
use App\Models\Refund;

class FinanceDocumentService
{
    public function recordThirdPartyPayment(Payment $payment, Invoice $invoice): RechargeRecord
    {
        $this->assertSameUser($payment->user_id, $invoice->user_id);
        $this->assertPositiveAmount((float) $payment->amount, '第三方支付金额不正确');

        $existing = RechargeRecord::query()
            ->where('payment_id', (int) $payment->id)
            ->first();

        if ($existing instanceof RechargeRecord) {
            $this->assertRecordBinding($existing, $invoice, $payment);

            return $existing;
        }

        $this->assertBusinessPaymentComposition($invoice);

        return RechargeRecord::query()->create([
            'record_no' => RechargeRecord::generateRecordNo(),
            'user_id' => (int) $invoice->user_id,
            'order_id' => $invoice->order_id,
            'invoice_id' => (int) $invoice->id,
            'payment_id' => (int) $payment->id,
            'scene' => $this->businessScene($invoice),
            'direction' => 'in',
            'amount' => $this->money($payment->amount),
            'currency' => 'CNY',
            'entry_type' => 'third_party_payment',
            'remark' => '第三方实付 '.(string) $payment->payment_no,
            'trace_id' => $payment->trace_id ?: $invoice->trace_id,
        ]);
    }

    public function recordRecharge(
        Invoice $invoice,
        ?Payment $payment,
        AccountTransaction $accountTransaction,
        string $scene,
        array $context = [],
    ): RechargeRecord {
        $this->assertSameUser($invoice->user_id, $accountTransaction->user_id);
        if ($payment instanceof Payment) {
            $this->assertSameUser($invoice->user_id, $payment->user_id);
        }

        $amount = abs((float) $accountTransaction->change_amount);
        $this->assertPositiveAmount($amount, '充值金额不正确');
        throw_if(
            abs($amount - (float) $invoice->amount) > 0.00001,
            new BusinessException('充值记录金额必须与账单金额一致')
        );

        $existing = RechargeRecord::query()
            ->where('account_transaction_id', (int) $accountTransaction->id)
            ->first();

        if ($existing instanceof RechargeRecord) {
            if ((int) $existing->invoice_id !== (int) $invoice->id) {
                throw new BusinessException('账户流水已绑定到其他账单');
            }

            return $existing;
        }

        return RechargeRecord::query()->create([
            'record_no' => RechargeRecord::generateRecordNo(),
            'user_id' => (int) $invoice->user_id,
            'invoice_id' => (int) $invoice->id,
            'payment_id' => $payment?->id,
            'account_transaction_id' => (int) $accountTransaction->id,
            'scene' => $scene,
            'direction' => 'in',
            'amount' => $this->money($amount),
            'currency' => 'CNY',
            'entry_type' => $scene === 'admin_recharge' ? 'manual_recharge' : 'account_recharge',
            'remark' => (string) ($context['record_remark'] ?? ($scene === 'admin_recharge' ? '管理员手工充值' : '账户充值')),
            'operator_type' => $this->nullableString($context['operator_type'] ?? null),
            'operator_id' => $this->nullableInt($context['operator_id'] ?? null),
            'operator_name' => $this->nullableString($context['operator_name'] ?? null),
            'trace_id' => $this->nullableString($context['trace_id'] ?? $invoice->trace_id),
        ]);
    }

    /**
     * @return array{refund: Refund, refund_invoice: Invoice, recharge_record: ?RechargeRecord}
     */
    public function createBalanceRefundDocuments(
        Invoice $invoice,
        ?Payment $payment,
        AccountTransaction $accountTransaction,
        float $refundAmount,
        string $reason,
        array $context = [],
    ): array {
        if ($payment instanceof Payment) {
            $this->assertSameUser($invoice->user_id, $payment->user_id);
        }
        $this->assertSameUser($invoice->user_id, $accountTransaction->user_id);
        $this->assertPositiveAmount($refundAmount, '退款金额不正确');

        $refund = Refund::query()->create([
            'refund_no' => Refund::generateRefundNo(),
            'user_id' => (int) $invoice->user_id,
            'invoice_id' => (int) $invoice->id,
            'payment_id' => $payment?->id,
            'amount' => $this->money($refundAmount),
            'status' => Refund::STATUS_COMPLETED,
            'refund_method' => 'balance',
            'currency' => 'CNY',
            'reason' => $reason,
            'operator_type' => $this->nullableString($context['operator_type'] ?? 'admin'),
            'operator_id' => $this->nullableInt($context['operator_id'] ?? null),
            'operator_name' => $this->nullableString($context['operator_name'] ?? null),
            'trace_id' => $this->nullableString($context['trace_id'] ?? null),
            'refunded_at' => now(),
        ]);

        $refundInvoice = Invoice::query()->create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => (int) $invoice->user_id,
            'origin_invoice_id' => (int) $invoice->id,
            'type' => InvoiceType::REFUND,
            'amount' => $this->money(-$refundAmount),
            'paid_amount' => $this->money(-$refundAmount),
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => null,
            'config_snapshot' => [
                'refund_no' => $refund->refund_no,
                'origin_invoice_no' => $invoice->invoice_no,
                'refund_method' => 'balance',
                'reason' => $reason,
            ],
            'remark' => $reason,
            'operator' => $this->nullableString($context['operator_name'] ?? null),
            'trace_id' => $this->nullableString($context['trace_id'] ?? $invoice->trace_id),
        ]);

        $refund->forceFill(['refund_invoice_id' => (int) $refundInvoice->id])->save();

        $originRechargeRecord = $payment instanceof Payment
            ? RechargeRecord::query()
                ->where('payment_id', (int) $payment->id)
                ->lockForUpdate()
                ->first()
            : null;
        $offsetAmount = $originRechargeRecord instanceof RechargeRecord
            ? min($refundAmount, $this->remainingOffsetAmount($originRechargeRecord))
            : 0.0;

        $negativeRecord = null;
        if ($offsetAmount > 0) {
            $negativeRecord = RechargeRecord::query()->create([
                'record_no' => RechargeRecord::generateRecordNo(),
                'user_id' => (int) $invoice->user_id,
                'order_id' => $invoice->order_id,
                'invoice_id' => (int) $refundInvoice->id,
                'account_transaction_id' => (int) $accountTransaction->id,
                'refund_id' => (int) $refund->id,
                'origin_recharge_record_id' => (int) $originRechargeRecord->id,
                'scene' => 'refund',
                'direction' => 'out',
                'amount' => $this->money(-$offsetAmount),
                'currency' => 'CNY',
                'entry_type' => 'refund_offset',
                'remark' => '退款冲抵原充值记录 '.(string) $originRechargeRecord->record_no,
                'operator_type' => $this->nullableString($context['operator_type'] ?? 'admin'),
                'operator_id' => $this->nullableInt($context['operator_id'] ?? null),
                'operator_name' => $this->nullableString($context['operator_name'] ?? null),
                'trace_id' => $this->nullableString($context['trace_id'] ?? $invoice->trace_id),
            ]);
        }

        return [
            'refund' => $refund->fresh(),
            'refund_invoice' => $refundInvoice,
            'recharge_record' => $negativeRecord,
        ];
    }

    private function assertBusinessPaymentComposition(Invoice $invoice): void
    {
        $type = InvoiceType::normalize((string) $invoice->type);
        if (! in_array($type, [InvoiceType::NEW_PURCHASE, InvoiceType::RENEW], true)) {
            return;
        }

        $thirdPartyAmount = (float) Payment::query()
            ->where('invoice_id', (int) $invoice->id)
            ->whereIn('status', [PaymentStatus::SUCCESS, PaymentStatus::REFUNDED])
            ->sum('amount');
        $balanceAmount = -1 * (float) AccountTransaction::query()
            ->where('user_id', (int) $invoice->user_id)
            ->whereIn('event_type', [
                FinanceLedgerEventType::INVOICE_PAYMENT,
                FinanceLedgerEventType::INVOICE_REFUND,
            ])
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
            ->sum('change_amount');
        $invoiceAmount = max((float) $invoice->amount, (float) $invoice->paid_amount);

        if ((int) ($invoice->order_id ?? 0) > 0) {
            $orderAmount = (float) $invoice->order()->value('amount');
            throw_if(
                abs($orderAmount - $invoiceAmount) > 0.00001,
                new BusinessException('订单与账单金额不一致')
            );
        }

        throw_if(
            abs($invoiceAmount - $thirdPartyAmount - $balanceAmount) > 0.00001,
            new BusinessException('订单、账单与支付金额不一致')
        );
    }

    private function remainingOffsetAmount(RechargeRecord $record): float
    {
        $used = abs((float) RechargeRecord::query()
            ->where('origin_recharge_record_id', (int) $record->id)
            ->sum('amount'));

        return round(max((float) $record->amount - $used, 0), 2);
    }

    private function businessScene(Invoice $invoice): string
    {
        return InvoiceType::normalize((string) $invoice->type) === InvoiceType::RENEW
            ? 'renewal'
            : 'new_purchase';
    }

    private function assertRecordBinding(RechargeRecord $record, Invoice $invoice, Payment $payment): void
    {
        if ((int) $record->invoice_id !== (int) $invoice->id || (int) $record->payment_id !== (int) $payment->id) {
            throw new BusinessException('支付记录已绑定到其他账单');
        }
    }

    private function assertSameUser(mixed $firstUserId, mixed $secondUserId): void
    {
        throw_if((int) $firstUserId !== (int) $secondUserId, new BusinessException('单据归属用户不一致'));
    }

    private function assertPositiveAmount(float $amount, string $message): void
    {
        throw_if($amount <= 0, new BusinessException($message));
    }

    private function money(float|string $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
