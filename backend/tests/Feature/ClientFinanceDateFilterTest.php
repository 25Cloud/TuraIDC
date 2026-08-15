<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\OrderStatus;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientFinanceDateFilterTest extends TestCase
{
    public function test_client_orders_invoices_and_payments_support_single_sided_dates(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = $this->createClientUser('client-finance-date-'.strtolower($suffix));
        $product = $this->createProduct('client-finance-date-'.$suffix);

        $this->createOrder($user, $product, [
            'order_no' => 'CORDDATE'.$suffix.'OLD',
            'created_at' => '2037-03-02 10:00:00',
            'updated_at' => '2037-03-02 10:00:00',
        ]);
        $newOrder = $this->createOrder($user, $product, [
            'order_no' => 'CORDDATE'.$suffix.'NEW',
            'created_at' => '2037-03-05 10:00:00',
            'updated_at' => '2037-03-05 10:00:00',
        ]);

        $oldInvoice = $this->createInvoice($user, $product, [
            'invoice_no' => 'CINVDATE'.$suffix.'OLD',
            'created_at' => '2037-03-02 11:00:00',
            'updated_at' => '2037-03-02 11:00:00',
        ]);
        $newInvoice = $this->createInvoice($user, $product, [
            'invoice_no' => 'CINVDATE'.$suffix.'NEW',
            'created_at' => '2037-03-05 11:00:00',
            'updated_at' => '2037-03-05 11:00:00',
        ]);

        $oldPayment = $this->createPayment($user, $oldInvoice, [
            'payment_no' => 'CPAYDATE'.$suffix.'OLD',
            'created_at' => '2037-03-02 12:00:00',
            'updated_at' => '2037-03-02 12:00:00',
        ]);
        $this->createPayment($user, $newInvoice, [
            'payment_no' => 'CPAYDATE'.$suffix.'NEW',
            'created_at' => '2037-03-05 12:00:00',
            'updated_at' => '2037-03-05 12:00:00',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/orders?keyword=CORDDATE'.$suffix.'&start_date=2037-03-03')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', (int) $newOrder->id);

        $this->getJson('/api/v2/client/invoices?keyword=CINVDATE'.$suffix.'&start_date=2037-03-03')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', (int) $newInvoice->id);

        $this->getJson('/api/v2/client/payments?keyword=CPAYDATE'.$suffix.'&end_date=2037-03-03')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', (int) $oldPayment->id);
    }

    public function test_client_finance_lists_reject_legacy_date_range_and_reverse_dates(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = $this->createClientUser('client-finance-invalid-'.strtolower($suffix));

        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'system_adjustment',
            'change_amount' => '10.00',
            'balance_after' => '10.00',
            'source_type' => 'manual_adjustment',
            'source_id' => 0,
            'remark' => 'old '.$suffix,
            'created_at' => '2037-04-02 10:00:00',
            'updated_at' => '2037-04-02 10:00:00',
        ]);
        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'system_adjustment',
            'change_amount' => '20.00',
            'balance_after' => '30.00',
            'source_type' => 'manual_adjustment',
            'source_id' => 0,
            'remark' => 'new '.$suffix,
            'created_at' => '2037-04-05 10:00:00',
            'updated_at' => '2037-04-05 10:00:00',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/balance-logs?event_type=system_adjustment&end_date=2037-04-03')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $legacyQuery = http_build_query([
            'date_range' => ['2037-04-01', '2037-04-06'],
        ]);

        $this->getJson('/api/v2/client/orders?'.$legacyQuery)
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $this->getJson('/api/v2/client/invoices?start_date=2037-04-06&end_date=2037-04-01')
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $this->getJson('/api/v2/client/invoices?per_page=50')
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);
    }

    private function createClientUser(string $prefix): User
    {
        return User::query()->create([
            'email' => "{$prefix}@example.com",
            'password' => 'secret123',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => '',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
            'balance' => '0.00',
        ]);
    }

    private function createProduct(string $name): Product
    {
        return Product::query()->create([
            'name' => $name,
            'product_type' => 'cloud',
            'remark' => $name,
            'pricing' => ['monthly' => '100.00'],
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 100,
            'status' => 1,
            'sort_order' => 1,
        ]);
    }

    private function createOrder(User $user, Product $product, array $overrides = []): Order
    {
        $order = Order::query()->create(array_merge([
            'order_no' => 'ORD'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => (string) $product->name,
            'product_type_snapshot' => (string) $product->product_type,
            'type' => 'new',
            'amount' => '100.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [],
            'config_pricing_snapshot' => [],
            'coupon_snapshot' => [],
            'status' => OrderStatus::PENDING,
        ], array_diff_key($overrides, array_flip(['created_at', 'updated_at']))));

        $dates = array_intersect_key($overrides, array_flip(['created_at', 'updated_at']));
        if ($dates !== []) {
            $order->forceFill($dates)->save();
        }

        return $order;
    }

    private function createInvoice(User $user, Product $product, array $overrides = []): Invoice
    {
        $invoice = Invoice::query()->create(array_merge([
            'invoice_no' => 'INV'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'type' => 'normal',
            'amount' => '100.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'status' => 0,
            'due_date' => now()->addDay(),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
        ], array_diff_key($overrides, array_flip(['created_at', 'updated_at']))));

        $dates = array_intersect_key($overrides, array_flip(['created_at', 'updated_at']));
        if ($dates !== []) {
            $invoice->forceFill($dates)->save();
        }

        return $invoice;
    }

    private function createPayment(User $user, Invoice $invoice, array $overrides = []): Payment
    {
        $payment = Payment::query()->create(array_merge([
            'payment_no' => 'PAY'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => 'alipay',
            'trade_no' => 'ALI'.strtoupper(bin2hex(random_bytes(4))),
            'amount' => '100.00',
            'status' => 1,
            'paid_at' => now(),
        ], array_diff_key($overrides, array_flip(['created_at', 'updated_at']))));

        $dates = array_intersect_key($overrides, array_flip(['created_at', 'updated_at']));
        if ($dates !== []) {
            $payment->forceFill($dates)->save();
        }

        return $payment;
    }
}
