<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Services\Admin\Rbac\BuiltinAdminRoleService;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDefaultRoleTest extends TestCase
{
    public function test_builtin_roles_are_synced_and_permissions_are_fixed(): void
    {
        app(BuiltinAdminRoleService::class)->sync();

        $roles = Role::query()
            ->whereIn('name', AdminPermissions::builtInRoleNames())
            ->get()
            ->keyBy('name');

        $this->assertCount(3, $roles);
        $this->assertSame([AdminPermissions::ALL], $roles['super_admin']->resolvedPermissions());
        $this->assertEqualsCanonicalizing(AdminPermissions::visitorPermissions(), $roles['visitor']->resolvedPermissions());
        $this->assertEqualsCanonicalizing(AdminPermissions::adminDefaultPermissions(), $roles['admin']->resolvedPermissions());

        $this->assertNotContains(AdminPermissions::PRIVACY_VIEW_RAW, $roles['visitor']->resolvedPermissions());
        $this->assertNotContains(AdminPermissions::SETTINGS_SECRET_REVEAL, $roles['admin']->resolvedPermissions());
        $this->assertNotContains(AdminPermissions::ROLE_MANAGE, $roles['admin']->resolvedPermissions());
        $this->assertNotContains(AdminPermissions::USER_LOGIN_AS, $roles['admin']->resolvedPermissions());
        $this->assertNotContains(AdminPermissions::USER_RECHARGE, $roles['admin']->resolvedPermissions());
        $this->assertNotContains(AdminPermissions::FINANCE_WITHDRAW, $roles['admin']->resolvedPermissions());
    }

    public function test_builtin_role_permissions_ignore_database_drift_and_sync_back(): void
    {
        app(BuiltinAdminRoleService::class)->sync();

        $visitor = Role::query()->where('name', 'visitor')->firstOrFail();
        $visitor->forceFill([
            'permissions' => [AdminPermissions::USER_MANAGE, AdminPermissions::PRIVACY_VIEW_RAW],
        ])->save();

        $this->assertEqualsCanonicalizing(AdminPermissions::visitorPermissions(), $visitor->fresh()->resolvedPermissions());

        app(BuiltinAdminRoleService::class)->sync();

        $this->assertEqualsCanonicalizing(AdminPermissions::visitorPermissions(), (array) $visitor->fresh()->permissions);
    }

    public function test_builtin_roles_are_marked_locked_in_admin_api(): void
    {
        app(BuiltinAdminRoleService::class)->sync();
        Sanctum::actingAs($this->createAdmin([AdminPermissions::ROLE_LIST]));

        $response = $this->getJson('/api/v2/admin/roles')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $roles = collect($response->json('data.list'))->keyBy('name');

        foreach (['super_admin', 'admin', 'visitor'] as $name) {
            $this->assertTrue((bool) data_get($roles, "{$name}.is_builtin"));
            $this->assertTrue((bool) data_get($roles, "{$name}.is_locked"));
        }
    }

    public function test_builtin_roles_cannot_be_updated_or_deleted(): void
    {
        app(BuiltinAdminRoleService::class)->sync();
        $visitor = Role::query()->where('name', 'visitor')->firstOrFail();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ROLE_MANAGE]));

        $this->putJson('/api/v2/admin/roles/'.$visitor->id, [
            'name' => 'visitor',
            'label' => 'Visitor Changed',
            'permissions' => [AdminPermissions::USER_MANAGE],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $this->deleteJson('/api/v2/admin/roles/'.$visitor->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $this->assertEqualsCanonicalizing(AdminPermissions::visitorPermissions(), $visitor->fresh()->resolvedPermissions());
    }

    /**
     * @param  string[]  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'default-role-test-'.$suffix,
            'label' => 'Default Role Test',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'default-role-test-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'email' => 'default-role-test-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }
}
