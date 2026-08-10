<?php

namespace App\Services\ProductCatalog;

use App\Constants\ProductType;
use App\Exceptions\BusinessException;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use Illuminate\Support\Facades\Schema;

class ProductTypeService
{
    private readonly ProductGroupHierarchyService $hierarchyService;

    public function __construct(?ProductGroupHierarchyService $hierarchyService = null)
    {
        $this->hierarchyService = $hierarchyService ?? app(ProductGroupHierarchyService::class);
    }

    public function list(): array
    {
        $this->hierarchyService->syncProductTypes();

        $items = ProductType::items();
        $values = array_values(array_map(fn (array $item): string => (string) $item['value'], $items));
        $firstGroups = Schema::hasTable('first_product_groups')
            ? FirstProductGroup::query()->whereIn('code', $values)->get()->keyBy('code')
            : collect();
        $usageMap = Schema::hasTable('products')
            && Schema::hasTable('third_product_groups')
            && Schema::hasTable('second_product_groups')
            && Schema::hasTable('first_product_groups')
            ? Product::query()
                ->join('third_product_groups', 'third_product_groups.id', '=', 'products.product_group_id')
                ->join('second_product_groups', 'second_product_groups.id', '=', 'third_product_groups.second_product_group_id')
                ->join('first_product_groups', 'first_product_groups.id', '=', 'second_product_groups.first_product_group_id')
                ->selectRaw('first_product_groups.code as product_type, COUNT(products.id) as total')
                ->groupBy('first_product_groups.code')
                ->pluck('total', 'product_type')
                ->all()
            : [];

        $secondGroupUsageMap = Schema::hasTable('second_product_groups') && Schema::hasTable('first_product_groups')
            ? SecondProductGroup::query()
                ->join('first_product_groups', 'first_product_groups.id', '=', 'second_product_groups.first_product_group_id')
                ->selectRaw('first_product_groups.code as product_type, COUNT(second_product_groups.id) as total')
                ->groupBy('first_product_groups.code')
                ->pluck('total', 'product_type')
                ->all()
            : [];

        $thirdGroupUsageMap = Schema::hasTable('third_product_groups') && Schema::hasTable('second_product_groups') && Schema::hasTable('first_product_groups')
            ? ThirdProductGroup::query()
                ->join('second_product_groups', 'second_product_groups.id', '=', 'third_product_groups.second_product_group_id')
                ->join('first_product_groups', 'first_product_groups.id', '=', 'second_product_groups.first_product_group_id')
                ->selectRaw('first_product_groups.code as product_type, COUNT(third_product_groups.id) as total')
                ->groupBy('first_product_groups.code')
                ->pluck('total', 'product_type')
                ->all()
            : [];

        $groupUsageMap = [];
        foreach ($values as $value) {
            $groupUsageMap[$value] = (int) ($secondGroupUsageMap[$value] ?? 0) + (int) ($thirdGroupUsageMap[$value] ?? 0);
        }

        return array_map(function (array $item, int $index) use ($usageMap, $groupUsageMap, $firstGroups) {
            $value = (string) $item['value'];
            $firstGroup = $firstGroups->get($value);
            $businessType = ProductType::normalizeBusinessValue(
                $firstGroup instanceof FirstProductGroup
                    ? ($firstGroup->getAttribute('product_type') ?: ($item['product_type'] ?? $value))
                    : ($item['product_type'] ?? $value)
            );

            return [
                'internal_id' => (int) ($item['internal_id'] ?? 0),
                'value' => $value,
                'label' => (string) $item['label'],
                'product_type' => $businessType,
                'product_type_label' => ProductType::businessLabelOf($businessType),
                'product_type_icon' => ProductType::businessIconOf($businessType),
                'product_type_plugin_driven' => ProductType::isPluginDriven($businessType),
                'first_product_group_id' => $firstGroup instanceof FirstProductGroup ? (int) $firstGroup->id : null,
                'first_product_group_code' => $value,
                'first_product_group_name' => $firstGroup instanceof FirstProductGroup ? (string) $firstGroup->name : (string) $item['label'],
                'icon' => (string) ($item['icon'] ?? ''),
                'is_builtin' => (bool) ($item['is_builtin'] ?? false),
                'is_hidden' => (bool) ($item['is_hidden'] ?? false),
                'sort_order' => $index + 1,
                'usage_count' => (int) ($usageMap[$value] ?? 0),
                'group_count' => (int) ($groupUsageMap[$value] ?? 0),
            ];
        }, $items, array_keys($items));
    }

    public function create(string $label, ?string $icon = null, ?string $productType = null): array
    {
        $normalizedLabel = $this->normalizeLabel($label);
        $normalizedIcon = $this->normalizeIcon($icon);
        $normalizedProductType = ProductType::normalizeBusinessValue($productType);
        $items = ProductType::items();

        foreach ($items as $item) {
            if ((string) $item['label'] === $normalizedLabel) {
                throw new BusinessException('商品种类名称已存在');
            }
        }

        $existingValues = array_map(
            fn (array $item) => (string) $item['value'],
            $items
        );

        $baseValue = ProductType::normalizeValue($normalizedLabel);
        $value = $baseValue;
        $suffix = 1;

        while (in_array($value, $existingValues, true)) {
            $suffix++;
            $value = ProductType::normalizeValue($baseValue.'_'.$suffix);
        }

        $nextInternalId = collect($items)
            ->map(fn (array $item) => (int) ($item['internal_id'] ?? 0))
            ->max() + 1;

        $items[] = [
            'internal_id' => max(1, $nextInternalId),
            'value' => $value,
            'label' => $normalizedLabel,
            'product_type' => $normalizedProductType,
            'icon' => $normalizedIcon,
            'is_builtin' => false,
            'is_hidden' => false,
        ];

        ProductType::saveItems($items);
        $this->hierarchyService->syncProductTypes();

        return $this->findOrFail($value);
    }

