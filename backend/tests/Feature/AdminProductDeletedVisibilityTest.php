<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ProductType;
use App\Models\AdminUser;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminProductDeletedVisibilityTest extends TestCase
{
    public function test_admin_can_list_restore_and_force_delete_soft_deleted_products(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = $this->createAdmin($suffix);
        $root = $this->firstGroupForType('vps', 'Deleted visibility root '.$suffix, 'deleted-visibility-root-'.$suffix);
        $category = $this->createSecondGroup($root, 'Deleted visibility category '.$suffix, 'deleted-visibility-category-'.$suffix);
        $leaf = $this->createThirdGroup($category, 'Deleted visibility leaf '.$suffix, 'deleted-visibility-leaf-'.$suffix);

        $product = Product::query()->create($this->productPayload($leaf, 'Deleted visibility product '.$suffix, '66.00', 1));
        $product->delete();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/product-groups/'.$root->id.'/children?'.http_build_query([
            'level' => 1,
            'keyword' => $suffix,
            'page' => 1,
            'page_size' => 50,
        ]))
            ->assertOk()
            ->assertJsonFragment([
                'id' => (int) $category->id,
                'products_count' => 0,
            ]);

        $this->getJson('/api/v2/admin/products?second_product_group_id='.$category->id.'&first_product_group_code=vps&lifecycle_status=deleted&page=1&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', (int) $product->id)
            ->assertJsonPath('data.list.0.is_deleted', true)
            ->assertJsonPath('data.list.0.lifecycle_status', 'deleted');

        $this->postJson('/api/v2/admin/products/'.$product->id.'/restorations')
            ->assertOk()
            ->assertJsonPath('data.product.id', (int) $product->id)
            ->assertJsonPath('data.product.lifecycle.lifecycle_status', 'active')
            ->assertJsonPath('data.product.lifecycle.deleted_at', null);

        $this->assertDatabaseHas('products', [
            'id' => (int) $product->id,
            'deleted_at' => null,
        ]);

        $product->refresh()->delete();

        $this->deleteJson('/api/v2/admin/products/'.$product->id.'/force')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.product.id', (int) $product->id)
            ->assertJsonPath('data.detail.product.lifecycle_status', 'purged');

        $this->assertDatabaseMissing('products', [
            'id' => (int) $product->id,
        ]);
    }

    private function createAdmin(string $suffix): AdminUser
    {
        $role = Role::query()->create([
            'name' => 'admin-product-deleted-'.$suffix,
            'label' => 'Admin Product Deleted',
            'permissions' => [AdminPermissions::ALL],
        ]);

        return AdminUser::query()->create([
            'username' => 'admin-product-deleted-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Product Deleted',
            'email' => 'admin-product-deleted-'.$suffix.'@example.com',
            'status' => 1,
        ]);
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

        if ((int) $group->is_visible !== 1) {
            $group->update(['is_visible' => 1]);
        }
        if ((string) ($group->product_type ?? '') === '') {
            $group->update(['product_type' => ProductType::normalizeBusinessValueFromMenuCode($code)]);
        }

        return $group->refresh();
    }

    private function createSecondGroup(FirstProductGroup $firstGroup, string $name, string $slug): SecondProductGroup
    {
        return SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => $name,
            'slug' => $slug,
            'sort_order' => 0,
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
