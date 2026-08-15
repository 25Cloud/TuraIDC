<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\AdminUser;
use App\Models\Invoice;
use App\Models\OperationLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentCallback;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceLedgerControllerTest extends TestCase
{
    public function test_client_finance_ledger_returns_only_current_users_records_and_normalizes_legacy_events(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createClientUser('ledger-client-'.$suffix, ['balance' => '128.80']);
        $otherUser = $this->createClientUser('ledger-other-'.$suffix, ['balance' => '9.90']);

        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'consume',
            'change_amount' => '-20.00',
            'balance_after' => '108.80',
            'source_type' => 'invoice',
            'source_id' => 101001,
            'origin_type' => 'invoice',
            'origin_id' => 101001,
            'remark' => '账单支付测试',
            'operator' => 'system',
            'trace_id' => 'trace-consume-'.$suffix,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'admin_deduct',
            'change_amount' => '-8.00',
            'balance_after' => '100.80',
            'source_type' => 'manual_adjustment',
            'source_id' => 101002,
            'origin_type' => 'manual_adjustment',
            'origin_id' => 101002,
            'remark' => '手工扣款测试',
            'operator' => 'admin',
            'trace_id' => 'trace-deduct-'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AccountTransaction::query()->create([
            'user_id' => (int) $otherUser->id,
            'account_type' => 'cash',
            'event_type' => 'recharge',
            'change_amount' => '9.90',
            'balance_after' => '9.90',
            'source_type' => 'payment',
            'source_id' => 201001,
            'origin_type' => 'payment',
            'origin_id' => 201001,
            'remark' => '其他用户记录',
            'operator' => 'system',
            'trace_id' => 'trace-other-'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/finance/ledger?page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.event_type', 'manual_deduction')
            ->assertJsonPath('data.list.1.event_type', 'invoice_payment');

        $this->getJson('/api/v2/client/finance/ledger?event_type=invoice_payment&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.event_type', 'invoice_payment');

        $this->getJson('/api/v2/client/finance/ledger/summary')
            ->assertOk()
            ->assertJsonPath('data.cash_balance', '128.80')
            ->assertJsonMissingPath('data.balance')
            ->assertJsonPath('data.total_out', '28.00')
            ->assertJsonPath('data.manual_adjust_out', '8.00');
    }

    public function test_client_finance_ledger_exposes_payment_identifiers_and_hides_trace_id(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = $this->createClientUser('ledger-safe-'.$suffix, ['balance' => '76.00']);

        $order = Order::query()->create([
            'order_no' => 'ORDSAFE'.$suffix,
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '80.00',
            'discount' => '0.00',
            'paid_amount' => '80.00',
            'billing_cycle' => 'monthly',
            'status' => 1,
            'paid_at' => now(),
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVSAFE'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'new',
            'amount' => '80.00',
            'paid_amount' => '80.00',
            'status' => 1,
            'due_date' => now()->addDay(),
            'paid_at' => now(),
        ]);

        $payment = Payment::query()->create([
            'payment_no' => 'PAYSAFE'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => 'alipay',
            'trade_no' => 'TRADESAFE'.$suffix,
            'amount' => '80.00',
            'status' => 1,
            'paid_at' => now(),
        ]);

        $ledger = AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'consume',
            'change_amount' => '-80.00',
            'balance_after' => '76.00',
            'source_type' => 'invoice',
            'source_id' => (int) $invoice->id,
            'origin_type' => 'payment',
            'origin_id' => (int) $payment->id,
            'remark' => '客户端资金明细脱敏测试',
            'operator' => 'system',
            'trace_id' => 'ledger-safe-trace-'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/finance/ledger?page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.payment.payment_no', (string) $payment->payment_no)
            ->assertJsonPath('data.list.0.payment.trade_no', (string) $payment->trade_no)
            ->assertJsonMissingPath('data.list.0.trace_id');

        $this->getJson('/api/v2/client/finance/ledger/'.$ledger->id)
            ->assertOk()
            ->assertJsonPath('data.payment.payment_no', (string) $payment->payment_no)
            ->assertJsonPath('data.payment.trade_no', (string) $payment->trade_no)
            ->assertJsonMissingPath('data.trace_id');
    }

    public function test_client_finance_ledger_v2_rejects_legacy_and_non_list_pagination_parameters(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createClientUser('ledger-client-validation-'.$suffix);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/finance/ledger?per_page=20')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/client/finance/ledger?pageSize=20')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $this->getJson('/api/v2/client/finance/ledger/summary?page=1')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);

        $this->getJson('/api/v2/client/finance/ledger/1?pageSize=20')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);
    }

    public function test_admin_finance_ledger_returns_all_records_and_supports_filters(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = $this->createAdminUser(['invoice.list', 'invoice.detail']);
        $user = $this->createClientUser('ledger-admin-'.$suffix, ['balance' => '88.00']);

        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'recharge',
            'change_amount' => '100.00',
            'balance_after' => '100.00',
            'source_type' => 'payment',
            'source_id' => 301001,
            'origin_type' => 'payment',
            'origin_id' => 301001,
            'remark' => '充值到账',
            'operator' => 'system',
            'trace_id' => 'trace-recharge-'.$suffix,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'refund',
            'change_amount' => '12.00',
            'balance_after' => '112.00',
            'source_type' => 'payment',
            'source_id' => 301002,
            'origin_type' => 'payment',
            'origin_id' => 301002,
            'remark' => '退款到账',
            'operator' => 'system',
            'trace_id' => 'trace-refund-'.$suffix,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'adjust',
            'change_amount' => '-24.00',
            'balance_after' => '88.00',
            'source_type' => 'manual_adjustment',
            'source_id' => 301003,
            'origin_type' => 'manual_adjustment',
            'origin_id' => 301003,
            'remark' => '系统调账',
            'operator' => 'admin',
            'trace_id' => 'trace-adjust-'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/finance/ledger?page_size=20&user_id='.$user->id)
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.list.0.event_type', 'system_adjustment')
            ->assertJsonPath('data.list.1.event_type', 'invoice_refund')
            ->assertJsonPath('data.list.2.event_type', 'recharge');

        $this->getJson('/api/v2/admin/finance/ledger?tab=adjustment&page_size=20&user_id='.$user->id)
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.event_type', 'system_adjustment');

        $this->getJson('/api/v2/admin/finance/ledger/summary?user_id='.$user->id)
            ->assertOk()
            ->assertJsonPath('data.total_in', '112.00')
            ->assertJsonPath('data.total_out', '24.00')
            ->assertJsonPath('data.refund_in', '12.00');
    }

    public function test_admin_finance_ledger_v2_rejects_legacy_and_non_list_pagination_parameters(): void
    {
        $admin = $this->createAdminUser(['invoice.list', 'invoice.detail']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/finance/ledger?per_page=20&pageSize=20')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $this->getJson('/api/v2/admin/finance/ledger/summary?page_size=20')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['page_size']]]);

        $this->getJson('/api/v2/admin/finance/ledger/1?page=1')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);
    }

    public function test_admin_finance_ledger_detail_returns_audit_chain_payload(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = $this->createAdminUser(['invoice.list', 'invoice.detail']);
        $user = $this->createClientUser('ledger-detail-'.$suffix, ['balance' => '76.00']);

        $order = Order::query()->create([
            'order_no' => 'ORDLEDGER'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_spec_snapshot' => '高频云主机',
            'type' => 'new',
            'amount' => '88.00',
            'discount' => '8.00',
            'paid_amount' => '80.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => 1,
            'paid_at' => now()->subMinutes(25),
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVLEDGER'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'amount' => '80.00',
            'paid_amount' => '80.00',
            'status' => 1,
            'due_date' => now()->addDay(),
            'paid_at' => now()->subMinutes(20),
        ]);

        $payment = Payment::query()->create([
            'payment_no' => 'PAYLEDGER'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => 'alipay',
            'trade_no' => 'TRADELEDGER'.strtoupper($suffix),
            'amount' => '80.00',
            'status' => 1,
            'callback_raw' => [
                'trace_id' => 'callback-trace-'.$suffix,
                'trade_status' => 'TRADE_SUCCESS',
            ],
            'paid_at' => now()->subMinutes(18),
        ]);

        $ledger = AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'consume',
            'change_amount' => '-80.00',
            'balance_after' => '76.00',
            'source_type' => 'invoice',
            'source_id' => (int) $invoice->id,
            'origin_type' => 'payment',
            'origin_id' => (int) $payment->id,
            'remark' => '余额支付账单',
            'operator' => 'system',
            'trace_id' => 'ledger-trace-'.$suffix,
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(15),
        ]);

        $callback = PaymentCallback::query()->create([
            'payment_id' => (int) $payment->id,
            'callback_type' => 'payment',
            'gateway_trade_no' => 'TRADELEDGER'.strtoupper($suffix),
            'payload_json' => [
                'trade_status' => 'TRADE_SUCCESS',
                'trace_id' => 'ledger-trace-'.$suffix,
            ],
            'is_verified' => 1,
            'received_at' => now()->subMinutes(17),
        ]);

        OperationLog::query()->create([
            'user_id' => (int) $user->id,
            'user_type' => 'client',
            'action' => 'invoice.paid',
            'module' => 'invoice',
            'subject_id' => (int) $invoice->id,
            'context' => [
                'trace_id' => 'ledger-trace-'.$suffix,
                'actor_name' => 'Client User',
                'invoice_no' => $invoice->invoice_no,
            ],
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subMinutes(16),
        ]);

        OperationLog::query()->create([
            'user_id' => (int) $user->id,
            'user_type' => 'client',
            'action' => 'order.paid',
            'module' => 'order',
            'subject_id' => (int) $order->id,
            'context' => [
                'trace_id' => 'ledger-trace-'.$suffix,
                'actor_name' => 'Client User',
                'order_no' => $order->order_no,
            ],
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subMinutes(19),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v2/admin/finance/ledger/'.$ledger->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.ledger_id', (int) $ledger->id)
            ->assertJsonPath('data.event_type', 'invoice_payment')
            ->assertJsonPath('data.audit.source_chain.0.key', 'order')
            ->assertJsonPath('data.audit.source_chain.1.key', 'invoice')
            ->assertJsonPath('data.audit.source_chain.2.key', 'payment')
            ->assertJsonPath('data.audit.source_chain.3.key', 'ledger')
            ->assertJsonPath('data.audit.payment_callbacks.0.callback_type', 'payment')
            ->assertJsonPath('data.audit.payment_callbacks.0.is_verified', 1)
            ->assertJsonPath('data.audit.trace_links.0.value', 'ledger-trace-'.$suffix);

        $payload = $response->json('data');
        $timelineKeys = array_column($payload['audit']['status_timeline'] ?? [], 'key');
        $logModules = array_column($payload['audit']['audit_logs'] ?? [], 'module');

        $this->assertContains('order', $timelineKeys);
        $this->assertContains('invoice', $timelineKeys);
        $this->assertContains('payment', $timelineKeys);
        $this->assertContains('ledger', $timelineKeys);
        $this->assertContains('payment_callback_'.(int) $callback->id, $timelineKeys);
        $this->assertContains('order', $logModules);
        $this->assertContains('invoice', $logModules);
    }

    private function createClientUser(string $prefix, array $overrides = []): User
    {
        // balance 已从 $fillable 移出（安全修复），需通过 forceFill+save 触发 booted hook 同步到 user_accounts
        $balance = $overrides['balance'] ?? '0.00';
        $attrs = array_diff_key($overrides, ['balance' => true]);

        $user = User::query()->create(array_merge([
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
        ], $attrs));

        if ((float) $balance !== 0.0) {
            $user->forceFill(['balance' => $balance])->save();
            $user->refresh();
        }

        return $user;
    }

    private function createAdminUser(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));

        $role = Role::query()->create([
            'name' => 'ledger-role-'.$suffix,
            'label' => 'Ledger Role',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'ledger-admin-'.$suffix,
            'password' => 'secret123',
            'nickname' => 'Ledger Admin',
            'role_id' => (int) $role->id,
            'status' => 1,
        ]);
    }
}
