<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Constants\ProductType;
use App\Models\FirstProductGroup;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponProductGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return match (true) {
            $this->resource instanceof FirstProductGroup => $this->firstGroupPayload($this->resource),
            $this->resource instanceof SecondProductGroup => $this->secondGroupPayload($this->resource),
            $this->resource instanceof ThirdProductGroup => $this->thirdGroupPayload($this->resource),
            default => [],
        };
    }

    private function firstGroupPayload(FirstProductGroup $group): array
    {
        $firstGroupCode = (string) $group->code;
        $productType = ProductType::businessValueForFirstGroup($group, $firstGroupCode);

        return [
            'id' => (int) $group->id,
            'node_key' => 'first:'.(int) $group->id,
            'node_type' => 'first_product_group',
            'name' => (string) $group->name,
            'label' => (string) $group->name,
            'parent_id' => null,
            'parent_level' => null,
            'level' => 1,
            'product_type' => $productType,
            'product_type_label' => ProductType::businessLabelOf($productType),
            'service_type_code' => $productType,
            'service_type_label' => ProductType::businessLabelOf($productType),
            'first_product_group_id' => (int) $group->id,
            'first_product_group_code' => $firstGroupCode,
            'first_product_group_name' => (string) $group->name,
            'second_product_group_id' => null,
            'second_product_group_name' => null,
            'third_product_group_id' => null,
            'third_product_group_name' => null,
            'effective_product_group_id' => (int) $group->id,
            'effective_product_group_level' => 1,
            'group_path' => (string) $group->name,
            'children_count' => (int) ($group->children_count ?? 0),
            'products_count' => (int) ($group->products_count ?? 0),
            'direct_products_count' => (int) ($group->direct_products_count ?? 0),
            'has_children' => (int) ($group->children_count ?? 0) > 0,
            'has_products' => (int) ($group->products_count ?? 0) > 0,
            'status' => (int) $group->is_visible,
            'sort_order' => (int) $group->sort_order,
        ];
    }

    private function secondGroupPayload(SecondProductGroup $group): array
    {
        $firstGroup = $group->firstProductGroup;
        $firstGroupCode = (string) ($firstGroup?->code ?? '');
        $productType = ProductType::businessValueForFirstGroup($firstGroup, $firstGroupCode);
        $firstGroupName = (string) ($firstGroup?->name ?? '');
        $groupPath = collect([$firstGroupName, (string) $group->name])->filter()->implode(' / ');

        return [
            'id' => (int) $group->id,
            'node_key' => 'second:'.(int) $group->id,
            'node_type' => 'second_product_group',
            'name' => (string) $group->name,
            'label' => (string) $group->name,
            'parent_id' => (int) $group->first_product_group_id,
            'parent_level' => 1,
            'level' => 2,
            'product_type' => $productType,
            'product_type_label' => ProductType::businessLabelOf($productType),
            'service_type_code' => $productType,
            'service_type_label' => ProductType::businessLabelOf($productType),
            'first_product_group_id' => (int) $group->first_product_group_id,
            'first_product_group_code' => $firstGroupCode,
            'first_product_group_name' => $firstGroupName,
            'second_product_group_id' => (int) $group->id,
            'second_product_group_name' => (string) $group->name,
            'third_product_group_id' => null,
            'third_product_group_name' => null,
            'effective_product_group_id' => (int) $group->id,
            'effective_product_group_level' => 2,
            'group_path' => $groupPath,
            'children_count' => (int) ($group->children_count ?? 0),
            'products_count' => (int) ($group->products_count ?? 0),
            'direct_products_count' => (int) ($group->direct_products_count ?? 0),
            'has_children' => (int) ($group->children_count ?? 0) > 0,
            'has_products' => (int) ($group->products_count ?? 0) > 0,
            'status' => (int) $group->is_visible,
            'sort_order' => (int) $group->sort_order,
        ];
    }

    private function thirdGroupPayload(ThirdProductGroup $group): array
    {
        $secondGroup = $group->secondProductGroup;
        $firstGroup = $secondGroup?->firstProductGroup;
        $firstGroupCode = (string) ($firstGroup?->code ?? '');
        $productType = ProductType::businessValueForFirstGroup($firstGroup, $firstGroupCode);
        $firstGroupName = (string) ($firstGroup?->name ?? '');
        $secondGroupName = (string) ($secondGroup?->name ?? '');
        $groupPath = collect([$firstGroupName, $secondGroupName, (string) $group->name])->filter()->implode(' / ');

        return [
            'id' => (int) $group->id,
            'node_key' => 'third:'.(int) $group->id,
            'node_type' => 'third_product_group',
            'name' => (string) $group->name,
            'label' => (string) $group->name,
            'parent_id' => (int) $group->second_product_group_id,
            'parent_level' => 2,
            'level' => 3,
            'product_type' => $productType,
            'product_type_label' => ProductType::businessLabelOf($productType),
            'service_type_code' => $productType,
            'service_type_label' => ProductType::businessLabelOf($productType),
            'first_product_group_id' => (int) ($secondGroup?->first_product_group_id ?? 0),
            'first_product_group_code' => $firstGroupCode,
            'first_product_group_name' => $firstGroupName,
            'second_product_group_id' => (int) $group->second_product_group_id,
            'second_product_group_name' => $secondGroupName,
            'third_product_group_id' => (int) $group->id,
            'third_product_group_name' => (string) $group->name,
            'effective_product_group_id' => (int) $group->id,
            'effective_product_group_level' => 3,
            'group_path' => $groupPath,
            'children_count' => 0,
            'products_count' => (int) ($group->products_count ?? 0),
            'direct_products_count' => (int) ($group->direct_products_count ?? $group->products_count ?? 0),
            'has_children' => false,
            'has_products' => (int) ($group->products_count ?? 0) > 0,
            'status' => (int) $group->is_visible,
            'sort_order' => (int) $group->sort_order,
        ];
    }
}
