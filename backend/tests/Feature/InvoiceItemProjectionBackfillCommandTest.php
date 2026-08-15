<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class InvoiceItemProjectionBackfillCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_is_read_only_by_default_and_never_overwrites_existing_multiple_items(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'invoice-item-backfill-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Invoice Item Backfill',
        ]);
        $missingItemsInvoice = $this->createInvoice($user, '32.10');
        $existingItemsInvoice = $this->createInvoice($user, '40.00');
        $this->createInvoiceItem($existingItemsInvoice, '既有明细一', '10.00');
        $this->createInvoiceItem($existingItemsInvoice, '既有明细二', '30.00');

        $options = [
            '--invoice-ids' => implode(',', [(int) $missingItemsInvoice->id, (int) $existingItemsInvoice->id]),
            '--sample' => 1,
        ];

        $this->assertSame(0, Artisan::call('finance:backfill-invoice-item-projections', $options));
        $readOnlyOutput = Artisan::output();
        $this->assertStringContainsString('候选账单数: 1', $readOnlyOutput);
        $this->assertStringContainsString('只读模式：未写入任何账单明细', $readOnlyOutput);
        $this->assertSame(0, InvoiceItem::query()->where('invoice_id', $missingItemsInvoice->id)->count());
        $this->assertSame(2, InvoiceItem::query()->where('invoice_id', $existingItemsInvoice->id)->count());

        $this->assertSame(0, Artisan::call('finance:backfill-invoice-item-projections', [
            ...$options,
            '--execute' => true,
        ]));

        $executeOutput = Artisan::output();
        $this->assertStringContainsString('初始候选: 1', $executeOutput);
        $this->assertStringContainsString('补齐成功: 1', $executeOutput);
        $this->assertSame(1, InvoiceItem::query()->where('invoice_id', $missingItemsInvoice->id)->count());
        $this->assertSame(2, InvoiceItem::query()->where('invoice_id', $existingItemsInvoice->id)->count());
        $this->assertSame(
            '既有明细一',
            (string) InvoiceItem::query()
                ->where('invoice_id', $existingItemsInvoice->id)
                ->orderBy('id')
                ->value('item_name')
        );
    }

    private function createInvoice(User $user, string $amount): Invoice
    {
        return Invoice::query()->create([
            'invoice_no' => 'INVBACKFILL'.strtoupper(bin2hex(random_bytes(6))),
            'user_id' => (int) $user->id,
            'type' => 'deduction',
            'amount' => $amount,
            'paid_amount' => $amount,
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => null,
            'product_spec_snapshot' => '账单明细补齐测试',
        ]);
    }

    private function createInvoiceItem(Invoice $invoice, string $itemName, string $amount): InvoiceItem
    {
        return InvoiceItem::query()->create([
            'invoice_id' => (int) $invoice->id,
            'item_name' => $itemName,
            'item_type' => 'deduction',
            'quantity' => 1,
            'unit_price' => $amount,
            'discount_amount' => '0.00',
            'line_amount' => $amount,
            'meta_json' => ['source' => 'existing-multiple-items'],
        ]);
    }
}