    public function update(string $value, string $label, ?bool $isHidden = null, ?string $icon = null, ?string $productType = null): array
    {
        $normalizedValue = trim($value);
        $normalizedLabel = $this->normalizeLabel($label);
        $normalizedIcon = $this->normalizeIcon($icon);
        $normalizedProductType = $productType !== null
            ? ProductType::normalizeBusinessValue($productType)
            : null;
        $items = ProductType::items();
        $matched = false;

        foreach ($items as $index => $item) {
            if ((string) $item['value'] !== $normalizedValue) {
                if ((string) $item['label'] === $normalizedLabel) {
                    throw new BusinessException('商品种类名称已存在');
                }

                continue;
            }

            $items[$index]['label'] = $normalizedLabel;
            $items[$index]['is_hidden'] = $isHidden ?? (bool) ($item['is_hidden'] ?? false);
            $items[$index]['icon'] = $normalizedIcon;
            $items[$index]['product_type'] = $normalizedProductType ?? ProductType::normalizeBusinessValue($item['product_type'] ?? $item['value'] ?? $normalizedValue);
            $matched = true;
        }

        if (! $matched) {
            throw new BusinessException('商品种类不存在', 40400, 404);
        }

        ProductType::saveItems($items);
        $this->hierarchyService->syncProductTypes();

        return $this->findOrFail($normalizedValue);
    }

    public function delete(string $value): void
    {
        $normalizedValue = trim($value);
        $items = ProductType::items();
        $matched = collect($items)->first(
            fn (array $item) => (string) $item['value'] === $normalizedValue
        );

        if (! $matched) {
            throw new BusinessException('商品种类不存在', 40400, 404);
        }

        $firstGroup = FirstProductGroup::query()
            ->where('code', $normalizedValue)
            ->first();
        $firstGroupId = $firstGroup instanceof FirstProductGroup ? (int) $firstGroup->id : 0;

        $secondGroupCount = $firstGroupId > 0
            ? SecondProductGroup::query()->where('first_product_group_id', $firstGroupId)->count()
            : 0;
        $thirdGroupCount = $firstGroupId > 0
            ? ThirdProductGroup::query()
                ->join('second_product_groups', 'second_product_groups.id', '=', 'third_product_groups.second_product_group_id')
                ->where('second_product_groups.first_product_group_id', $firstGroupId)
                ->count()
            : 0;
        $groupCount = $secondGroupCount + $thirdGroupCount;
        if ($groupCount > 0) {
            throw new BusinessException("该种类下仍有 {$groupCount} 个分组，无法删除");
        }

        $usageCount = $firstGroupId > 0
            ? Product::query()->inFirstProductGroup($firstGroupId)->count()
            : 0;
        if ($usageCount > 0) {
            throw new BusinessException("该种类下仍有 {$usageCount} 个商品，无法删除");
        }

        if (count($items) <= 1) {
            throw new BusinessException('至少保留一个商品种类');
        }

        $items = array_values(array_filter(
            $items,
            fn (array $item) => (string) $item['value'] !== $normalizedValue
        ));

        ProductType::saveItems($items);
        $this->hierarchyService->syncProductTypes();
    }

    public function reorder(array $values): array
    {
        $normalizedValues = collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values();

        $items = ProductType::items();
        $currentValues = collect($items)->pluck('value')->map(fn ($value) => (string) $value)->values();

        if (
            $normalizedValues->count() !== $currentValues->count()
            || $normalizedValues->sort()->values()->all() !== $currentValues->sort()->values()->all()
        ) {
            throw new BusinessException('商品种类列表已发生变化，请刷新后重新拖动排序');
        }

        $itemMap = collect($items)->keyBy(fn (array $item) => (string) $item['value']);
        $reorderedItems = $normalizedValues
            ->map(fn (string $value) => $itemMap->get($value))
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();

        ProductType::saveItems($reorderedItems);
        $this->hierarchyService->syncProductTypes();

        return $this->list();
    }

    private function findOrFail(string $value): array
    {
        $matched = collect($this->list())->first(
            fn (array $item) => (string) $item['value'] === $value
        );

        if (! $matched) {
            throw new BusinessException('商品种类不存在', 40400, 404);
        }

        return $matched;
    }

    private function normalizeLabel(string $label): string
    {
        $normalizedLabel = trim($label);

        if ($normalizedLabel === '') {
            throw new BusinessException('商品种类名称不能为空');
        }

        return mb_substr($normalizedLabel, 0, 30);
    }

    private function normalizeIcon(?string $icon): string
    {
        return mb_substr(trim((string) $icon), 0, 50);
    }
}
