<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Constants\ProductType;
use App\Models\FirstProductGroup;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductGroupListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $level = $this->level();
        $firstGroup = $this->firstGroup();
        $secondGroup = $this->secondGroup();
        $thirdGroup = $this->thirdGroup();
        $firstGroupCode = (string) ($firstGroup?->code ?? '');
        $productType = ProductType::businessValueForFirstGroup($firstGroup, $firstGroupCode);
        $name = (string) ($this->resource->name ?? '');

        return [
            'id' => (int) $this->resource->id,
            'node_type' => 'product_group',
            'name' => $name,
            'label' => $name,
            'parent_id' => $this->parentId(),
            'parent_level' => $level > 1 ? $level - 1 : null,
            'level' => $level,
            'product_type' => $productType,
            'product_type_label' => ProductType::businessLabelOf($productType),
            'service_type_code' => $productType,
            'service_type_label' => ProductType::businessLabelOf($productType),
            'slug' => (string) ($this->resource->slug ?? ''),
            'first_product_group_id' => $firstGroup?->id ? (int) $firstGroup->id : null,
            'first_product_group_code' => $firstGroupCode,
            'first_product_group_name' => $firstGroup?->name,
            'second_product_group_id' => $secondGroup?->id ? (int) $secondGroup->id : null,
            'second_product_group_name' => $secondGroup?->name,
            'third_product_group_id' => $thirdGroup?->id ? (int) $thirdGroup->id : null,
            'third_product_group_name' => $thirdGroup?->name,
            'effective_product_group_id' => (int) $this->resource->id,
            'effective_product_group_level' => $level,
            'children_count' => (int) ($this->resource->children_count ?? 0),
            'products_count' => (int) ($this->resource->products_count ?? $this->resource->product_count ?? 0),
            'direct_products_count' => (int) ($this->resource->direct_products_count ?? $this->resource->products_count ?? 0),
            'status' => (int) ($this->resource->is_visible ?? 0),
            'sort_order' => (int) ($this->resource->sort_order ?? 0),
        ];
    }

    protected function level(): int
    {
        return match (true) {
            $this->resource instanceof FirstProductGroup => 1,
            $this->resource instanceof SecondProductGroup => 2,
            $this->resource instanceof ThirdProductGroup => 3,
            default => 0,
        };
    }

    protected function parentId(): ?int
    {
        return match (true) {
            $this->resource instanceof SecondProductGroup => (int) $this->resource->first_product_group_id,
            $this->resource instanceof ThirdProductGroup => (int) $this->resource->second_product_group_id,
            default => null,
        };
    }

    protected function firstGroup(): ?FirstProductGroup
    {
        if ($this->resource instanceof FirstProductGroup) {
            return $this->resource;
        }

        if ($this->resource instanceof SecondProductGroup) {
            return $this->resource->relationLoaded('firstProductGroup') ? $this->resource->firstProductGroup : null;
        }

        if ($this->resource instanceof ThirdProductGroup) {
            $secondGroup = $this->secondGroup();

            return $secondGroup?->relationLoaded('firstProductGroup') ? $secondGroup->firstProductGroup : null;
        }

        return null;
    }

    protected function secondGroup(): ?SecondProductGroup
    {
        if ($this->resource instanceof SecondProductGroup) {
            return $this->resource;
        }

        if ($this->resource instanceof ThirdProductGroup) {
            return $this->resource->relationLoaded('secondProductGroup') ? $this->resource->secondProductGroup : null;
        }

        return null;
    }

    protected function thirdGroup(): ?ThirdProductGroup
    {
        return $this->resource instanceof ThirdProductGroup ? $this->resource : null;
    }
}
