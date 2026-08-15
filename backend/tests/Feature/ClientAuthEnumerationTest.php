<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\VerificationCodeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ClientAuthEnumerationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_by_code_returns_generic_error_for_unregistered_account(): void
    {
        $this->postJson('/api/v2/client/auth/login-by-code', [
            'account' => 'ghost-'.bin2hex(random_bytes(4)).'@example.com',
            'code' => '123456',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('message', '账号或验证码错误');
    }

    public function test_login_by_code_returns_generic_error_for_wrong_code(): void
    {
        $user = $this->createClientUser();

        $this->app->make(VerificationCodeService::class)
            ->storeEmailCode('guest', $user->email, '654321');

        $this->postJson('/api/v2/client/auth/login-by-code', [
            'account' => $user->email,
            'code' => '000000',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('message', '账号或验证码错误');
    }

    public function test_login_by_code_succeeds_with_correct_code(): void
    {
        $user = $this->createClientUser();

        $this->app->make(VerificationCodeService::class)
            ->storeEmailCode('guest', $user->email, '654321');

        $this->postJson('/api/v2/client/auth/login-by-code', [
            'account' => $user->email,
            'code' => '654321',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', (int) $user->id);
    }

    public function test_reset_password_returns_generic_error_for_unregistered_account(): void
    {
        $this->postJson('/api/v2/client/auth/reset-password', [
            'account' => 'ghost-'.bin2hex(random_bytes(4)).'@example.com',
            'code' => '123456',
            'password' => 'Newpass123',
            'password_confirmation' => 'Newpass123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('message', '账号或验证码错误');
    }

    public function test_reset_password_succeeds_with_correct_code(): void
    {
        $user = $this->createClientUser();

        $this->app->make(VerificationCodeService::class)
            ->storeEmailCode('guest', $user->email, '654321');

        $this->postJson('/api/v2/client/auth/reset-password', [
            'account' => $user->email,
            'code' => '654321',
            'password' => 'Newpass123',
            'password_confirmation' => 'Newpass123',
        ])->assertOk();

        $this->assertNotSame('Newpass123', $user->fresh()->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Newpass123', $user->fresh()->password));
    }

    private function createClientUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => "enum-{$suffix}@example.com",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => '枚举测试用户',
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
        ]);
    }
}
