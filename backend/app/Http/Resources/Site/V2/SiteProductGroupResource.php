<?php

declare(strict_types=1);

namespace App\Http\Resources\Site\V2;

use App\Constants\ProductType;
use App\Models\FirstProductGroup;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteProductGroupResource extends JsonResource
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
        $directProductCount = (int) ($this->resource->direct_products_count ?? 0);
        $childProductCount = (int) ($this->resource->child_products_count ?? 0);
        $productCount = (int) ($this->resource->product_count ?? $this->resource->products_count ?? ($directProductCount + $childProductCount));

        return [
            'id' => (int) $this->resource->id,
            'parent_id' => $this->parentId(),
            'product_type' => $productType,
            'product_type_id' => $firstGroup?->id ? (int) $firstGroup->id : ProductType::routeIdOf($firstGroupCode),
            'product_type_label' => ProductType::businessLabelOf($productType),
            'first_product_group_id' => $firstGroup?->id ? (int) $firstGroup->id : null,
            'first_product_group_code' => $firstGroupCode,
            'first_product_group_name' => $firstGroup?->name,
            'second_product_group_id' => $secondGroup?->id ? (int) $secondGroup->id : null,
            'second_product_group_name' => $secondGroup?->name,
            'second_product_group_parent_id' => $secondGroup?->first_product_group_id ? (int) $secondGroup->first_product_group_id : null,
            'second_product_group_parent_name' => $firstGroup?->name,
            'third_product_group_id' => $thirdGroup?->id ? (int) $thirdGroup->id : null,
            'third_product_group_name' => $thirdGroup?->name,
            'effective_product_group_id' => (int) $this->resource->id,
            'effective_product_group_level' => $level,
            'service_type_code' => $productType,
            'name' => (string) ($this->resource->name ?? ''),
            'slogan' => (string) ($this->resource->description ?? ''),
            'slug' => (string) ($this->resource->slug ?? ''),
            'children_count' => (int) ($this->resource->children_count ?? 0),
            'direct_product_count' => $directProductCount,
            'product_count' => $productCount,
        ];
    }

    private function level(): int
    {
        return match (true) {
            $this->resource instanceof FirstProductGroup => 1,
            $this->resource instanceof SecondProductGroup => 2,
            $this->resource instanceof ThirdProductGroup => 3,
            default => 0,
        };
    }

    private function parentId(): ?int
    {
        return match (true) {
            $this->resource instanceof SecondProductGroup => (int) $this->resource->first_product_group_id,
            $this->resource instanceof ThirdProductGroup => (int) $this->resource->second_product_group_id,
            default => null,
        };
    }

    private function firstGroup(): ?FirstProductGroup
    {
        if ($this->resource instanceof FirstProductGroup) {
            return $this->resource;
        }

        if ($this->resource instanceof SecondProductGroup) {
            return $this->resource->relationLoaded('firstProductGroup') ? $this->resource->firstProductGroup : null;
        }

        $secondGroup = $this->secondGroup();

        return $secondGroup?->relationLoaded('firstProductGroup') ? $secondGroup->firstProductGroup : null;
    }

    private function secondGroup(): ?SecondProductGroup
    {
        if ($this->resource instanceof SecondProductGroup) {
            return $this->resource;
        }

        if ($this->resource instanceof ThirdProductGroup) {
            return $this->resource->relationLoaded('secondProductGroup') ? $this->resource->secondProductGroup : null;
        }

        return null;
    }

    private function thirdGroup(): ?ThirdProductGroup
    {
        return $this->resource instanceof ThirdProductGroup ? $this->resource : null;
    }
}
