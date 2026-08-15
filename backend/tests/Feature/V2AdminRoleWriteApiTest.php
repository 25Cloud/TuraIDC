<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminRoleWriteApiTest extends TestCase
{
    public function test_role_create_update_and_delete_use_v2_contract(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $createPayload = [
            'name' => 'v2-role-write-'.$suffix,
            'label' => 'V2 Role Write '.$suffix,
            'permissions' => [AdminPermissions::USER_LIST],
        ];

        $this->postJson('/api/v2/admin/roles', $createPayload)
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ROLE_LIST], 'role-write-forbidden'));

        $this->postJson('/api/v2/admin/roles', $createPayload)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ROLE_MANAGE], 'role-write-allowed'));

        $this->postJson('/api/v2/admin/roles', $createPayload + ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $createResponse = $this->postJson('/api/v2/admin/roles', $createPayload)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '角色创建成功')
            ->assertJsonPath('data.name', $createPayload['name'])
            ->assertJsonPath('data.permissions.0', AdminPermissions::USER_LIST);

        $createdRoleId = (int) $createResponse->json('data.id');
        $this->assertSame($this->roleFields(), array_keys($createResponse->json('data')));
        $this->assertNoSensitiveKeys($createResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $createResponse->getContent()));

        $updatePayload = [
            'name' => 'v2-role-write-updated-'.$suffix,
            'label' => 'V2 Role Write Updated '.$suffix,
            'permissions' => [AdminPermissions::TICKET_LIST],
        ];

        $this->putJson('/api/v2/admin/roles/'.$createdRoleId, $updatePayload + ['pageSize' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $updateResponse = $this->putJson('/api/v2/admin/roles/'.$createdRoleId, $updatePayload)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '角色更新成功')
            ->assertJsonPath('data.id', $createdRoleId)
            ->assertJsonPath('data.name', $updatePayload['name'])
            ->assertJsonPath('data.permissions.0', AdminPermissions::TICKET_LIST);

        $this->assertSame($this->roleFields(), array_keys($updateResponse->json('data')));
        $this->assertNoSensitiveKeys($updateResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $updateResponse->getContent()));

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ROLE_LIST], 'role-delete-forbidden'));

        $this->deleteJson('/api/v2/admin/roles/'.$createdRoleId)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ROLE_MANAGE], 'role-delete-allowed'));

        $this->deleteJson('/api/v2/admin/roles/'.$createdRoleId, ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $deleteResponse = $this->deleteJson('/api/v2/admin/roles/'.$createdRoleId)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '角色删除成功')
            ->assertJsonPath('data', null);

        $this->assertNoSensitiveKeys($deleteResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $deleteResponse->getContent()));
        $this->assertDatabaseMissing('roles', ['id' => $createdRoleId]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions, string $prefix): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-role-write-admin-'.$prefix.'-'.$suffix,
            'label' => 'V2 Role Write Admin '.$prefix,
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-role-write-admin-'.$prefix.'-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Role Write Admin',
            'email' => 'v2-role-write-admin-'.$prefix.'-'.$suffix.'@example.com',
            'status' => 1,
        ]);
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
