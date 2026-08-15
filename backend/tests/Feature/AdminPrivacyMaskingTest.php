<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\Rbac\BuiltinAdminRoleService;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPrivacyMaskingTest extends TestCase
{
    public function test_visitor_receives_masked_user_privacy_and_cannot_search_explicit_private_needles(): void
    {
        $user = $this->createTargetUser();
        Sanctum::actingAs($this->createVisitorAdmin());

        $response = $this->getJson('/api/v2/admin/users?user_id='.$user->id)
            ->assertOk()
            ->assertJsonPath('code', 0);

        $row = $response->json('data.list.0');
        $this->assertNotSame($user->email, $row['email'] ?? null);
        $this->assertNotSame($user->phone, $row['phone'] ?? null);
        $this->assertStringContainsString('***', (string) ($row['email'] ?? ''));
        $this->assertStringContainsString('****', (string) ($row['phone'] ?? ''));

        $this->getJson('/api/v2/admin/users?keyword='.urlencode((string) $user->email))
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);
    }

    public function test_admin_with_privacy_view_raw_can_see_original_privacy_values(): void
    {
        $user = $this->createTargetUser();
        Sanctum::actingAs($this->createAdmin([
            AdminPermissions::USER_LIST,
            AdminPermissions::PRIVACY_VIEW_RAW,
        ]));

        $this->getJson('/api/v2/admin/users?user_id='.$user->id)
            ->assertOk()
            ->assertJsonPath('data.list.0.email', $user->email)
            ->assertJsonPath('data.list.0.phone', $user->phone);
    }

    public function test_privacy_view_raw_does_not_grant_secret_reveal(): void
    {
        Sanctum::actingAs($this->createAdmin([
            AdminPermissions::SETTINGS_VIEW,
            AdminPermissions::PRIVACY_VIEW_RAW,
        ]));

        $this->getJson('/api/v2/admin/settings/system/secrets/app_key')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    private function createTargetUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => 'privacy-'.$suffix.'@example.com',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'password' => 'Temp@123456',
            'real_name' => '张三',
            'verification_status' => 2,
            'is_verified' => 1,
            'status' => 1,
        ]);
    }

    private function createVisitorAdmin(): AdminUser
    {
        app(BuiltinAdminRoleService::class)->sync();

        $role = Role::query()->where('name', 'visitor')->firstOrFail();
        $suffix = bin2hex(random_bytes(4));

        return AdminUser::query()->create([
            'username' => 'privacy-visitor-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'email' => 'privacy-visitor-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @param  string[]  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'privacy-admin-'.$suffix,
            'label' => 'Privacy Admin',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'privacy-admin-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'email' => 'privacy-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }
}
