<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\FinanceLedgerEventType;
use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RechargeRecord;
use App\Models\Refund;
use App\Models\User;
use App\Services\Finance\FinanceDocumentService;
use App\Services\Finance\PaymentService;
use Tests\TestCase;

class FinanceDocumentGenerationTest extends TestCase
{
    public function test_new_purchase_and_renewal_record_only_the_third_party_paid_portion(): void
    {
        [$user, $order, $invoice, $payment] = $this->createPaidBusinessDocuments(
            type: 'new',
            invoiceAmount: '100.00',
            paymentAmount: '50.00',
            balanceAmount: '50.00',
        );

        $service = app(FinanceDocumentService::class);
        $newPurchaseRecord = $service->recordThirdPartyPayment($payment, $invoice);

        $this->assertSame('new_purchase', $newPurchaseRecord->scene);
        $this->assertSame('50.00', $newPurchaseRecord->amount);
        $this->assertSame('CNY', $newPurchaseRecord->currency);
        $this->assertSame('CNY', $order->fresh()->currency);
        $this->assertSame('CNY', $invoice->fresh()->currency);
        $this->assertSame('CNY', $payment->fresh()->currency);
        $this->assertSame((int) $order->id, (int) $newPurchaseRecord->order_id);
        $this->assertSame((int) $invoice->id, (int) $newPurchaseRecord->invoice_id);
        $this->assertSame((int) $payment->id, (int) $newPurchaseRecord->payment_id);

        [, $renewOrder, $renewInvoice, $renewPayment] = $this->createPaidBusinessDocuments(
            type: 'renew',
            invoiceAmount: '200.00',
            paymentAmount: '200.00',
            balanceAmount: '0.00',
        );

        $renewRecord = $service->recordThirdPartyPayment($renewPayment, $renewInvoice);

        $this->assertSame('renewal', $renewRecord->scene);
        $this->assertSame('200.00', $renewRecord->amount);
        $this->assertSame((int) $renewOrder->id, (int) $renewRecord->order_id);
    }

