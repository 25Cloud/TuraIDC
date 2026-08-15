<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ProductType;
use App\Models\AdminUser;
use App\Models\FirstProductGroup;
use App\Models\IntegrationPlugin;
use App\Models\Product;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Models\Supplier;
use App\Models\ThirdProductGroup;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\Integrations\Plugins\UpstreamBindingWriter;
use App\Services\ProductCatalog\ProductCategoryService;
use App\Services\ProductCatalog\ProductTypeService;
use App\Services\Upstream\ProviderKey;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCatalogRegressionTest extends TestCase
{
    public function test_admin_catalog_and_coupon_pages_do_not_require_removed_tables(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-catalog-'.$suffix,
            'label' => 'Admin Catalog',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-catalog-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Catalog',
            'email' => 'admin-catalog-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/services?page=1&page_size=20')
            ->assertOk()
            ->assertJsonStructure(['code', 'message', 'data', 'timestamp']);

        $this->getJson('/api/v2/admin/products/summary')
            ->assertOk()
            ->assertJsonStructure(['code', 'message', 'data', 'timestamp']);

        $this->getJson('/api/v2/admin/product-types')
            ->assertOk()
            ->assertJsonStructure(['code', 'message', 'data', 'timestamp']);

        $this->getJson('/api/v2/admin/coupon-product-groups?page=1&page_size=20')
            ->assertOk()
            ->assertJsonStructure(['code', 'message', 'data' => ['list', 'total', 'page', 'page_size'], 'timestamp']);

        $this->getJson('/api/v2/admin/coupon-campaigns?page=1&page_size=20')
            ->assertOk()
            ->assertJsonStructure(['code', 'message', 'data', 'timestamp']);

        $this->getJson('/api/v2/admin/coupon-campaigns/summary')
            ->assertOk()
            ->assertJsonStructure(['code', 'message', 'data', 'timestamp']);
    }

    public function test_admin_product_list_filters_by_current_product_group_structure(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-product-filter-'.$suffix,
            'label' => 'Admin Product Filter',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-product-filter-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Product Filter',
            'email' => 'admin-product-filter-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        $type = app(ProductTypeService::class)->create('Filter type '.$suffix, '');
        $firstGroup = FirstProductGroup::query()->findOrFail((int) $type['first_product_group_id']);
        $group = $this->createSecondGroup($firstGroup, 'Filter group '.$suffix, 'admin-filter-group-'.$suffix);
        $thirdGroup = $this->createThirdGroup($group, 'Filter leaf '.$suffix, 'admin-filter-leaf-'.$suffix);

        Product::query()->create($this->productPayload($thirdGroup, 'Domain product '.$suffix, '10.00', 0));

        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/products?'.http_build_query([
            'second_product_group_id' => (int) $group->id,
            'first_product_group_code' => (string) $firstGroup->code,
            'page' => 1,
            'page_size' => 20,
        ]))
            ->assertOk()
            ->assertJsonStructure(['code', 'message', 'data', 'timestamp']);
    }

    public function test_admin_category_crud_uses_three_level_hierarchy(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-category-crud-'.$suffix,
            'label' => 'Admin Category Crud',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-category-crud-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Category Crud',
            'email' => 'admin-category-crud-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        $firstGroup = $this->firstGroupForType(
            'category-crud-'.$suffix,
            '云服务器 '.$suffix,
            'admin-category-crud-root-'.$suffix
        );

        Sanctum::actingAs($admin);

        $secondResponse = $this->postJson('/api/v2/admin/product-groups', [
            'effective_product_group_level' => 2,
            'first_product_group_code' => (string) $firstGroup->code,
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => '襄阳 '.$suffix,
            'sort_order' => 1,
            'is_visible' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.group.effective_product_group_level', 2)
            ->assertJsonPath('data.group.first_product_group_id', (int) $firstGroup->id)
            ->assertJsonPath('data.group.second_product_group_name', '襄阳 '.$suffix);

        $secondGroupId = (int) $secondResponse->json('data.group.second_product_group_id');
        $this->assertGreaterThan(0, $secondGroupId);

        $thirdResponse = $this->postJson('/api/v2/admin/product-groups', [
            'effective_product_group_level' => 3,
            'second_product_group_id' => $secondGroupId,
            'name' => '三网精品 '.$suffix,
            'sort_order' => 1,
            'is_visible' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.group.effective_product_group_level', 3)
            ->assertJsonPath('data.group.second_product_group_id', $secondGroupId)
            ->assertJsonPath('data.group.third_product_group_name', '三网精品 '.$suffix);

        $thirdGroupId = (int) $thirdResponse->json('data.group.third_product_group_id');
        $this->assertGreaterThan(0, $thirdGroupId);

        $this->putJson('/api/v2/admin/product-groups/'.$thirdGroupId, [
            'effective_product_group_level' => 3,
            'second_product_group_id' => $secondGroupId,
            'name' => '高性能 '.$suffix,
            'is_visible' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.group.third_product_group_name', '高性能 '.$suffix);

        $rootResponse = $this->getJson('/api/v2/admin/product-groups?'.http_build_query([
            'keyword' => $suffix,
            'page' => 1,
            'page_size' => 20,
        ]))
            ->assertOk();
        $rootGroup = $rootResponse->json('data.list.0');
        $this->assertSame('云服务器 '.$suffix, $rootGroup['first_product_group_name']);

        $childrenResponse = $this->getJson('/api/v2/admin/product-groups/'.$firstGroup->id.'/children?'.http_build_query([
            'level' => 1,
            'page' => 1,
            'page_size' => 20,
        ]))
            ->assertOk();
        $this->assertSame('襄阳 '.$suffix, $childrenResponse->json('data.list.0.second_product_group_name'));

        $thirdChildrenResponse = $this->getJson('/api/v2/admin/product-groups/'.$secondGroupId.'/children?'.http_build_query([
            'level' => 2,
            'page' => 1,
            'page_size' => 20,
        ]))
            ->assertOk();
        $this->assertSame('高性能 '.$suffix, $thirdChildrenResponse->json('data.list.0.third_product_group_name'));

        $this->deleteJson('/api/v2/admin/product-groups/'.$thirdGroupId, [
            'effective_product_group_level' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('data', null);
        $this->assertDatabaseMissing('third_product_groups', ['id' => $thirdGroupId]);

        $this->deleteJson('/api/v2/admin/product-groups/'.$secondGroupId, [
            'effective_product_group_level' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('data', null);
        $this->assertDatabaseMissing('second_product_groups', ['id' => $secondGroupId]);
    }

    public function test_numeric_product_type_label_can_create_second_level_category(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $label = (string) random_int(10000000, 99999999);
        $type = app(ProductTypeService::class)->create($label, '');

        $productType = (string) $type['value'];
        $firstGroupId = (int) $type['first_product_group_id'];

        $this->assertMatchesRegularExpression('/^type_\d+$/', $productType);
        $this->assertGreaterThan(0, $firstGroupId);
        $this->assertDatabaseHas('first_product_groups', [
            'id' => $firstGroupId,
            'code' => $productType,
            'name' => $label,
        ]);

        $category = app(ProductCategoryService::class)->createCategory([
            'effective_product_group_level' => 2,
            'service_type_code' => $productType,
            'first_product_group_id' => $firstGroupId,
            'name' => '西安 '.$suffix,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);

        $this->assertSame($firstGroupId, (int) $category['first_product_group_id']);
        $this->assertSame('西安 '.$suffix, $category['second_product_group_name']);
    }

    public function test_second_level_category_create_recovers_missing_first_group_from_product_type_code(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $label = 'Recover '.$suffix;
        $type = app(ProductTypeService::class)->create($label, '');
        $productType = (string) $type['value'];
        $staleFirstGroupId = (int) $type['first_product_group_id'];

        FirstProductGroup::query()->whereKey($staleFirstGroupId)->delete();
        $this->assertDatabaseMissing('first_product_groups', [
            'id' => $staleFirstGroupId,
        ]);

        $category = app(ProductCategoryService::class)->createCategory([
            'effective_product_group_level' => 2,
            'service_type_code' => $productType,
            'first_product_group_code' => $productType,
            'first_product_group_id' => $staleFirstGroupId,
            'name' => '美国 '.$suffix,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);

        $firstGroup = FirstProductGroup::query()->where('code', $productType)->first();

        $this->assertInstanceOf(FirstProductGroup::class, $firstGroup);
        $this->assertSame((int) $firstGroup->id, (int) $category['first_product_group_id']);
        $this->assertSame($productType, $category['first_product_group_code']);
        $this->assertSame('美国 '.$suffix, $category['second_product_group_name']);
    }

    public function test_admin_category_reorder_accepts_current_hierarchy_payload(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-category-reorder-'.$suffix,
            'label' => 'Admin Category Reorder',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-category-reorder-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Category Reorder',
            'email' => 'admin-category-reorder-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        $firstGroup = $this->firstGroupForType(
            'category-reorder-'.$suffix,
            '游戏云 '.$suffix,
            'admin-category-reorder-root-'.$suffix
        );
        $firstSecondGroup = $this->createSecondGroup($firstGroup, '美国 '.$suffix, 'admin-category-reorder-us-'.$suffix, 1);
        $secondSecondGroup = $this->createSecondGroup($firstGroup, '香港 '.$suffix, 'admin-category-reorder-hk-'.$suffix, 2);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v2/admin/product-groups/reorders', [
            'effective_product_group_level' => 2,
            'first_product_group_id' => (int) $firstGroup->id,
            'second_product_group_ids' => [(int) $secondSecondGroup->id, (int) $firstSecondGroup->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.level', 2)
            ->assertJsonPath('data.parent_id', (int) $firstGroup->id);

        $orderedSecondGroupIds = SecondProductGroup::query()
            ->where('first_product_group_id', (int) $firstGroup->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertSame([
            (int) $secondSecondGroup->id,
            (int) $firstSecondGroup->id,
        ], $orderedSecondGroupIds);
    }

    public function test_admin_product_create_accepts_upstream_binding_payload(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-product-upstream-binding-'.$suffix,
            'label' => 'Admin Product Upstream Binding',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-product-upstream-binding-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Product Upstream Binding',
            'email' => 'admin-product-upstream-binding-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        IntegrationPlugin::query()->updateOrCreate(
            [
                'domain' => PluginDomain::UPSTREAM,
                'plugin_key' => ProviderKey::ZJMF_FINANCE_API,
            ],
            [
                'slug' => 'zjmf_finance',
                'name' => 'ZJMF 财务',
                'version' => '1.0.0',
                'entry_class' => 'Tests\\Fixtures\\ZjmfFinancePlugin',
                'capabilities_json' => [],
                'config_schema_json' => [],
                'status' => IntegrationPlugin::STATUS_ENABLED,
                'installed_at' => now(),
            ]
        );

        $supplier = Supplier::query()->create([
            'name' => 'ZJMF 财务 '.$suffix,
            'code' => 'zjmf-'.$suffix,
            'status' => 1,
            'sort_order' => 0,
        ]);
        app(UpstreamBindingWriter::class)->syncSupplierBinding($supplier, [
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'base_url' => 'https://panel.example.com',
            'account_name' => 'demo',
            'api_key' => 'secret',
            'provider_config' => [],
            'status' => 1,
        ]);

        $firstGroup = $this->firstGroupForType('vps', 'Binding root '.$suffix, 'admin-binding-root-'.$suffix);
        $group = $this->createSecondGroup($firstGroup, 'Binding group '.$suffix, 'admin-binding-group-'.$suffix);
        $thirdGroup = $this->createThirdGroup($group, 'Binding leaf '.$suffix, 'admin-binding-leaf-'.$suffix);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v2/admin/products', [
            'display_name' => 'Binding product '.$suffix,
            'product_type' => 'vps',
            'first_product_group_id' => (int) $firstGroup->id,
            'second_product_group_id' => (int) $group->id,
            'third_product_group_id' => (int) $thirdGroup->id,
            'pricing' => ['monthly' => '12.00'],
            'auto_setup' => 1,
            'status' => 1,
            'config_options' => [],
            'upstream_binding' => [
                'provider_key' => ProviderKey::ZJMF_FINANCE_API,
                'supplier_id' => (int) $supplier->id,
                'upstream_product_id' => '900123',
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.product.upstream_binding.provider_key', ProviderKey::ZJMF_FINANCE_API)
            ->assertJsonPath('data.product.upstream_binding.supplier_id', (int) $supplier->id)
            ->assertJsonPath('data.product.upstream_binding.upstream_product_id', '900123');

        $productId = (int) $response->json('data.product.id');
        $this->assertGreaterThan(0, $productId);
        $this->assertDatabaseHas('products', ['id' => $productId]);
        $this->assertDatabaseHas('supplier_plugin_bindings', [
            'supplier_id' => (int) $supplier->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
        ]);
        $this->assertDatabaseHas('product_upstream_bindings', [
            'product_id' => $productId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_product_id' => '900123',
        ]);

        $this->assertSame(
            $productId,
            (int) DB::table('product_upstream_bindings')
                ->join('supplier_plugin_bindings', 'supplier_plugin_bindings.id', '=', 'product_upstream_bindings.supplier_plugin_binding_id')
                ->where('supplier_plugin_bindings.supplier_id', (int) $supplier->id)
                ->where('product_upstream_bindings.provider_key', ProviderKey::ZJMF_FINANCE_API)
                ->where('upstream_product_id', '900123')
                ->value('product_id')
        );
    }

    public function test_admin_can_batch_move_products_to_another_category(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-product-batch-category-'.$suffix,
            'label' => 'Admin Product Batch Category',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-product-batch-category-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Product Batch Category',
            'email' => 'admin-product-batch-category-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        $root = $this->firstGroupForType('vps', 'Batch category root '.$suffix, 'admin-batch-category-root-'.$suffix);
        $sourceCategory = $this->createSecondGroup($root, 'Batch category source '.$suffix, 'admin-batch-category-source-'.$suffix, 1);
        $targetCategory = $this->createSecondGroup($root, 'Batch category target '.$suffix, 'admin-batch-category-target-'.$suffix, 2);
        $sourceLeaf = $this->createThirdGroup($sourceCategory, 'Batch source leaf '.$suffix, 'admin-batch-category-source-leaf-'.$suffix);
        $targetLeaf = $this->createThirdGroup($targetCategory, 'Batch target leaf '.$suffix, 'admin-batch-category-target-leaf-'.$suffix);

        $existingTargetProduct = Product::query()->create($this->productPayload($targetLeaf, 'Target product '.$suffix, '30.00', 1));
        $firstSourceProduct = Product::query()->create($this->productPayload($sourceLeaf, 'Source product A '.$suffix, '10.00', 1));
        $secondSourceProduct = Product::query()->create($this->productPayload($sourceLeaf, 'Source product B '.$suffix, '20.00', 2));

        Sanctum::actingAs($admin);

        $this->postJson('/api/v2/admin/products/category-batches', [
            'product_ids' => [(int) $firstSourceProduct->id, (int) $secondSourceProduct->id],
            'target_second_product_group_id' => (int) $targetCategory->id,
            'target_third_product_group_id' => (int) $targetLeaf->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.target_effective_product_group_id', (int) $targetLeaf->id);

        $this->assertDatabaseHas('products', [
            'id' => (int) $firstSourceProduct->id,
            'product_group_id' => (int) $targetLeaf->id,
            'product_type' => ProductType::CLOUD_SERVER,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => (int) $secondSourceProduct->id,
            'product_group_id' => (int) $targetLeaf->id,
            'product_type' => ProductType::CLOUD_SERVER,
        ]);

        $targetOrder = Product::query()
            ->where('product_group_id', (int) $targetLeaf->id)
            ->orderBy('sort_order')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertSame([
            (int) $existingTargetProduct->id,
            (int) $firstSourceProduct->id,
            (int) $secondSourceProduct->id,
        ], $targetOrder);
    }

    public function test_admin_product_drag_reorder_uses_visible_list_order_when_sort_values_match(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-product-drag-reorder-'.$suffix,
            'label' => 'Admin Product Drag Reorder',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-product-drag-reorder-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Product Drag Reorder',
            'email' => 'admin-product-drag-reorder-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        $root = $this->firstGroupForType('vps', 'Drag reorder root '.$suffix, 'admin-drag-reorder-root-'.$suffix);
        $category = $this->createSecondGroup($root, 'Drag reorder category '.$suffix, 'admin-drag-reorder-category-'.$suffix);
        $leaf = $this->createThirdGroup($category, 'Drag reorder leaf '.$suffix, 'admin-drag-reorder-leaf-'.$suffix);

        $firstProduct = Product::query()->create($this->productPayload($leaf, 'Drag product A '.$suffix, '10.00', 0));
        $secondProduct = Product::query()->create($this->productPayload($leaf, 'Drag product B '.$suffix, '20.00', 0));
        $thirdProduct = Product::query()->create($this->productPayload($leaf, 'Drag product C '.$suffix, '30.00', 0));

        Sanctum::actingAs($admin);

        $this->postJson('/api/v2/admin/products/reorders', [
            'product_id' => (int) $thirdProduct->id,
            'target_second_product_group_id' => (int) $category->id,
            'target_third_product_group_id' => (int) $leaf->id,
            'reference_product_id' => (int) $secondProduct->id,
            'position' => 'after',
        ])
            ->assertOk()
            ->assertJsonPath('data.product_id', (int) $thirdProduct->id)
            ->assertJsonPath('data.target_effective_product_group_id', (int) $leaf->id)
            ->assertJsonPath('data.position', 'after');

        $orderedIds = Product::query()
            ->where('product_group_id', (int) $leaf->id)
            ->orderBy('sort_order')
            ->orderByDesc('status')
            ->orderByDesc('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertSame([
            (int) $secondProduct->id,
            (int) $thirdProduct->id,
            (int) $firstProduct->id,
        ], $orderedIds);
    }

    public function test_product_detail_payloads_do_not_depend_on_removed_description_column(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-product-detail-'.$suffix,
            'label' => 'Admin Product Detail',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-product-detail-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Product Detail',
            'email' => 'admin-product-detail-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        $root = $this->firstGroupForType('vps', 'Product detail root '.$suffix, 'admin-product-detail-root-'.$suffix);
        $category = $this->createSecondGroup($root, 'Product detail category '.$suffix, 'admin-product-detail-category-'.$suffix);
        $leaf = $this->createThirdGroup($category, 'Product detail leaf '.$suffix, 'admin-product-detail-leaf-'.$suffix);

        $product = Product::query()->create($this->productPayload($leaf, 'Product detail item '.$suffix, '299.00', 1));

        Sanctum::actingAs($admin);

        $adminResponse = $this->getJson('/api/v2/admin/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.product.id', (int) $product->id)
            ->assertJsonPath('data.product.display.display_name', (string) $product->name);

        $siteResponse = $this->getJson('/api/v2/site/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.product.id', (int) $product->id)
            ->assertJsonPath('data.product.name', (string) $product->name);

        $this->assertArrayNotHasKey('description', $adminResponse->json('data.product'));
        $this->assertArrayNotHasKey('description', $siteResponse->json('data.product'));
    }

    public function test_admin_product_detail_keeps_description_removed_when_remark_support_is_added(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-product-remark-'.$suffix,
            'label' => 'Admin Product Remark',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-product-remark-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Product Remark',
            'email' => 'admin-product-remark-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        $root = $this->firstGroupForType('vps', 'Product remark root '.$suffix, 'admin-product-remark-root-'.$suffix);
        $category = $this->createSecondGroup($root, 'Product remark category '.$suffix, 'admin-product-remark-category-'.$suffix);
        $leaf = $this->createThirdGroup($category, 'Product remark leaf '.$suffix, 'admin-product-remark-leaf-'.$suffix);

        $product = Product::query()->create($this->productPayload($leaf, 'Product remark item '.$suffix, '299.00', 1));

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v2/admin/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.product.id', (int) $product->id);

        $this->assertArrayNotHasKey('description', $response->json('data.product'));
        $this->assertArrayHasKey('remark', $response->json('data.product.display'));
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
                'product_type' => ProductType::normalizeBusinessValueFromMenuCode($code),
            ]
        );

        $updates = [];
        if ((int) $group->is_visible !== 1) {
            $updates['is_visible'] = 1;
        }
        if (trim((string) ($group->product_type ?? '')) === '') {
            $updates['product_type'] = ProductType::normalizeBusinessValueFromMenuCode($code);
        }
        if ($updates !== []) {
            $group->update($updates);
        }

        return $group->refresh();
    }

    private function createSecondGroup(FirstProductGroup $firstGroup, string $name, string $slug, int $sortOrder = 0): SecondProductGroup
    {
        return SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => $name,
            'slug' => $slug,
            'sort_order' => $sortOrder,
            'is_visible' => 1,
        ]);
    }

    private function createThirdGroup(SecondProductGroup $secondGroup, string $name, string $slug): ThirdProductGroup
    {
        return ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => $name,
            'slug' => $slug,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
    }

    private function productPayload(ThirdProductGroup $group, string $name, string $monthlyPrice, int $sortOrder): array
    {
        $secondGroup = $group->secondProductGroup ?: SecondProductGroup::query()->findOrFail((int) $group->second_product_group_id);
        $firstGroup = $secondGroup->firstProductGroup ?: FirstProductGroup::query()->findOrFail((int) $secondGroup->first_product_group_id);
        $code = (string) $firstGroup->code;
        $productType = ProductType::businessValueForFirstGroup($firstGroup, $code);

        return [
            'product_group_id' => (int) $group->id,
            'service_type_code' => $productType,
            'name' => $name,
            'custom_display_name' => $name,
            'product_type' => $productType,
            'pricing' => ['monthly' => $monthlyPrice],
            'purchase_requires' => [],
            'config_options' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => $sortOrder,
            'provision_module' => null,
            'auto_setup' => 0,
        ];
    }
}
