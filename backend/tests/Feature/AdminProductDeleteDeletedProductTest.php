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

class AdminProductDeleteDeletedProductTest extends TestCase
{
    public function test_admin_can_repeat_delete_soft_deleted_product_without_404(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = $this->createAdmin($suffix);
        $root = $this->firstGroupForType('vps', 'Deleted delete root '.$suffix, 'deleted-delete-root-'.$suffix);
        $category = $this->createSecondGroup($root, 'Deleted delete category '.$suffix, 'deleted-delete-category-'.$suffix);
        $leaf = $this->createThirdGroup($category, 'Deleted delete leaf '.$suffix, 'deleted-delete-leaf-'.$suffix);

        $product = Product::query()->create($this->productPayload($leaf, 'Deleted delete product '.$suffix, '66.00', 1));
        $product->delete();

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/v2/admin/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.product.id', (int) $product->id)
            ->assertJsonPath('data.detail.product.lifecycle_status', 'deleted');

        $this->assertDatabaseHas('products', [
            'id' => (int) $product->id,
        ]);
    }

    private function createAdmin(string $suffix): AdminUser
    {
        $role = Role::query()->create([
            'name' => 'admin-product-delete-deleted-'.$suffix,
            'label' => 'Admin Product Delete Deleted',
            'permissions' => [AdminPermissions::ALL],
        ]);

        return AdminUser::query()->create([
            'username' => 'admin-product-delete-deleted-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Product Delete Deleted',
            'email' => 'admin-product-delete-deleted-'.$suffix.'@example.com',
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
                'legacy_product_type' => $code,
            ]
        );

        if ((int) $group->is_visible !== 1) {
            $group->update(['is_visible' => 1]);
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

        return [
            'product_group_id' => (int) $group->id,
            'service_type_code' => $code,
            'name' => $name,
            'custom_display_name' => $name,
            'product_type' => $code,
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
