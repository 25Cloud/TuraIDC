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
