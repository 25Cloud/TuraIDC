<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientFinanceBalanceLogRegressionTest extends TestCase
{
    public function test_balance_logs_endpoints_use_account_transactions(): void
    {
        $user = User::query()->create([
            'email' => 'finance-balance-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Finance Balance',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);

        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'recharge',
            'change_amount' => '88.00',
            'balance_after' => '88.00',
            'source_type' => 'payment',
            'source_id' => 1001,
            'origin_type' => 'payment',
            'origin_id' => 1001,
            'remark' => 'balance regression',
            'operator' => 'system',
            'trace_id' => 'balance-regression',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/balance-logs?page=1&page_size=15')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.event_type', 'recharge')
            ->assertJsonPath('data.list.0.change_amount', '88.00');

        $this->getJson('/api/v2/client/balance-logs/summary')
            ->assertOk()
            ->assertJsonPath('data.total_in', '88.00')
            ->assertJsonPath('data.total_out', '0.00');
    }

    public function test_balance_logs_v2_rejects_legacy_and_summary_pagination_parameters(): void
    {
        $user = User::query()->create([
            'email' => 'finance-balance-validation-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Finance Balance Validation',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/balance-logs?per_page=20')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/client/balance-logs?pageSize=20')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $this->getJson('/api/v2/client/balance-logs/summary?page=1')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);
    }

    public function test_balance_logs_endpoints_do_not_default_to_balance_tab_when_account_transactions_table_exists(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'finance-ledger-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Finance Ledger',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);
        // balance 已从 $fillable 移出，通过 forceFill+save 触发 booted hook 同步到 user_accounts
        $user->forceFill(['balance' => '65.00'])->save();
        $user->refresh();

        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'consume',
            'change_amount' => '-20.00',
            'balance_after' => '80.00',
            'source_type' => 'invoice',
            'source_id' => 1001,
            'origin_type' => 'invoice',
            'origin_id' => 2001,
            'remark' => '账单支付测试',
            'operator' => 'system',
            'trace_id' => 'consume-'.$suffix,
        ]);

        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'refund',
            'change_amount' => '15.00',
            'balance_after' => '95.00',
            'source_type' => 'invoice',
            'source_id' => 1002,
            'origin_type' => 'invoice',
            'origin_id' => 2002,
            'remark' => '账单退款测试',
            'operator' => 'system',
            'trace_id' => 'refund-'.$suffix,
        ]);

        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'recharge',
            'change_amount' => '50.00',
            'balance_after' => '145.00',
            'source_type' => 'payment',
            'source_id' => 1003,
            'origin_type' => 'payment',
            'origin_id' => 2003,
            'remark' => '充值到账测试',
            'operator' => 'system',
            'trace_id' => 'recharge-'.$suffix,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/balance-logs?page=1&page_size=15')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.list.0.event_type', 'recharge')
            ->assertJsonPath('data.list.0.event_type_label', '充值到账')
            ->assertJsonPath('data.list.1.event_type', 'refund')
            ->assertJsonPath('data.list.1.event_type_label', '账单退款')
            ->assertJsonPath('data.list.2.event_type', 'consume')
            ->assertJsonPath('data.list.2.event_type_label', '账单支付');

        $this->getJson('/api/v2/client/balance-logs/summary')
            ->assertOk()
            ->assertJsonPath('data.cash_balance', '65.00')
            ->assertJsonMissingPath('data.balance')
            ->assertJsonPath('data.total_in', '65.00')
            ->assertJsonPath('data.total_out', '20.00')
            ->assertJsonPath('data.recharge_in', '50.00')
            ->assertJsonPath('data.refund_in', '15.00')
            ->assertJsonPath('data.invoice_payment_out', '20.00');
    }
}
