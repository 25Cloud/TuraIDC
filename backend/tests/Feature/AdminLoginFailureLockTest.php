<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdminLoginFailureLockTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_account_is_locked_after_five_failed_attempts(): void
    {
        $admin = $this->createAdmin();

        // 每次使用不同 IP，避免命中路由 throttle:5,1 干扰账户级锁定断言
        for ($i = 1; $i <= 5; $i++) {
            $this->postLogin($admin->username, 'wrong-password', "203.0.113.{$i}")
                ->assertStatus(401)
                ->assertJsonPath('code', 40100);
        }

        // 即使密码正确，锁定期内依然拒绝
        $this->postLogin($admin->username, 'Temp@123456', '203.0.113.6')
            ->assertStatus(429)
            ->assertJsonPath('code', 42900);
    }

    public function test_admin_login_success_clears_failed_counter(): void
    {
        $admin = $this->createAdmin();

        for ($i = 1; $i <= 4; $i++) {
            $this->postLogin($admin->username, 'wrong-password', "203.0.113.{$i}")->assertStatus(401);
        }

        $this->postLogin($admin->username, 'Temp@123456', '203.0.113.5')
            ->assertOk()
            ->assertJsonPath('data.admin.id', (int) $admin->id);

        // 计数已清除：再次输错密码回到普通 401，而不是锁定 429
        $this->postLogin($admin->username, 'wrong-password', '203.0.113.6')
            ->assertStatus(401)
            ->assertJsonPath('code', 40100);
    }

    public function test_unknown_admin_username_returns_same_generic_error(): void
    {
        $this->postLogin('ghost-'.bin2hex(random_bytes(4)), 'whatever123', '203.0.113.10')
            ->assertStatus(401)
            ->assertJsonPath('code', 40100)
            ->assertJsonPath('message', '用户名或密码错误');
    }

    private function postLogin(string $username, string $password, string $ip): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v2/admin/login', [
            'username' => $username,
            'password' => $password,
        ]);
    }

    private function createAdmin(): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));

        $role = Role::query()->create([
            'name' => 'lock-admin-role-'.$suffix,
            'label' => 'Lock Admin Role',
            'permissions' => ['*'],
        ]);

        return AdminUser::query()->create([
            'username' => 'lock-admin-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Lock Admin',
            'email' => 'lock-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }
}
