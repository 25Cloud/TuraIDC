<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\PaymentStatus;
use App\Models\AdminUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Finance\AdminFinanceQueryService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminFinanceMenuControllerTest extends TestCase
{
    public function test_finance_menu_order_recharge_renewal_and_upgrade_lists_use_expected_sources(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = $this->createAdminUser([
            'order.list',
            'invoice.list',
            'invoice.detail',
            'finance.report',
        ]);
        $user = $this->createClientUser('finance-menu-'.$suffix);
        $product = $this->createProduct('finance-menu-product-'.$suffix);

        $newOrder = $this->createOrder($user, $product, [
            'order_no' => 'ORDNEW'.strtoupper($suffix),
            'type' => 'new',
            'status' => 0,
            'amount' => '120.00',
        ]);
        $this->createInvoice($user, $product, [
            'invoice_no' => 'INVNEW'.strtoupper($suffix),
            'order_id' => (int) $newOrder->id,
            'type' => 'normal',
            'status' => 0,
            'amount' => '120.00',
        ]);

        $renewOrder = $this->createOrder($user, $product, [
            'order_no' => 'ORDREN'.strtoupper($suffix),
            'type' => 'renew',
            'status' => 1,
            'amount' => '88.00',
        ]);
        $this->createInvoice($user, $product, [
            'invoice_no' => 'INVREN'.strtoupper($suffix),
            'order_id' => (int) $renewOrder->id,
            'type' => 'renew',
            'status' => 1,
            'amount' => '88.00',
            'paid_amount' => '88.00',
            'paid_at' => now()->subDay(),
        ]);

        $upgradeOrder = $this->createOrder($user, $product, [
            'order_no' => 'ORDADDON'.strtoupper($suffix),
            'type' => 'upgrade',
            'status' => 3,
            'amount' => '19.90',
            'config_pricing_snapshot' => [
                'meta' => [
                    'kind' => 'traffic_package',
                    'mode' => 'upgradeconfig',
                    'target_label' => '2TB',
                ],
            ],
        ]);

        $this->createInvoice($user, $product, [
            'invoice_no' => 'INVADDON'.strtoupper($suffix),
            'order_id' => (int) $upgradeOrder->id,
            'type' => 'upgrade',
            'status' => 1,
            'amount' => '19.90',
            'paid_amount' => '19.90',
            'paid_at' => now()->subHours(3),
        ]);

        $rechargeInvoice = $this->createInvoice($user, $product, [
            'invoice_no' => 'INVRECHARGE'.strtoupper($suffix),
            'type' => 'recharge',
            'status' => 1,
            'amount' => '200.00',
            'paid_amount' => '200.00',
            'paid_at' => now()->subHours(2),
        ]);
        $this->createInvoice($user, $product, [
            'invoice_no' => 'INVRECHARGEONLY'.strtoupper($suffix),
            'type' => 'recharge',
            'status' => 1,
            'amount' => '300.00',
            'paid_amount' => '300.00',
            'paid_at' => now()->subHour(),
        ]);
        Payment::query()->create([
            'payment_no' => 'PAYRECHARGE'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'invoice_id' => (int) $rechargeInvoice->id,
            'gateway' => 'alipay',
            'trade_no' => 'ALI'.strtoupper($suffix),
            'amount' => '200.00',
            'status' => 1,
            'paid_at' => now()->subHours(2),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/orders?keyword=ORDNEW'.strtoupper($suffix))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.order_no', 'ORDNEW'.strtoupper($suffix));

        $this->getJson('/api/v2/admin/finance/recharges?keyword=PAYRECHARGE'.strtoupper($suffix))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.payment_no', 'PAYRECHARGE'.strtoupper($suffix))
            ->assertJsonPath('data.list.0.invoice_no', 'INVRECHARGE'.strtoupper($suffix))
            ->assertJsonPath('data.list.0.amount', '200.00');

        $this->getJson('/api/v2/admin/finance/renewal-orders?keyword=ORDREN'.strtoupper($suffix))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.type', 'renew');

        $this->getJson('/api/v2/admin/finance/upgrade-orders?upgrade_kind=traffic_package&keyword=ORDADDON'.strtoupper($suffix))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.type', 'upgrade')
            ->assertJsonPath('data.list.0.upgrade_kind', 'traffic_package')
            ->assertJsonPath('data.list.0.upgrade_kind_label', '流量包');
    }

    public function test_finance_reports_use_daily_and_product_income_contracts(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = $this->createAdminUser(['finance.report', 'order.list', 'invoice.list']);
        $user = $this->createClientUser('finance-report-'.$suffix);
        $product = $this->createProduct('finance-report-product-'.$suffix);
        $reportMonth = '2037-05';
        $baselineDaily = app(AdminFinanceQueryService::class)->dailyCustomerSummary($reportMonth)['summary'];
        $baselineIncome = app(AdminFinanceQueryService::class)->productIncomeSummary($reportMonth)['summary'];
        $baselineRangeDaily = app(AdminFinanceQueryService::class)->dailyCustomerSummary('2037-05-03', '2037-05-03')['summary'];
        $baselineRangeIncome = app(AdminFinanceQueryService::class)->productIncomeSummary('2037-05-03', '2037-05-04')['summary'];

        $createdUser = $this->createClientUser('finance-report-new-'.$suffix);
        $createdUser->forceFill(['created_at' => '2037-05-03 08:00:00', 'updated_at' => '2037-05-03 08:00:00'])->save();

        $this->createOrder($user, $product, [
            'order_no' => 'ORDDAYNEW'.strtoupper($suffix),
            'type' => 'new',
            'status' => 0,
            'created_at' => '2037-05-03 09:00:00',
        ]);
        $this->createOrder($user, $product, [
            'order_no' => 'ORDDAYDONE'.strtoupper($suffix),
            'type' => 'new',
            'status' => 3,
            'created_at' => '2037-05-01 09:00:00',
            'updated_at' => '2037-05-03 10:00:00',
        ]);
        $this->createOrder($user, $product, [
            'order_no' => 'ORDDAYCANCEL'.strtoupper($suffix),
            'type' => 'new',
            'status' => 4,
            'created_at' => '2037-05-01 09:00:00',
            'updated_at' => '2037-05-03 11:00:00',
        ]);

        $ticket = Ticket::query()->create([
            'user_id' => (int) $user->id,
            'department' => 'support',
            'subject' => 'finance report ticket '.$suffix,
            'priority' => 1,
            'status' => 0,
        ]);
        $ticket->forceFill(['created_at' => '2037-05-03 12:00:00', 'updated_at' => '2037-05-03 12:00:00'])->save();

        TicketReply::query()->create([
            'ticket_id' => (int) $ticket->id,
            'user_id' => (int) $admin->id,
            'content' => 'staff reply',
            'is_staff' => 1,
            'created_at' => '2037-05-03 13:00:00',
        ]);
        TicketReply::query()->create([
            'ticket_id' => (int) $ticket->id,
            'user_id' => (int) $user->id,
            'content' => 'user reply',
            'is_staff' => 0,
            'created_at' => '2037-05-03 14:00:00',
        ]);

        $this->createInvoice($user, $product, [
            'invoice_no' => 'INVINCNEW'.strtoupper($suffix),
            'type' => 'normal',
            'status' => 1,
            'amount' => '100.00',
            'paid_amount' => '100.00',
            'quantity' => 2,
            'paid_at' => '2037-05-03 15:00:00',
        ]);
        $this->createInvoice($user, $product, [
            'invoice_no' => 'INVINCRENEW'.strtoupper($suffix),
            'type' => 'renew',
            'status' => 1,
            'amount' => '80.00',
            'paid_amount' => '80.00',
            'quantity' => 1,
            'paid_at' => '2037-05-04 15:00:00',
        ]);
        $this->createInvoice($user, $product, [
            'invoice_no' => 'INVINCEXCLUDED'.strtoupper($suffix),
            'type' => 'recharge',
            'status' => 1,
            'amount' => '999.00',
            'paid_amount' => '999.00',
            'quantity' => 1,
            'paid_at' => '2037-05-04 16:00:00',
        ]);
        $refundedPaidInvoice = $this->createInvoice($user, $product, [
            'invoice_no' => 'INVINCREFUND'.strtoupper($suffix),
            'type' => 'normal',
            'status' => 1,
            'amount' => '120.00',
            'paid_amount' => '120.00',
            'quantity' => 1,
            'paid_at' => '2037-05-04 17:00:00',
        ]);
        Payment::query()->create([
            'payment_no' => 'PAYREFUND'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'invoice_id' => (int) $refundedPaidInvoice->id,
            'gateway' => 'alipay',
            'amount' => '120.00',
            'status' => PaymentStatus::REFUNDED,
            'paid_at' => '2037-05-04 17:00:00',
            'callback_raw' => [
                'refund' => [
                    'refund_amount' => '120.00',
                    'refund_method' => 'alipay',
                    'refunded_at' => '2037-05-04 17:10:00',
                ],
            ],
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/finance/new-customer-daily-summary?month='.$reportMonth)
            ->assertOk()
            ->assertJsonPath('data.summary.new_customers', (int) ($baselineDaily['new_customers'] ?? 0) + 1)
            ->assertJsonPath('data.summary.new_orders', (int) ($baselineDaily['new_orders'] ?? 0) + 3)
            ->assertJsonPath('data.summary.completed_orders', (int) ($baselineDaily['completed_orders'] ?? 0) + 1)
            ->assertJsonPath('data.summary.new_tickets', (int) ($baselineDaily['new_tickets'] ?? 0) + 1)
            ->assertJsonPath('data.summary.ticket_replies', (int) ($baselineDaily['ticket_replies'] ?? 0) + 1)
            ->assertJsonPath('data.summary.cancel_requests', (int) ($baselineDaily['cancel_requests'] ?? 0) + 1);

        $this->getJson('/api/v2/admin/finance/product-income-summary?month='.$reportMonth)
            ->assertOk()
            ->assertJsonPath('data.summary.new_income', $this->money((float) ($baselineIncome['new_income'] ?? 0) + 100))
            ->assertJsonPath('data.summary.new_quantity', (int) ($baselineIncome['new_quantity'] ?? 0) + 2)
            ->assertJsonPath('data.summary.renew_income', $this->money((float) ($baselineIncome['renew_income'] ?? 0) + 80))
            ->assertJsonPath('data.summary.renew_quantity', (int) ($baselineIncome['renew_quantity'] ?? 0) + 1)
            ->assertJsonPath('data.summary.total_amount', $this->money((float) ($baselineIncome['total_amount'] ?? 0) + 180));

        $this->getJson('/api/v2/admin/finance/new-customer-daily-summary?start_date=2037-05-03&end_date=2037-05-03')
            ->assertOk()
            ->assertJsonPath('data.start_date', '2037-05-03')
            ->assertJsonPath('data.end_date', '2037-05-03')
            ->assertJsonPath('data.summary.new_customers', (int) ($baselineRangeDaily['new_customers'] ?? 0) + 1)
            ->assertJsonPath('data.summary.new_orders', (int) ($baselineRangeDaily['new_orders'] ?? 0) + 1)
            ->assertJsonPath('data.summary.completed_orders', (int) ($baselineRangeDaily['completed_orders'] ?? 0) + 1)
            ->assertJsonPath('data.summary.cancel_requests', (int) ($baselineRangeDaily['cancel_requests'] ?? 0) + 1);

        $this->getJson('/api/v2/admin/finance/product-income-summary?start_date=2037-05-03&end_date=2037-05-04')
            ->assertOk()
            ->assertJsonPath('data.start_date', '2037-05-03')
            ->assertJsonPath('data.end_date', '2037-05-04')
            ->assertJsonPath('data.summary.new_income', $this->money((float) ($baselineRangeIncome['new_income'] ?? 0) + 100))
            ->assertJsonPath('data.summary.renew_income', $this->money((float) ($baselineRangeIncome['renew_income'] ?? 0) + 80))
            ->assertJsonPath('data.summary.total_amount', $this->money((float) ($baselineRangeIncome['total_amount'] ?? 0) + 180));
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

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function createAdminUser(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));

        $role = Role::query()->create([
            'name' => 'finance-menu-role-'.$suffix,
            'label' => 'Finance Menu Role',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'finance-menu-admin-'.$suffix,
            'password' => 'secret123',
            'nickname' => 'Finance Menu Admin',
            'role_id' => (int) $role->id,
            'status' => 1,
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
        return Invoice::query()->create(array_merge([
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
        ], $overrides));
    }
}
