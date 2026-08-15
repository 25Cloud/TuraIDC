<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminPermissions;
use Tests\TestCase;

class AdminUserListRegressionTest extends TestCase
{
    public function test_admin_user_list_returns_phone_field(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $phone = '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);

        $role = Role::query()->create([
            'name' => 'user-list-regression-'.$suffix,
            'label' => 'User List Regression',
            'permissions' => [AdminPermissions::USER_LIST, AdminPermissions::PRIVACY_VIEW_RAW],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'user-list-admin-'.$suffix,
            'password' => 'secret123',
            'role_id' => (int) $role->id,
            'nickname' => 'List Regression Admin',
            'email' => 'user-list-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        $user = User::query()->create([
            'email' => 'user-list-client-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => $phone,
            'status' => 1,
            'nickname' => 'User List Client',
        ]);

        $token = $admin->createToken('admin-user-list-regression')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v2/admin/users?page=1&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.list.0.id', (int) $user->id)
            ->assertJsonPath('data.list.0.phone', $phone);
    }
}
