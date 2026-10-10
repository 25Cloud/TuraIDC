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

        $this->assertSame('ORD202604051314150427', $pair['order_no']);
        $this->assertSame('INV202604051314150427', $pair['invoice_no']);
        $this->assertSame('20260405131415', $pair['timestamp']);
        $this->assertSame('0427', $pair['suffix']);
    }

    public function test_models_can_build_fixed_order_and_invoice_numbers(): void
    {
        $time = CarbonImmutable::create(2026, 4, 5, 9, 8, 7);

        $this->assertSame('ORD202604050908070123', Order::generateOrderNo($time, '0123'));
        $this->assertSame('INV202604050908070123', Invoice::generateInvoiceNo($time, '0123'));
    }

    public function test_invoice_number_can_be_derived_from_order_number(): void
    {
        $this->assertSame(
            'INV202604051314150427',
            Invoice::generateInvoiceNoFromOrderNo('ORD202604051314150427')
        );
    }

    public function test_order_number_can_be_derived_from_invoice_number(): void
    {
        $this->assertSame(
            'ORD202604051314150427',
            OrderInvoiceNoGenerator::deriveOrderNoFromInvoiceNo('INV202604051314150427')
        );
    }

    /**
     * 存量 dd / zd 编号仍需可互相派生，用于影子订单绑定与对账修复。
     */
    public function test_legacy_numbers_remain_derivable(): void
    {
        $this->assertSame(
            'zd202604051314150427',
            OrderInvoiceNoGenerator::deriveInvoiceNoFromOrderNo('dd202604051314150427')
        );
        $this->assertSame(
            'dd202604051314150427',
            OrderInvoiceNoGenerator::deriveOrderNoFromInvoiceNo('zd202604051314150427')
        );
    }

    /**
     * 前缀方向不得互串，且非法编号一律返回 null。
     */
    public function test_derivation_rejects_cross_prefixed_or_malformed_numbers(): void
    {
        $this->assertNull(OrderInvoiceNoGenerator::deriveInvoiceNoFromOrderNo('zd202604051314150427'));
        $this->assertNull(OrderInvoiceNoGenerator::deriveOrderNoFromInvoiceNo('dd202604051314150427'));
        $this->assertNull(OrderInvoiceNoGenerator::deriveInvoiceNoFromOrderNo('dd20260405131415'));
        $this->assertNull(OrderInvoiceNoGenerator::deriveInvoiceNoFromOrderNo('202604051314150427'));
        $this->assertNull(OrderInvoiceNoGenerator::deriveOrderNoFromInvoiceNo(''));
    }

    public function test_derivation_tolerates_surrounding_whitespace(): void
    {
        $this->assertSame(
            'INV202604051314150427',
            OrderInvoiceNoGenerator::deriveInvoiceNoFromOrderNo('  ORD202604051314150427  ')
        );
    }
}
