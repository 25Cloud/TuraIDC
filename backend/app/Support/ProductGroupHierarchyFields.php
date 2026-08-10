<?php

declare(strict_types=1);

namespace App\Support;

use App\Constants\ProductType;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;

class ProductGroupHierarchyFields
{
    public static function fromProduct(Product $product): array
    {
        [$first, $second, $third] = $product->resolvedProductGroupHierarchy();

        $firstId = $first instanceof FirstProductGroup ? (int) $first->id : null;
        $secondId = $second instanceof SecondProductGroup ? (int) $second->id : null;
        $thirdId = $third instanceof ThirdProductGroup ? (int) $third->id : null;
        $fallbackType = trim((string) ($product->getRawOriginal('product_type') ?: ''));
        $productType = ProductType::businessValueForFirstGroup($first, $fallbackType);
        $firstCode = trim((string) ($first?->code ?? ''));
        $firstName = trim((string) ($first?->name ?? ProductType::labelOf($firstCode)));
        $secondName = trim((string) ($second?->name ?? ''));
        $thirdName = $thirdId !== null ? trim((string) ($third?->name ?? '')) : null;
        $secondDescription = trim((string) ($second?->description ?? ''));
        $thirdDescription = $thirdId !== null ? trim((string) ($third?->description ?? '')) : null;

        return [
            'first_product_group_id' => $firstId,
            'first_product_group_code' => $firstCode,
            'first_product_group_name' => $firstName,
            'second_product_group_id' => $secondId,
            'second_product_group_name' => $secondName,
            'second_product_group_description' => $secondDescription,
            'second_product_group_parent_id' => $firstId,
            'second_product_group_parent_name' => $firstName,
            'third_product_group_id' => $thirdId,
            'third_product_group_name' => $thirdName,
            'third_product_group_description' => $thirdDescription,
            'effective_product_group_id' => $thirdId ?? $secondId,
            'effective_product_group_level' => $thirdId !== null ? 3 : ($secondId !== null ? 2 : null),
            'product_type' => $productType,
            'product_type_label' => ProductType::businessLabelOf($productType),
            'service_type_code' => $productType,
        ];
    }

    private static function empty(): array
    {
        return [
            'first_product_group_id' => null,
            'first_product_group_code' => '',
            'first_product_group_name' => '',
            'second_product_group_id' => null,
            'second_product_group_name' => '',
            'second_product_group_parent_id' => null,
            'second_product_group_parent_name' => '',
            'third_product_group_id' => null,
            'third_product_group_name' => null,
            'effective_product_group_id' => null,
            'effective_product_group_level' => null,
        ];
    }
}
