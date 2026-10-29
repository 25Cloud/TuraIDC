<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationPlugin;
use App\Models\Role;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Support\AdminPermissions;
use App\Support\OptionalFeature;
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

        $this->assertSame(['token', 'admin', 'features'], array_keys($response->json('data')));
        $this->assertSame($this->adminProfileWhitelist(), array_keys($response->json('data.admin')));
        // 可选功能开关随登录一起下发，前端据此决定侧栏分组是否出现
        $this->assertArrayHasKey(OptionalFeature::AI_ASSISTANT, $response->json('data.features'));
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

        $this->assertSame(['admin', 'features'], array_keys($response->json('data')));
        $this->assertSame($this->adminProfileWhitelist(), array_keys($response->json('data.admin')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    /**
     * 可选功能开关必须跟着插件状态走。
     *
     * 「AI 助手」的管理端页面托管在核心（插件不能注册菜单与路由），能力来自
     * addons/ai_assistant 插件：插件停用/卸载后 /api/v2/admin/ai/* 会 404。
     * 前端靠这份开关隐藏侧栏分组，所以这里把它钉在插件状态上——不是写死的 true。
     */
    public function test_optional_feature_follows_the_plugin_status(): void
    {
        $admin = $this->createAdmin([AdminPermissions::ORDER_LIST]);
        Sanctum::actingAs($admin);

        $plugin = IntegrationPlugin::query()
            ->where('domain', PluginDomain::ADDONS)
            ->where('slug', 'ai_assistant')
            ->first();

        if (! $plugin instanceof IntegrationPlugin) {
            // 干净测试库上没有这条登记：这时开关必须是 false，前端据此不显示入口
            $this->getJson('/api/v2/admin/auth/info')
                ->assertOk()
                ->assertJsonPath('data.features.'.OptionalFeature::AI_ASSISTANT, false);

            return;
        }

        $originalStatus = (int) $plugin->status;

        try {
            $plugin->status = IntegrationPlugin::STATUS_DISABLED;
            $plugin->save();
            $this->getJson('/api/v2/admin/auth/info')
                ->assertOk()
                ->assertJsonPath('data.features.'.OptionalFeature::AI_ASSISTANT, false);

            $plugin->status = IntegrationPlugin::STATUS_ENABLED;
            $plugin->save();
            $this->getJson('/api/v2/admin/auth/info')
                ->assertOk()
                ->assertJsonPath('data.features.'.OptionalFeature::AI_ASSISTANT, true);
        } finally {
            $plugin->status = $originalStatus;
            $plugin->save();
        }
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
