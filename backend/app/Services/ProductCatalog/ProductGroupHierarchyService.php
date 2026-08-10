<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Constants\ProductType;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductGroupHierarchyService
{
    public function tablesReady(): bool
    {
        return Schema::hasTable('first_product_groups')
            && Schema::hasTable('second_product_groups')
            && Schema::hasTable('third_product_groups');
    }

    public function productColumnsReady(): bool
    {
        return Schema::hasTable('products') && Schema::hasColumn('products', 'product_group_id');
    }

    public function syncProductTypes(): array
    {
        if (! $this->tablesReady()) {
            return ['synced_count' => 0, 'hidden_count' => 0];
        }

        $items = ProductType::items();
        $codes = [];
        $synced = 0;

        foreach ($items as $index => $item) {
            $code = $this->normalizeProductTypeCode($item['value'] ?? '');
            if ($code === '') {
                continue;
            }

            $codes[] = $code;
            $this->ensureFirstProductGroup($code, $item, $index + 1);
            $synced++;
        }

        $hidden = 0;
        if ($codes !== []) {
            $hidden = FirstProductGroup::query()
                ->whereNotIn('code', $codes)
                ->update(['is_visible' => 0]);
        }

        return [
            'synced_count' => $synced,
            'hidden_count' => $hidden,
        ];
    }

    public function checkHierarchy(): array
    {
        $blockingErrors = [];
        $warnings = [];

        if (! $this->tablesReady()) {
            $blockingErrors[] = '三层商品分类表尚未全部创建';
        }

        $rootCount = $this->tablesReady() ? FirstProductGroup::query()->count() : 0;
        $secondCount = $this->tablesReady() ? SecondProductGroup::query()->count() : 0;
        $thirdCount = $this->tablesReady() ? ThirdProductGroup::query()->count() : 0;
        $orphanSecondCount = $this->tablesReady()
            ? SecondProductGroup::query()
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('first_product_groups')
                    ->whereColumn('first_product_groups.id', 'second_product_groups.first_product_group_id'))
                ->count()
            : 0;
        $orphanThirdCount = $this->tablesReady()
            ? ThirdProductGroup::query()
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('second_product_groups')
                    ->whereColumn('second_product_groups.id', 'third_product_groups.second_product_group_id'))
                ->count()
            : 0;
        $missingProductHierarchyCount = 0;
        $orphanProductGroupCount = 0;

        if (! $this->productColumnsReady()) {
            $warnings[] = 'products.product_group_id 尚不存在';
        } else {
            $missingProductHierarchyCount = Product::withTrashed()->whereNull('product_group_id')->count();
            $orphanProductGroupCount = Product::withTrashed()
                ->whereNotNull('product_group_id')
                ->whereNotIn('product_group_id', ThirdProductGroup::query()->select('id'))
                ->count();
        }

        foreach ([
            $orphanSecondCount => "存在 {$orphanSecondCount} 个二级分类找不到一级归属",
            $orphanThirdCount => "存在 {$orphanThirdCount} 个三级分类找不到二级归属",
            $missingProductHierarchyCount => "存在 {$missingProductHierarchyCount} 个商品缺少 product_group_id 挂载字段",
            $orphanProductGroupCount => "存在 {$orphanProductGroupCount} 个商品引用了不存在的三级分类",
        ] as $count => $message) {
            if ($count > 0) {
                $blockingErrors[] = $message;
            }
        }

        return [
            'blocking_errors' => $blockingErrors,
            'warnings' => $warnings,
            'counts' => [
                'root_product_groups' => $rootCount,
                'second_product_groups' => $secondCount,
                'third_product_groups' => $thirdCount,
                'orphan_second_product_groups' => $orphanSecondCount,
                'orphan_third_product_groups' => $orphanThirdCount,
                'missing_product_hierarchy' => $missingProductHierarchyCount,
                'orphan_product_group_products' => $orphanProductGroupCount,
            ],
        ];
    }

    public function ensureFirstProductGroupForType(string $code): ?FirstProductGroup
    {
        return $this->ensureFirstProductGroup($code);
    }

    private function ensureFirstProductGroup(string $code, ?array $item = null, int $sortOrder = 0): ?FirstProductGroup
    {
        if (! $this->tablesReady()) {
            return null;
        }

        $code = $this->normalizeProductTypeCode($code);
        if ($code === '') {
            $code = ProductType::OTHER;
        }

        $item ??= $this->productTypeItem($code);
        $existing = FirstProductGroup::query()->where('code', $code)->first();
        $label = trim((string) ($item['label'] ?? ProductType::labelOf($code)));
        $slug = $this->uniqueSlug(
            FirstProductGroup::class,
            Str::slug($code) ?: $code,
            [],
            $existing?->id
        );
        $payload = [
            'name' => $label !== '' ? $label : $code,
            'slug' => $slug,
            'icon' => $this->nullableString($item['icon'] ?? null),
            'sort_order' => $sortOrder > 0 ? $sortOrder : $this->productTypeSortOrder($code),
            'is_visible' => (bool) ($item['is_hidden'] ?? false) ? 0 : 1,
            'is_system' => (bool) ($item['is_builtin'] ?? false) ? 1 : 0,
            'product_type' => $this->resolveFirstGroupProductType($code, $item),
        ];

        /** @var FirstProductGroup $group */
        $group = FirstProductGroup::query()->updateOrCreate(
            ['code' => $code],
            $payload
        );

        return $group;
    }

    private function productTypeItem(string $code): array
    {
        foreach (ProductType::items() as $item) {
            if ((string) ($item['value'] ?? '') === $code) {
                return $item;
            }
        }

        return [
            'value' => $code,
            'label' => ProductType::labelOf($code),
            'icon' => '',
            'is_builtin' => false,
            'is_hidden' => false,
        ];
    }

    private function resolveFirstGroupProductType(string $code, ?array $item): string
    {
        if (is_array($item) && array_key_exists('product_type', $item)) {
            return ProductType::normalizeBusinessValue($item['product_type']);
        }

        return ProductType::normalizeBusinessValueFromMenuCode($item['value'] ?? $code);
    }

    private function productTypeSortOrder(string $code): int
    {
        foreach (array_values(ProductType::items()) as $index => $item) {
            if ((string) ($item['value'] ?? '') === $code) {
                return $index + 1;
            }
        }

        return 999;
    }

    private function uniqueSlug(string $modelClass, string $baseSlug, array $scope, ?int $ignoreId = null): string
    {
        $slug = Str::slug($baseSlug);
        if ($slug === '') {
            $slug = 'group';
        }

        $candidate = $slug;
        $suffix = 1;

        while ($this->slugExists($modelClass, $candidate, $scope, $ignoreId)) {
            $suffix++;
            $candidate = $slug.'-'.$suffix;
        }

        return $candidate;
    }

    private function slugExists(string $modelClass, string $slug, array $scope, ?int $ignoreId): bool
    {
        /** @var class-string<Model> $modelClass */
        $query = $modelClass::query()->where('slug', $slug);

        foreach ($scope as $column => $value) {
            $query->where($column, $value);
        }

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }

    private function normalizeProductTypeCode(mixed $value): string
    {
        return trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
