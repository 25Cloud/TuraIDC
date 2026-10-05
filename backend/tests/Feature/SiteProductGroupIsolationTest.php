<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ProductType;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\Setting;
use App\Models\ThirdProductGroup;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 站点分类层级回归测试。
 *
 * 真实站点里「大陆云服」和「轻量服务器」两个一级菜单共用同一个商品类型
 * （product_type = cloud_server）。如果查询按商品类型兜底，
 * 两个一级菜单下的二级分类会被并进同一份列表，前端再也分不清分类挂在哪条产品线下。
 */
class SiteProductGroupIsolationTest extends TestCase
{
    public function test_first_menu_filter_does_not_merge_menus_sharing_one_product_type(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $menuACode = 'type_a'.$suffix;
        $menuBCode = 'type_b'.$suffix;
        $originalItems = Setting::getValue(ProductType::SETTING_GROUP, ProductType::SETTING_KEY, '');
        $created = ['first' => [], 'second' => [], 'third' => [], 'products' => []];

        try {
            ProductType::saveItems([
                [
                    'internal_id' => 90,
                    'value' => $menuACode,
                    'label' => '测试菜单甲',
                    'product_type' => ProductType::CLOUD_SERVER,
                    'icon' => 'server',
                    'is_builtin' => false,
                    'is_hidden' => false,
                ],
                [
                    'internal_id' => 91,
                    'value' => $menuBCode,
                    'label' => '测试菜单乙',
                    'product_type' => ProductType::CLOUD_SERVER,
                    'icon' => 'server',
                    'is_builtin' => false,
                    'is_hidden' => false,
                ],
            ]);
            ProductType::resetCache();

            $menuA = $this->createFirstGroup($menuACode, '测试菜单甲', $suffix, $created);
            $menuB = $this->createFirstGroup($menuBCode, '测试菜单乙', $suffix, $created);

            $secondA = $this->createSecondGroup($menuA, '华东', 'ea', $suffix, $created);
            $secondB = $this->createSecondGroup($menuB, '华北', 'eb', $suffix, $created);
            $thirdA = $this->createThirdGroup($secondA, '宁波', 'third-a', $suffix, $created);
            $thirdB = $this->createThirdGroup($secondB, '内蒙', 'third-b', $suffix, $created);

            $this->createProduct($thirdA, $suffix, $created);
            $this->createProduct($thirdB, $suffix, $created);

            // 按一级菜单 code 过滤时只能看到该菜单自己的二级分类
            $listA = $this->siteGroupNames('first_product_group_code', $menuACode);
            $this->assertSame(['华东'], $listA);
            $this->assertSame([$menuACode], $this->siteGroupMenuCodes('first_product_group_code', $menuACode));

            $listB = $this->siteGroupNames('first_product_group_code', $menuBCode);
            $this->assertSame(['华北'], $listB);
            $this->assertSame([$menuBCode], $this->siteGroupMenuCodes('first_product_group_code', $menuBCode));

            // 按商品类型过滤时两个菜单都要出现，且逐条带自己的 first_product_group_code
            $namesByType = $this->siteGroupNames('product_type', ProductType::CLOUD_SERVER);
            sort($namesByType);
            $this->assertSame(['华东', '华北'], $namesByType);
            $menuCodesByType = $this->siteGroupMenuCodes('product_type', ProductType::CLOUD_SERVER);
            sort($menuCodesByType);
            $this->assertSame([$menuACode, $menuBCode], $menuCodesByType);

            // 层级字段：二级分组必须带完整路径，标签固定为「二级分类」
            $payload = $this->getJson('/api/v2/site/product-groups?first_product_group_code='.$menuACode.'&page_size=50')
                ->assertOk()
                ->assertJsonPath('code', 0)
                ->json('data.list.0');
            $this->assertIsArray($payload);
            $this->assertSame('二级分类', $payload['group_level_label'] ?? '');
            $this->assertSame('测试菜单甲 / 华东', $payload['group_path_text'] ?? '');
            $this->assertSame(2, $payload['effective_product_group_level'] ?? 0);
            $this->assertSame(
                ['一级分类', '二级分类'],
                collect($payload['group_path'] ?? [])->pluck('level_label')->all()
            );

            // 三级分组同样带三级标签
            $children = $this->getJson('/api/v2/site/product-groups/'.$secondA->id.'/children?page_size=50')
                ->assertOk()
                ->assertJsonPath('code', 0)
                ->json('data.list');
            $this->assertIsArray($children);
            $this->assertSame('三级分类', $children[0]['group_level_label'] ?? '');
            $this->assertSame('测试菜单甲 / 华东 / 宁波', $children[0]['group_path_text'] ?? '');
        } finally {
            foreach (array_reverse($created['products']) as $id) {
                DB::table('products')->where('id', $id)->delete();
            }
            foreach (array_reverse($created['third']) as $id) {
                DB::table('third_product_groups')->where('id', $id)->delete();
            }
            foreach (array_reverse($created['second']) as $id) {
                DB::table('second_product_groups')->where('id', $id)->delete();
            }
            foreach (array_reverse($created['first']) as $id) {
                DB::table('first_product_groups')->where('id', $id)->delete();
            }

            if ($originalItems === '') {
                Setting::setValue(ProductType::SETTING_GROUP, ProductType::SETTING_KEY, '');
            } else {
                Setting::setValue(ProductType::SETTING_GROUP, ProductType::SETTING_KEY, $originalItems);
            }
            ProductType::resetCache();
        }
    }

