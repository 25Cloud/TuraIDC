<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\VerificationCodeService;
use App\Services\System\SmsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientAuthBindingSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private function createClientUser(array $attributes = []): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create(array_merge([
            'email' => "bind-sec-{$suffix}@example.com",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => '绑定安全测试用户',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
            'login_email_alert' => 0,
            'login_notify' => 0,
            'login_location_alert' => 0,
            'phone_change_alert' => 0,
            'email_change_alert' => 0,
        ], $attributes));
    }

    private function actingAsClient(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function storePhoneCode(int|string $ownerId, string $phone, string $code): void
    {
        app(VerificationCodeService::class)->storePhoneCode($ownerId, $phone, $code);
    }

    private function storeEmailCode(int|string $ownerId, string $email, string $code): void
    {
        app(VerificationCodeService::class)->storeEmailCode($ownerId, $email, $code);
    }

    public function test_change_phone_requires_old_code_when_phone_bound(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);

        $newPhone = '15'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $this->storePhoneCode((int) $user->id, $newPhone, '111111');

        $this->putJson('/api/v2/client/auth/phone', [
            'phone' => $newPhone,
            'code' => '111111',
        ])->assertStatus(422)->assertJsonPath('code', 42200);
    }

    public function test_change_phone_rejects_wrong_old_code(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);

        $newPhone = '15'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $this->storePhoneCode((int) $user->id, (string) $user->phone, '222222');
        $this->storePhoneCode((int) $user->id, $newPhone, '111111');

        $this->putJson('/api/v2/client/auth/phone', [
            'phone' => $newPhone,
            'code' => '111111',
            'old_code' => '999999',
        ])->assertStatus(422)->assertJsonPath('code', 42200)->assertJsonPath('message', '原手机验证码错误或已过期');

        $this->assertSame($user->phone, $user->fresh()->phone);
    }

    public function test_change_phone_succeeds_with_old_and_new_codes(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);

        $newPhone = '15'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $this->storePhoneCode((int) $user->id, (string) $user->phone, '222222');
        $this->storePhoneCode((int) $user->id, $newPhone, '111111');

        $this->putJson('/api/v2/client/auth/phone', [
            'phone' => $newPhone,
            'code' => '111111',
            'old_code' => '222222',
        ])->assertOk();

        $this->assertSame($newPhone, $user->fresh()->phone);
    }

    public function test_change_phone_rejects_guest_scope_code(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);

        $newPhone = '15'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        // 攻击者用未登录的 guest 作用域验证码尝试换绑，必须失败
        $this->storePhoneCode('guest', $newPhone, '111111');

        $this->putJson('/api/v2/client/auth/phone', [
            'phone' => $newPhone,
            'code' => '111111',
            'old_code' => '222222',
        ])->assertStatus(422)->assertJsonPath('code', 42200);

        $this->assertSame($user->phone, $user->fresh()->phone);
    }

    public function test_bind_phone_without_old_code_succeeds(): void
    {
        $user = $this->createClientUser(['phone' => '']);
        $this->actingAsClient($user);

        $newPhone = '15'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $this->storePhoneCode((int) $user->id, $newPhone, '111111');

        $this->putJson('/api/v2/client/auth/phone', [
            'phone' => $newPhone,
            'code' => '111111',
        ])->assertOk();

        $this->assertSame($newPhone, $user->fresh()->phone);
    }

    public function test_change_email_succeeds_with_old_and_new_codes(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);

        $newEmail = 'bind-new-'.bin2hex(random_bytes(4)).'@example.com';
        $this->storeEmailCode((int) $user->id, (string) $user->email, '222222');
        $this->storeEmailCode((int) $user->id, $newEmail, '111111');

        $this->putJson('/api/v2/client/auth/email', [
            'email' => $newEmail,
            'code' => '111111',
            'old_code' => '222222',
        ])->assertOk();

        $this->assertSame($newEmail, $user->fresh()->email);
    }

    public function test_change_email_rejects_guest_scope_code(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);

        $newEmail = 'bind-new-'.bin2hex(random_bytes(4)).'@example.com';
        // guest 作用域验证码（未登录下发）不能用于已登录换绑
        $this->storeEmailCode('guest', $newEmail, '111111');

        $this->putJson('/api/v2/client/auth/email', [
            'email' => $newEmail,
            'code' => '111111',
            'old_code' => '222222',
        ])->assertStatus(422)->assertJsonPath('code', 42200);

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_send_verify_bound_phone_code_rejects_unbound_phone(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);

        $otherPhone = '17'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);

        $this->postJson('/api/v2/client/auth/phone-code', [
            'phone' => $otherPhone,
            'purpose' => 'verify_bound_phone',
        ])->assertStatus(422)->assertJsonPath('code', 42200)->assertJsonPath('message', '目标手机号与当前绑定不一致');
    }

    public function test_send_verify_bound_phone_code_allows_own_phone(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);

        $this->mock(SmsService::class, function ($mock) {
            $mock->shouldReceive('sendVerifyCode')->once();
        });

        $this->postJson('/api/v2/client/auth/phone-code', [
            'phone' => $user->phone,
            'purpose' => 'verify_bound_phone',
        ])->assertOk();
    }
}
