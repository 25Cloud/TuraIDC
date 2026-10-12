<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\FinanceLedgerEventType;
use App\Constants\InvoiceType;
use App\Exceptions\BusinessException;
use App\Models\AccountTransaction;
use App\Models\User;
use App\Services\Finance\PaymentService;
use App\Services\User\AccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 管理员手动调账的幂等回归。
 *
 * 调账直接改用户余额且不可自动回滚：按钮连点、请求超时重试、浏览器重发都会让
 * 同一笔调账执行多次。缺省即拒绝是硬要求——退化成「没带键也照常加钱」等于没有防重。
 */
class AdminBalanceAdjustmentIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_missing_idempotency_key_is_rejected_instead_of_adjusting_balance(): void
    {
        $user = $this->createUser('balance-idem-missing');
        $payments = app(PaymentService::class);
        $before = app(AccountService::class)->cashBalance($user, true);

        try {
            $payments->adjustBalance($user, 50.00, '未带幂等标识');
            $this->fail('缺少幂等标识时必须拒绝执行，不能照常调账');
        } catch (BusinessException $exception) {
            $this->assertStringContainsString('幂等', $exception->getMessage());
        }

        $this->assertSame(
            $before,
            app(AccountService::class)->cashBalance($user->fresh(), true),
            '被拒绝的调账不得改动余额'
        );
        $this->assertSame(
            0,
            $user->invoices()->where('type', InvoiceType::RECHARGE)->count(),
            '被拒绝的调账不得留下账单'
        );
        $this->assertSame(
            0,
            AccountTransaction::query()
                ->where('user_id', (int) $user->id)
                ->whereIn('event_type', [
                    FinanceLedgerEventType::MANUAL_RECHARGE,
                    FinanceLedgerEventType::MANUAL_DEDUCTION,
                ])
                ->count(),
            '被拒绝的调账不得留下流水'
        );
    }

    public function test_repeated_submission_with_same_key_moves_money_only_once(): void
    {
        $user = $this->createUser('balance-idem-replay');
        $payments = app(PaymentService::class);
        $accounts = app(AccountService::class);
        $before = $accounts->cashBalance($user, true);

        $context = [
            'operator_id' => 9,
            'operator_name' => 'finance-admin',
            'trace_id' => 'idem-replay-trace',
            'idempotency_key' => 'balance-idem-replay-key',
        ];

        $first = $payments->adjustBalance($user, 120.00, '重复提交测试', $context);
        $afterFirst = $accounts->cashBalance($user->fresh(), true);

        // 模拟按钮连点/网络重试：完全相同的请求再来一次
        $second = $payments->adjustBalance($user->fresh(), 120.00, '重复提交测试', $context);

        $this->assertSame(
            $before + 120.00,
            round($afterFirst, 2),
            '首次执行必须只加一次'
        );
        $this->assertSame(
            $afterFirst,
            $accounts->cashBalance($user->fresh(), true),
            '相同幂等标识的重复提交不得二次动账'
        );

        // 重放必须返回首次那批单据，而不是新建一批
        $this->assertSame((int) $first['invoice']->id, (int) $second['invoice']->id);
        $this->assertSame((int) $first['transaction']->id, (int) $second['transaction']->id);
        $this->assertSame((int) $first['recharge_record']->id, (int) $second['recharge_record']->id);

        $this->assertSame(
            1,
            $user->invoices()->where('idempotency_key', 'balance-idem-replay-key')->count(),
            '同一幂等标识只能留下一张账单'
        );
        $this->assertSame(
            1,
            AccountTransaction::query()
                ->where('user_id', (int) $user->id)
                ->where('event_type', FinanceLedgerEventType::MANUAL_RECHARGE)
                ->count(),
            '同一幂等标识只能留下一条流水'
        );
    }

    public function test_same_key_with_different_amount_is_rejected(): void
    {
        $user = $this->createUser('balance-idem-conflict');
        $payments = app(PaymentService::class);
        $accounts = app(AccountService::class);

        $context = [
            'operator_name' => 'finance-admin',
            'trace_id' => 'idem-conflict-trace',
            'idempotency_key' => 'balance-idem-conflict-key',
        ];

        $payments->adjustBalance($user, 30.00, '首次调整', $context);
        $afterFirst = $accounts->cashBalance($user->fresh(), true);

        // 复用旧标识提交另一笔金额：返回原单据会让管理员误以为新金额已入账，必须报错
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('幂等');

        try {
            $payments->adjustBalance($user->fresh(), 999.00, '改金额重提', $context);
        } finally {
            $this->assertSame(
                $afterFirst,
                $accounts->cashBalance($user->fresh(), true),
                '同键不同额被拒时余额不得变化'
            );
        }
    }

    public function test_distinct_keys_apply_independently(): void
    {
        $user = $this->createUser('balance-idem-distinct');
        $payments = app(PaymentService::class);
        $accounts = app(AccountService::class);
        $before = $accounts->cashBalance($user, true);

        $payments->adjustBalance($user, 10.00, '第一次调整', [
            'operator_name' => 'finance-admin',
            'trace_id' => 'idem-distinct-trace-1',
            'idempotency_key' => 'balance-idem-distinct-1',
        ]);
        $payments->adjustBalance($user->fresh(), 20.00, '第二次调整', [
            'operator_name' => 'finance-admin',
            'trace_id' => 'idem-distinct-trace-2',
            'idempotency_key' => 'balance-idem-distinct-2',
        ]);

        $this->assertSame(
            $before + 30.00,
            round($accounts->cashBalance($user->fresh(), true), 2),
            '不同幂等标识代表两次独立操作，都必须生效'
        );
    }

    public function test_replay_works_without_a_request_trace_header(): void
    {
        $user = $this->createUser('balance-idem-notrace');
        $payments = app(PaymentService::class);
        $accounts = app(AccountService::class);

        // 前端可能不带 X-Request-Id；此时追踪号由幂等标识派生，重放仍须精确命中
        $context = [
            'operator_name' => 'finance-admin',
            'trace_id' => '',
            'idempotency_key' => 'balance-idem-notrace-key',
        ];

        $first = $payments->adjustBalance($user, 60.00, '无追踪号调整', $context);
        $afterFirst = $accounts->cashBalance($user->fresh(), true);
        $second = $payments->adjustBalance($user->fresh(), 60.00, '无追踪号调整', $context);

        $this->assertSame($afterFirst, $accounts->cashBalance($user->fresh(), true));
        $this->assertSame((int) $first['invoice']->id, (int) $second['invoice']->id);
        $this->assertSame((int) $first['transaction']->id, (int) $second['transaction']->id);
    }

    private function createUser(string $prefix): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => $prefix.'-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => $prefix,
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
    }
}
