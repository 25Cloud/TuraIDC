<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\GeeTestService;
use App\Services\Auth\LoginRiskControlService;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginSoftLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 清理风控计数，避免共享缓存串扰
        RateLimiter::clear('login-risk:account-ip:'.sha1('soft-lock@example.com|203.0.113.50'));
        RateLimiter::clear('login-risk:account:'.sha1('soft-lock@example.com'));
        RateLimiter::clear('login-risk:account-ip:'.sha1('soft-lock-ip@example.com|203.0.113.60'));
        RateLimiter::clear('login-risk:account:'.sha1('soft-lock-ip@example.com'));
    }

    #[Test]
    public function soft_lock_engages_after_five_failed_attempts_when_captcha_disabled(): void
    {
        $service = new LoginRiskControlService($this->disabledCaptcha());

        $account = 'soft-lock@example.com';
        $ip = '203.0.113.50';

        for ($i = 1; $i <= 4; $i++) {
            $service->recordFailedAttempt($account, $ip);
        }
        $this->assertFalse($service->isLoginLocked($account, $ip));

        $service->recordFailedAttempt($account, $ip);
        $this->assertTrue($service->isLoginLocked($account, $ip));
    }

    #[Test]
    public function soft_lock_engages_on_account_dimension_across_ips_when_captcha_disabled(): void
    {
        $service = new LoginRiskControlService($this->disabledCaptcha());

        $account = 'soft-lock-ip@example.com';

        // 轮换 IP 绕过 account-ip 维度后，账号维度兜底锁定
        for ($i = 1; $i <= 10; $i++) {
            $service->recordFailedAttempt($account, '203.0.113.'.(100 + $i));
        }

        $this->assertTrue($service->isLoginLocked($account, '203.0.113.199'));
    }

    #[Test]
    public function soft_lock_stays_inactive_when_captcha_enabled(): void
    {
        $captcha = $this->createMock(GeeTestService::class);
        $captcha->method('isEnabled')->willReturn(true);

        $service = new LoginRiskControlService($captcha);
        $account = 'soft-lock-enabled@example.com';

        for ($i = 1; $i <= 6; $i++) {
            $service->recordFailedAttempt($account, '203.0.113.70');
        }

        // 启用验证码时走验证码机制，不启用软锁定
        $this->assertFalse($service->isLoginLocked($account, '203.0.113.70'));
        $this->assertTrue($service->shouldRequireCaptcha($account, '203.0.113.70'));
    }

    #[Test]
    public function login_endpoint_returns_429_when_soft_lock_engaged(): void
    {
        $this->mock(LoginRiskControlService::class, function ($mock) {
            $mock->shouldReceive('isLoginLocked')->once()->andReturn(true);
        });

        $user = User::query()->create([
            'email' => 'soft-lock-api-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Soft Lock Api',
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

        $response = $this->postJson('/api/v2/client/login', [
            'account' => (string) $user->email,
            'password' => 'Temp@123456',
        ]);

        $response
            ->assertStatus(429)
            ->assertJsonPath('code', 42900)
            ->assertJsonPath('message', '登录尝试次数过多，请稍后再试');
    }

    #[Test]
    public function alipay_rebind_password_confirmation_engages_soft_lock(): void
    {
        $user = User::query()->create([
            'email' => 'soft-lock-alipay-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Soft Lock Alipay',
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

        $payload = [
            'real_name' => '测试',
            'account' => '13800001111',
            'code' => '123456',
            'password' => 'wrong-password',
        ];

        // 改绑密码二次确认失败须累积登录风险计数：前 5 次仍为密码错误。
        for ($i = 0; $i < 5; $i++) {
            $this->putJson('/api/v2/client/auth/alipay-account', $payload)
                ->assertStatus(422)
                ->assertJsonPath('code', 42200)
                ->assertJsonPath('message', '登录密码错误');
        }

        // 第 6 次：账号+IP 维度软锁定已生效，接口拒绝继续尝试（防止无速率限制的密码爆破预言机）。
        $this->putJson('/api/v2/client/auth/alipay-account', $payload)
            ->assertStatus(429)
            ->assertJsonPath('code', 42900);
    }

    private function disabledCaptcha(): GeeTestService
    {
        $captcha = $this->createMock(GeeTestService::class);
        $captcha->method('isEnabled')->willReturn(false);

        return $captcha;
    }
}
