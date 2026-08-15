<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\ReferralWithdrawal;
use App\Models\User;
use App\Services\Referral\ReferralService;
use Tests\TestCase;

/**
 * 提现打款凭证闭环：支付宝方式审核通过后打款确认，回填打款单号与打款时间；余额方式与非审核通过状态拒绝确认。
 */
class ReferralWithdrawalPaymentConfirmationTest extends TestCase
{
    public function test_confirm_payment_marks_alipay_withdrawal_paid_and_records_voucher(): void
    {
        $user = $this->createUser('withdraw-paid');
        $withdrawal = $this->createWithdrawal($user, 'alipay', ReferralWithdrawal::STATUS_APPROVED);

        $updated = app(ReferralService::class)->confirmWithdrawalPayment(
            $withdrawal,
            9001,
            'Finance Admin',
            'PAY-20260813-001',
            '支付宝已打款',
            'trace-withdraw-paid-'.bin2hex(random_bytes(4)),
        );

        $this->assertSame(ReferralWithdrawal::STATUS_PAID, (int) $updated->status);
        $this->assertSame('PAY-20260813-001', (string) $updated->payment_no);
        $this->assertNotNull($updated->paid_at);
        $this->assertDatabaseHas('operation_logs', [
            'module' => 'referral_withdrawal',
            'action' => 'referral.withdraw.paid',
            'subject_id' => (int) $updated->id,
        ]);
    }

    public function test_confirm_payment_rejects_balance_method(): void
    {
        $user = $this->createUser('withdraw-balance');
        $withdrawal = $this->createWithdrawal($user, 'balance', ReferralWithdrawal::STATUS_APPROVED);

        try {
            app(ReferralService::class)->confirmWithdrawalPayment($withdrawal, 9001, 'Finance Admin', 'PAY-X');
            $this->fail('余额提现不应支持打款确认');
        } catch (BusinessException $exception) {
            $this->assertSame('余额提现无需打款确认', $exception->getMessage());
        }

        $this->assertSame(ReferralWithdrawal::STATUS_APPROVED, (int) $withdrawal->refresh()->status);
    }

    public function test_confirm_payment_rejects_non_approved_status(): void
    {
        $user = $this->createUser('withdraw-pending');
        $withdrawal = $this->createWithdrawal($user, 'alipay', ReferralWithdrawal::STATUS_PENDING);

        try {
            app(ReferralService::class)->confirmWithdrawalPayment($withdrawal, 9001, 'Finance Admin', 'PAY-X');
            $this->fail('未审核通过的提现不应支持打款确认');
        } catch (BusinessException $exception) {
            $this->assertSame('仅审核通过的提现支持打款确认', $exception->getMessage());
        }
    }

    public function test_confirm_payment_rejects_empty_payment_no(): void
    {
        $user = $this->createUser('withdraw-nopayno');
        $withdrawal = $this->createWithdrawal($user, 'alipay', ReferralWithdrawal::STATUS_APPROVED);

        try {
            app(ReferralService::class)->confirmWithdrawalPayment($withdrawal, 9001, 'Finance Admin', '   ');
            $this->fail('打款单号不能为空');
        } catch (BusinessException $exception) {
            $this->assertSame('打款单号不能为空', $exception->getMessage());
        }
    }

    private function createWithdrawal(User $user, string $method, int $status): ReferralWithdrawal
    {
        return ReferralWithdrawal::query()->create([
            'user_id' => (int) $user->id,
            'amount' => '100.00',
            'method' => $method,
            'account_name' => '张三',
            'account_no' => $method === 'alipay' ? 'alipay@example.com' : '',
            'status' => $status,
            'trace_id' => 'withdraw-'.bin2hex(random_bytes(4)),
            'processed_at' => now(),
        ]);
    }

    private function createUser(string $prefix): User
    {
        return User::query()->create([
            'email' => $prefix.'-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
    }
}
