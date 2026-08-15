<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminRbacActionApiTest extends TestCase
{
    public function test_staff_actions_require_login_and_staff_manage_permission(): void
    {
        $staff = $this->createAdmin([AdminPermissions::STAFF_LIST]);

        $this->patchJson('/api/v2/admin/staff/'.$staff->id.'/status', ['enabled' => false])
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::STAFF_LIST]));

        $this->patchJson('/api/v2/admin/staff/'.$staff->id.'/status', ['enabled' => false])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_staff_status_action_uses_explicit_enabled_and_small_projection(): void
    {
        $staff = $this->createAdmin([AdminPermissions::STAFF_LIST], ['status' => 1]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::STAFF_MANAGE]));

        $this->patchJson('/api/v2/admin/staff/'.$staff->id.'/status', ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['enabled', 'per_page']]]);

        $response = $this->patchJson('/api/v2/admin/staff/'.$staff->id.'/status', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $staff->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.staff.id', $staff->id)
            ->assertJsonPath('data.detail.staff.status', 0);

        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
        $this->assertSame(0, (int) $staff->refresh()->status);
    }

    public function test_staff_password_reset_validates_payload_and_hides_password_fields(): void
    {
        $staff = $this->createAdmin([AdminPermissions::STAFF_LIST]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ALL]));

        $this->postJson('/api/v2/admin/staff/'.$staff->id.'/password-resets', [
            'password' => 'short',
            'password_confirmation' => 'short',
            'per_page' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['password', 'per_page']]]);

        $response = $this->postJson('/api/v2/admin/staff/'.$staff->id.'/password-resets', [
            'password' => 'Reset#123456',
            'password_confirmation' => 'Reset#123456',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $staff->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.staff.id', $staff->id);

        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
        $this->assertTrue(Hash::check('Reset#123456', (string) $staff->refresh()->password));
    }

    public function test_role_copy_action_requires_permission_and_returns_compact_result(): void
    {
        $role = $this->createRole([AdminPermissions::STAFF_LIST]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::STAFF_MANAGE]));

        $this->postJson('/api/v2/admin/roles/'.$role->id.'/copies')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ROLE_MANAGE]));

        $this->postJson('/api/v2/admin/roles/'.$role->id.'/copies', ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->postJson('/api/v2/admin/roles/'.$role->id.'/copies')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.role.permissions_count', 1);

        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertNotSame((int) $role->id, (int) $response->json('data.id'));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    /**
     * @return list<string>
     */
    private function actionResultWhitelist(): array
    {
        return [
            'id',
            'status',
            'message',
            'detail',
        ];
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, mixed>  $overrides
     */
    private function createAdmin(array $permissions, array $overrides = []): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = $this->createRole($permissions, 'v2-rbac-'.$suffix);

        return AdminUser::query()->create(array_replace([
            'username' => 'v2-rbac-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 RBAC',
            'email' => 'v2-rbac-'.$suffix.'@example.com',
            'status' => 1,
        ], $overrides));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createRole(array $permissions, ?string $name = null): Role
    {
        $suffix = bin2hex(random_bytes(4));

        return Role::query()->create([
            'name' => $name ?? 'v2-role-'.$suffix,
            'label' => 'V2 Role '.$suffix,
            'permissions' => $permissions,
        ]);
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
