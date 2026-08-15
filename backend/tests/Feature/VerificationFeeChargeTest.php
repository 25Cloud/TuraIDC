<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\FinanceLedgerEventType;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Auth\VerificationService;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Verification\Contracts\ProvidesVerificationFeeConfig;
use App\Services\Verification\Contracts\VerificationDriver;
use App\Services\Verification\Data\VerificationFeeConfig;
use App\Services\Verification\Data\VerificationInitializeRequest;
use App\Services\Verification\Data\VerificationInitializeResult;
use App\Services\Verification\Data\VerificationScanUrlResult;
use App\Services\Verification\Data\VerificationStatusResult;
use App\Services\Verification\VerificationDriverManager;
use Tests\TestCase;

/**
 * 实名收费消费点：免费次数内免费，超出后每次认证从余额扣取费用；余额不足拒绝认证。
 */
class VerificationFeeChargeTest extends TestCase
{
    public function test_verification_free_within_free_attempts_then_charged_afterwards(): void
    {
        $user = $this->createUser('verification-fee', 10.0);
        $service = $this->makeFeeService(freeAttempts: 1, retryFee: 5.0);

        // 第 1 次免费
        $service->initVerification($user, '张三', '320505199001010012');
        $this->assertSame('10.00', (string) $user->fresh()->balance);

        // 第 2 次超出免费次数 → 扣 5 元
        $service->initVerification($user->fresh(), '张三', '320505199001010012');
        $this->assertSame('5.00', (string) $user->fresh()->balance);
        $this->assertDatabaseHas('account_transactions', [
            'user_id' => (int) $user->id,
            'event_type' => FinanceLedgerEventType::VERIFICATION_FEE,
            'change_amount' => '-5.00',
        ]);
    }

    public function test_verification_fee_not_charged_when_disabled(): void
    {
        $user = $this->createUser('verification-no-fee', 10.0);
        $service = $this->makeFeeService(freeAttempts: 0, retryFee: 5.0, chargeEnabled: false);

        $service->initVerification($user, '张三', '320505199001010012');
        $this->assertSame('10.00', (string) $user->fresh()->balance);
        $this->assertDatabaseMissing('account_transactions', [
            'user_id' => (int) $user->id,
            'event_type' => FinanceLedgerEventType::VERIFICATION_FEE,
        ]);
    }

    public function test_verification_fee_insufficient_balance_rejects_and_rolls_back(): void
    {
        $user = $this->createUser('verification-fee-low', 2.0);
        $service = $this->makeFeeService(freeAttempts: 0, retryFee: 5.0);

        try {
            $service->initVerification($user, '张三', '320505199001010012');
            $this->fail('余额不足时应拒绝发起实名认证');
        } catch (BusinessException $exception) {
            $this->assertSame('实名认证需要支付认证费用，当前余额不足', $exception->getMessage());
        }

        $this->assertSame('2.00', (string) $user->fresh()->balance);
        $this->assertSame(0, (int) $user->fresh()->verification_status);
        $this->assertDatabaseMissing('account_transactions', [
            'user_id' => (int) $user->id,
            'event_type' => FinanceLedgerEventType::VERIFICATION_FEE,
        ]);
    }

    private function makeFeeService(int $freeAttempts, float $retryFee, bool $chargeEnabled = true): VerificationService
    {
        $resolver = new class extends IntegrationDriverBindingResolver
        {
            public function verificationDriverCandidates(): array
            {
                return ['verification-fee-test'];
            }

            public function verificationContext(?string $driverKey = null): array
            {
                return ['plugin_id' => null, 'driver_key' => $driverKey ?: 'verification-fee-test'];
            }
        };

        $driver = new class($freeAttempts, $retryFee, $chargeEnabled) implements ProvidesVerificationFeeConfig, VerificationDriver
        {
            public function __construct(
                private readonly int $freeAttempts,
                private readonly float $retryFee,
                private readonly bool $chargeEnabled,
            ) {}

            public function key(): string
            {
                return 'verification-fee-test';
            }

            public function label(): string
            {
                return '实名收费测试驱动';
            }

            public function initialize(VerificationInitializeRequest $request): VerificationInitializeResult
            {
                return new VerificationInitializeResult(200, '初始化成功', 'fee-test-certify-id');
            }

            public function generateScanUrl(string $certifyId): VerificationScanUrlResult
            {
                return new VerificationScanUrlResult(200, '获取成功', 'https://example.test/verification');
            }

            public function queryStatus(string $certifyId): VerificationStatusResult
            {
                return new VerificationStatusResult(4, '等待认证');
            }

            public function feeConfig(): VerificationFeeConfig
            {
                return new VerificationFeeConfig(
                    freeAttempts: $this->freeAttempts,
                    retryFee: $this->retryFee,
                    chargeEnabled: $this->chargeEnabled,
                    amount: $this->retryFee,
                );
            }
        };

        return new VerificationService(
            new VerificationDriverManager([$driver], $resolver),
            null,
            $resolver,
        );
    }

    private function createUser(string $prefix, float $balance): User
    {
        $user = User::query()->create([
            'email' => $prefix.'-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'is_verified' => 0,
            'verification_status' => 0,
        ]);
        $user->forceFill(['balance' => number_format($balance, 2, '.', '')])->save();

        return $user;
    }
}
