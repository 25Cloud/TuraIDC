<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminStaffApiTest extends TestCase
{
    public function test_staff_read_endpoints_require_permission_and_return_whitelisted_payloads(): void
    {
        $role = $this->createRole([AdminPermissions::USER_LIST], 'staff-read-role');
        $staff = $this->createStaff($role, 'staff-read-target');

        $this->getJson('/api/v2/admin/staff?keyword='.$staff->username.'&page_size=1')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ROLE_LIST], 'staff-read-forbidden'));

        $this->getJson('/api/v2/admin/staff?keyword='.$staff->username.'&page_size=1')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::STAFF_LIST], 'staff-read-allowed'));

        $this->getJson('/api/v2/admin/staff?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/staff?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $listResponse = $this->getJson('/api/v2/admin/staff?keyword='.$staff->username.'&page_size=1')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 1)
            ->assertJsonPath('data.list.0.id', $staff->id)
            ->assertJsonMissingPath('data.list.0.password');

        $listPayload = $listResponse->json();
        $this->assertSame(['code', 'message', 'data', 'timestamp'], array_keys($listPayload));
        $this->assertSame(['list', 'total', 'page', 'page_size'], array_keys($listPayload['data']));
        $this->assertSame($this->staffFields(), array_keys($listPayload['data']['list'][0]));
        $this->assertSame($this->roleFields(), array_keys($listPayload['data']['list'][0]['role']));
        $this->assertNoSensitiveKeys($listPayload);
        $this->assertLessThan(100 * 1024, strlen((string) $listResponse->getContent()));

        $this->getJson('/api/v2/admin/staff/'.$staff->id.'?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $detailResponse = $this->getJson('/api/v2/admin/staff/'.$staff->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $staff->id)
            ->assertJsonMissingPath('data.password');

        $this->assertSame($this->staffFields(), array_keys($detailResponse->json('data')));
        $this->assertNoSensitiveKeys($detailResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $detailResponse->getContent()));

        $this->getJson('/api/v2/admin/staff/roles?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $rolesResponse = $this->getJson('/api/v2/admin/staff/roles')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $roleOption = $this->findByKey($rolesResponse->json('data.list'), 'id', (int) $role->id);
        $this->assertNotNull($roleOption);
        $this->assertSame($this->roleFields(), array_keys($roleOption));
        $this->assertNoSensitiveKeys($rolesResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $rolesResponse->getContent()));
    }

    public function test_staff_write_endpoints_require_permission_and_return_whitelisted_payloads(): void
    {
        $role = $this->createRole([AdminPermissions::TICKET_LIST], 'staff-write-role');
        $suffix = bin2hex(random_bytes(4));
        $createPayload = [
            'username' => 'v2-staff-write-'.$suffix,
            'password' => 'Staff#123456',
            'nickname' => 'V2 Staff Write',
            'email' => 'v2-staff-write-'.$suffix.'@example.com',
            'role_id' => (int) $role->id,
            'status' => 1,
        ];

        $this->postJson('/api/v2/admin/staff', $createPayload)
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::STAFF_LIST], 'staff-write-forbidden'));

        $this->postJson('/api/v2/admin/staff', $createPayload)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::STAFF_MANAGE], 'staff-write-allowed'));

        $this->postJson('/api/v2/admin/staff', $createPayload + ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $createResponse = $this->postJson('/api/v2/admin/staff', $createPayload)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '员工创建成功')
            ->assertJsonPath('data.username', $createPayload['username'])
            ->assertJsonMissingPath('data.password');

        $staffId = (int) $createResponse->json('data.id');
        $this->assertSame($this->staffFields(), array_keys($createResponse->json('data')));
        $this->assertNoSensitiveKeys($createResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $createResponse->getContent()));

        $updatePayload = [
            'nickname' => 'V2 Staff Updated',
            'role_id' => (int) $role->id,
            'status' => 0,
        ];

        $this->putJson('/api/v2/admin/staff/'.$staffId, $updatePayload + ['pageSize' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $updateResponse = $this->putJson('/api/v2/admin/staff/'.$staffId, $updatePayload)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '员工更新成功')
            ->assertJsonPath('data.id', $staffId)
            ->assertJsonPath('data.nickname', 'V2 Staff Updated')
            ->assertJsonPath('data.status', 0)
            ->assertJsonMissingPath('data.password');

        $this->assertSame($this->staffFields(), array_keys($updateResponse->json('data')));
        $this->assertNoSensitiveKeys($updateResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $updateResponse->getContent()));

        Sanctum::actingAs($this->createAdmin([AdminPermissions::STAFF_MANAGE], 'staff-delete-no-root'));

        $this->deleteJson('/api/v2/admin/staff/'.$staffId)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ALL], 'staff-delete-root'));

        $this->deleteJson('/api/v2/admin/staff/'.$staffId, ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $deleteResponse = $this->deleteJson('/api/v2/admin/staff/'.$staffId)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '员工已删除')
            ->assertJsonPath('data', null);

        $this->assertNoSensitiveKeys($deleteResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $deleteResponse->getContent()));
        $this->assertDatabaseMissing('admin_users', ['id' => $staffId]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createRole(array $permissions, string $prefix): Role
    {
        $suffix = bin2hex(random_bytes(4));

        return Role::query()->create([
            'name' => 'v2-'.$prefix.'-'.$suffix,
            'label' => 'V2 '.$prefix.' '.$suffix,
            'permissions' => $permissions,
        ]);
    }

    private function createStaff(Role $role, string $prefix, array $overrides = []): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));

        return AdminUser::query()->create(array_replace([
            'username' => 'v2-'.$prefix.'-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Staff '.$prefix,
            'email' => 'v2-'.$prefix.'-'.$suffix.'@example.com',
            'status' => 1,
            'last_login_at' => now(),
            'last_login_ip' => '127.0.0.1',
        ], $overrides));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions, string $prefix): AdminUser
    {
        $role = $this->createRole($permissions, $prefix.'-admin-role');

        return $this->createStaff($role, $prefix.'-admin');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function findByKey(array $items, string $key, int|string $value): ?array
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
    private function staffFields(): array
    {
        return [
            'id',
            'username',
            'nickname',
            'email',
            'status',
            'role_id',
            'role',
            'role_label',
            'permissions',
            'last_login_at',
            'last_login_ip',
            'created_at',
            'updated_at',
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
