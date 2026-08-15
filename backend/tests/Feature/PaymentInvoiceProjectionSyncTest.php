<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentInvoiceProjectionSyncTest extends TestCase
{
    public function test_invoice_service_create_from_order_still_materializes_invoice_items_without_model_hook(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'invoice-projection-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Invoice Projection',
        ]);

        $order = Order::query()->create([
            'order_no' => 'INVPRJ'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => null,
            'product_spec_snapshot' => '投影测试配置',
            'product_type_snapshot' => 'server',
            'type' => 'new',
            'amount' => '99.00',
            'discount' => '9.00',
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'status' => 0,
        ]);

        $invoice = app(InvoiceService::class)->createFromOrder($order);
        $invoice->refresh()->load('items');

        $this->assertCount(1, $invoice->items);
        $this->assertSame(2, (int) $invoice->items[0]->quantity);
        $this->assertSame('90.00', number_format((float) $invoice->items[0]->line_amount, 2, '.', ''));
    }

    public function test_referral_credit_and_deduction_invoices_materialize_invoice_items_on_creation(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'invoice-finance-projection-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Invoice Finance Projection',
        ]);

        $invoiceService = app(InvoiceService::class);
        $referralInvoice = $invoiceService->createForReferralCredit($user, 12.34, '推荐奖励投影测试');
        $deductionInvoice = $invoiceService->createForDeduction($user, 56.78, '扣款投影测试');

        $referralInvoice->refresh()->load('items');
        $deductionInvoice->refresh()->load('items');

        $this->assertCount(1, $referralInvoice->items);
        $this->assertSame('referral_credit', $referralInvoice->items->first()->item_type);
        $this->assertSame('12.34', number_format((float) $referralInvoice->items->first()->line_amount, 2, '.', ''));
        $this->assertCount(1, $deductionInvoice->items);
        $this->assertSame('deduction', $deductionInvoice->items->first()->item_type);
        $this->assertSame('56.78', number_format((float) $deductionInvoice->items->first()->line_amount, 2, '.', ''));
    }

    public function test_invoice_client_detail_loads_order_without_selecting_virtual_display_column(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'invoice-detail-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Invoice Detail',
        ]);

        $order = Order::query()->create([
            'order_no' => 'INVDET'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => null,
            'product_spec_snapshot' => '详情测试配置',
            'product_type_snapshot' => 'server',
            'type' => 'new',
            'amount' => '48.00',
            'discount' => '0.00',
            'paid_amount' => '48.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => 2,
            'paid_at' => now(),
        ]);

        $invoice = app(InvoiceService::class)->createFromOrder($order);
        $detail = app(InvoiceService::class)->clientDetail($invoice->refresh());

        $this->assertSame((int) $invoice->id, (int) $detail['id']);
        $this->assertSame('详情测试配置', $detail['product_display_name']);
        $this->assertSame('详情测试配置', $detail['combined_display_name']);
    }

    public function test_payment_service_sync_projection_materializes_payment_callbacks_without_model_hook(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'payment-projection-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Payment Projection',
        ]);

        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'invoice_id' => null,
            'gateway' => 'alipay',
            'trade_no' => 'TRADE-'.bin2hex(random_bytes(4)),
            'amount' => '19.90',
            'status' => PaymentStatus::SUCCESS,
            'callback_raw' => [
                'trade_no' => 'TRADE-CALLBACK-'.bin2hex(random_bytes(4)),
                'trade_status' => 'TRADE_SUCCESS',
                'refund' => [
                    'trade_no' => 'REFUND-'.bin2hex(random_bytes(4)),
                    'refunded_at' => now()->format('Y-m-d H:i:s'),
                ],
            ],
            'paid_at' => now(),
        ]);

        app(PaymentService::class)->syncProjection($payment);
        $payment->refresh()->load('callbacks');

        $this->assertCount(2, $payment->callbacks);
        $this->assertNotNull($payment->callbacks->firstWhere('callback_type', 'payment'));
        $this->assertNotNull($payment->callbacks->firstWhere('callback_type', 'refund'));
    }

    public function test_payment_service_sync_projection_gracefully_skips_callback_relation_when_projection_table_missing(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'payment-projection-skip-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Payment Projection Skip',
        ]);

        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'invoice_id' => null,
            'gateway' => 'alipay',
            'trade_no' => 'TRADE-'.bin2hex(random_bytes(4)),
            'amount' => '29.90',
            'status' => PaymentStatus::SUCCESS,
            'callback_raw' => [
                'trade_no' => 'TRADE-CALLBACK-'.bin2hex(random_bytes(4)),
                'trade_status' => 'TRADE_SUCCESS',
            ],
            'paid_at' => now(),
        ]);

        $actualSchema = DB::connection()->getSchemaBuilder();

        Schema::shouldReceive('hasTable')
            ->andReturnUsing(static function (string $table) use ($actualSchema): bool {
                return $table === 'payment_callbacks' ? false : $actualSchema->hasTable($table);
            });

        $synced = app(PaymentService::class)->syncProjection($payment);

        $this->assertInstanceOf(Payment::class, $synced);
        $this->assertSame((int) $payment->id, (int) $synced->id);
        $this->assertSame('TRADE_SUCCESS', (string) ($synced->callback_raw['trade_status'] ?? ''));
        $this->assertFalse($synced->relationLoaded('callbacks'));
    }

    public function test_payment_and_invoice_models_no_longer_use_saved_projection_hooks(): void
    {
        $paymentModel = file_get_contents(base_path('app/Models/Payment.php'));
        $invoiceModel = file_get_contents(base_path('app/Models/Invoice.php'));

        $this->assertIsString($paymentModel);
        $this->assertIsString($invoiceModel);
        $this->assertStringNotContainsString('static::saved', $paymentModel);
        $this->assertStringNotContainsString('static::saved', $invoiceModel);
    }
}
