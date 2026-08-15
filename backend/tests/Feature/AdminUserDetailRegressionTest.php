<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminPermissions;
use Tests\TestCase;

class AdminUserDetailRegressionTest extends TestCase
{
    public function test_admin_user_detail_and_balance_logs_use_account_transactions(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $role = Role::query()->create([
            'name' => 'user-detail-regression-'.$suffix,
            'label' => 'User Detail Regression',
            'permissions' => [AdminPermissions::USER_DETAIL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'user-detail-admin-'.$suffix,
            'password' => 'secret123',
            'role_id' => (int) $role->id,
            'nickname' => 'Regression Admin',
            'email' => 'user-detail-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        $user = User::query()->create([
            'email' => 'user-detail-client-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'User Detail Client',
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

        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'recharge',
            'change_amount' => '88.00',
            'balance_after' => '88.00',
            'source_type' => 'payment',
            'source_id' => 1001,
            'origin_type' => 'payment',
            'origin_id' => 1001,
            'remark' => 'admin user detail regression',
            'operator' => 'system',
            'trace_id' => 'admin-user-detail-'.$suffix,
        ]);

        $token = $admin->createToken('admin-user-detail-regression')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v2/admin/users/'.$user->id)
            ->assertOk()
            ->assertJsonPath('data.user.id', (int) $user->id)
            ->assertJsonPath('data.stats.total_income', 88)
            ->assertJsonPath('data.stats.total_expense', 0);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v2/admin/users/'.$user->id.'/balance-logs?page=1&page_size=15')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.event_type', 'recharge')
            ->assertJsonPath('data.summary.total_income', 88);
    }
}
