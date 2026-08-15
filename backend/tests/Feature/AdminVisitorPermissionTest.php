<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationPlugin;
use App\Models\Role;
use App\Services\Admin\Rbac\BuiltinAdminRoleService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminVisitorPermissionTest extends TestCase
{
    public function test_visitor_can_open_representative_readonly_admin_pages(): void
    {
        Sanctum::actingAs($this->createVisitorAdmin());

        foreach ([
            '/api/v2/admin/users',
            '/api/v2/admin/roles',
            '/api/v2/admin/permissions',
            '/api/v2/admin/settings',
            '/api/v2/admin/integration-plugins',
            '/api/v2/admin/schedules/overview',
            '/api/v2/admin/referral-withdrawals',
        ] as $uri) {
            $this->getJson($uri)
                ->assertOk()
                ->assertJsonPath('code', 0);
        }
    }

    public function test_visitor_cannot_write_execute_or_reveal_secrets(): void
    {
        Sanctum::actingAs($this->createVisitorAdmin());
        $plugin = IntegrationPlugin::query()->create([
            'domain' => 'mail',
            'slug' => 'visitor_denied_smtp_'.bin2hex(random_bytes(4)),
            'plugin_key' => 'visitor_denied_smtp_'.bin2hex(random_bytes(4)),
            'name' => 'Visitor Denied SMTP',
            'version' => '1.0.0',
            'entry_class' => 'Tests\\Fixtures\\Plugin',
            'capabilities_json' => [],
            'config_schema_json' => [],
            'status' => IntegrationPlugin::STATUS_ENABLED,
            'installed_at' => now(),
        ]);

        foreach ([
            ['postJson', '/api/v2/admin/roles', ['name' => 'visitor-denied', 'label' => 'Denied', 'permissions' => []]],
            ['postJson', '/api/v2/admin/settings', ['group' => 'system', 'settings' => []]],
            ['postJson', '/api/v2/admin/integration-plugin-scans', []],
            ['postJson', "/api/v2/admin/integration-plugins/{$plugin->id}/tasks", ['type' => 'health_check']],
            ['postJson', '/api/v2/admin/schedule-triggers', ['task' => 'test']],
            ['getJson', '/api/v2/admin/settings/system/secrets/app_key', []],
        ] as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)
                ->assertForbidden()
                ->assertJsonPath('code', 40300);
        }
    }

    private function createVisitorAdmin(): AdminUser
    {
        app(BuiltinAdminRoleService::class)->sync();

        $role = Role::query()->where('name', 'visitor')->firstOrFail();
        $suffix = bin2hex(random_bytes(4));

        return AdminUser::query()->create([
            'username' => 'visitor-test-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'email' => 'visitor-test-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }
}
