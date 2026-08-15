<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ProductType;
use App\Models\AdminUser;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Models\Setting;
use App\Models\ThirdProductGroup;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2ProductGroupCatalogTest extends TestCase
{
    public function test_admin_product_groups_require_login_and_permission(): void
    {
        $this->getJson('/api/v2/admin/product-groups')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/product-groups')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_admin_product_groups_reject_per_page_and_return_paginated_whitelist(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->createAdminFirstGroup($suffix);
        $secondGroup = $this->createSecondGroup($firstGroup, '后台二级 '.$suffix, 1, true);
        Product::query()->create($this->productPayload($secondGroup, null, '后台直属商品 '.$suffix, '10.00', 1));

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->getJson('/api/v2/admin/product-groups?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/product-groups?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $this->getJson('/api/v2/admin/product-groups/'.$firstGroup->id.'/children?level=1&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $listResponse = $this->getJson('/api/v2/admin/product-groups?'.http_build_query([
            'keyword' => $suffix,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 10)
            ->assertJsonPath('data.list.0.id', $firstGroup->id)
            ->assertJsonPath('data.list.0.level', 1)
            ->assertJsonPath('data.list.0.children_count', 1)
            ->assertJsonPath('data.list.0.products_count', 1);

        $this->assertSame($this->adminGroupListWhitelist(), array_keys($listResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($listResponse->json());

        $detailResponse = $this->getJson('/api/v2/admin/product-groups/'.$firstGroup->id.'?level=1')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.group.id', $firstGroup->id)
            ->assertJsonPath('data.group.effective_product_group_level', 1);

        $this->assertSame($this->adminGroupDetailWhitelist(), array_keys($detailResponse->json('data.group')));
        $this->assertNoSensitiveKeys($detailResponse->json());
    }

    public function test_site_product_group_children_and_products_are_public_paginated_and_whitelisted(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->visibleSiteFirstGroup();
        $secondGroup = $this->createSecondGroup($firstGroup, '站点二级 '.$suffix, 1, true);
        $thirdGroup = $this->createThirdGroup($secondGroup, '站点三级 '.$suffix, 1, true);
        $hiddenThirdGroup = $this->createThirdGroup($secondGroup, '隐藏三级 '.$suffix, 2, false);
        $directProduct = Product::query()->create($this->productPayload($secondGroup, null, '站点直属商品 '.$suffix, '11.00', 1));
        $thirdProduct = Product::query()->create($this->productPayload($secondGroup, $thirdGroup, '站点三级商品 '.$suffix, '22.00', 2));
        Product::query()->create($this->productPayload($secondGroup, $hiddenThirdGroup, '隐藏商品 '.$suffix, '33.00', 3));
        Setting::setValue('product', 'cpu_model_catalog', json_encode([
            [
                'id' => 'group_intel_'.$suffix,
                'value' => 'intel_'.$suffix,
                'name' => 'Intel',
                'models' => [
                    [
                        'id' => 'model_platinum_'.$suffix,
                        'value' => 'intel_platinum_'.$suffix,
                        'name' => 'Intel Xeon Platinum 8269CY',
                        'base_frequency' => '2.50GHz',
                        'turbo_frequency' => '3.80GHz',
                        'bindings' => [
                            [
                                'product_id' => (int) $directProduct->id,
                                'product_name' => (string) $directProduct->name,
                                'category_full_name' => '云服务器 / 站点二级',
                                'status' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->getJson('/api/v2/site/product-groups?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/site/product-groups?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $this->getJson('/api/v2/site/product-groups/'.$secondGroup->id.'/children?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $this->getJson('/api/v2/site/product-groups/'.$secondGroup->id.'/products?level=2&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $rootResponse = $this->getJson('/api/v2/site/product-groups?'.http_build_query([
            'first_product_group_code' => 'vps',
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 10);

        $this->assertSame($this->siteGroupWhitelist(), array_keys($rootResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($rootResponse->json());

        $childrenResponse = $this->getJson('/api/v2/site/product-groups/'.$secondGroup->id.'/children?'.http_build_query([
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.id', $thirdGroup->id)
            ->assertJsonPath('data.list.0.effective_product_group_level', 3);

        $this->assertSame($this->siteGroupWhitelist(), array_keys($childrenResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($childrenResponse->json());

        $directProductsResponse = $this->getJson('/api/v2/site/product-groups/'.$secondGroup->id.'/products?'.http_build_query([
            'level' => 2,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.id', $directProduct->id)
            ->assertJsonPath('data.list.0.cpu_model_name', 'Intel Xeon Platinum 8269CY')
            ->assertJsonPath('data.list.0.cpu_base_frequency', '2.50GHz')
            ->assertJsonPath('data.list.0.cpu_turbo_frequency', '3.80GHz');

        $this->assertSame($this->siteProductWhitelist(), array_keys($directProductsResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($directProductsResponse->json());

        $thirdProductsResponse = $this->getJson('/api/v2/site/product-groups/'.$thirdGroup->id.'/products?'.http_build_query([
            'level' => 3,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', $thirdProduct->id);

        $this->assertSame($this->siteProductWhitelist(), array_keys($thirdProductsResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($thirdProductsResponse->json());
    }

    public function test_site_product_group_responses_stay_under_size_limits(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->visibleSiteFirstGroup();
        $secondGroup = $this->createSecondGroup($firstGroup, '大响应二级 '.$suffix, 1, true);

        foreach (range(1, 50) as $index) {
            $this->createThirdGroup($secondGroup, '大响应三级 '.$suffix.' '.$index, $index, true);
        }

        foreach (range(1, 50) as $index) {
            Product::query()->create($this->productPayload(
                $secondGroup,
                null,
                '大响应站点商品 '.$suffix.' '.$index,
                (string) (20 + $index).'.00',
                $index
            ));
        }

        $childrenResponse = $this->getJson('/api/v2/site/product-groups/'.$secondGroup->id.'/children?'.http_build_query([
            'page' => 1,
            'page_size' => 50,
        ]))
            ->assertOk()
            ->assertJsonCount(50, 'data.list');

        $this->assertLessThan(60 * 1024, strlen((string) $childrenResponse->getContent()));

        $productsResponse = $this->getJson('/api/v2/site/product-groups/'.$secondGroup->id.'/products?'.http_build_query([
            'level' => 2,
            'page' => 1,
            'page_size' => 50,
        ]))
            ->assertOk()
            ->assertJsonCount(50, 'data.list');

        $this->assertLessThan(100 * 1024, strlen((string) $productsResponse->getContent()));
        $this->assertNoSensitiveKeys($productsResponse->json());
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-product-groups-'.$suffix,
            'label' => 'V2 Product Groups',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-product-groups-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Product Groups',
            'email' => 'v2-product-groups-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function createAdminFirstGroup(string $suffix): FirstProductGroup
    {
        return FirstProductGroup::query()->create([
            'code' => 'v2_group_'.$suffix,
            'name' => 'P0 分组 '.$suffix,
            'slug' => 'v2-group-'.$suffix,
            'description' => 'P0 分组说明 '.$suffix,
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'legacy_product_type' => 'v2_group_'.$suffix,
            'product_type' => ProductType::OTHER,
        ]);
    }

    private function visibleSiteFirstGroup(): FirstProductGroup
    {
        $group = FirstProductGroup::query()->where('code', 'vps')->first();

        if ($group instanceof FirstProductGroup) {
            $group->update([
                'name' => $group->name ?: '云服务器',
                'slug' => $group->slug ?: 'vps',
                'is_visible' => 1,
                'product_type' => ProductType::CLOUD_SERVER,
            ]);

            return $group->refresh();
        }

        return FirstProductGroup::query()->create([
            'code' => 'vps',
            'name' => '云服务器',
            'slug' => 'vps',
            'description' => '云服务器',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 1,
            'legacy_product_type' => 'vps',
            'product_type' => ProductType::CLOUD_SERVER,
        ]);
    }

    private function createSecondGroup(FirstProductGroup $firstGroup, string $name, int $sortOrder, bool $visible): SecondProductGroup
    {
        return SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => $name,
            'slug' => 'second-'.$firstGroup->id.'-'.$sortOrder.'-'.bin2hex(random_bytes(3)),
            'description' => $name.' 说明',
            'sort_order' => $sortOrder,
            'is_visible' => $visible ? 1 : 0,
        ]);
    }

    private function createThirdGroup(SecondProductGroup $secondGroup, string $name, int $sortOrder, bool $visible): ThirdProductGroup
    {
        return ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => $name,
            'slug' => 'third-'.$secondGroup->id.'-'.$sortOrder.'-'.bin2hex(random_bytes(3)),
            'description' => $name.' 说明',
            'sort_order' => $sortOrder,
            'is_visible' => $visible ? 1 : 0,
        ]);
    }

    private function productPayload(
        SecondProductGroup $secondGroup,
        ?ThirdProductGroup $thirdGroup,
        string $name,
        string $monthlyPrice,
        int $sortOrder,
    ): array {
        $thirdGroup ??= $this->createThirdGroup($secondGroup, $name.' 三级分组', $sortOrder, true);
        $firstGroup = $secondGroup->firstProductGroup ?: FirstProductGroup::query()->findOrFail((int) $secondGroup->first_product_group_id);
        $code = (string) $firstGroup->code;
        $productType = ProductType::businessValueForFirstGroup($firstGroup, $code);

        return [
            'product_group_id' => (int) $thirdGroup->id,
            'service_type_code' => $productType,
            'custom_display_name' => $name,
            'product_type' => $productType,
            'pricing' => [
                'monthly' => $monthlyPrice,
                'quarterly' => number_format((float) $monthlyPrice * 3, 2, '.', ''),
                'semiannually' => number_format((float) $monthlyPrice * 6, 2, '.', ''),
                'annually' => number_format((float) $monthlyPrice * 12, 2, '.', ''),
            ],
            'setup_fee' => '0.00',
            'purchase_requires' => [],
            'config_options' => [],
            'stock' => -1,
            'status' => $thirdGroup?->is_visible === 0 ? 0 : 1,
            'sort_order' => $sortOrder,
            'auto_setup' => 1,
        ];
    }

    /**
     * @return list<string>
     */
    private function adminGroupListWhitelist(): array
    {
        return [
            'id',
            'node_type',
            'name',
            'label',
            'parent_id',
            'parent_level',
            'level',
            'product_type',
            'product_type_label',
            'service_type_code',
            'service_type_label',
            'slug',
            'first_product_group_id',
            'first_product_group_code',
            'first_product_group_name',
            'second_product_group_id',
            'second_product_group_name',
            'third_product_group_id',
            'third_product_group_name',
            'effective_product_group_id',
            'effective_product_group_level',
            'children_count',
            'products_count',
            'direct_products_count',
            'status',
            'sort_order',
        ];
    }

    /**
     * @return list<string>
     */
    private function adminGroupDetailWhitelist(): array
    {
        return [
            ...$this->adminGroupListWhitelist(),
            'description',
            'banner_image',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function siteGroupWhitelist(): array
    {
        return [
            'id',
            'parent_id',
            'product_type',
            'product_type_id',
            'product_type_label',
            'first_product_group_id',
            'first_product_group_code',
            'first_product_group_name',
            'second_product_group_id',
            'second_product_group_name',
            'second_product_group_parent_id',
            'second_product_group_parent_name',
            'third_product_group_id',
            'third_product_group_name',
            'effective_product_group_id',
            'effective_product_group_level',
            'service_type_code',
            'name',
            'slogan',
            'slug',
            'children_count',
            'direct_product_count',
            'product_count',
        ];
    }

    /**
     * @return list<string>
     */
    private function siteProductWhitelist(): array
    {
        return [
            'id',
            'name',
            'display_name',
            'product_display_name',
            'combined_display_name',
            'cpu_memory_display',
            'cpu_model_name',
            'cpu_base_frequency',
            'cpu_turbo_frequency',
            'product_type',
            'first_product_group_id',
            'first_product_group_code',
            'first_product_group_name',
            'second_product_group_id',
            'second_product_group_name',
            'second_product_group_description',
            'second_product_group_parent_id',
            'second_product_group_parent_name',
            'third_product_group_id',
            'third_product_group_name',
            'third_product_group_description',
            'effective_product_group_id',
            'effective_product_group_level',
            'product_type_label',
            'service_type_code',
            'pricing',
            'pricing_entries',
            'primary_cycle',
            'primary_price',
            'setup_fee',
            'stock',
            'auto_setup',
        ];
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
