<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\Payment;
use Tests\TestCase;

class VersionedJsonWriteSemanticsTest extends TestCase
{
    public function test_invoice_snapshots_are_written_with_schema_version(): void
    {
        $invoice = new Invoice;
        $invoice->config_snapshot = ['hostname' => 'invoice-host'];
        $invoice->config_pricing_snapshot = ['base_amount' => '88.00'];
        $invoice->coupon_snapshot = ['name' => 'Invoice Coupon'];

        $this->assertStringContainsString('"_schema_version":1', $invoice->getAttributes()['config_snapshot']);
        $this->assertStringContainsString('"invoice.config_pricing_snapshot"', $invoice->getAttributes()['config_pricing_snapshot']);
        $this->assertStringContainsString('"invoice.coupon_snapshot"', $invoice->getAttributes()['coupon_snapshot']);
    }

    public function test_payment_callback_mirror_is_written_with_schema_version(): void
    {
        $payment = new Payment;
        $payment->callback_raw = [
            'trade_no' => 'TRADE-VERSIONED',
            'trace_id' => 'trace-versioned',
        ];

        $this->assertStringContainsString('"_schema_version":1', $payment->getAttributes()['callback_raw']);
        $this->assertStringContainsString('"payment_callback.payment"', $payment->getAttributes()['callback_raw']);
    }
}
