<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ProductType;
use App\Models\AdminUser;
use App\Models\FirstProductGroup;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminProductTypeAndGroupMutationApiTest extends TestCase
{
    public function test_admin_product_types_require_permission_and_return_whitelist(): void
    {
        $this->getJson('/api/v2/admin/product-types')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/product-types')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->getJson('/api/v2/admin/product-types?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/product-types?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/admin/product-types')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['list']]);

        $this->assertNotEmpty($response->json('data.list'));
        $this->assertSame($this->productTypeWhitelist(), array_keys($response->json('data.list.0')));
        $this->assertLessThan(50 * 1024, strlen((string) $response->getContent()));
        $this->assertNoSensitiveKeys($response->json());
    }

    public function test_admin_product_type_create_update_reorder_and_delete_use_v2_contract(): void
    {
        Sanctum::actingAs($this->createAdmin([
            AdminPermissions::PRODUCT_LIST,
            AdminPermissions::PRODUCT_MANAGE,
        ]));

        $suffix = bin2hex(random_bytes(4));
        $createResponse = $this->postJson('/api/v2/admin/product-types', [
            'label' => 'V2 类型 '.$suffix,
            'product_type' => 'cloud_server',
            'icon' => 'Server',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '商品种类已创建');

        $value = (string) $createResponse->json('data.type.value');
        $this->assertNotSame('', $value);
        $this->assertSame($this->productTypeWhitelist(), array_keys($createResponse->json('data.type')));
        $this->assertNoSensitiveKeys($createResponse->json());

        $this->putJson('/api/v2/admin/product-types/'.$value, [
            'label' => 'V2 类型更新 '.$suffix,
            'product_type' => 'game_cloud',
            'icon' => 'Cloud',
            'is_hidden' => true,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.type.value', $value)
            ->assertJsonPath('data.type.is_hidden', true);

        $list = $this->getJson('/api/v2/admin/product-types')
            ->assertOk()
            ->json('data.list');
        $values = collect($list)->pluck('value')->map(fn ($item): string => (string) $item)->values()->all();
        $reorderedValues = array_values(array_unique([$value, ...array_values(array_filter($values, fn (string $item): bool => $item !== $value))]));

        $reorderResponse = $this->postJson('/api/v2/admin/product-types/reorders', [
            'values' => $reorderedValues,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.value', $value);

        $this->assertSame($this->productTypeWhitelist(), array_keys($reorderResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($reorderResponse->json());

        $this->deleteJson('/api/v2/admin/product-types/'.$value)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data', null);
    }

    public function test_admin_product_group_children_are_paginated_whitelisted_and_size_limited(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->createFirstGroup($suffix);
        $secondA = $this->createSecondGroup($firstGroup, '二级 A '.$suffix, 1);
        $secondB = $this->createSecondGroup($firstGroup, '二级 B '.$suffix, 2);
        $this->createSecondGroup($firstGroup, '二级 C '.$suffix, 3);
        $third = $this->createThirdGroup($secondA, '三级 A '.$suffix, 1);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->getJson('/api/v2/admin/product-groups/'.$firstGroup->id.'/children?'.http_build_query([
            'level' => 1,
            'per_page' => 20,
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $childrenResponse = $this->getJson('/api/v2/admin/product-groups/'.$firstGroup->id.'/children?'.http_build_query([
            'level' => 1,
            'page' => 1,
            'page_size' => 2,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.page_size', 2)
            ->assertJsonPath('data.list.0.id', $secondA->id)
            ->assertJsonPath('data.list.1.id', $secondB->id);

        $this->assertSame($this->productGroupListWhitelist(), array_keys($childrenResponse->json('data.list.0')));
        $this->assertLessThan(70 * 1024, strlen((string) $childrenResponse->getContent()));
        $this->assertNoSensitiveKeys($childrenResponse->json());

        $thirdResponse = $this->getJson('/api/v2/admin/product-groups/'.$secondA->id.'/children?'.http_build_query([
            'level' => 2,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', $third->id)
            ->assertJsonPath('data.list.0.effective_product_group_level', 3);

        $this->assertSame($this->productGroupListWhitelist(), array_keys($thirdResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($thirdResponse->json());
    }

    public function test_admin_product_group_tree_returns_nested_sidebar_contract(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->createFirstGroup($suffix);
        $secondA = $this->createSecondGroup($firstGroup, '二级 A '.$suffix, 1);
        $this->createSecondGroup($firstGroup, '二级 B '.$suffix, 2);
        $third = $this->createThirdGroup($secondA, '三级 A '.$suffix, 1);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/product-groups/tree?'.http_build_query([
            'first_product_group_code' => $firstGroup->code,
        ]))
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $response = $this->getJson('/api/v2/admin/product-groups/tree?'.http_build_query([
            'first_product_group_code' => $firstGroup->code,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.tree.0.id', $firstGroup->id)
            ->assertJsonPath('data.tree.0.children.0.id', $secondA->id)
            ->assertJsonPath('data.tree.0.children.0.children.0.id', $third->id)
            ->assertJsonPath('data.list.0.id', $firstGroup->id);

        $this->assertSame($this->productGroupTreeWhitelist(), array_keys($response->json('data.tree.0')));
        $this->assertSame($this->productGroupTreeWhitelist(), array_keys($response->json('data.tree.0.children.0')));
        $this->assertSame($this->productGroupTreeWhitelist(), array_keys($response->json('data.tree.0.children.0.children.0')));
        $this->assertLessThan(70 * 1024, strlen((string) $response->getContent()));
        $this->assertNoSensitiveKeys($response->json());
    }

    public function test_admin_product_group_create_update_reorder_and_delete_use_v2_contract(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_MANAGE]));

        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->createFirstGroup($suffix);

        $createA = $this->postJson('/api/v2/admin/product-groups', [
            'effective_product_group_level' => 2,
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => 'V2 二级 A '.$suffix,
            'description' => 'V2 二级说明',
            'sort_order' => 1,
            'is_visible' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.group.effective_product_group_level', 2);

        $secondAId = (int) $createA->json('data.group.id');
        $this->assertSame($this->productGroupDetailWhitelist(), array_keys($createA->json('data.group')));
        $this->assertNoSensitiveKeys($createA->json());

        $createB = $this->postJson('/api/v2/admin/product-groups', [
            'effective_product_group_level' => 2,
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => 'V2 二级 B '.$suffix,
            'sort_order' => 2,
            'is_visible' => 1,
        ])
            ->assertOk();

        $secondBId = (int) $createB->json('data.group.id');

        $this->putJson('/api/v2/admin/product-groups/'.$secondAId, [
            'effective_product_group_level' => 2,
            'name' => 'V2 二级 A 更新 '.$suffix,
            'is_visible' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.group.name', 'V2 二级 A 更新 '.$suffix)
            ->assertJsonPath('data.group.status', 0);

        $thirdResponse = $this->postJson('/api/v2/admin/product-groups', [
            'effective_product_group_level' => 3,
            'second_product_group_id' => $secondAId,
            'name' => 'V2 三级 '.$suffix,
            'sort_order' => 1,
            'is_visible' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.group.effective_product_group_level', 3);

        $thirdId = (int) $thirdResponse->json('data.group.id');

        $reorderResponse = $this->postJson('/api/v2/admin/product-groups/reorders', [
            'effective_product_group_level' => 2,
            'first_product_group_id' => (int) $firstGroup->id,
            'second_product_group_ids' => [$secondBId, $secondAId],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.level', 2);

        $this->assertNoSensitiveKeys($reorderResponse->json());

        $this->deleteJson('/api/v2/admin/product-groups/'.$thirdId, [
            'effective_product_group_level' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->deleteJson('/api/v2/admin/product-groups/'.$secondAId, [
            'effective_product_group_level' => 2,
        ])->assertOk();

        $this->deleteJson('/api/v2/admin/product-groups/'.$secondBId, [
            'effective_product_group_level' => 2,
        ])->assertOk();

        $this->deleteJson('/api/v2/admin/product-groups/'.$firstGroup->id, [
            'effective_product_group_level' => 1,
        ])->assertOk();
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-product-types-groups-'.$suffix,
            'label' => 'V2 Product Types Groups',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-product-types-groups-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Product Types Groups',
            'email' => 'v2-product-types-groups-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function createFirstGroup(string $suffix): FirstProductGroup
    {
        return FirstProductGroup::query()->create([
            'code' => 'v2_type_'.$suffix,
            'name' => 'V2 一级 '.$suffix,
            'slug' => 'v2-type-'.$suffix,
            'description' => 'V2 一级说明 '.$suffix,
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'legacy_product_type' => 'v2_type_'.$suffix,
            'product_type' => ProductType::OTHER,
        ]);
    }

    private function createSecondGroup(FirstProductGroup $firstGroup, string $name, int $sortOrder): SecondProductGroup
    {
        return SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => $name,
            'slug' => 'second-'.$firstGroup->id.'-'.$sortOrder.'-'.bin2hex(random_bytes(3)),
            'description' => $name.' 说明',
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
            'description' => $name.' 说明',
            'sort_order' => $sortOrder,
            'is_visible' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function productTypeWhitelist(): array
    {
        return [
            'internal_id',
            'value',
            'label',
            'product_type',
            'product_type_label',
            'product_type_icon',
            'product_type_plugin_driven',
            'first_product_group_id',
            'first_product_group_code',
            'first_product_group_name',
            'icon',
            'is_builtin',
            'is_hidden',
            'sort_order',
            'usage_count',
            'group_count',
        ];
    }

    /**
     * @return list<string>
     */
    private function productGroupListWhitelist(): array
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
    private function productGroupTreeWhitelist(): array
    {
        return [
            ...$this->productGroupListWhitelist(),
            'children',
        ];
    }

    /**
     * @return list<string>
     */
    private function productGroupDetailWhitelist(): array
    {
        return [
            ...$this->productGroupListWhitelist(),
            'description',
            'banner_image',
            'created_at',
            'updated_at',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $this->assertNotContains(strtolower($key), [
                    'password',
                    'secret',
                    'api_key',
                    'raw_response',
                    'third_party_response',
                ]);
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
