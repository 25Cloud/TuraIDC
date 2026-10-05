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
    /**
     * 层级的中文名，站点与管理端共用一套，避免各端自己造词导致层级又对不上。
     */
    public static function levelLabel(int $level): string
    {
        return match ($level) {
            1 => '一级分类',
            2 => '二级分类',
            3 => '三级分类',
            default => '未分类',
        };
    }

    /**
     * 一级 → 二级 → 三级的完整路径，用于面包屑与层级提示。
     * 缺哪一级就只返回到已有层级，绝不用父级名称顶替缺失层级。
     *
     * @return array<int, array{level: int, level_label: string, id: int, code: string, name: string}>
     */
    public static function path(
        ?FirstProductGroup $first = null,
        ?SecondProductGroup $second = null,
        ?ThirdProductGroup $third = null,
    ): array {
        $nodes = [];

        if ($first instanceof FirstProductGroup) {
            $nodes[] = [1, $first, (string) ($first->code ?? '')];
        }

        if ($second instanceof SecondProductGroup) {
            $nodes[] = [2, $second, (string) ($second->slug ?? '')];
        }

        if ($third instanceof ThirdProductGroup) {
            $nodes[] = [3, $third, (string) ($third->slug ?? '')];
        }

        return array_map(
            static fn (array $node): array => [
                'level' => $node[0],
                'level_label' => self::levelLabel($node[0]),
                'id' => (int) $node[1]->id,
                'code' => $node[2],
                'name' => trim((string) ($node[1]->name ?? '')),
            ],
            $nodes
        );
    }

    /**
     * @param  array<int, array{level: int, level_label: string, id: int, code: string, name: string}>  $path
     */
    public static function pathText(array $path): string
    {
        return collect($path)
            ->map(static fn (array $node): string => (string) ($node['name'] ?? ''))
            ->filter(static fn (string $name): bool => $name !== '')
            ->implode(' / ');
    }

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
        $effectiveLevel = $thirdId !== null ? 3 : ($secondId !== null ? 2 : null);
        $groupPath = self::path($first, $second, $third);

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
            'effective_product_group_level' => $effectiveLevel,
            'group_level_label' => $effectiveLevel === null ? self::levelLabel(0) : self::levelLabel($effectiveLevel),
            'group_path' => $groupPath,
            'group_path_text' => self::pathText($groupPath),
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
            'group_level_label' => self::levelLabel(0),
            'group_path' => [],
            'group_path_text' => '',
        ];
    }
}
