<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 商品描述上游同步的管理端接口回归。
 *
 * 四条口径：
 *
 *   1. 批量开关只改被选中的商品，不牵连别的商品；
 *   2. 分组批量按层级取商品：二级分组含其下三级分组；
 *   3. 没绑定上游的商品走「跳过」而不是报错——批量同步里一个商品没上游
 *      不该让整批失败；
 *   4. 这组接口是执行类操作，必须有 product.sync 权限。
 */
class V2AdminProductDescriptionSyncApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_flips_the_auto_sync_flag_for_selected_products(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_SYNC]));
        $product = $this->createProduct();
        $other = $this->createProduct();

        $this->postJson('/api/v2/admin/products/description/auto-sync', [
            'product_ids' => [(int) $product->id],
            'enabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.affected', 1);

        $this->assertSame(1, (int) Product::query()->findOrFail((int) $product->id)->description_sync_enabled);
        $this->assertSame(0, (int) Product::query()->findOrFail((int) $other->id)->description_sync_enabled);

        $this->postJson('/api/v2/admin/products/description/auto-sync', [
            'product_ids' => [(int) $product->id],
            'enabled' => false,
        ])->assertOk();

        $this->assertSame(0, (int) Product::query()->findOrFail((int) $product->id)->description_sync_enabled);
    }

    public function test_it_flips_the_auto_sync_flag_for_a_second_level_group(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_SYNC]));
        $secondGroup = $this->createSecondGroup();
        $thirdGroup = $this->createThirdGroup($secondGroup);
        $product = $this->createProductIn($thirdGroup);

        $this->postJson("/api/v2/admin/product-groups/{$secondGroup->id}/description/auto-sync", [
            'level' => 2,
            'enabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.targets', 1);

        $this->assertSame(1, (int) $product->refresh()->description_sync_enabled);
    }

    public function test_it_reports_skip_reasons_for_products_without_upstream_binding(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_SYNC]));
        $product = $this->createProduct();

        $response = $this->postJson("/api/v2/admin/products/{$product->id}/description/sync")
            ->assertOk()
            ->assertJsonPath('code', 0)
            // 一条都没同步成功时不能报「同步完成」：此前三个入口一律回成功，
            // 管理员看到成功提示、前台毫无变化，只能判断成同步坏了。
            ->assertJsonPath('data.outcome', 'skipped');

        $this->assertSame(0, (int) $response->json('data.summary.synced'));
        $this->assertSame(1, (int) $response->json('data.summary.skipped'));
        $this->assertStringContainsString('供应商', (string) $response->json('data.items.0.message'));
        $this->assertStringContainsString('未同步', (string) $response->json('message'));
    }

    /**
     * 保存商品时没带 description 不能把已有描述抹掉。
     *
     * 描述只由「商品表单 / 从上游同步」两处维护；任何最小字段集的保存
     * （脚本、批量改价、前端详情接口失败导致表单里是空串）都不该顺手清空它。
     * 此前 `sanitize($data['description'] ?? null)` 会把它写成空串，前台卡片随即
     * 退回 remark 里的旧上游描述——现象就是「同步完、再保存一下又变回老的」。
     */
    public function test_it_keeps_the_stored_description_when_the_update_payload_omits_it(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_MANAGE]));
        $secondGroup = $this->createSecondGroup();
        $thirdGroup = $this->createThirdGroup($secondGroup);
        $product = $this->createProductIn($thirdGroup);

        Product::query()->whereKey((int) $product->id)->update([
            'description' => '<ul><li>CPU：2 核</li></ul>',
        ]);

        $this->putJson("/api/v2/admin/products/{$product->id}", [
            'third_product_group_id' => (int) $thirdGroup->id,
            'pricing' => ['monthly' => '29.00'],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame(
            '<ul><li>CPU：2 核</li></ul>',
            (string) Product::query()->findOrFail((int) $product->id)->description
        );
    }

    public function test_it_reads_and_writes_the_global_sync_schedule(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_SYNC]));

        $this->getJson('/api/v2/admin/products/description/sync-settings')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure([
                'data' => [
                    'schedule_mode',
                    'schedule_time',
                    'stats' => ['total', 'auto_sync_enabled', 'synced'],
                ],
            ]);

        $this->putJson('/api/v2/admin/products/description/sync-settings', [
            'schedule_mode' => 'hourly',
            'schedule_time' => '01:15:00',
        ])
            ->assertOk()
            ->assertJsonPath('data.schedule_mode', 'hourly');
    }

    public function test_it_requires_the_product_sync_permission(): void
    {
        Sanctum::actingAs($this->createAdmin([]));

        $this->postJson('/api/v2/admin/products/description/auto-sync', [
            'product_ids' => [1],
            'enabled' => true,
        ])->assertForbidden();
    }

    private function createProduct(): Product
    {
        $secondGroup = $this->createSecondGroup();
        $thirdGroup = $this->createThirdGroup($secondGroup);

        return $this->createProductIn($thirdGroup);
    }

    private function createProductIn(ThirdProductGroup $thirdGroup): Product
    {
        $secondGroup = $thirdGroup->secondProductGroup ?: SecondProductGroup::query()->findOrFail((int) $thirdGroup->second_product_group_id);
        $firstGroup = $secondGroup->firstProductGroup ?: FirstProductGroup::query()->findOrFail((int) $secondGroup->first_product_group_id);

        return Product::query()->create([
            'product_group_id' => (int) $thirdGroup->id,
            'service_type_code' => (string) $firstGroup->code,
            'custom_display_name' => 'Description Sync Product',
            'product_type' => (string) $firstGroup->code,
            'pricing' => ['monthly' => '19.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 1,
        ])->refresh();
    }

    private function createSecondGroup(): SecondProductGroup
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = FirstProductGroup::query()->create([
            'code' => 'desc-sync-'.$suffix,
            'name' => 'Desc Sync '.$suffix,
            'slug' => 'desc-sync-'.$suffix,
            'description' => 'Desc Sync',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'legacy_product_type' => 'desc-sync-'.$suffix,
        ]);

        return SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => 'Desc Sync Second '.$suffix,
            'slug' => 'desc-sync-second-'.$suffix,
            'description' => 'Desc Sync Second',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);
    }

    private function createThirdGroup(SecondProductGroup $secondGroup): ThirdProductGroup
    {
        $suffix = bin2hex(random_bytes(4));

        return ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => 'Desc Sync Third '.$suffix,
            'slug' => 'desc-sync-third-'.$suffix,
            'description' => 'Desc Sync Third',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-desc-sync-'.$suffix,
            'label' => 'V2 Description Sync',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-desc-sync-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Description Sync',
            'email' => 'v2-desc-sync-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }
}
