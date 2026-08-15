<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\VerificationCodeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientPasswordPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private function createClientUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => "pwd-policy-{$suffix}@example.com",
            'password' => 'Temp@123456',
            'phone' => '',
            'status' => 1,
            'nickname' => '密码策略测试用户',
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

    public function test_register_rejects_short_password(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $email = "pwd-register-{$suffix}@example.com";
        app(VerificationCodeService::class)->storeEmailCode('guest', $email, '123456');

        $this->postJson('/api/v2/client/register', [
            'account' => $email,
            'code' => '123456',
            'password' => '123456',
            'password_confirmation' => '123456',
        ])->assertStatus(422)->assertJsonPath('code', 42200);

        $this->assertNull(User::query()->where('email', $email)->first());
    }

    public function test_register_accepts_password_of_eight_chars(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $email = "pwd-register-{$suffix}@example.com";
        app(VerificationCodeService::class)->storeEmailCode('guest', $email, '123456');

        $this->postJson('/api/v2/client/register', [
            'account' => $email,
            'code' => '123456',
            'password' => 'Pass1234',
            'password_confirmation' => 'Pass1234',
            'nickname' => '密码策略测试',
        ])->assertOk();

        $this->assertNotNull(User::query()->where('email', $email)->first());
    }

    public function test_reset_password_rejects_short_password(): void
    {
        $user = $this->createClientUser();
        app(VerificationCodeService::class)->storeEmailCode('guest', (string) $user->email, '654321');

        $this->postJson('/api/v2/client/auth/reset-password', [
            'account' => $user->email,
            'code' => '654321',
            'password' => '123456',
            'password_confirmation' => '123456',
        ])->assertStatus(422)->assertJsonPath('code', 42200);

        $this->assertTrue(Hash::check('Temp@123456', $user->fresh()->password));
    }

    public function test_change_password_rejects_short_new_password(): void
    {
        $user = $this->createClientUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/v2/client/password', [
            'oldPassword' => 'Temp@123456',
            'newPassword' => '123456',
            'confirmPassword' => '123456',
        ])->assertStatus(422)->assertJsonPath('code', 42200);

        $this->assertTrue(Hash::check('Temp@123456', $user->fresh()->password));
    }
}
