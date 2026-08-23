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

class V2AdminCouponProductGroupTest extends TestCase
{
    public function test_coupon_product_groups_require_login(): void
    {
        $this->getJson('/api/v2/admin/coupon-product-groups')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);
    }

    public function test_coupon_product_groups_require_product_list_permission(): void
    {
        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/coupon-product-groups')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_coupon_product_groups_reject_legacy_per_page_parameter(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->getJson('/api/v2/admin/coupon-product-groups?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/coupon-product-groups/1/children?level=1&per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/coupon-product-groups/1/products?level=1&per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);
    }

    public function test_coupon_product_group_children_and_products_are_paginated_with_whitelisted_fields(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->createFirstGroup($suffix);
        $secondGroup = $this->createSecondGroup($firstGroup, '二级分组 '.$suffix, 1);
        $thirdGroup = $this->createThirdGroup($secondGroup, '三级分组 '.$suffix, 1);
        $directProduct = Product::query()->create($this->productPayload($secondGroup, null, '直属商品 '.$suffix, '10.00', 1));
        $thirdProduct = Product::query()->create($this->productPayload($secondGroup, $thirdGroup, '三级商品 '.$suffix, '20.00', 2));

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $rootResponse = $this->getJson('/api/v2/admin/coupon-product-groups?'.http_build_query([
            'keyword' => $suffix,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 10);

        $rootGroup = $rootResponse->json('data.list.0');
        $this->assertSame($firstGroup->id, (int) $rootGroup['id']);
        $this->assertSame(1, (int) $rootGroup['level']);
        $this->assertSame(1, (int) $rootGroup['children_count']);
        $this->assertSame(2, (int) $rootGroup['products_count']);
        $this->assertSame(0, (int) $rootGroup['direct_products_count']);
        $this->assertSame($this->groupFieldWhitelist(), array_keys($rootGroup));
        $this->assertNoSensitiveKeys($rootResponse->json());

        $childrenResponse = $this->getJson('/api/v2/admin/coupon-product-groups/'.$firstGroup->id.'/children?'.http_build_query([
            'level' => 1,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $secondGroup->id)
            ->assertJsonPath('data.list.0.level', 2)
            ->assertJsonPath('data.list.0.children_count', 2);
        $this->assertSame($this->groupFieldWhitelist(), array_keys($childrenResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($childrenResponse->json());

        $thirdChildrenResponse = $this->getJson('/api/v2/admin/coupon-product-groups/'.$secondGroup->id.'/children?'.http_build_query([
            'level' => 2,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $thirdGroup->id)
            ->assertJsonPath('data.list.0.level', 3);
        $this->assertSame($this->groupFieldWhitelist(), array_keys($thirdChildrenResponse->json('data.list.0')));

        $rootProductsResponse = $this->getJson('/api/v2/admin/coupon-product-groups/'.$firstGroup->id.'/products?'.http_build_query([
            'level' => 1,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.id', $directProduct->id);
        $this->assertSame($this->productFieldWhitelist(), array_keys($rootProductsResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($rootProductsResponse->json());

        $directProductsResponse = $this->getJson('/api/v2/admin/coupon-product-groups/'.$secondGroup->id.'/products?'.http_build_query([
            'level' => 2,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.id', $directProduct->id);
        $this->assertSame($this->productFieldWhitelist(), array_keys($directProductsResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($directProductsResponse->json());

        $thirdProductsResponse = $this->getJson('/api/v2/admin/coupon-product-groups/'.$thirdGroup->id.'/products?'.http_build_query([
            'level' => 3,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', $thirdProduct->id);
        $this->assertSame($this->productFieldWhitelist(), array_keys($thirdProductsResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($thirdProductsResponse->json());
    }

    public function test_coupon_product_group_responses_stay_under_size_limits(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->createFirstGroup('large-'.$suffix);
        $secondGroup = $this->createSecondGroup($firstGroup, '大响应二级主分组 '.$suffix, 0);

        foreach (range(1, 80) as $index) {
            $this->createSecondGroup($firstGroup, '大响应二级 '.$suffix.' '.$index, $index);
        }

        foreach (range(1, 100) as $index) {
            Product::query()->create($this->productPayload(
                $secondGroup,
                null,
                '大响应商品 '.$suffix.' '.$index,
                (string) (10 + $index).'.00',
                $index
            ));
        }

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $childrenResponse = $this->getJson('/api/v2/admin/coupon-product-groups/'.$firstGroup->id.'/children?'.http_build_query([
            'level' => 1,
            'page' => 1,
            'page_size' => 50,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonCount(50, 'data.list');

        $this->assertLessThan(70 * 1024, strlen((string) $childrenResponse->getContent()));

        $productsResponse = $this->getJson('/api/v2/admin/coupon-product-groups/'.$secondGroup->id.'/products?'.http_build_query([
            'level' => 2,
            'page' => 1,
            'page_size' => 100,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonCount(100, 'data.list');

        $this->assertLessThan(100 * 1024, strlen((string) $productsResponse->getContent()));
        $this->assertNoSensitiveKeys($productsResponse->json());
    }

    public function test_batch_products_use_level_scoped_keys_and_the_product_resource(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->createFirstGroup($suffix);
        $secondGroup = $this->createSecondGroup($firstGroup, '批量二级分组 '.$suffix, 1);
        $thirdGroup = $this->createThirdGroup($secondGroup, '批量三级分组 '.$suffix, 1);
        $product = Product::query()->create($this->productPayload($secondGroup, $thirdGroup, '批量商品 '.$suffix, '66.00', 1));

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $batchKey = '3:'.$thirdGroup->id;
        $response = $this->postJson('/api/v2/admin/coupon-product-groups/batch-products', [
            'groups' => [
                ['id' => $thirdGroup->id, 'level' => 3],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '操作成功')
            ->assertJsonPath('data.'.$batchKey.'.0.id', $product->id)
            ->assertJsonPath('data.'.$batchKey.'.0.product_id', $product->id)
            ->assertJsonPath('data.'.$batchKey.'.0.label', '批量商品 '.$suffix)
            ->assertJsonPath('data.'.$batchKey.'.0.primary_price.cycle', 'monthly')
            ->assertJsonPath('data.'.$batchKey.'.0.primary_price.amount', '66.00')
            ->assertJsonStructure(['code', 'message', 'data', 'timestamp']);

        $this->assertSame($this->productFieldWhitelist(), array_keys($response->json('data.'.$batchKey.'.0')));
        $this->assertNoSensitiveKeys($response->json());
    }

    public function test_batch_products_empty_groups_uses_the_standard_success_envelope(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->postJson('/api/v2/admin/coupon-product-groups/batch-products', [
            'groups' => [],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '操作成功')
            ->assertJsonPath('data', [])
            ->assertJsonStructure(['code', 'message', 'data', 'timestamp']);
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-coupon-product-'.$suffix,
            'label' => 'V2 Coupon Product',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-coupon-product-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Coupon Product',
            'email' => 'v2-coupon-product-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function createFirstGroup(string $suffix): FirstProductGroup
    {
        return FirstProductGroup::query()->create([
            'code' => 'v2_coupon_'.$suffix,
            'name' => '优惠券一级 '.$suffix,
            'slug' => 'v2-coupon-'.$suffix,
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'legacy_product_type' => 'v2_coupon_'.$suffix,
        ]);
    }

    private function createSecondGroup(FirstProductGroup $firstGroup, string $name, int $sortOrder): SecondProductGroup
    {
        return SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => $name,
            'slug' => 'second-'.$firstGroup->id.'-'.$sortOrder.'-'.bin2hex(random_bytes(3)),
            'sort_order' => $sortOrder,
            'is_visible' => 1,
        ]);
    }

    private function createThirdGroup(SecondProductGroup $secondGroup, string $name, int $sortOrder): ThirdProductGroup
    {
        return ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => $name,
            'slug' => 'third-'.$secondGroup->id.'-'.$sortOrder.'-'.bin2hex(random_bytes(3)),
            'sort_order' => $sortOrder,
            'is_visible' => 1,
        ]);
    }

    private function productPayload(
        SecondProductGroup $secondGroup,
        ?ThirdProductGroup $thirdGroup,
        string $name,
        string $monthlyPrice,
        int $sortOrder,
    ): array {
        $thirdGroup ??= $this->createThirdGroup($secondGroup, $name.' 三级分组', $sortOrder);
        $firstGroup = $secondGroup->firstProductGroup ?: FirstProductGroup::query()->findOrFail((int) $secondGroup->first_product_group_id);
        $code = (string) $firstGroup->code;

        return [
            'product_group_id' => (int) $thirdGroup->id,
            'service_type_code' => $code,
            'custom_display_name' => $name,
            'product_type' => $code,
            'pricing' => ['monthly' => $monthlyPrice],
            'purchase_requires' => [],
            'config_options' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => $sortOrder,
            'auto_setup' => 0,
        ];
    }

    private function rootProductPayload(
        FirstProductGroup $firstGroup,
        string $name,
        string $monthlyPrice,
        int $sortOrder,
    ): array {
        $code = (string) $firstGroup->code;
        $secondGroup = $this->createSecondGroup($firstGroup, $name.' 二级分组', $sortOrder);
        $thirdGroup = $this->createThirdGroup($secondGroup, $name.' 三级分组', $sortOrder);

        return [
            'product_group_id' => (int) $thirdGroup->id,
            'service_type_code' => $code,
            'custom_display_name' => $name,
            'product_type' => $code,
            'pricing' => ['monthly' => $monthlyPrice],
            'purchase_requires' => [],
            'config_options' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => $sortOrder,
            'auto_setup' => 0,
        ];
    }

    /**
     * @return list<string>
     */
    private function groupFieldWhitelist(): array
    {
        return [
            'id',
            'node_key',
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
            'first_product_group_id',
            'first_product_group_code',
            'first_product_group_name',
            'second_product_group_id',
            'second_product_group_name',
            'third_product_group_id',
            'third_product_group_name',
            'effective_product_group_id',
            'effective_product_group_level',
            'group_path',
            'children_count',
            'products_count',
            'direct_products_count',
            'has_children',
            'has_products',
            'status',
            'sort_order',
        ];
    }

    /**
     * @return list<string>
     */
    private function productFieldWhitelist(): array
    {
        return [
            'id',
            'product_id',
            'node_type',
            'label',
            'product_display_name',
            'custom_display_name',
            'cpu_memory_display',
            'product_spec_display',
            'combined_display_name',
            'product_type',
            'service_type_code',
            'category_full_name',
            'first_product_group_id',
            'first_product_group_name',
            'second_product_group_id',
            'second_product_group_name',
            'third_product_group_id',
            'third_product_group_name',
            'effective_product_group_id',
            'effective_product_group_level',
            'primary_price',
            'status',
            'sort_order',
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
