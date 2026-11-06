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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 商品定价「按月价同步到其他周期」（Issue #77）。
 *
 * 口径（Lead 已拍定）：
 * - 换算固定为「来源周期价 × 目标周期月数」，月数取 `App\Constants\BillingCycle`；
 * - 一次性写库，只动请求里列出的周期，未勾选的周期一个字节都不改；
 * - `overwrite=false` 只补空档，已有价跳过并回报原因；
 * - 权限沿用 `product.manage`，非法周期 422。
 *
 * 这里同时锁住「钱别算错」：33.33 × 12 必须是 399.96（不能落 399.9599999999999），
 * 以及 0.01 × 3/6/12 的边界。
 */
class AdminProductPricingCopyTest extends TestCase
{
    private string $suffix;

    private FirstProductGroup $firstGroup;

    private SecondProductGroup $secondGroup;

    private ThirdProductGroup $leafGroup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->suffix = bin2hex(random_bytes(4));
        $this->firstGroup = $this->firstGroupForType('vps', 'Pricing copy root '.$this->suffix, 'pricing-copy-root-'.$this->suffix);
        $this->secondGroup = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $this->firstGroup->id,
            'name' => 'Pricing copy category '.$this->suffix,
            'slug' => 'pricing-copy-category-'.$this->suffix,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
        $this->leafGroup = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $this->secondGroup->id,
            'name' => 'Pricing copy leaf '.$this->suffix,
            'slug' => 'pricing-copy-leaf-'.$this->suffix,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
    }

    /**
     * 测试库是共享的、不带 RefreshDatabase：本用例造的商品/分组不删就会一直堆在
     * `idc_test` 里（公开目录的响应体积断言对行数敏感）。收尾只清本用例造的那些行。
     */
    protected function tearDown(): void
    {
        Product::query()
            ->where('custom_display_name', 'like', 'Pricing copy product '.$this->suffix.'%')
            ->forceDelete();
        // 先删管理员再删角色：admin_users.role_id 指向 roles
        AdminUser::query()->where('username', 'like', 'admin-pricing-copy-'.$this->suffix.'%')->delete();
        Role::query()->where('name', 'like', 'admin-product-pricing-copy-'.$this->suffix.'%')->delete();
        ThirdProductGroup::query()->where('slug', 'pricing-copy-leaf-'.$this->suffix)->delete();
        SecondProductGroup::query()->where('slug', 'pricing-copy-category-'.$this->suffix)->delete();

        parent::tearDown();
    }

    public function test_copy_multiplies_monthly_price_by_cycle_months(): void
    {
        $admin = $this->createAdmin([AdminPermissions::ALL]);
        $product = $this->createProduct(['monthly' => '33.33']);

        Sanctum::actingAs($admin);

        $response = $this->putJson($this->copyUrl($product), [
            'from_cycle' => 'monthly',
            'to_cycles' => ['quarterly', 'semiannually', 'annually'],
            'overwrite' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.from_cycle', 'monthly')
            ->assertJsonPath('data.from_price', '33.33')
            ->assertJsonPath('data.updated.0.cycle', 'quarterly')
            ->assertJsonPath('data.updated.0.months', 3)
            ->assertJsonPath('data.updated.0.after', '99.99')
            ->assertJsonPath('data.updated.1.after', '199.98')
            ->assertJsonPath('data.updated.2.after', '399.96')
            ->assertJsonPath('data.pricing.annually', '399.96')
            ->assertJsonPath('data.skipped', []);

        // 399.96 而不是 399.9599999999999：Money 在末尾一次性舍入到分
        $this->assertPricing($product, [
            'monthly' => '33.33',
            'quarterly' => '99.99',
            'semiannually' => '199.98',
            'annually' => '399.96',
        ]);

        // 写现有操作日志：能解释这笔价是谁、按什么口径改的
        // 注意 `operation_logs` 里 target_id/detail 是模型别名，实际列是 subject_id/context
        $this->assertDatabaseHas('operation_logs', [
            'action' => 'product.pricing.copy',
            'module' => 'product',
            'subject_id' => (int) $product->id,
        ]);
    }

    public function test_copy_keeps_cent_precision_at_the_lowest_positive_price(): void
    {
        $admin = $this->createAdmin([AdminPermissions::ALL]);
        $product = $this->createProduct(['monthly' => '0.01']);

        Sanctum::actingAs($admin);

        $this->putJson($this->copyUrl($product), [
            'from_cycle' => 'monthly',
            'to_cycles' => ['quarterly', 'semiannually', 'annually'],
            'overwrite' => true,
        ])->assertOk()
            ->assertJsonPath('data.updated.0.after', '0.03')
            ->assertJsonPath('data.updated.1.after', '0.06')
            ->assertJsonPath('data.updated.2.after', '0.12');

        $this->assertPricing($product, [
            'monthly' => '0.01',
            'quarterly' => '0.03',
            'semiannually' => '0.06',
            'annually' => '0.12',
        ]);
    }

    public function test_copy_only_touches_selected_cycles(): void
    {
        $admin = $this->createAdmin([AdminPermissions::ALL]);
        $product = $this->createProduct([
            'monthly' => '10.00',
            'quarterly' => '1.00',
            'semiannually' => '2.00',
            'annually' => '3.00',
        ]);

        Sanctum::actingAs($admin);

        $this->putJson($this->copyUrl($product), [
            'from_cycle' => 'monthly',
            'to_cycles' => ['quarterly'],
            'overwrite' => true,
        ])->assertOk()
            ->assertJsonCount(1, 'data.updated')
            ->assertJsonPath('data.updated.0.after', '30.00');

        // 未勾选的半年付/年付保持原值，逐字节不变
        $this->assertPricing($product, [
            'monthly' => '10.00',
            'quarterly' => '30.00',
            'semiannually' => '2.00',
            'annually' => '3.00',
        ]);
    }

    public function test_copy_without_overwrite_skips_existing_prices_and_reports_them(): void
    {
        $admin = $this->createAdmin([AdminPermissions::ALL]);
        $product = $this->createProduct([
            'monthly' => '10.00',
            'quarterly' => '15.00',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson($this->copyUrl($product), [
            'from_cycle' => 'monthly',
            'to_cycles' => ['quarterly', 'semiannually', 'annually'],
            'overwrite' => false,
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.updated')
            ->assertJsonPath('data.updated.0.cycle', 'semiannually')
            ->assertJsonPath('data.updated.0.after', '60.00')
            ->assertJsonPath('data.updated.1.cycle', 'annually')
            ->assertJsonPath('data.updated.1.after', '120.00')
            // 跳过不是静默丢弃：必须回报周期与原因，管理端要能分列展示
            ->assertJsonCount(1, 'data.skipped')
            ->assertJsonPath('data.skipped.0.cycle', 'quarterly')
            ->assertJsonPath('data.skipped.0.reason', 'existing_price')
            ->assertJsonPath('data.skipped.0.current', '15.00');

        $this->assertPricing($product, [
            'monthly' => '10.00',
            'quarterly' => '15.00',
            'semiannually' => '60.00',
            'annually' => '120.00',
        ]);
    }

    public function test_copy_without_overwrite_changes_nothing_when_all_cycles_are_priced(): void
    {
        $admin = $this->createAdmin([AdminPermissions::ALL]);
        $product = $this->createProduct([
            'monthly' => '10.00',
            'quarterly' => '15.00',
            'semiannually' => '55.00',
            'annually' => '99.00',
        ]);

        Sanctum::actingAs($admin);

        $this->putJson($this->copyUrl($product), [
            'from_cycle' => 'monthly',
            'to_cycles' => ['quarterly', 'semiannually', 'annually'],
            'overwrite' => false,
        ])->assertOk()
            ->assertJsonCount(0, 'data.updated')
            ->assertJsonCount(3, 'data.skipped');

        $this->assertPricing($product, [
            'monthly' => '10.00',
            'quarterly' => '15.00',
            'semiannually' => '55.00',
            'annually' => '99.00',
        ]);
    }

    public function test_copy_requires_product_manage_permission(): void
    {
        $admin = $this->createAdmin([AdminPermissions::PRODUCT_LIST]);
        $product = $this->createProduct(['monthly' => '10.00']);

        Sanctum::actingAs($admin);

        $this->putJson($this->copyUrl($product), [
            'from_cycle' => 'monthly',
            'to_cycles' => ['quarterly'],
            'overwrite' => true,
        ])->assertStatus(403);

        $this->assertPricing($product, ['monthly' => '10.00']);
    }

    public function test_copy_rejects_illegal_cycles(): void
    {
        $admin = $this->createAdmin([AdminPermissions::ALL]);
        $product = $this->createProduct(['monthly' => '10.00']);

        Sanctum::actingAs($admin);

        $cases = [
            '未知目标周期' => ['from_cycle' => 'monthly', 'to_cycles' => ['weekly']],
            '历史别名不算规范值' => ['from_cycle' => 'monthly', 'to_cycles' => ['yearly']],
            '目标周期与来源相同' => ['from_cycle' => 'monthly', 'to_cycles' => ['monthly']],
            '目标周期为空数组' => ['from_cycle' => 'monthly', 'to_cycles' => []],
            '来源周期不合法' => ['from_cycle' => 'weekly', 'to_cycles' => ['quarterly']],
            '目标周期重复' => ['from_cycle' => 'monthly', 'to_cycles' => ['quarterly', 'quarterly']],
        ];

        foreach ($cases as $label => $payload) {
            $this->putJson($this->copyUrl($product), $payload)
                ->assertStatus(422);
            $this->assertPricing($product, ['monthly' => '10.00'], $label.' 不应写库');
        }
    }

    public function test_copy_rejects_empty_source_price(): void
    {
        $admin = $this->createAdmin([AdminPermissions::ALL]);
        $product = $this->createProduct(['quarterly' => '30.00']);

        Sanctum::actingAs($admin);

        $this->putJson($this->copyUrl($product), [
            'from_cycle' => 'monthly',
            'to_cycles' => ['quarterly'],
            'overwrite' => true,
        ])->assertStatus(422);

        $this->assertPricing($product, ['quarterly' => '30.00']);
    }

    /**
     * 逐周期比对库里的价目表。
     *
     * `products.pricing` 是 MySQL JSON 列，MySQL 存 JSON 对象时会按「键长度 + 字典序」重排键，
     * 所以整数组的 assertSame 会因为键顺序不同而假红；这里只比对值与周期集合。
     *
     * @param  array<string, string>  $expected
     */
    private function assertPricing(Product $product, array $expected, string $message = ''): void
    {
        $actual = (array) $product->fresh()->pricing;

        $this->assertSame(count($expected), count($actual), $message.' 价目表周期数不符');
        foreach ($expected as $cycle => $amount) {
            $this->assertSame($amount, (string) ($actual[$cycle] ?? ''), $message.' '.$cycle.' 价格不符');
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $role = Role::query()->create([
            'name' => 'admin-product-pricing-copy-'.$this->suffix.'-'.bin2hex(random_bytes(3)),
            'label' => 'Admin Product Pricing Copy',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'admin-pricing-copy-'.$this->suffix.'-'.bin2hex(random_bytes(3)),
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Pricing Copy',
            'email' => 'admin-pricing-copy-'.$this->suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @param  array<string, string>  $pricing
     */
    private function createProduct(array $pricing): Product
    {
        return Product::query()->create([
            'product_group_id' => (int) $this->leafGroup->id,
            'service_type_code' => (string) $this->firstGroup->code,
            'name' => 'Pricing copy product '.$this->suffix,
            'custom_display_name' => 'Pricing copy product '.$this->suffix,
            'product_type' => (string) $this->firstGroup->code,
            'pricing' => $pricing,
            'purchase_requires' => [],
            'config_options' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);
    }

    private function copyUrl(Product $product): string
    {
        return '/api/v2/admin/products/'.$product->id.'/pricing/copy';
    }

    private function firstGroupForType(string $code, string $name, string $slug): FirstProductGroup
    {
        $group = FirstProductGroup::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'slug' => $slug,
                'sort_order' => 0,
                'is_visible' => 1,
                'is_system' => 0,
                'legacy_product_type' => $code,
            ]
        );

        if ((int) $group->is_visible !== 1) {
            $group->update(['is_visible' => 1]);
        }

        return $group->refresh();
    }
}
