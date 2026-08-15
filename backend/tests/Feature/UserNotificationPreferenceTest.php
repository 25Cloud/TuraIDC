<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notification\UserNotificationService;
use Tests\TestCase;

/**
 * 通知偏好生效：营销类站内信受 marketing_alert 偏好过滤，业务必要提醒不受影响。
 */
class UserNotificationPreferenceTest extends TestCase
{
    public function test_marketing_notification_skipped_when_preference_disabled(): void
    {
        $user = $this->createUser('pref-off', 0);

        $result = app(UserNotificationService::class)->create(
            (int) $user->id,
            'promo_campaign',
            '限时优惠',
            '全场折扣',
            '/client/promo',
            ['promo' => true],
            true,
        );

        $this->assertNull($result);
        $this->assertSame(0, UserNotification::query()->where('user_id', (int) $user->id)->count());
    }

    public function test_marketing_notification_written_when_preference_enabled(): void
    {
        $user = $this->createUser('pref-on', 1);

        $result = app(UserNotificationService::class)->create(
            (int) $user->id,
            'promo_campaign',
            '限时优惠',
            '全场折扣',
            '/client/promo',
            ['promo' => true],
            true,
        );

        $this->assertNotNull($result);
        $this->assertSame(1, UserNotification::query()->where('user_id', (int) $user->id)->count());
    }

    public function test_business_notification_always_written_regardless_of_marketing_preference(): void
    {
        $user = $this->createUser('pref-business', 0);

        $result = app(UserNotificationService::class)->create(
            (int) $user->id,
            'order_paid',
            '开通成功',
            '您的服务已开通',
        );

        $this->assertNotNull($result);
        $this->assertSame(1, UserNotification::query()->where('user_id', (int) $user->id)->count());
    }

    private function createUser(string $prefix, int $marketingAlert): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => $prefix.'-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'marketing_alert' => $marketingAlert,
        ]);
    }
}
