<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Auth\VerificationCodeService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class VerificationCodeAttemptLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('redis_volatile')->flush();
    }

    public function test_phone_code_is_invalidated_after_five_wrong_attempts(): void
    {
        $service = app(VerificationCodeService::class);
        $service->storePhoneCode('guest', '13800138000', '123456');

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($service->verifyPhoneCode('guest', '13800138000', '000000'));
        }

        // 正确验证码也已作废
        $this->assertFalse($service->verifyPhoneCode('guest', '13800138000', '123456'));
    }

    public function test_phone_code_succeeds_before_attempt_limit(): void
    {
        $service = app(VerificationCodeService::class);
        $service->storePhoneCode('guest', '13800138000', '123456');

        for ($i = 0; $i < 4; $i++) {
            $this->assertFalse($service->verifyPhoneCode('guest', '13800138000', '000000'));
        }

        $this->assertTrue($service->verifyPhoneCode('guest', '13800138000', '123456'));
    }

    public function test_resending_phone_code_resets_attempt_counter(): void
    {
        $service = app(VerificationCodeService::class);
        $service->storePhoneCode('guest', '13800138000', '123456');

        for ($i = 0; $i < 4; $i++) {
            $this->assertFalse($service->verifyPhoneCode('guest', '13800138000', '000000'));
        }

        $service->storePhoneCode('guest', '13800138000', '654321');
        $this->assertTrue($service->verifyPhoneCode('guest', '13800138000', '654321'));
    }

    public function test_email_code_consumed_after_success_and_invalidated_after_limit(): void
    {
        $service = app(VerificationCodeService::class);
        $service->storeEmailCode('guest', 'attempt@example.com', '654321');

        $this->assertTrue($service->verifyEmailCode('guest', 'attempt@example.com', '654321'));
        // 已消费
        $this->assertFalse($service->verifyEmailCode('guest', 'attempt@example.com', '654321'));

        $service->storeEmailCode('guest', 'attempt@example.com', '111222');
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($service->verifyEmailCode('guest', 'attempt@example.com', '000000'));
        }

        $this->assertFalse($service->verifyEmailCode('guest', 'attempt@example.com', '111222'));
    }
}
