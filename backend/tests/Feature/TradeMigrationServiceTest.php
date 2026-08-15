<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\System\TradeMigrationService;
use Tests\TestCase;

class TradeMigrationServiceTest extends TestCase
{
    public function test_it_builds_invoice_payload_from_legacy_invoice_and_order_context(): void
    {
        $service = new TradeMigrationService;

        $payload = $service->buildInvoicePayload(
            legacyInvoice: [
                'id' => 10,
                'invoice_no' => 'INVRENEWSYNCE0007053',
                'user_id' => 37,
                'order_id' => 11,
                'product_id' => null,
                'service_id' => 5,
                'coupon_id' => null,
                'user_coupon_id' => null,
                'coupon_code' => null,
                'type' => 'normal',
                'amount' => '16.00',
                'discount' => '0.00',
                'billing_cycle' => null,
                'quantity' => 1,
                'config_snapshot' => null,
                'config_pricing_snapshot' => null,
                'coupon_snapshot' => null,
                'paid_amount' => '16.00',
                'status' => 1,
                'due_date' => '2026-05-19',
                'paid_at' => '2026-05-18 01:39:52',
                'created_at' => '2026-05-18 01:39:52',
                'updated_at' => '2026-05-18 01:39:52',
                'remark' => null,
                'operator' => null,
                'trace_id' => 'legacy-invoices-10',
                'product_spec_snapshot' => null,
                'product_type_snapshot' => null,
            ],
            legacyOrder: [
                'id' => 11,
                'type' => 'renew',
                'product_id' => 6,
                'billing_cycle' => 'monthly',
                'product_spec_snapshot' => '未配置规格 #6',
                'product_type_snapshot' => 'server',
                'config_snapshot' => '{"region":"hk"}',
                'config_pricing_snapshot' => '{"monthly":"16.00"}',
                'coupon_snapshot' => null,
                'coupon_code' => null,
            ],
            targetServiceInstanceId: 5
        );

        $this->assertSame(10, $payload['id']);
        $this->assertSame('INVRENEWSYNCE0007053', $payload['invoice_no']);
        $this->assertSame(37, $payload['user_id']);
        $this->assertSame('renewal', $payload['scene']);
        $this->assertSame(6, $payload['product_id']);
        $this->assertSame(5, $payload['service_instance_id']);
        $this->assertSame('CNY', $payload['currency']);
        $this->assertSame('16.00', $payload['subtotal_amount']);
        $this->assertSame('16.00', $payload['total_amount']);
        $this->assertSame('16.00', $payload['paid_amount']);
        $this->assertSame('monthly', $payload['billing_cycle']);
        $this->assertSame('2026-05-19 00:00:00', $payload['due_at']);
        $this->assertSame('2026-05-18 01:39:52', $payload['paid_at']);
        $this->assertJson($payload['product_snapshot_json']);
        $this->assertJson($payload['config_snapshot_json']);
        $this->assertJson($payload['pricing_snapshot_json']);
        $this->assertNull($payload['cancelled_at']);
    }

    public function test_it_builds_payment_payload_and_maps_gateway_trade_status(): void
    {
        $service = new TradeMigrationService;

        $payload = $service->buildPaymentPayload([
            'id' => 1,
            'payment_no' => 'PAYLEDGERD894BF5F',
            'user_id' => 31,
            'invoice_id' => 4,
            'gateway' => 'alipay',
            'trade_no' => 'TRADELEDGERD894BF5F',
            'amount' => '80.00',
            'status' => 1,
            'callback_raw' => '{"trace_id":"callback-trace-d894bf5f","trade_status":"TRADE_SUCCESS"}',
            'paid_at' => '2026-05-18 01:21:50',
            'created_at' => '2026-05-18 01:39:50',
            'updated_at' => '2026-05-18 01:39:50',
            'remark' => null,
            'operator' => null,
            'trace_id' => 'legacy-payments-1',
        ]);

        $this->assertSame(1, $payload['id']);
        $this->assertSame('PAYLEDGERD894BF5F', $payload['payment_no']);
        $this->assertSame(4, $payload['invoice_id']);
        $this->assertSame(31, $payload['user_id']);
        $this->assertSame('alipay', $payload['gateway']);
        $this->assertSame('TRADELEDGERD894BF5F', $payload['gateway_trade_no']);
        $this->assertSame('80.00', $payload['amount']);
        $this->assertSame(1, $payload['status']);
        $this->assertSame('2026-05-18 01:21:50', $payload['paid_at']);
        $this->assertJson($payload['callback_summary_json']);
    }

    public function test_it_builds_callback_payload_and_extracts_verify_message(): void
    {
        $service = new TradeMigrationService;

        $payload = $service->buildPaymentCallbackPayload([
            'id' => 4,
            'payment_id' => 4,
            'callback_type' => 'payment',
            'gateway_trade_no' => null,
            'payload_json' => '{"source":"alipay_precreate_mix","trace_id":"mix-pay-regression","mix_payment":true}',
            'is_verified' => 0,
            'received_at' => '2026-05-18 01:39:51',
            'remark' => '等待支付宝回调完成',
            'operator' => null,
            'trace_id' => null,
            'created_at' => '2026-05-18 01:39:51',
            'updated_at' => '2026-05-18 01:39:51',
        ]);

        $this->assertSame(4, $payload['id']);
        $this->assertSame(4, $payload['payment_id']);
        $this->assertSame('payment', $payload['callback_type']);
        $this->assertSame(0, $payload['is_verified']);
        $this->assertSame('等待支付宝回调完成', $payload['verify_message']);
        $this->assertJson($payload['payload_json']);
    }

    public function test_it_builds_refund_payload_from_refunded_payment(): void
    {
        $service = new TradeMigrationService;

        $payload = $service->buildRefundPayload(
            legacyPayment: [
                'id' => 15,
                'payment_no' => 'PAYREFUND0001',
                'user_id' => 68,
                'invoice_id' => 19,
                'gateway' => 'alipay',
                'trade_no' => 'TRADE-REFUND-0001',
                'amount' => '15.00',
                'status' => 3,
                'callback_raw' => '{"trade_status":"TRADE_CLOSED","refund_status":"REFUND_SUCCESS","refund_amount":"15.00","refund_no":"RF202605180001","reason":"用户申请退款","refunded_at":"2026-05-18 03:00:00"}',
                'paid_at' => '2026-05-18 01:40:17',
                'created_at' => '2026-05-18 01:40:17',
                'updated_at' => '2026-05-18 03:00:00',
                'remark' => null,
                'operator' => null,
                'trace_id' => 'legacy-payments-15',
            ],
            invoiceUserId: 68
        );

        $this->assertSame('RF202605180001', $payload['refund_no']);
        $this->assertSame(15, $payload['payment_id']);
        $this->assertSame(19, $payload['invoice_id']);
        $this->assertSame(68, $payload['user_id']);
        $this->assertSame('15.00', $payload['amount']);
        $this->assertSame(1, $payload['status']);
        $this->assertSame('用户申请退款', $payload['reason']);
        $this->assertSame('TRADE-REFUND-0001', $payload['gateway_refund_no']);
        $this->assertSame('2026-05-18 03:00:00', $payload['refunded_at']);
    }
}
