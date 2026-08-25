<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InstanceSpecCatalogService
{
    public const SETTING_GROUP = 'product';

    public const SETTING_KEY = 'instance_spec_catalog';

    public function __construct(
        private readonly ?ProductDisplayNameResolver $productDisplayNameResolver = null,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCatalog(string $keyword = '', string $bindingStatus = ''): array
    {
        return $this->filterCatalog(
            $this->normalizeCatalog($this->readStoredCatalog()),
            $keyword,
            $bindingStatus
        );
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
     * @param  array<int, int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    public function resolveProductSpecMap(array $productIds): array
    {
        $normalizedProductIds = collect($productIds)
            ->map(fn ($productId) => (int) $productId)
            ->filter(fn (int $productId) => $productId > 0)
            ->unique()
            ->values()
            ->all();

        if ($normalizedProductIds === []) {
            return [];
        }

        $targetProductIds = array_fill_keys($normalizedProductIds, true);
        $resolved = [];

        foreach ($this->readStoredCatalog() as $spec) {
            if (! is_array($spec) || $this->isSpecHidden($spec)) {
                continue;
            }

            $specText = trim((string) ($spec['text'] ?? $spec['name'] ?? ''));
            if ($specText === '') {
                continue;
            }

            $bindings = is_array($spec['bindings'] ?? null) ? $spec['bindings'] : [];

            foreach ($bindings as $binding) {
                if (! is_array($binding)) {
                    continue;
                }

                $productId = (int) ($binding['product_id'] ?? 0);
                if ($productId <= 0 || ! isset($targetProductIds[$productId])) {
                    continue;
                }

                if (isset($resolved[$productId])) {
                    continue;
                }

                if (! $this->isBindingEnabled($binding)) {
                    continue;
                }

                $resolved[$productId] = [
                    'instance_spec_id' => trim((string) ($spec['id'] ?? '')),
                    'instance_spec_value' => trim((string) ($spec['value'] ?? '')),
                    'instance_spec_text' => $specText,
                    'instance_spec_alias' => trim((string) ($spec['alias'] ?? '')),
                    'instance_spec_note' => trim((string) ($spec['note'] ?? '')),
                    'instance_spec_status' => trim((string) ($spec['status'] ?? '')),
                ];
            }
        }

        return $resolved;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readStoredCatalog(): array
    {
        $storedValue = Setting::getValue(self::SETTING_GROUP, self::SETTING_KEY, '[]');
        $decoded = json_decode((string) $storedValue, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<int, mixed>  $catalog
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCatalog(array $catalog, bool $strict = false): array
    {
        $normalizedItems = [];
        $seenValues = [];

        foreach ($catalog as $index => $item) {
            if (! is_array($item)) {
                if ($strict) {
                    throw new BusinessException('实例规格数据格式不正确');
                }

                continue;
            }

            $text = $this->normalizeLabel((string) ($item['text'] ?? $item['name'] ?? ''), '实例规格文本不能为空');
            $value = $this->normalizeValue((string) ($item['value'] ?? $text), $seenValues);

            if (isset($seenValues[$value])) {
                throw new BusinessException('实例规格文本标识重复，请检查后重试');
            }

            $seenValues[$value] = true;

            $normalizedItems[] = [
                'id' => $this->normalizeId($item['id'] ?? null, $index),
                'value' => $value,
                'text' => $text,
                'alias' => $this->limitText((string) ($item['alias'] ?? ''), 80),
                'note' => $this->limitText((string) ($item['note'] ?? ''), 255),
                'status' => $this->normalizeStatus($item['status'] ?? '仅文本'),
                'sort_order' => $index + 1,
                'bindings' => $this->normalizeBindings($item['bindings'] ?? [], $text, $strict),
            ];
        }

        return array_values($normalizedItems);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBindings(mixed $bindings, string $specText, bool $strict): array
    {
        if ($bindings === null || $bindings === '') {
            return [];
        }

        if (! is_array($bindings)) {
            throw new BusinessException("实例规格「{$specText}」的绑定商品格式不正确");
        }

        $normalizedBindings = [];
        $seenProductIds = [];

        foreach ($bindings as $binding) {
            if (! is_array($binding)) {
                if ($strict) {
                    throw new BusinessException("实例规格「{$specText}」存在无效绑定商品");
                }

                continue;
            }

            $productId = (int) ($binding['product_id'] ?? 0);
            if ($productId <= 0) {
                if ($strict) {
                    throw new BusinessException("实例规格「{$specText}」存在无效绑定商品");
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
    private function normalizeValue(string $value, array $seenValues): string
    {
        $normalized = Str::slug(trim($value), '_');

        if ($normalized === '') {
            $normalized = 'spec_'.Str::lower(Str::random(6));
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
        $text = trim((string) $status);
        if (in_array($text, ['1', '启用', '启用中', '已启用', 'active', 'open'], true)) {
            return 1;
        }

        return 0;
    }

    private function isBindingEnabled(array $binding): bool
    {
        return $this->normalizeBinaryStatus($binding['status'] ?? 0) === 1;
    }

    private function normalizeStatus(mixed $status): string
    {
        $text = trim((string) $status);

        return $text !== '' ? mb_substr($text, 0, 30) : '仅文本';
    }

    private function isSpecHidden(array $spec): bool
    {
        return $this->normalizeStatus($spec['status'] ?? '仅文本') === '隐藏';
    }

    private function limitText(string $value, int $maxLength): string
    {
        return mb_substr(trim($value), 0, $maxLength);
    }

    private function forgetSiteCatalogCache(): void
    {
        // 这里原本清的是自定义常量 'catalog:site:instance_spec:v1'——全仓没有任何写入方，
        // 且不 bump 目录拆分版本号，等于改完实例规格后官网目录页要等 600-900 秒 TTL 才更新。
        app(SiteCatalogCacheInvalidator::class)->flush();
    }

    private function normalizeId(mixed $id, int $index): string
    {
        $candidate = trim((string) $id);
        if ($candidate !== '') {
            return mb_substr($candidate, 0, 80);
        }

        return 'spec_'.Str::lower(Str::random(6)).'_'.($index + 1);
    }

    /**
     * @param  array<int, array<string, mixed>>  $catalog
     * @return array<int, array<string, mixed>>
     */
    private function filterCatalog(array $catalog, string $keyword, string $bindingStatus): array
    {
        $normalizedKeyword = mb_strtolower(trim($keyword));
        $normalizedBindingStatus = trim($bindingStatus);

        return array_values(array_filter($catalog, function (array $item) use ($normalizedKeyword, $normalizedBindingStatus) {
            if ($normalizedBindingStatus === 'bound' && empty($item['bindings'])) {
                return false;
            }

            if ($normalizedBindingStatus === 'unbound' && ! empty($item['bindings'])) {
                return false;
            }

            if ($normalizedKeyword === '') {
                return true;
            }

            $haystacks = [
                (string) ($item['text'] ?? ''),
                (string) ($item['alias'] ?? ''),
                (string) ($item['note'] ?? ''),
                (string) ($item['value'] ?? ''),
            ];

            foreach (($item['bindings'] ?? []) as $binding) {
                if (! is_array($binding)) {
                    continue;
                }

                $haystacks[] = (string) ($binding['display_name'] ?? '');
                $haystacks[] = (string) ($binding['custom_display_name'] ?? '');
                $haystacks[] = (string) ($binding['cpu_memory_display'] ?? '');
                $haystacks[] = (string) ($binding['product_spec_display'] ?? '');
                $haystacks[] = (string) ($binding['category_full_name'] ?? '');
            }

            foreach ($haystacks as $haystack) {
                if (str_contains(mb_strtolower($haystack), $normalizedKeyword)) {
                    return true;
                }
            }

            return false;
        }));
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
