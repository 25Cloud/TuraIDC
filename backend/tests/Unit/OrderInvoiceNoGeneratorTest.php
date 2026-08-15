<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\Order;
use App\Support\OrderInvoiceNoGenerator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class OrderInvoiceNoGeneratorTest extends TestCase
{
    public function test_build_pair_uses_expected_format_and_shared_suffix(): void
    {
        $time = CarbonImmutable::create(2026, 4, 5, 13, 14, 15);
        $pair = OrderInvoiceNoGenerator::buildPair($time, '0427');

        $this->assertSame('dd202604051314150427', $pair['order_no']);
        $this->assertSame('zd202604051314150427', $pair['invoice_no']);
        $this->assertSame('20260405131415', $pair['timestamp']);
        $this->assertSame('0427', $pair['suffix']);
    }

    public function test_models_can_build_fixed_order_and_invoice_numbers(): void
    {
        $time = CarbonImmutable::create(2026, 4, 5, 9, 8, 7);

        $this->assertSame('dd202604050908070123', Order::generateOrderNo($time, '0123'));
        $this->assertSame('zd202604050908070123', Invoice::generateInvoiceNo($time, '0123'));
    }

    public function test_invoice_number_can_be_derived_from_order_number(): void
    {
        $this->assertSame(
            'zd202604051314150427',
            Invoice::generateInvoiceNoFromOrderNo('dd202604051314150427')
        );
    }
}
