<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientNotificationPreferencesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_client_can_fetch_notification_preferences(): void
    {
        $user = User::query()->create([
            'email' => 'notify-fetch@example.com',
            'password' => 'secret123',
            'phone' => '13800000001',
            'status' => 1,
            'nickname' => '通知读取用户',
            'login_email_alert' => 1,
            'login_notify' => 1,
            'login_location_alert' => 0,
            'password_change_alert' => 1,
            'phone_change_alert' => 0,
            'email_change_alert' => 1,
            'marketing_alert' => 0,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v2/client/auth/notification-preferences');

        $response->assertOk()
            ->assertJson([
                'code' => 0,
                'data' => [
                    'login_notify' => 1,
                    'login_location_alert' => 0,
                    'password_change_alert' => 1,
                    'phone_change_alert' => 0,
                    'email_change_alert' => 1,
                    'marketing_alert' => 0,
                ],
            ]);
    }

    public function test_client_can_update_notification_preferences(): void
    {
        $user = User::query()->create([
            'email' => 'notify-update@example.com',
            'password' => 'secret123',
            'phone' => '13800000002',
            'status' => 1,
            'nickname' => '通知更新用户',
            'login_email_alert' => 1,
            'login_notify' => 1,
            'login_location_alert' => 1,
            'password_change_alert' => 1,
            'phone_change_alert' => 1,
            'email_change_alert' => 1,
            'marketing_alert' => 0,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v2/client/auth/notification-preferences', [
            'login_notify' => false,
            'login_location_alert' => true,
            'password_change_alert' => false,
            'phone_change_alert' => true,
            'email_change_alert' => false,
            'marketing_alert' => true,
        ]);

        $response->assertOk()
            ->assertJson([
                'code' => 0,
                'data' => [
                    'login_notify' => 0,
                    'login_location_alert' => 1,
                    'password_change_alert' => 0,
                    'phone_change_alert' => 1,
                    'email_change_alert' => 0,
                    'marketing_alert' => 1,
                ],
            ]);

        $user->refresh();

        $this->assertFalse((bool) $user->login_notify);
        $this->assertFalse((bool) $user->login_email_alert);
        $this->assertTrue((bool) $user->login_location_alert);
        $this->assertFalse((bool) $user->password_change_alert);
        $this->assertTrue((bool) $user->phone_change_alert);
        $this->assertFalse((bool) $user->email_change_alert);
        $this->assertTrue((bool) $user->marketing_alert);
    }
}
