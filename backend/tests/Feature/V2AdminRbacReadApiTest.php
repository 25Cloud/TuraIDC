<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminRbacReadApiTest extends TestCase
{
    public function test_permission_catalog_requires_permission_rejects_legacy_pagination_and_returns_whitelist(): void
    {
        $this->getJson('/api/v2/admin/permissions')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW], 'permission-forbidden'));

        $this->getJson('/api/v2/admin/permissions')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PERMISSION_LIST], 'permission-allowed'));

        $this->getJson('/api/v2/admin/permissions?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/permissions?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/admin/permissions')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $payload = $response->json();
        $this->assertSame(['code', 'message', 'data', 'timestamp'], array_keys($payload));
        $this->assertSame(['list'], array_keys($payload['data']));
        $this->assertNotEmpty($payload['data']['list']);

        $permission = $this->findByKey($payload['data']['list'], 'key', AdminPermissions::PERMISSION_LIST);
        $this->assertNotNull($permission);
        $this->assertSame($this->permissionFields(), array_keys($permission));
        $this->assertNoSensitiveKeys($payload);
        $this->assertLessThan(70 * 1024, strlen((string) $response->getContent()));
    }

    public function test_role_list_requires_permission_rejects_legacy_pagination_and_returns_whitelist(): void
    {
        $role = $this->createRole('list', [AdminPermissions::USER_LIST]);

        $this->getJson('/api/v2/admin/roles?keyword='.$role->name)
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW], 'roles-forbidden'));

        $this->getJson('/api/v2/admin/roles?keyword='.$role->name)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ROLE_LIST], 'roles-allowed'));

        $this->getJson('/api/v2/admin/roles?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/roles?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/admin/roles?keyword='.$role->name)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $role->id);

        $payload = $response->json();
        $this->assertSame(['code', 'message', 'data', 'timestamp'], array_keys($payload));
        $this->assertSame(['list'], array_keys($payload['data']));
        $this->assertSame($this->roleFields(), array_keys($payload['data']['list'][0]));
        $this->assertNoSensitiveKeys($payload);
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_role_detail_requires_permission_rejects_legacy_pagination_and_returns_whitelist(): void
    {
        $role = $this->createRole('detail', [AdminPermissions::TICKET_LIST]);

        $this->getJson('/api/v2/admin/roles/'.$role->id)
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW], 'role-detail-forbidden'));

        $this->getJson('/api/v2/admin/roles/'.$role->id)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ROLE_LIST], 'role-detail-allowed'));

        $this->getJson('/api/v2/admin/roles/'.$role->id.'?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/roles/'.$role->id.'?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/admin/roles/'.$role->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $role->id)
            ->assertJsonPath('data.permissions.0', AdminPermissions::TICKET_LIST);

        $payload = $response->json();
        $this->assertSame(['code', 'message', 'data', 'timestamp'], array_keys($payload));
        $this->assertSame($this->roleFields(), array_keys($payload['data']));
        $this->assertNoSensitiveKeys($payload);
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createRole(string $prefix, array $permissions): Role
    {
        $suffix = bin2hex(random_bytes(4));

        return Role::query()->create([
            'name' => 'v2-rbac-'.$prefix.'-'.$suffix,
            'label' => 'V2 RBAC '.$prefix.' '.$suffix,
            'permissions' => $permissions,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions, string $prefix): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-rbac-admin-'.$prefix.'-'.$suffix,
            'label' => 'V2 RBAC Admin '.$prefix,
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-rbac-admin-'.$prefix.'-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 RBAC Admin',
            'email' => 'v2-rbac-admin-'.$prefix.'-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function findByKey(array $items, string $key, string $value): ?array
    {
        foreach ($items as $item) {
            if (($item[$key] ?? null) === $value) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function permissionFields(): array
    {
        return [
            'key',
            'module',
            'module_label',
            'group',
            'group_label',
            'name',
            'description',
            'action',
            'action_label',
            'risk_level',
            'is_dangerous',
            'is_all',
            'sort',
        ];
    }

    /**
     * @return list<string>
     */
    private function roleFields(): array
    {
        return [
            'id',
            'name',
            'label',
            'permissions',
            'stored_permissions',
            'admin_count',
            'is_builtin',
            'is_locked',
            'can_edit_permissions',
            'can_delete',
            'created_at',
            'updated_at',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response', 'token'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
