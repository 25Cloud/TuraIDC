<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 回归：持 role.manage / staff.manage 的管理员不得一步取得超级管理员权限。
 */
class AdminRbacPrivilegeEscalationTest extends TestCase
{
    public function test_role_manager_cannot_create_role_with_all_permission(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::ROLE_MANAGE], 'esc-role-create'));

        $suffix = bin2hex(random_bytes(4));
        $this->postJson('/api/v2/admin/roles', [
            'name' => 'esc-super-'.$suffix,
            'label' => 'Escalation Super '.$suffix,
            'permissions' => [AdminPermissions::ALL],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $this->assertDatabaseMissing('roles', ['name' => 'esc-super-'.$suffix]);
    }

    public function test_role_manager_cannot_add_all_permission_to_existing_role(): void
    {
        $role = $this->createRole([AdminPermissions::USER_LIST], 'esc-role-update');

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ROLE_MANAGE], 'esc-role-update-op'));

        $this->putJson('/api/v2/admin/roles/'.$role->id, [
            'name' => (string) $role->name,
            'label' => (string) $role->label,
            'permissions' => [AdminPermissions::ALL],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $this->assertSame([AdminPermissions::USER_LIST], $role->fresh()->resolvedPermissions());
    }

    public function test_super_admin_can_still_grant_all_permission(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::ALL], 'esc-super-op'));

        $suffix = bin2hex(random_bytes(4));
        $this->postJson('/api/v2/admin/roles', [
            'name' => 'esc-allowed-super-'.$suffix,
            'label' => 'Escalation Allowed '.$suffix,
            'permissions' => [AdminPermissions::ALL],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.permissions.0', AdminPermissions::ALL);
    }

    public function test_staff_manager_cannot_assign_super_admin_role_to_others(): void
    {
        $superRole = $this->createRole([AdminPermissions::ALL], 'esc-staff-super');

        Sanctum::actingAs($this->createAdmin([AdminPermissions::STAFF_MANAGE], 'esc-staff-op'));

        $suffix = bin2hex(random_bytes(4));
        $this->postJson('/api/v2/admin/staff', [
            'username' => 'esc-staff-'.$suffix,
            'password' => 'Staff#123456',
            'nickname' => 'Escalation Staff',
            'email' => 'esc-staff-'.$suffix.'@example.com',
            'role_id' => (int) $superRole->id,
            'status' => 1,
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $this->assertDatabaseMissing('admin_users', ['username' => 'esc-staff-'.$suffix]);
    }

    public function test_staff_manager_cannot_promote_self_to_super_admin_role(): void
    {
        $superRole = $this->createRole([AdminPermissions::ALL], 'esc-self-super');
        $operator = $this->createAdmin([AdminPermissions::STAFF_MANAGE], 'esc-self-op');

        Sanctum::actingAs($operator);

        $this->putJson('/api/v2/admin/staff/'.$operator->id, [
            'nickname' => 'Escalated Self',
            'role_id' => (int) $superRole->id,
            'status' => 1,
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $this->assertNotSame((int) $superRole->id, (int) $operator->fresh()->role_id);
    }

    public function test_staff_manager_can_still_assign_ordinary_role(): void
    {
        $ordinaryRole = $this->createRole([AdminPermissions::TICKET_LIST], 'esc-ordinary');

        Sanctum::actingAs($this->createAdmin([AdminPermissions::STAFF_MANAGE], 'esc-ordinary-op'));

        $suffix = bin2hex(random_bytes(4));
        $this->postJson('/api/v2/admin/staff', [
            'username' => 'esc-ordinary-'.$suffix,
            'password' => 'Staff#123456',
            'nickname' => 'Ordinary Staff',
            'email' => 'esc-ordinary-'.$suffix.'@example.com',
            'role_id' => (int) $ordinaryRole->id,
            'status' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createRole(array $permissions, string $prefix): Role
    {
        $suffix = bin2hex(random_bytes(4));

        return Role::query()->create([
            'name' => $prefix.'-'.$suffix,
            'label' => $prefix.' '.$suffix,
            'permissions' => $permissions,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions, string $prefix): AdminUser
    {
        $role = $this->createRole($permissions, $prefix.'-role');
        $suffix = bin2hex(random_bytes(4));

        return AdminUser::query()->create([
            'username' => $prefix.'-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Escalation Operator',
            'email' => $prefix.'-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }
}
