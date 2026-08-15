<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminFinanceDateFilterTest extends TestCase
{
    public function test_admin_orders_support_single_sided_dates_and_reject_legacy_date_range(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $admin = $this->createAdminUser(['order.list']);
        $user = $this->createClientUser('admin-order-date-'.strtolower($suffix));
        $product = $this->createProduct('admin-order-date-'.$suffix);

        $this->createOrder($user, $product, [
            'order_no' => 'ORDDATE'.$suffix.'OLD',
            'created_at' => '2037-01-02 10:00:00',
            'updated_at' => '2037-01-02 10:00:00',
        ]);
        $newOrder = $this->createOrder($user, $product, [
            'order_no' => 'ORDDATE'.$suffix.'NEW',
            'created_at' => '2037-01-05 10:00:00',
            'updated_at' => '2037-01-05 10:00:00',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/orders?keyword=ORDDATE'.$suffix.'&start_date=2037-01-03')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', (int) $newOrder->id);

        $this->getJson('/api/v2/admin/orders?keyword=ORDDATE'.$suffix.'&end_date=2037-01-03')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.order_no', 'ORDDATE'.$suffix.'OLD');

        $this->getJson('/api/v2/admin/orders?start_date=2037-01-06&end_date=2037-01-01')
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $legacyQuery = http_build_query([
            'date_range' => ['2037-01-01', '2037-01-06'],
        ]);

        $this->getJson('/api/v2/admin/orders?'.$legacyQuery)
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);
    }

    public function test_admin_invoices_and_recharges_support_single_sided_dates(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $admin = $this->createAdminUser(['invoice.list']);
        $user = $this->createClientUser('admin-finance-date-'.strtolower($suffix));
        $product = $this->createProduct('admin-finance-date-'.$suffix);

        $oldInvoice = $this->createInvoice($user, $product, [
            'invoice_no' => 'INVDATE'.$suffix.'OLD',
            'created_at' => '2037-02-02 10:00:00',
            'updated_at' => '2037-02-02 10:00:00',
        ]);
        $newInvoice = $this->createInvoice($user, $product, [
            'invoice_no' => 'INVDATE'.$suffix.'NEW',
            'created_at' => '2037-02-05 10:00:00',
            'updated_at' => '2037-02-05 10:00:00',
        ]);

        $oldPayment = $this->createPayment($user, $oldInvoice, [
            'payment_no' => 'PAYDATE'.$suffix.'OLD',
            'created_at' => '2037-02-02 11:00:00',
            'updated_at' => '2037-02-02 11:00:00',
        ]);
        $this->createPayment($user, $newInvoice, [
            'payment_no' => 'PAYDATE'.$suffix.'NEW',
            'created_at' => '2037-02-05 11:00:00',
            'updated_at' => '2037-02-05 11:00:00',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/invoices?keyword=INVDATE'.$suffix.'&start_date=2037-02-03')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', (int) $newInvoice->id);

        $this->getJson('/api/v2/admin/invoices?keyword=INVDATE'.$suffix.'&end_date=2037-02-03')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', (int) $oldInvoice->id);

        $this->getJson('/api/v2/admin/finance/recharges?keyword=PAYDATE'.$suffix.'&end_date=2037-02-03')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', (int) $oldPayment->id);
    }

    private function createAdminUser(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));

        $role = Role::query()->create([
            'name' => 'admin-finance-date-role-'.$suffix,
            'label' => 'Admin Finance Date Role',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'admin-finance-date-'.$suffix,
            'password' => 'secret123',
            'nickname' => 'Admin Finance Date',
            'role_id' => (int) $role->id,
            'status' => 1,
        ]);
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
            'status' => 0,
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
