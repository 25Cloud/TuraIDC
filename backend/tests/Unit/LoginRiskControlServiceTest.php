<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Auth\GeeTestService;
use App\Services\Auth\LoginRiskControlService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginRiskControlServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::store('redis_volatile')->flush();
    }

    #[Test]
    public function it_does_not_require_captcha_before_any_failed_login_attempts(): void
    {
        $service = $this->makeService();

        $this->assertFalse($service->shouldRequireCaptcha('member@example.com', '127.0.0.1'));
    }

    #[Test]
    public function it_requires_captcha_from_the_next_attempt_after_the_first_failed_login(): void
    {
        $service = $this->makeService();

        $service->recordFailedAttempt('Member@Example.com', '127.0.0.1');

        $this->assertTrue($service->shouldRequireCaptcha('member@example.com', '127.0.0.1'));
    }

    #[Test]
    public function it_only_allows_one_failure_alert_until_a_successful_login_clears_the_lock(): void
    {
        $service = $this->makeService();

        $this->assertTrue($service->acquireFailureAlertLock('Member@Example.com'));
        $this->assertFalse($service->acquireFailureAlertLock('member@example.com'));

        $service->recordFailedAttempt('member@example.com', '127.0.0.1');
        $this->assertTrue($service->shouldRequireCaptcha('member@example.com', '127.0.0.1'));

        $service->clearSuccessfulLogin('member@example.com', '127.0.0.1');

        $this->assertFalse($service->shouldRequireCaptcha('member@example.com', '127.0.0.1'));
        $this->assertTrue($service->acquireFailureAlertLock('member@example.com'));
    }

    /**
     * 密码通道与验证码通道必须各自记账。
     *
     * 共用计数会造成双向误锁：验证码通道刷错能锁死他人的密码登录（账号级 DoS），
     * 而验证码登录成功又会清掉密码通道的失败计数、削弱密码爆破防护。
     */
    #[Test]
    public function it_keeps_password_and_code_channel_counters_isolated(): void
    {
        $service = $this->makeService();
        $account = 'member@example.com';
        $ip = '127.0.0.1';

        // 验证码通道失败，不应影响密码通道
        $service->recordFailedAttempt($account, $ip, LoginRiskControlService::SCOPE_CLIENT_CODE);

        $this->assertTrue(
            $service->shouldRequireCaptcha($account, $ip, LoginRiskControlService::SCOPE_CLIENT_CODE),
            '验证码通道自身应已计数'
        );
        $this->assertFalse(
            $service->shouldRequireCaptcha($account, $ip, LoginRiskControlService::SCOPE_CLIENT),
            '验证码通道的失败不应写入密码通道的计数'
        );

        // 反向：密码通道失败后，清除验证码通道不应把密码通道的计数一起清掉
        $service->recordFailedAttempt($account, $ip, LoginRiskControlService::SCOPE_CLIENT);
        $service->clearSuccessfulLogin($account, $ip, LoginRiskControlService::SCOPE_CLIENT_CODE);

        $this->assertTrue(
            $service->shouldRequireCaptcha($account, $ip, LoginRiskControlService::SCOPE_CLIENT),
            '验证码通道的成功不应清除密码通道的失败计数'
        );
    }

    /**
     * 管理端与客户端也必须隔离：同名的管理员与客户不应互相累加或互相解锁。
     */
    #[Test]
    public function it_keeps_admin_and_client_counters_isolated(): void
    {
        $service = $this->makeService();
        $account = 'cerbo';
        $ip = '127.0.0.1';

        $service->recordFailedAttempt($account, $ip, LoginRiskControlService::SCOPE_ADMIN);

        $this->assertTrue($service->shouldRequireCaptcha($account, $ip, LoginRiskControlService::SCOPE_ADMIN));
        $this->assertFalse($service->shouldRequireCaptcha($account, $ip, LoginRiskControlService::SCOPE_CLIENT));
    }

    /**
     * 非法作用域必须回退到客户端作用域，而不是凭空开出一个独立命名空间——
     * 否则手误传错的作用域会得到一份全新的零计数，等于绕过限流。
     */
    #[Test]
    public function it_falls_back_to_the_client_scope_for_unknown_scopes(): void
    {
        $service = $this->makeService();
        $account = 'member@example.com';
        $ip = '127.0.0.1';

        $service->recordFailedAttempt($account, $ip, 'not-a-real-scope');

        $this->assertTrue(
            $service->shouldRequireCaptcha($account, $ip, LoginRiskControlService::SCOPE_CLIENT),
            '未知作用域应落到客户端作用域，不得另开命名空间'
        );
    }

    private function makeService(): LoginRiskControlService
    {
        $geeTestService = new class extends GeeTestService
        {
            public function isEnabled(): bool
            {
                return true;
            }
        };

        return new LoginRiskControlService($geeTestService);
    }
}
