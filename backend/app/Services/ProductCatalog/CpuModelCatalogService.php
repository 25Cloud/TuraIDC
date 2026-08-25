<?php

namespace App\Services\ProductCatalog;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CpuModelCatalogService
{
    public const SETTING_GROUP = 'product';

    public const SETTING_KEY = 'cpu_model_catalog';

    public function __construct(
        private readonly ?ProductDisplayNameResolver $productDisplayNameResolver = null,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCatalog(): array
    {
        $storedValue = Setting::getValue(self::SETTING_GROUP, self::SETTING_KEY, '[]');
        $decoded = json_decode((string) $storedValue, true);

        if (! is_array($decoded)) {
            return [];
        }

        return $this->normalizeCatalog($decoded);
    }

    /**
     * @param  array<int, array<string, mixed>>  $catalog
     * @return array<int, array<string, mixed>>
     */
    public function saveCatalog(array $catalog): array
    {
        $normalized = $this->normalizeCatalog($catalog, true);

        Setting::setValue(
            self::SETTING_GROUP,
            self::SETTING_KEY,
            json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->forgetSiteCatalogCache();

        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $catalog
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCatalog(array $catalog, bool $strict = false): array
    {
        $normalizedGroups = [];
        $seenGroupValues = [];

        foreach ($catalog as $groupIndex => $group) {
            if (! is_array($group)) {
                if ($strict) {
                    throw new BusinessException('CPU 分组数据格式不正确');
                }

                continue;
            }

            $groupName = $this->normalizeLabel((string) ($group['name'] ?? ''), 'CPU 分组名称不能为空');
            $groupValue = $this->normalizeValue(
                (string) ($group['value'] ?? $groupName),
                'cpu_group',
                $seenGroupValues
            );

            if (isset($seenGroupValues[$groupValue])) {
                throw new BusinessException('CPU 分组标识重复，请检查后重试');
            }

            $seenGroupValues[$groupValue] = true;
            $models = $group['models'] ?? [];

            if (! is_array($models)) {
                throw new BusinessException("CPU 分组「{$groupName}」的型号列表格式不正确");
            }

            $normalizedModels = [];
            $seenModelValues = [];

            foreach ($models as $modelIndex => $model) {
                if (! is_array($model)) {
                    if ($strict) {
                        throw new BusinessException("CPU 分组「{$groupName}」存在无效型号数据");
                    }

                    continue;
                }

                $modelName = $this->normalizeLabel((string) ($model['name'] ?? ''), "CPU 分组「{$groupName}」下的型号名称不能为空");
                $modelValue = $this->normalizeValue(
                    (string) ($model['value'] ?? $modelName),
                    'cpu_model',
                    $seenModelValues
                );

                if (isset($seenModelValues[$modelValue])) {
                    throw new BusinessException("CPU 分组「{$groupName}」下存在重复型号");
                }

                $seenModelValues[$modelValue] = true;
                $normalizedBindings = $this->normalizeBindings(
                    $model['bindings'] ?? [],
                    $groupName,
                    $modelName,
                    $strict
                );

                $normalizedModels[] = [
                    'id' => $this->normalizeId($model['id'] ?? null, 'model', $groupIndex, $modelIndex),
                    'value' => $modelValue,
                    'name' => $modelName,
                    'base_frequency' => $this->normalizeFrequency($model['base_frequency'] ?? ''),
                    'turbo_frequency' => $this->normalizeFrequency($model['turbo_frequency'] ?? ''),
                    'sort_order' => $modelIndex + 1,
                    'bindings' => $normalizedBindings,
                ];
            }

            $normalizedGroups[] = [
                'id' => $this->normalizeId($group['id'] ?? null, 'group', $groupIndex, null),
                'value' => $groupValue,
                'name' => $groupName,
                'sort_order' => $groupIndex + 1,
                'model_count' => count($normalizedModels),
                'models' => $normalizedModels,
            ];
        }

        return array_values($normalizedGroups);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBindings(mixed $bindings, string $groupName, string $modelName, bool $strict): array
    {
        if ($bindings === null || $bindings === '') {
            return [];
        }

        if (! is_array($bindings)) {
            throw new BusinessException("CPU 分组「{$groupName}」下的型号「{$modelName}」绑定商品格式不正确");
        }

        $normalizedBindings = [];
        $seenProductIds = [];

        foreach ($bindings as $binding) {
            if (! is_array($binding)) {
                if ($strict) {
                    throw new BusinessException("CPU 分组「{$groupName}」下的型号「{$modelName}」存在无效绑定商品");
                }

                continue;
            }

            $productId = (int) ($binding['product_id'] ?? 0);
            if ($productId <= 0) {
                if ($strict) {
                    throw new BusinessException("CPU 分组「{$groupName}」下的型号「{$modelName}」存在无效绑定商品");
                }

                continue;
            }

            if (isset($seenProductIds[$productId])) {
                continue;
            }

            $seenProductIds[$productId] = true;
            $displayPayload = $this->resolveBindingDisplayPayload($productId);

            $normalizedBindings[] = [
                'product_id' => $productId,
                'display_name' => $displayPayload['display_name'],
                'custom_display_name' => $displayPayload['custom_display_name'],
                'cpu_memory_display' => $displayPayload['cpu_memory_display'],
                'product_spec_display' => $displayPayload['product_spec_display'],
                'combined_display_name' => $displayPayload['combined_display_name'],
                'category_full_name' => $this->limitText((string) ($binding['category_full_name'] ?? ''), 160),
                'primary_price' => $this->normalizeBindingPrice($binding['primary_price'] ?? []),
                'status' => $this->normalizeBinaryStatus($binding['status'] ?? 0),
            ];
        }

        return $normalizedBindings;
    }

    /**
     * @param  array<string, bool>  $seenValues
     */
    private function normalizeValue(string $value, string $prefix, array $seenValues): string
    {
        $normalized = Str::slug(trim($value), '_');

        if ($normalized === '') {
            $normalized = $prefix.'_'.Str::lower(Str::random(6));
        }

        $normalized = Str::limit($normalized, 60, '');
        $candidate = $normalized;
        $suffix = 2;

        while (isset($seenValues[$candidate])) {
            $candidate = Str::limit($normalized.'_'.$suffix, 60, '');
            $suffix++;
        }

        return $candidate;
    }

    private function normalizeLabel(string $value, string $errorMessage): string
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw new BusinessException($errorMessage);
        }

        return mb_substr($normalized, 0, 80);
    }

    /**
     * @return array{cycle: string, amount: string}
     */
    private function normalizeBindingPrice(mixed $price): array
    {
        if (! is_array($price)) {
            return [
                'cycle' => '',
                'amount' => '0.00',
            ];
        }

        $cycle = $this->limitText((string) ($price['cycle'] ?? ''), 40);
        $amount = trim((string) ($price['amount'] ?? '0.00'));

        if ($amount === '') {
            $amount = '0.00';
        } elseif (is_numeric($amount)) {
            $amount = number_format((float) $amount, 2, '.', '');
        } else {
            $amount = $this->limitText($amount, 40);
        }

        return [
            'cycle' => $cycle,
            'amount' => $amount,
        ];
    }

    private function normalizeBinaryStatus(mixed $status): int
    {
        return (int) ((int) $status === 1 ? 1 : 0);
    }

    private function normalizeFrequency(mixed $value): string
    {
        return $this->limitText((string) $value, 40);
    }

    private function limitText(string $value, int $maxLength): string
    {
        return mb_substr(trim($value), 0, $maxLength);
    }

    private function forgetSiteCatalogCache(): void
    {
        // 这里原本自带一份实现，漏掉了管理端目录概览（catalog:admin_summary:v1）的失效，
        // 改完 CPU 型号后管理端概览会继续读旧值。统一走唯一入口。
        app(SiteCatalogCacheInvalidator::class)->flush();
    }

    private function normalizeId(mixed $id, string $prefix, int $primaryIndex, ?int $secondaryIndex): string
    {
        $candidate = trim((string) $id);

        if ($candidate !== '') {
            return mb_substr($candidate, 0, 80);
        }

        $tail = $secondaryIndex === null ? (string) ($primaryIndex + 1) : ($primaryIndex + 1).'_'.($secondaryIndex + 1);

        return $prefix.'_'.Str::lower(Str::random(6)).'_'.$tail;
    }

    /**
     * @return array{
     *     display_name: string,
     *     custom_display_name: string,
     *     cpu_memory_display: string,
     *     product_spec_display: string,
     *     combined_display_name: string
     * }
     */
    private function resolveBindingDisplayPayload(int $productId): array
    {
        $fallback = $productId > 0 ? '未配置规格 #'.$productId : '';
        if ($productId <= 0) {
            return $this->emptyBindingDisplayPayload($fallback);
        }

        if (! Schema::hasTable('products')) {
            return $this->emptyBindingDisplayPayload($fallback);
        }

        $product = Product::query()
            ->select(['id', 'purchase_requires', 'config_options'])
            ->find($productId);

        if (! $product instanceof Product) {
            return $this->emptyBindingDisplayPayload($fallback);
        }

        $resolver = $this->productDisplayNameResolver ?? new ProductDisplayNameResolver;
        $resolved = $resolver->resolveForProduct($product);
        $customDisplayName = trim((string) ($resolved['custom_display_name'] ?? ''));
        $productSpecDisplay = trim((string) ($resolved['product_spec_display'] ?? ''));
        $cpuMemoryDisplay = trim((string) ($resolved['cpu_memory_display'] ?? ''));
        $combinedDisplayName = trim((string) ($resolved['combined_display_name'] ?? ''));
        $displayName = $customDisplayName
            ?: ($productSpecDisplay ?: ($cpuMemoryDisplay ?: ($combinedDisplayName ?: $fallback)));

        return [
            'display_name' => $displayName,
            'custom_display_name' => $customDisplayName,
            'cpu_memory_display' => $cpuMemoryDisplay,
            'product_spec_display' => $productSpecDisplay,
            'combined_display_name' => $combinedDisplayName,
        ];
    }

    /**
     * @return array{
     *     display_name: string,
     *     custom_display_name: string,
     *     cpu_memory_display: string,
     *     product_spec_display: string,
     *     combined_display_name: string
     * }
     */
    private function emptyBindingDisplayPayload(string $fallback): array
    {
        return [
            'display_name' => $fallback,
            'custom_display_name' => '',
            'cpu_memory_display' => $fallback,
            'product_spec_display' => '',
            'combined_display_name' => '',
        ];
    }
}
