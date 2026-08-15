<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class SanctumIdleTimeoutTest extends TestCase
{
    public function test_client_token_is_rejected_after_three_hours_of_inactivity(): void
    {
        $user = User::query()->create([
            'email' => 'idle-client-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Idle Client',
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

        $plainTextToken = $user->createToken('idle-client-token')->plainTextToken;
        $accessToken = $user->tokens()->latest('id')->firstOrFail();
        $accessToken->forceFill([
            'last_used_at' => now()->subHours(4),
        ])->save();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$plainTextToken,
        ])->getJson('/api/v2/client/auth/info')
            ->assertStatus(401)
            ->assertJsonPath('code', 40100);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => (int) $accessToken->id,
        ]);
    }

    public function test_client_token_is_rejected_after_absolute_expiration(): void
    {
        $user = User::query()->create([
            'email' => 'expired-client-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Expired Client',
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

        $plainTextToken = $user->createToken('expired-client-token')->plainTextToken;
        $accessToken = $user->tokens()->latest('id')->firstOrFail();
        // 最近仍在活跃（idle_timeout 不触发），仅验证绝对过期（默认 24 小时）生效
        $accessToken->forceFill([
            'created_at' => now()->subHours(25),
            'last_used_at' => now()->subMinute(),
        ])->save();

        // Guard 原生按 created_at + sanctum.expiration 判定过期并拒绝，过期 token 由 prune 命令清理
        $this->withHeaders([
            'Authorization' => 'Bearer '.$plainTextToken,
        ])->getJson('/api/v2/client/auth/info')
            ->assertStatus(401)
            ->assertJsonPath('code', 40100);
    }

    public function test_admin_token_stays_valid_within_idle_timeout_window(): void
    {
        $role = Role::query()->create([
            'name' => 'idle-admin-role-'.bin2hex(random_bytes(4)),
            'label' => 'Idle Admin',
            'permissions' => ['*'],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'idle-admin-'.bin2hex(random_bytes(4)),
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Idle Admin',
            'email' => 'idle-admin-'.bin2hex(random_bytes(4)).'@example.com',
            'status' => 1,
        ]);

        $plainTextToken = $admin->createToken('idle-admin-token')->plainTextToken;
        $accessToken = $admin->tokens()->latest('id')->firstOrFail();
        $accessToken->forceFill([
            'last_used_at' => now()->subHours(2)->subMinutes(30),
        ])->save();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$plainTextToken,
        ])->getJson('/api/v2/admin/auth/info')
            ->assertOk()
            ->assertJsonPath('data.admin.id', (int) $admin->id);
    }
}
