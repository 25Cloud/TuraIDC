<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStaffAccountManagementTest extends TestCase
{
    public function test_super_admin_can_update_staff_account_identity_and_reset_password(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $operator = $this->createAdmin([AdminPermissions::ALL]);
        $role = $this->createRole([AdminPermissions::STAFF_LIST]);
        $staff = $this->createStaff($role, ['username' => 'staff-original-'.$suffix, 'email' => 'staff-original-'.$suffix.'@example.com']);

        Sanctum::actingAs($operator);

        $this->putJson('/api/v2/admin/staff/'.$staff->id, [
            'username' => 'staff-renamed-'.$suffix,
            'nickname' => 'Renamed Staff',
            'email' => 'staff-renamed-'.$suffix.'@example.com',
            'role_id' => (int) $role->id,
            'status' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.username', 'staff-renamed-'.$suffix)
            ->assertJsonPath('data.email', 'staff-renamed-'.$suffix.'@example.com');

        $this->postJson('/api/v2/admin/staff/'.$staff->id.'/password-resets', [
            'password' => 'NewPass@123',
            'password_confirmation' => 'NewPass@123',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $staff->refresh();
        $this->assertSame('staff-renamed-'.$suffix, $staff->username);
        $this->assertSame('staff-renamed-'.$suffix.'@example.com', $staff->email);
        $this->assertTrue(Hash::check('NewPass@123', (string) $staff->password));
    }

    public function test_staff_login_account_accepts_at_sign(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $operator = $this->createAdmin([AdminPermissions::ALL]);
        $role = $this->createRole([AdminPermissions::STAFF_LIST]);

        Sanctum::actingAs($operator);

        $this->postJson('/api/v2/admin/staff', [
            'username' => 'staff@'.$suffix,
            'nickname' => 'At Sign Staff',
            'email' => null,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'status' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.username', 'staff@'.$suffix);
    }

    public function test_non_super_admin_cannot_change_staff_username_email_or_reset_password(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $operator = $this->createAdmin([AdminPermissions::STAFF_MANAGE]);
        $role = $this->createRole([AdminPermissions::STAFF_LIST]);
        $staff = $this->createStaff($role, ['username' => 'staff-locked-'.$suffix, 'email' => 'staff-locked-'.$suffix.'@example.com']);

        Sanctum::actingAs($operator);

        $this->putJson('/api/v2/admin/staff/'.$staff->id, [
            'username' => 'staff-hacked',
            'nickname' => 'Allowed Nickname',
            'email' => 'staff-hacked@example.com',
            'role_id' => (int) $role->id,
            'status' => 1,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $this->postJson('/api/v2/admin/staff/'.$staff->id.'/password-resets', [
            'password' => 'NewPass@123',
            'password_confirmation' => 'NewPass@123',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $staff->refresh();
        $this->assertSame('staff-locked-'.$suffix, $staff->username);
        $this->assertSame('staff-locked-'.$suffix.'@example.com', $staff->email);
        $this->assertFalse(Hash::check('NewPass@123', (string) $staff->password));
    }

    public function test_super_admin_can_delete_disabled_staff_only(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $operator = $this->createAdmin([AdminPermissions::ALL]);
        $role = $this->createRole([AdminPermissions::STAFF_LIST]);
        $activeStaff = $this->createStaff($role, ['username' => 'staff-active-'.$suffix, 'status' => 1]);
        $disabledStaff = $this->createStaff($role, ['username' => 'staff-disabled-'.$suffix, 'status' => 0]);

        Sanctum::actingAs($operator);

        $this->deleteJson('/api/v2/admin/staff/'.$activeStaff->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $this->deleteJson('/api/v2/admin/staff/'.$disabledStaff->id)
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertDatabaseHas('admin_users', ['id' => (int) $activeStaff->id]);
        $this->assertDatabaseMissing('admin_users', ['id' => (int) $disabledStaff->id]);
    }

    public function test_admin_can_update_own_password_with_current_password(): void
    {
        $admin = $this->createAdmin([AdminPermissions::STAFF_LIST], ['password' => 'OldPass@123']);

        Sanctum::actingAs($admin);

        $this->putJson('/api/v2/admin/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'NewPass@123',
            'password_confirmation' => 'NewPass@123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $this->putJson('/api/v2/admin/auth/password', [
            'current_password' => 'OldPass@123',
            'password' => 'NewPass@123',
            'password_confirmation' => 'NewPass@123',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertTrue(Hash::check('NewPass@123', (string) $admin->refresh()->password));
    }

    /**
     * @param  string[]  $permissions
     * @param  array<string, mixed>  $overrides
     */
    private function createAdmin(array $permissions, array $overrides = []): AdminUser
    {
        $role = $this->createRole($permissions);

        return $this->createStaff($role, array_merge([
            'username' => 'staff-test-admin-'.bin2hex(random_bytes(4)),
            'password' => 'Temp@123456',
            'status' => 1,
        ], $overrides));
    }

    /**
     * @param  string[]  $permissions
     */
    private function createRole(array $permissions): Role
    {
        $suffix = bin2hex(random_bytes(4));

        return Role::query()->create([
            'name' => 'staff-account-role-'.$suffix,
            'label' => 'Staff Account Role',
            'permissions' => $permissions,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createStaff(Role $role, array $overrides = []): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));

        return AdminUser::query()->create(array_merge([
            'username' => 'staff-account-'.$suffix,
            'password' => 'Temp@123456',
            'nickname' => 'Staff Account',
            'email' => 'staff-account-'.$suffix.'@example.com',
            'role_id' => (int) $role->id,
            'status' => 1,
        ], $overrides));
    }
}
