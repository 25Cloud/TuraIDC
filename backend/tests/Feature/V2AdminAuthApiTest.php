<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminAuthApiTest extends TestCase
{
    public function test_admin_login_uses_v2_envelope_and_compact_profile(): void
    {
        $admin = $this->createAdmin([AdminPermissions::ALL]);

        $this->postJson('/api/v2/admin/login', [
            'username' => $admin->username,
            'password' => 'Temp@123456',
            'per_page' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->postJson('/api/v2/admin/login', [
            'username' => $admin->username,
            'password' => 'Temp@123456',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.admin.id', $admin->id)
            ->assertJsonPath('data.admin.username', $admin->username)
            ->assertJsonPath('data.admin.permissions.0', AdminPermissions::ALL)
            ->assertJsonStructure(['code', 'message', 'data', 'timestamp']);

        $this->assertSame(['token', 'admin'], array_keys($response->json('data')));
        $this->assertSame($this->adminProfileWhitelist(), array_keys($response->json('data.admin')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_admin_auth_info_requires_login_and_returns_profile_resource(): void
    {
        $admin = $this->createAdmin([AdminPermissions::ORDER_LIST]);

        $this->getJson('/api/v2/admin/auth/info')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v2/admin/auth/info')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.admin.id', $admin->id)
            ->assertJsonPath('data.admin.permissions.0', AdminPermissions::ORDER_LIST);

        $this->assertSame(['admin'], array_keys($response->json('data')));
        $this->assertSame($this->adminProfileWhitelist(), array_keys($response->json('data.admin')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_admin_auth_profile_and_password_actions_reject_legacy_pagination(): void
    {
        $admin = $this->createAdmin([AdminPermissions::ALL]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/v2/admin/auth/profile', [
            'nickname' => 'Updated Admin',
            'per_page' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->putJson('/api/v2/admin/auth/profile', [
            'nickname' => 'Updated Admin',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.admin.nickname', 'Updated Admin');

        $this->assertSame($this->adminProfileWhitelist(), array_keys($response->json('data.admin')));

        $this->putJson('/api/v2/admin/auth/password', [
            'current_password' => 'Temp@123456',
            'password' => 'Changed@123456',
            'password_confirmation' => 'Changed@123456',
            'per_page' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->putJson('/api/v2/admin/auth/password', [
            'current_password' => 'Temp@123456',
            'password' => 'Changed@123456',
            'password_confirmation' => 'Changed@123456',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data', null);

        $this->assertTrue(Hash::check('Changed@123456', (string) $admin->refresh()->password));
    }

    /**
     * @return list<string>
     */
    private function adminProfileWhitelist(): array
    {
        return [
            'id',
            'username',
            'nickname',
            'email',
            'role',
            'permissions',
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-auth-'.$suffix,
            'label' => 'V2 Auth '.$suffix,
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-auth-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Auth',
            'email' => 'v2-auth-'.$suffix.'@example.com',
            'status' => 1,
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
