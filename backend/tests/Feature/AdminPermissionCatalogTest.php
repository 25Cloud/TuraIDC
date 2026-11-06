<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Services\Admin\Rbac\PermissionCatalogService;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPermissionCatalogTest extends TestCase
{
    public function test_permission_catalog_contains_new_split_permissions_with_risk_levels(): void
    {
        $items = collect(app(PermissionCatalogService::class)->list())->keyBy('key');

        foreach ([
            AdminPermissions::SETTINGS_VIEW,
            AdminPermissions::DATABASE_VIEW,
            AdminPermissions::DATABASE_MANAGE,
            AdminPermissions::INTEGRATION_PLUGIN_VIEW,
            AdminPermissions::INTEGRATION_PLUGIN_MANAGE,
            AdminPermissions::INTEGRATION_PLUGIN_TEST,
            AdminPermissions::SCHEDULE_VIEW,
            AdminPermissions::SCHEDULE_TRIGGER,
            AdminPermissions::SITE_VIEW,
            AdminPermissions::SITE_MANAGE,
            AdminPermissions::MEMBER_LEVEL_LIST,
            AdminPermissions::SUPPLIER_LIST,
            AdminPermissions::SUPPLIER_DETAIL,
            AdminPermissions::SUPPLIER_MANAGE,
            AdminPermissions::SUPPLIER_SYNC,
            AdminPermissions::PRODUCT_SYNC,
            AdminPermissions::REFERRAL_WITHDRAWAL_LIST,
            AdminPermissions::PRIVACY_VIEW_RAW,
            AdminPermissions::SETTINGS_SECRET_REVEAL,
            AdminPermissions::SUPPLIER_SECRET_REVEAL,
            AdminPermissions::INTEGRATION_PLUGIN_SECRET_REVEAL,
        ] as $permission) {
            $this->assertTrue($items->has($permission), "Missing permission {$permission}");
        }

        $this->assertSame('high', $items[AdminPermissions::PRIVACY_VIEW_RAW]['risk_level']);
        $this->assertSame('high', $items[AdminPermissions::SETTINGS_SECRET_REVEAL]['risk_level']);
        $this->assertSame('high', $items[AdminPermissions::DATABASE_MANAGE]['risk_level']);
        $this->assertSame('high', $items[AdminPermissions::SUPPLIER_SECRET_REVEAL]['risk_level']);
        $this->assertSame('high', $items[AdminPermissions::INTEGRATION_PLUGIN_SECRET_REVEAL]['risk_level']);
        $this->assertSame('medium', $items[AdminPermissions::INTEGRATION_PLUGIN_TEST]['risk_level']);
        $this->assertSame('low', $items[AdminPermissions::INTEGRATION_PLUGIN_VIEW]['risk_level']);
        $this->assertSame('low', $items[AdminPermissions::DATABASE_VIEW]['risk_level']);
        $this->assertSame('system_database', $items[AdminPermissions::DATABASE_VIEW]['group']);
        $this->assertSame('查看数据库状态', $items[AdminPermissions::DATABASE_VIEW]['name']);

        // service.* 与 product.* 同口径：读=low、写=medium；分组与中文名不能落到兜底
        // （兜底会把键原样拼成 "service / view"，角色管理里就是一串英文）
        $this->assertSame('service_instance', $items[AdminPermissions::SERVICE_VIEW]['group']);
        $this->assertSame('服务实例', $items[AdminPermissions::SERVICE_VIEW]['group_label']);
        $this->assertSame('查看服务实例', $items[AdminPermissions::SERVICE_VIEW]['name']);
        $this->assertSame('low', $items[AdminPermissions::SERVICE_VIEW]['risk_level']);
        $this->assertSame('service_instance', $items[AdminPermissions::SERVICE_MANAGE]['group']);
        $this->assertSame('服务实例', $items[AdminPermissions::SERVICE_MANAGE]['group_label']);
        $this->assertSame('管理服务实例', $items[AdminPermissions::SERVICE_MANAGE]['name']);
        $this->assertSame('medium', $items[AdminPermissions::SERVICE_MANAGE]['risk_level']);
        // 与商品目录的读写口径对齐，防止以后有人把风险表改歪
        $this->assertSame(
            $items[AdminPermissions::PRODUCT_LIST]['risk_level'],
            $items[AdminPermissions::SERVICE_VIEW]['risk_level']
        );
        $this->assertSame(
            $items[AdminPermissions::PRODUCT_MANAGE]['risk_level'],
            $items[AdminPermissions::SERVICE_MANAGE]['risk_level']
        );
    }

    public function test_manage_permissions_do_not_imply_secret_reveal_or_raw_privacy(): void
    {
        $resolved = AdminPermissions::resolveRolePermissions(null, [
            AdminPermissions::INTEGRATION_PLUGIN_MANAGE,
            AdminPermissions::SETTINGS_MANAGE,
            AdminPermissions::DATABASE_MANAGE,
            AdminPermissions::SUPPLIER_MANAGE,
        ]);

        $this->assertContains(AdminPermissions::INTEGRATION_PLUGIN_VIEW, $resolved);
        $this->assertContains(AdminPermissions::SETTINGS_VIEW, $resolved);
        $this->assertContains(AdminPermissions::DATABASE_VIEW, $resolved);
        $this->assertContains(AdminPermissions::SUPPLIER_LIST, $resolved);
        $this->assertContains(AdminPermissions::SUPPLIER_DETAIL, $resolved);

        $this->assertNotContains(AdminPermissions::INTEGRATION_PLUGIN_SECRET_REVEAL, $resolved);
        $this->assertNotContains(AdminPermissions::SETTINGS_SECRET_REVEAL, $resolved);
        $this->assertNotContains(AdminPermissions::SUPPLIER_SECRET_REVEAL, $resolved);
        $this->assertNotContains(AdminPermissions::PRIVACY_VIEW_RAW, $resolved);
    }

    public function test_ticket_manage_does_not_imply_delivery_manage(): void
    {
        $resolved = AdminPermissions::resolveRolePermissions(null, [AdminPermissions::TICKET_MANAGE]);

        $this->assertContains(AdminPermissions::TICKET_LIST, $resolved);
        $this->assertContains(AdminPermissions::TICKET_REPLY, $resolved);
        $this->assertNotContains(AdminPermissions::TICKET_DELIVERY_MANAGE, $resolved);
    }

    public function test_ticket_delivery_rules_route_is_isolated_at_http_level(): void
    {
        // 仅持 ticket.manage：规则列表接口被中间件拦截，权限隔离在路由层生效
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE]));

        $this->getJson('/api/v2/admin/ticket-delivery-rules')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        // 显式授予 ticket.delivery_manage：可正常访问规则列表
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_DELIVERY_MANAGE]));

        $this->getJson('/api/v2/admin/ticket-delivery-rules')
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    public function test_permission_catalog_api_returns_new_items(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::PERMISSION_LIST]));

        $response = $this->getJson('/api/v2/admin/permissions')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $keys = collect($response->json('data.list'))->pluck('key');

        $this->assertContains(AdminPermissions::PRIVACY_VIEW_RAW, $keys);
        $this->assertContains(AdminPermissions::INTEGRATION_PLUGIN_SECRET_REVEAL, $keys);
    }

    /**
     * @param  string[]  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'permission-catalog-test-'.$suffix,
            'label' => 'Permission Catalog Test',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'permission-catalog-test-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'email' => 'permission-catalog-test-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }
}