    public function test_user_recharge_binds_invoice_payment_balance_transaction_and_recharge_record(): void
    {
        $user = $this->createUser('user-recharge');
        $payment = $this->createSuccessfulPayment($user, '100.00');
        $invoice = Invoice::query()->create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => (int) $user->id,
            'type' => 'recharge',
            'amount' => '100.00',
            'paid_amount' => '100.00',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
        ]);
        $payment->forceFill(['invoice_id' => $invoice->id])->save();
        $transaction = $this->createBalanceTransaction($user, FinanceLedgerEventType::RECHARGE, '100.00', '100.00', 'payment', (int) $payment->id);

        $record = app(FinanceDocumentService::class)->recordRecharge(
            invoice: $invoice,
            payment: $payment->fresh(),
            accountTransaction: $transaction,
            scene: 'user_recharge',
        );

        $this->assertSame('100.00', $record->amount);
        $this->assertSame((int) $invoice->id, (int) $record->invoice_id);
        $this->assertSame((int) $payment->id, (int) $record->payment_id);
        $this->assertSame((int) $transaction->id, (int) $record->account_transaction_id);
    }

    public function test_admin_credit_creates_invoice_and_recharge_record_but_admin_debit_does_not(): void
    {
        $user = $this->createUser('admin-adjust');
        $paymentService = app(PaymentService::class);

        $creditResult = $paymentService->adjustBalance($user, 100.00, '补偿充值', [
            'operator_id' => 7,
            'operator_name' => 'finance-admin',
            'trace_id' => 'test-admin-credit',
        ]);

        $this->assertSame('100.00', $creditResult['invoice']->amount);
        $this->assertSame('管理员手工充值', $creditResult['recharge_record']->remark);
        $this->assertSame('finance-admin', $creditResult['invoice']->operator);
        $this->assertSame('CNY', $creditResult['transaction']->fresh()->currency);
        $this->assertSame('finance-admin', $creditResult['transaction']->fresh()->operator);
        $this->assertSame('admin', $creditResult['recharge_record']->operator_type);
        $this->assertSame(7, (int) $creditResult['recharge_record']->operator_id);
        $this->assertSame('finance-admin', $creditResult['recharge_record']->operator_name);
        $this->assertSame((int) $creditResult['invoice']->id, (int) $creditResult['recharge_record']->invoice_id);
        $this->assertSame((int) $creditResult['transaction']->id, (int) $creditResult['recharge_record']->account_transaction_id);

        $debitResult = $paymentService->adjustBalance($user->fresh(), -50.00, '管理员扣费', [
            'operator_id' => 7,
            'operator_name' => 'finance-admin',
            'trace_id' => 'test-admin-debit',
        ]);

        $this->assertSame('deduction', $debitResult['invoice']->type);
        $this->assertNull($debitResult['recharge_record']);
        $this->assertDatabaseMissing('recharge_records', [
            'invoice_id' => (int) $debitResult['invoice']->id,
        ]);
    }

    public function test_refund_to_balance_creates_refund_document_red_invoice_and_negative_recharge_record(): void
    {
        [$user, $order, $invoice, $payment] = $this->createPaidBusinessDocuments(
            type: 'new',
            invoiceAmount: '100.00',
            paymentAmount: '100.00',
            balanceAmount: '0.00',
        );
        $originalRecord = app(FinanceDocumentService::class)->recordThirdPartyPayment($payment, $invoice);

        $result = app(PaymentService::class)->refundInvoiceToBalance($user, $invoice, [
            'amount' => '100.00',
            'remark' => '测试退款到余额',
        ], [
            'operator_id' => 9,
            'operator_name' => 'refund-admin',
            'trace_id' => 'test-refund-balance',
        ]);

        $refund = Refund::query()->findOrFail((int) $result['refund_id']);
        $redInvoice = Invoice::query()->findOrFail((int) $refund->refund_invoice_id);
        $negativeRecord = RechargeRecord::query()->findOrFail((int) $result['recharge_record_id']);
        $redInvoice->load('items');

        $this->assertSame((int) $invoice->id, (int) $refund->invoice_id);
        $this->assertSame((int) $invoice->id, (int) $redInvoice->origin_invoice_id);
        $this->assertSame('refund', $redInvoice->type);
        $this->assertSame('-100.00', $redInvoice->amount);
        $this->assertCount(1, $redInvoice->items);
        $this->assertSame('refund', $redInvoice->items->first()->item_type);
        $this->assertSame('-100.00', number_format((float) $redInvoice->items->first()->line_amount, 2, '.', ''));
        $this->assertSame('-100.00', $negativeRecord->amount);
        $this->assertSame((int) $originalRecord->id, (int) $negativeRecord->origin_recharge_record_id);
        $this->assertSame((int) $order->id, (int) $negativeRecord->order_id);
        $this->assertSame('admin', $refund->operator_type);
        $this->assertSame(9, (int) $refund->operator_id);
        $this->assertSame('refund-admin', $refund->operator_name);
        $this->assertSame('refund-admin', (string) AccountTransaction::query()
            ->where('event_type', FinanceLedgerEventType::INVOICE_REFUND)
            ->where('source_id', (int) $invoice->id)
            ->latest('id')
            ->value('operator'));
        $this->assertSame(InvoiceStatus::REFUNDED, (int) $invoice->fresh()->status);
        $this->assertSame(OrderStatus::REFUNDED, (int) $order->fresh()->status);
    }

    public function test_refund_amount_cannot_exceed_original_refundable_amount_or_original_recharge_record_balance(): void
    {
        [$user, , $invoice, $payment] = $this->createPaidBusinessDocuments(
            type: 'new',
            invoiceAmount: '100.00',
            paymentAmount: '50.00',
            balanceAmount: '50.00',
        );
        app(FinanceDocumentService::class)->recordThirdPartyPayment($payment, $invoice);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('退款金额超过原单可退金额');

        app(PaymentService::class)->refundInvoiceToBalance($user, $invoice, [
            'amount' => '100.01',
            'remark' => '超额退款',
        ]);
    }

    public function test_partial_refund_offsets_no_more_than_the_original_third_party_recharge_record(): void
    {
        [$user, , $invoice, $payment] = $this->createPaidBusinessDocuments(
            type: 'new',
            invoiceAmount: '100.00',
            paymentAmount: '50.00',
            balanceAmount: '50.00',
        );
        $originalRecord = app(FinanceDocumentService::class)->recordThirdPartyPayment($payment, $invoice);

        $result = app(PaymentService::class)->refundInvoiceToBalance($user, $invoice, [
            'amount' => '60.00',
            'remark' => '混合支付部分退款',
        ]);

        $negativeRecord = RechargeRecord::query()->findOrFail((int) $result['recharge_record_id']);

        $this->assertSame(InvoiceStatus::PARTIALLY_REFUNDED, (int) $invoice->fresh()->status);
        $this->assertSame('-50.00', $negativeRecord->amount);
        $this->assertSame((int) $originalRecord->id, (int) $negativeRecord->origin_recharge_record_id);
    }

    public function test_paid_by_balance_only_invoice_can_be_refunded_without_a_third_party_payment_or_recharge_record(): void
    {
        $user = $this->createUser('balance-only-refund');
        $order = Order::query()->create([
            'order_no' => Order::generateOrderNo(),
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '100.00',
            'paid_amount' => '100.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::COMPLETED,
        ]);
        $invoice = Invoice::query()->create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'new',
            'amount' => '100.00',
            'paid_amount' => '100.00',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
        ]);
        $this->createBalanceTransaction(
            $user,
            FinanceLedgerEventType::INVOICE_PAYMENT,
            '-100.00',
            '0.00',
            'invoice',
            (int) $invoice->id,
        );

        $result = app(PaymentService::class)->refundInvoiceToBalance($user, $invoice, [
            'amount' => '100.00',
            'remark' => '纯余额退款',
        ]);

        $refund = Refund::query()->findOrFail((int) $result['refund_id']);

        $this->assertNull($refund->payment_id);
        $this->assertNull($result['recharge_record_id']);
        $this->assertSame(InvoiceStatus::REFUNDED, (int) $invoice->fresh()->status);
        $this->assertSame(OrderStatus::REFUNDED, (int) $order->fresh()->status);
        $this->assertDatabaseMissing('recharge_records', [
            'invoice_id' => (int) $result['refund_invoice_id'],
        ]);
    }

    /**
     * @return array{0: User, 1: Order, 2: Invoice, 3: Payment}
     */
    private function createPaidBusinessDocuments(string $type, string $invoiceAmount, string $paymentAmount, string $balanceAmount): array
    {
        $user = $this->createUser('business-'.$type);
        $order = Order::query()->create([
            'order_no' => Order::generateOrderNo(),
            'user_id' => (int) $user->id,
            'type' => $type,
            'amount' => $invoiceAmount,
            'paid_amount' => $invoiceAmount,
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::COMPLETED,
        ]);
        $invoice = Invoice::query()->create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => $type,
            'amount' => $invoiceAmount,
            'paid_amount' => $invoiceAmount,
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
        ]);
        $payment = $this->createSuccessfulPayment($user, $paymentAmount, $order, $invoice);

        if ((float) $balanceAmount > 0) {
            $this->createBalanceTransaction(
                $user,
                FinanceLedgerEventType::INVOICE_PAYMENT,
                '-'.number_format((float) $balanceAmount, 2, '.', ''),
                '0.00',
                'invoice',
                (int) $invoice->id,
            );
        }

        return [$user, $order, $invoice, $payment];
    }

    private function createUser(string $prefix): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => $prefix.'-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => $prefix,
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);
    }

    private function createSuccessfulPayment(User $user, string $amount, ?Order $order = null, ?Invoice $invoice = null): Payment
    {
        return Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'order_id' => $order?->id,
            'invoice_id' => $invoice?->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'amount' => $amount,
            'status' => PaymentStatus::SUCCESS,
            'trade_no' => 'TRADE-'.strtoupper(bin2hex(random_bytes(6))),
            'paid_at' => now(),
        ]);
    }

    private function createBalanceTransaction(
        User $user,
        string $eventType,
        string $changeAmount,
        string $balanceAfter,
        string $sourceType,
        int $sourceId,
    ): AccountTransaction {
        return AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => $eventType,
            'change_amount' => $changeAmount,
            'balance_after' => $balanceAfter,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'origin_type' => $sourceType,
            'origin_id' => $sourceId,
            'remark' => '测试账户流水',
        ]);
    }
}
