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

class AdminProductShowDeletedDetailTest extends TestCase
{
    public function test_admin_can_view_soft_deleted_product_detail(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = $this->createAdmin($suffix);
        $root = $this->firstGroupForType('vps', 'Deleted detail root '.$suffix, 'deleted-detail-root-'.$suffix);
        $category = $this->createSecondGroup($root, 'Deleted detail category '.$suffix, 'deleted-detail-category-'.$suffix);
        $leaf = $this->createThirdGroup($category, 'Deleted detail leaf '.$suffix, 'deleted-detail-leaf-'.$suffix);

        $product = Product::query()->create($this->productPayload($leaf, 'Deleted detail product '.$suffix, '66.00', 1));
        $product->delete();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.product.id', (int) $product->id)
            ->assertJsonPath('data.product.lifecycle.lifecycle_status', 'deleted')
            ->assertJsonPath('data.product.lifecycle.deleted_at', $product->deleted_at?->format('Y-m-d H:i:s'));
    }

    private function createAdmin(string $suffix): AdminUser
    {
        $role = Role::query()->create([
            'name' => 'admin-product-deleted-detail-'.$suffix,
            'label' => 'Admin Product Deleted Detail',
            'permissions' => [AdminPermissions::ALL],
        ]);

        return AdminUser::query()->create([
            'username' => 'admin-product-deleted-detail-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Product Deleted Detail',
            'email' => 'admin-product-deleted-detail-'.$suffix.'@example.com',
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
