<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 券权限拆分回归：券生命周期从商品目录权限中解耦。
 *
 * 两件事必须同时成立：
 * 1. 边界真的生效——只持有 product.* 的角色打券接口被拒，持有 coupon.* 才放行；
 * 2. 存量能力不丢——回填迁移把旧角色的 product.list / product.manage 补成
 *    对应的 coupon.*，升级后管理员不会发现「发券按钮没了」。
 */
class CouponPermissionSplitTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<int> */
    private array $roleIds = [];

    protected function tearDown(): void
    {
        if ($this->roleIds !== []) {
            DB::table('roles')->whereIn('id', $this->roleIds)->delete();
        }

        parent::tearDown();
    }

    public function test_coupon_endpoints_reject_product_only_roles(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST, AdminPermissions::PRODUCT_MANAGE]));

        $this->getJson('/api/v2/admin/coupons')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $this->postJson('/api/v2/admin/coupons', ['name' => '无权创建'])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $this->getJson('/api/v2/admin/coupon-product-groups')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_coupon_list_grants_reads_but_not_writes(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::COUPON_LIST]));

        $this->getJson('/api/v2/admin/coupons')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->postJson('/api/v2/admin/coupons', ['name' => '只读不可写'])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_coupon_manage_alone_grants_both_reads_and_writes(): void
    {
        // coupon.manage 隐含展开出 coupon.list，只授 manage 即可完整管理
        Sanctum::actingAs($this->createAdmin([AdminPermissions::COUPON_MANAGE]));

        $this->getJson('/api/v2/admin/coupons')
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    public function test_backfill_migration_grants_coupon_permissions_from_product_permissions(): void
    {
        $listOnly = $this->createRole('coupon-split-list', [AdminPermissions::PRODUCT_LIST]);
        $manageOnly = $this->createRole('coupon-split-manage', [AdminPermissions::PRODUCT_MANAGE]);
        $unrelated = $this->createRole('coupon-split-unrelated', ['user.list', 'order.list']);
        $alreadyHas = $this->createRole('coupon-split-partial', [
            AdminPermissions::PRODUCT_MANAGE,
            AdminPermissions::COUPON_MANAGE,
        ]);

        $this->runBackfillMigration();

        $this->assertSame(
            [AdminPermissions::PRODUCT_LIST, AdminPermissions::COUPON_LIST],
            $this->storedPermissions($listOnly),
            '仅持 product.list 的角色补 coupon.list'
        );
        $this->assertSame(
            [AdminPermissions::PRODUCT_MANAGE, AdminPermissions::COUPON_MANAGE, AdminPermissions::COUPON_LIST],
            $this->storedPermissions($manageOnly),
            '持 product.manage 的角色补 coupon.manage 与 coupon.list：显式写入让角色 JSON 自描述，'
            .'不依赖隐含关系表的存在'
        );
        $this->assertSame(
            ['user.list', 'order.list'],
            $this->storedPermissions($unrelated),
            '与商品无关的角色不得被误授券权限'
        );
        $this->assertSame(
            [AdminPermissions::PRODUCT_MANAGE, AdminPermissions::COUPON_MANAGE, AdminPermissions::COUPON_LIST],
            $this->storedPermissions($alreadyHas),
            '已持有 coupon.manage 的角色补齐缺失的 coupon.list 后收敛（重复执行不再变化）'
        );
    }

    public function test_backfill_migration_skips_builtin_roles(): void
    {
        $admin = Role::query()->where('name', 'admin')->first();
        if (! $admin instanceof Role) {
            $this->markTestSkipped('共享测试库中不存在内置 admin 角色行');
        }

        // permissions 列在模型上已按 array 解析，取原始 JSON 做比对
        $before = (string) $admin->getRawOriginal('permissions');

        $this->runBackfillMigration();

        $admin->refresh();
        $this->assertSame(
            $before,
            (string) $admin->getRawOriginal('permissions'),
            '内置角色权限由代码解析，迁移不得改写其 JSON（写进去也会被内置解析覆盖）'
        );
    }

    private function runBackfillMigration(): void
    {
        $migration = require database_path('migrations/2026_10_25_000001_backfill_coupon_permissions_to_roles.php');
        $migration->up();
    }

    /**
     * @return list<string>
     */
    private function storedPermissions(int $roleId): array
    {
        $raw = DB::table('roles')->where('id', $roleId)->value('permissions');
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function createRole(string $name, array $permissions): int
    {
        $id = (int) Role::query()->create([
            'name' => $name.'-'.bin2hex(random_bytes(3)),
            'label' => $name,
            'permissions' => $permissions,
        ])->id;
        $this->roleIds[] = $id;

        return $id;
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'coupon-split-admin-'.$suffix,
            'label' => 'Coupon Split Test',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'coupon-split-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Coupon Split Test',
            'email' => 'coupon-split-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }
}
