<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminPermissions;
use Tests\TestCase;

class AdminUserLoginAsPermissionTest extends TestCase
{
    public function test_user_manage_permission_is_not_enough_for_login_as(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $role = Role::query()->create([
            'name' => 'login-as-manage-only-'.$suffix,
            'label' => 'Login As Manage Only',
            'permissions' => [AdminPermissions::USER_MANAGE],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'login-as-manage-only-'.$suffix,
            'password' => 'secret123',
            'role_id' => (int) $role->id,
            'status' => 1,
        ]);

        $user = User::query()->create([
            'email' => 'login-as-target-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $token = $admin->createToken('login-as-manage-only')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/admin/users/'.$user->id.'/login-as')
            ->assertForbidden();
    }

    public function test_login_as_requires_explicit_permission_and_returns_target_url_without_code_query(): void
    {
        config([
            'app.client_console_url' => 'https://console.example.test',
            'app.admin_url' => 'https://admin.example.test',
        ]);

        $suffix = bin2hex(random_bytes(4));

        $role = Role::query()->create([
            'name' => 'login-as-allowed-'.$suffix,
            'label' => 'Login As Allowed',
            'permissions' => [AdminPermissions::USER_LOGIN_AS],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'login-as-allowed-'.$suffix,
            'password' => 'secret123',
            'role_id' => (int) $role->id,
            'status' => 1,
        ]);

        $user = User::query()->create([
            'email' => 'login-as-target-ok-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $token = $admin->createToken('login-as-allowed')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/admin/users/'.$user->id.'/login-as')
            ->assertOk()
            ->assertJsonPath('data.target_url', 'https://console.example.test/client/login-as')
            ->assertJsonMissingPath('data.redirect_url');
    }
}