    /**
     * @return array<int, string>
     */
    private function siteGroupNames(string $filterKey, string $filterValue): array
    {
        return $this->siteGroupPayloads($filterKey, $filterValue)
            ->pluck('name')
            ->map(fn ($name): string => (string) $name)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function siteGroupMenuCodes(string $filterKey, string $filterValue): array
    {
        return $this->siteGroupPayloads($filterKey, $filterValue)
            ->pluck('first_product_group_code')
            ->map(fn ($code): string => (string) $code)
            ->values()
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function siteGroupPayloads(string $filterKey, string $filterValue)
    {
        $response = $this->getJson('/api/v2/site/product-groups?page_size=50&'.$filterKey.'='.urlencode($filterValue))
            ->assertOk()
            ->assertJsonPath('code', 0);

        return collect($response->json('data.list') ?? [])->values();
    }

    /**
     * @param  array<string, array<int, int>>  $created
     */
    private function createFirstGroup(string $code, string $name, string $suffix, array &$created): FirstProductGroup
    {
        $group = FirstProductGroup::query()->create([
            'code' => $code,
            'product_type' => ProductType::CLOUD_SERVER,
            'name' => $name,
            'slug' => $code,
            'description' => $name.' 说明',
            'sort_order' => 0,
            'is_visible' => 1,
            'is_system' => 0,
        ]);
        $created['first'][] = (int) $group->id;

        return $group;
    }

    /**
     * @param  array<string, array<int, int>>  $created
     */
    private function createSecondGroup(FirstProductGroup $firstGroup, string $name, string $slugPrefix, string $suffix, array &$created): SecondProductGroup
    {
        $group = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => $name,
            'slug' => $slugPrefix.'-'.$suffix,
            'description' => $name.' 说明',
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
        $created['second'][] = (int) $group->id;

        return $group;
    }

    /**
     * @param  array<string, array<int, int>>  $created
     */
    private function createThirdGroup(SecondProductGroup $secondGroup, string $name, string $slugPrefix, string $suffix, array &$created): ThirdProductGroup
    {
        $group = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => $name,
            'slug' => $slugPrefix.'-'.$suffix,
            'description' => $name.' 说明',
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
        $created['third'][] = (int) $group->id;

        return $group;
    }

    /**
     * @param  array<string, array<int, int>>  $created
     */
    private function createProduct(ThirdProductGroup $thirdGroup, string $suffix, array &$created): Product
    {
        $product = Product::query()->create([
            'product_group_id' => (int) $thirdGroup->id,
            'name' => '测试商品 '.$suffix.'-'.$thirdGroup->id,
            'product_type' => ProductType::CLOUD_SERVER,
            'service_type_code' => ProductType::CLOUD_SERVER,
            'description' => '',
            'pricing' => ['monthly' => '19.90'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 5,
            'status' => 1,
            'sort_order' => 0,
            'auto_setup' => 0,
        ]);
        $created['products'][] = (int) $product->id;

        return $product;
    }
}