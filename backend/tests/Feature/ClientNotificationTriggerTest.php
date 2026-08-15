<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\VerificationCodeService;
use App\Services\System\NotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ClientNotificationTriggerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_notify_really_sends_notification(): void
    {
        $user = $this->createClientUser([
            'email' => 'login-notify@example.com',
            'password' => 'secret123',
            'login_email_alert' => 1,
            'login_notify' => 1,
            'login_location_alert' => 0,
            'last_login_ip' => '127.0.0.1',
        ]);

        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('sendLoginEmailAlertToAddress')
            ->once()
            ->withArgs(function (string $email, string $displayName, string $loginAt, string $ip, ?string $userAgent): bool {
                return $email === 'login-notify@example.com'
                    && $displayName !== ''
                    && $loginAt !== ''
                    && $ip === '127.0.0.1'
                    && is_string($userAgent);
            });
        $this->app->instance(NotificationService::class, $mock);

        $this->postJson('/api/v2/client/login', [
            'account' => 'login-notify@example.com',
            'password' => 'secret123',
        ])->assertOk();
    }

    public function test_login_location_alert_really_sends_notification(): void
    {
        $user = $this->createClientUser([
            'email' => 'location-alert@example.com',
            'password' => 'secret123',
            'login_email_alert' => 0,
            'login_notify' => 0,
            'login_location_alert' => 1,
            'last_login_ip' => '10.0.0.1',
        ]);

        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('sendLoginLocationEmailAlertToAddress')
            ->once()
            ->withArgs(function (
                string $email,
                string $displayName,
                string $loginAt,
                string $ip,
                string $previousIp,
                ?string $userAgent
            ): bool {
                return $email === 'location-alert@example.com'
                    && $displayName !== ''
                    && $loginAt !== ''
                    && $ip === '203.0.113.8'
                    && $previousIp === '10.0.0.1'
                    && is_string($userAgent);
            });
        $this->app->instance(NotificationService::class, $mock);

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.8',
            'HTTP_USER_AGENT' => 'ClientNotificationTriggerTest/1.0',
        ])->postJson('/api/v2/client/login', [
            'account' => 'location-alert@example.com',
            'password' => 'secret123',
        ])->assertOk();
    }

    public function test_password_change_alert_really_sends_notification(): void
    {
        $user = $this->createClientUser([
            'email' => 'password-alert@example.com',
            'password' => 'secret123',
            'password_change_alert' => 1,
        ]);

        Sanctum::actingAs($user);

        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('sendPasswordChangedEmailAlertToAddress')
            ->once()
            ->withArgs(function (string $email, string $displayName, string $changedAt, string $ip, ?string $userAgent): bool {
                return $email === 'password-alert@example.com'
                    && $displayName !== ''
                    && $changedAt !== ''
                    && $ip === '127.0.0.1'
                    && is_string($userAgent);
            });
        $this->app->instance(NotificationService::class, $mock);

        $this->putJson('/api/v2/client/password', [
            'oldPassword' => 'secret123',
            'newPassword' => 'newSecret456',
            'confirmPassword' => 'newSecret456',
        ])->assertOk();
    }

    public function test_phone_change_alert_really_sends_notification(): void
    {
        $user = $this->createClientUser([
            'email' => 'phone-alert@example.com',
            'phone' => '13800000003',
            'phone_change_alert' => 1,
        ]);

        Sanctum::actingAs($user);

        app(VerificationCodeService::class)->storePhoneCode((int) $user->id, '13800000099', '123456');
        app(VerificationCodeService::class)->storePhoneCode((int) $user->id, '13800000003', '654321');

        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('sendPhoneChangedEmailAlertToAddress')
            ->once()
            ->withArgs(function (
                string $email,
                string $displayName,
                string $oldPhone,
                string $newPhone,
                string $changedAt,
                string $ip,
                ?string $userAgent
            ): bool {
                return $email === 'phone-alert@example.com'
                    && $displayName !== ''
                    && $oldPhone === '13800000003'
                    && $newPhone === '13800000099'
                    && $changedAt !== ''
                    && $ip === '127.0.0.1'
                    && is_string($userAgent);
            });
        $this->app->instance(NotificationService::class, $mock);

        $this->putJson('/api/v2/client/auth/phone', [
            'phone' => '13800000099',
            'code' => '123456',
            'old_code' => '654321',
        ])->assertOk();
    }

    public function test_email_change_alert_really_sends_notification(): void
    {
        $user = $this->createClientUser([
            'email' => 'old-email-alert@example.com',
            'email_change_alert' => 1,
        ]);

        Sanctum::actingAs($user);

        app(VerificationCodeService::class)->storeEmailCode((int) $user->id, 'new-email-alert@example.com', '654321');
        app(VerificationCodeService::class)->storeEmailCode((int) $user->id, 'old-email-alert@example.com', '123456');

        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('sendEmailChangedEmailAlertToAddress')
            ->once()
            ->withArgs(function (
                string $oldEmail,
                string $newEmail,
                string $displayName,
                string $changedAt,
                string $ip,
                ?string $userAgent
            ): bool {
                return $oldEmail === 'old-email-alert@example.com'
                    && $newEmail === 'new-email-alert@example.com'
                    && $displayName !== ''
                    && $changedAt !== ''
                    && $ip === '127.0.0.1'
                    && is_string($userAgent);
            });
        $this->app->instance(NotificationService::class, $mock);

        $this->putJson('/api/v2/client/auth/email', [
            'email' => 'new-email-alert@example.com',
            'code' => '654321',
            'old_code' => '123456',
        ])->assertOk();
    }

    private function createClientUser(array $attributes = []): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create(array_merge([
            'email' => "client-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => '通知触发测试用户',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
            'login_email_alert' => 1,
            'login_notify' => 1,
            'login_location_alert' => 1,
            'password_change_alert' => 1,
            'phone_change_alert' => 1,
            'email_change_alert' => 1,
            'marketing_alert' => 0,
        ], $attributes));
    }
}
