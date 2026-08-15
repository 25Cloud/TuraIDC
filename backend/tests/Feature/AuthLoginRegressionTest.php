<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class AuthLoginRegressionTest extends TestCase
{
    public function test_client_login_success_does_not_require_user_accounts_table(): void
    {
        $user = User::query()->create([
            'email' => 'client-regression-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Client Regression',
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

        $this->postJson('/api/v2/client/login', [
            'account' => $user->email,
            'password' => 'Temp@123456',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', (int) $user->id)
            ->assertJsonPath('data.user.cash_balance', '0.00')
            ->assertJsonMissingPath('data.user.balance');
    }

    public function test_client_login_returns_generic_reason_for_unknown_account(): void
    {
        $this->postJson('/api/v2/client/login', [
            'account' => 'missing-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 40100)
            ->assertJsonPath('message', '账号或密码错误');
    }

    public function test_client_login_returns_generic_reason_for_wrong_password(): void
    {
        $user = User::query()->create([
            'email' => 'client-regression-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Client Regression',
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

        $this->postJson('/api/v2/client/login', [
            'account' => $user->email,
            'password' => 'Temp@123456-wrong',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 40100)
            ->assertJsonPath('message', '账号或密码错误');
    }

    public function test_admin_login_success_does_not_require_admin_role_bridge_tables(): void
    {
        $role = Role::query()->create([
            'name' => 'admin-regression-'.bin2hex(random_bytes(4)),
            'label' => 'Admin Regression',
            'permissions' => ['*'],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-regression-'.bin2hex(random_bytes(4)),
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Regression',
            'email' => 'admin-regression-'.bin2hex(random_bytes(4)).'@example.com',
            'status' => 1,
        ]);

        $this->postJson('/api/v2/admin/login', [
            'username' => $admin->username,
            'password' => 'Temp@123456',
        ])
            ->assertOk()
            ->assertJsonPath('data.admin.id', (int) $admin->id)
            ->assertJsonPath('data.admin.role', 'Admin Regression');
    }
}
