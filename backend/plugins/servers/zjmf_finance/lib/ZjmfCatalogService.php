<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ZjmfCatalogService
{
    private const CONFIG_OPTION_FIELD_MAP = [
        1 => null,
        2 => null,
        3 => null,
        4 => 'ip_num',
        5 => 'os',
        6 => 'cpu',
        7 => 'cpu',
        8 => 'memory',
        9 => 'memory',
        10 => 'bw',
        11 => 'bw',
        12 => 'area',
        13 => 'system_disk_size',
        14 => 'system_disk_size',
        15 => null,
        16 => 'cpu',
        17 => 'memory',
        18 => 'bw',
        19 => 'system_disk_size',
        20 => null,
    ];

    private const RANGE_OPTION_TYPES = [4, 7, 9, 11, 14, 15, 16, 17, 18, 19];

    private const CONFIG_PRICING_CYCLE_MAP = [
        'hour' => 'hour',
        'day' => 'day',
        'ontrial' => 'ontrial',
        'monthly' => 'monthly',
        'quarterly' => 'quarterly',
        'semiannually' => 'semiannually',
        'annually' => 'annually',
        'biennially' => 'biennially',
        'triennially' => 'triennially',
        'fourly' => 'fourly',
        'fively' => 'fively',
        'sixly' => 'sixly',
        'sevenly' => 'sevenly',
        'eightly' => 'eightly',
        'ninely' => 'ninely',
        'tenly' => 'tenly',
        'onetime' => 'one_time',
        'one_time' => 'one_time',
    ];

    public function __construct(
        private readonly ZjmfFinanceTransport $transport,
        private readonly ZjmfCloudConfigTemplate $cloudConfigTemplate,
        private readonly ZjmfProductTypeMapper $productTypeMapper = new ZjmfProductTypeMapper,
    ) {}

    public function getProductCatalog(Supplier $supplier): array
    {
        $response = $this->transport->get($supplier, '/cart/all', $this->transport->login($supplier));

        return $this->normalizeProductCatalog($response);
    }

    /**
     * 批量对接时按需补充选中商品的价格（ZJMF 的 /cart/all 列表不含价格，
     * 价格在 /cart/get_product_config 的 product_pricings 中）。
     *
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<int, int>  $selectedIds
     * @return array<int, array<string, mixed>> 回填价格后的商品列表
     */
    public function hydrateSelectedPricing(Supplier $supplier, array $products, array $selectedIds): array
    {
        $selectedIdSet = collect($selectedIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->flip();

        if ($selectedIdSet->isEmpty()) {
            return $products;
        }

        $productsById = collect($products)
            ->filter(fn (array $product): bool => $selectedIdSet->has((int) ($product['id'] ?? 0)))
            ->keyBy(fn (array $product): int => (int) ($product['id'] ?? 0))
            ->all();

        if ($productsById === []) {
            return $products;
        }

        $pricingMap = $this->fetchBatchProductPricing($supplier, array_keys($productsById));

        return collect($products)
            ->map(function (array $product) use ($pricingMap): array {
                $productId = (int) ($product['id'] ?? 0);
                $pricing = $pricingMap[$productId] ?? null;

                if (! is_array($pricing) || $pricing === []) {
                    return $product;
                }

                return array_replace($product, [
                    'product_price' => $pricing['monthly_price'] ?? $pricing['product_price'] ?? $product['product_price'] ?? null,
                    'monthly_price' => $pricing['monthly_price'] ?? $product['monthly_price'] ?? null,
                    'billingcycle' => trim((string) ($pricing['billingcycle'] ?? $product['billingcycle'] ?? '')) !== ''
                        ? (string) $pricing['billingcycle']
                        : $product['billingcycle'] ?? '',
                    'setup_fee' => $pricing['setup_fee'] ?? $product['setup_fee'] ?? null,
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchBatchProductPricing(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        $chunkSize = max(1, min($chunkSize, 12));
        $results = [];

        foreach (array_chunk($productIds, $chunkSize) as $chunk) {
            foreach ($chunk as $productId) {
                try {
                    // 使用串行 requestWithMeta（含 WAF 挑战页 cookie 自动重试），
                    // parallelGet 走 Http::pool 不经过该逻辑，会被上游 WAF 拦截。
                    $response = $this->transport->get(
                        $supplier,
                        '/cart/get_product_config',
                        null,
                        ['pid' => $productId],
                    );
                    $pricing = is_array($response) ? $this->extractProductPricingFromResponse($response) : [];
                    if ($pricing !== []) {
                        $results[$productId] = $pricing;
                    }
                } catch (\Throwable $exception) {
                    Log::warning('[ZJMF 财务接口] 单个商品价格补充失败', [
                        'supplier_id' => $supplier->id,
                        'product_id' => $productId,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $results;
    }

    /**
     * 从 /cart/get_product_config 响应中提取商品基础价格（product_pricings 的 monthly 等）。
     *
     * @return array<string, mixed>
     */
    private function extractProductPricingFromResponse(array $response): array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $pricings = is_array($data['product_pricings'] ?? null) ? $data['product_pricings'] : [];

        foreach ($pricings as $pricing) {
            if (! is_array($pricing)) {
                continue;
            }

            $type = trim((string) ($pricing['type'] ?? ''));
            if ($type !== '' && $type !== 'product') {
                continue;
            }

            $monthly = $this->normalizeCatalogAmount($pricing['monthly'] ?? null);
            $productPrice = $this->normalizeCatalogAmount($pricing['monthly'] ?? $pricing['product_price'] ?? null);

            if ($monthly === null && $productPrice === null) {
                continue;
            }

            $billingCycle = strtolower(trim((string) ($pricing['billingcycle'] ?? '')));
            $billingCycle = $billingCycle !== '' && $billingCycle !== 'monthly' ? $billingCycle : 'monthly';

            return [
                'monthly_price' => $monthly,
                'product_price' => $productPrice,
                'billingcycle' => $billingCycle,
                'setup_fee' => $this->normalizeCatalogAmount($pricing['msetupfee'] ?? null),
            ];
        }

        return [];
    }

    public function getProductConfigTemplate(Supplier $supplier, int $productId): array
    {
        $catalog = $this->getProductCatalog($supplier);
        $product = collect($catalog['products'] ?? [])->first(
            fn (array $item) => (int) ($item['id'] ?? 0) === $productId
        );

        if (! is_array($product)) {
            throw new BusinessException('未找到对应的供应商商品', 40400);
        }

        if (! $this->cloudConfigTemplate->supports($product)) {
            throw new BusinessException('当前供应商商品类型暂不支持自动拉取配置项', 42200);
        }

        $configOptions = $this->fetchRealConfigOptions($supplier, $productId);
        if (empty($configOptions)) {
            $configOptions = collect($this->cloudConfigTemplate->build($product))
                ->filter(fn (array $item) => trim((string) ($item['parameter'] ?? '')) !== '')
                ->values()
                ->all();
        }

        $autoFilledFields = collect($configOptions)
            ->map(fn ($item) => $item['field'] ?? $item['option_name'] ?? '')
            ->filter()
            ->values()
            ->all();

        return [
            'product' => $product,
            'config_options' => $configOptions,
            'auto_filled_fields' => $autoFilledFields,
        ];
    }

    public function fetchRealConfigOptions(Supplier $supplier, int $productId): array
    {
        $response = $this->transport->get(
            $supplier,
            '/cart/get_product_config',
            $this->transport->login($supplier),
            ['pid' => $productId],
        );

        return $this->normalizeRemoteConfigOptions(
            $this->extractStorefrontConfigOptionsFromResponse($response)
        );
    }

    public function fetchBatchProductConfigOptions(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        $ids = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $chunkSize = max(1, min($chunkSize, 12));
        $jwt = $this->transport->login($supplier);
        $results = [];

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $responses = $this->transport->parallelGet(
                $supplier,
                collect($chunk)->mapWithKeys(fn (int $productId) => [
                    (string) $productId => [
                        'uri' => '/cart/get_product_config',
                        'query' => ['pid' => $productId],
                    ],
                ])->all(),
                $jwt,
            );

            foreach ($chunk as $productId) {
                $response = $responses[(string) $productId]['response'] ?? null;
                $storefrontConfigOptions = $this->extractStorefrontConfigOptionsFromResponse($response);
                $results[$productId] = $storefrontConfigOptions !== []
                    ? $this->normalizeRemoteConfigOptions($storefrontConfigOptions)
                    : [];
            }
        }

        return $results;
    }

    public function fetchBatchProductStocks(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        $ids = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $chunkSize = max(1, min($chunkSize, 12));
        $jwt = $this->transport->login($supplier);
        $results = [];

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $responses = $this->transport->parallelGet(
                $supplier,
                collect($chunk)->mapWithKeys(fn (int $productId) => [
                    (string) $productId => [
                        'uri' => '/cart/get_product_config',
                        'query' => ['pid' => $productId],
                    ],
                ])->all(),
                $jwt,
            );

            foreach ($chunk as $productId) {
                $response = $responses[(string) $productId]['response'] ?? null;
                $results[$productId] = is_array($response)
                    ? $this->extractLegacyProductStock($response, $productId)
                    : null;
            }
        }

        return $results;
    }

    private function normalizeProductCatalog(array $response): array
    {
        $payload = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $groupedProducts = [];
        $flatProducts = [];

        $this->collectCartCatalogProducts(
            is_array($payload['products'] ?? null) ? $payload['products'] : [],
            '',
            $groupedProducts,
            $flatProducts,
        );

        return [
            'groups' => collect($groupedProducts)
                ->map(fn (array $items, string $label): array => [
                    'key' => 'group-'.md5($label),
                    'label' => $label,
                    'items' => $items,
                ])
                ->values()
                ->all(),
            'products' => $flatProducts,
        ];
    }

    /**
     * @param  array<int, mixed>  $entries
     * @param  array<string, array<int, array<string, mixed>>>  $groupedProducts
     * @param  array<int, array<string, mixed>>  $flatProducts
     */
    private function collectCartCatalogProducts(
        array $entries,
        string $parentLabel,
        array &$groupedProducts,
        array &$flatProducts,
    ): void {
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            $children = is_array($entry['products'] ?? null) ? $entry['products'] : [];
            if ($children !== []) {
                $label = $name === ''
                    ? $parentLabel
                    : ($parentLabel === '' ? $name : "{$parentLabel} / {$name}");
                $this->collectCartCatalogProducts($children, $label, $groupedProducts, $flatProducts);

                continue;
            }

            $productId = (int) ($entry['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $groupLabel = $parentLabel !== '' ? $parentLabel : '未分组';
            $item = $this->productTypeMapper->normalizeProduct([
                'id' => $productId,
                'name' => $name !== '' ? $name : '未命名商品',
                'type' => trim((string) ($entry['type'] ?? '')),
                'description' => trim((string) ($entry['description'] ?? '')),
                'billingcycle' => trim((string) ($entry['upstream_cycle'] ?? $entry['billingcycle'] ?? '')),
                'product_price' => $entry['upstream_price'] ?? null,
                'monthly_price' => null,
                'setup_fee' => null,
                'allow_qty' => (int) ($entry['allow_qty'] ?? 0),
                'stock_control' => (int) ($entry['stock_control'] ?? 0),
                'qty' => is_numeric($entry['qty'] ?? null) ? max((int) $entry['qty'], 0) : null,
                'stock' => $this->normalizeCatalogStock($entry),
                'group_name' => $groupLabel,
                'group_label' => $groupLabel,
            ]);

            $groupedProducts[$groupLabel] ??= [];
            $groupedProducts[$groupLabel][] = $item;
            $flatProducts[] = $item;
        }
    }

    private function extractLegacyProductStock(array $response, int $productId): ?array
    {
        $product = $response['data']['products'] ?? null;
        if (! is_array($product) || (int) ($product['id'] ?? 0) !== $productId) {
            return null;
        }

        return [
            'stock_control' => (int) ($product['stock_control'] ?? 0),
            'qty' => is_numeric($product['qty'] ?? null) ? max((int) $product['qty'], 0) : null,
            'stock' => $this->normalizeCatalogStock($product),
            'allow_qty' => (int) ($product['allow_qty'] ?? 0),
        ];
    }

    private function extractStorefrontConfigOptionsFromResponse(?array $response): array
    {
        if (! is_array($response) || (int) ($response['status'] ?? 0) !== 200) {
            return [];
        }

        $data = $response['data'] ?? null;
        if (! is_array($data)) {
            return [];
        }

        return collect($data['config_groups'] ?? [])
            ->filter(fn ($group) => is_array($group))
            ->flatMap(function (array $group) {
                return is_array($group['options'] ?? null) ? $group['options'] : [];
            })
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();
    }

    private function normalizeRemoteConfigOptions(array $configOptions): array
    {
        return collect($configOptions)
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->map(function (array $item, int $index) {
                $type = (int) ($item['option_type'] ?? 0);
                $name = trim((string) ($item['option_name'] ?? $item['name'] ?? ''));
                $nameParts = explode('|', $name, 2);
                $displayName = trim((string) (count($nameParts) > 1 ? $nameParts[1] : $name));
                $field = $this->resolveConfigOptionField(
                    $item,
                    self::CONFIG_OPTION_FIELD_MAP[$type] ?? null,
                    count($nameParts) > 1 ? trim($nameParts[0]) : '',
                    $displayName
                );
                $subOptions = $this->normalizeRemoteConfigSubOptions($item['sub'] ?? [], $type);
                $sortOrder = (int) ($item['sort_order'] ?? $item['order'] ?? ($index + 1));
                $optionId = (int) ($item['id'] ?? $item['config_id'] ?? 0);
                $isRange = in_array($type, self::RANGE_OPTION_TYPES, true);

                return array_merge($item, [
                    'id' => $optionId,
                    'config_id' => $optionId,
                    'field' => $field,
                    'name' => $displayName !== '' ? $displayName : $name,
                    'option_name' => $name,
                    'required' => (int) ($item['required'] ?? 0),
                    'hidden' => (int) ($item['hidden'] ?? 0),
                    'order' => $sortOrder,
                    'sort_order' => $sortOrder,
                    'allow_upgrade' => (int) ($item['allow_upgrade'] ?? $item['upgrade'] ?? 0),
                    'allow_promo_code' => array_key_exists('allow_promo_code', $item)
                        ? (int) $item['allow_promo_code']
                        : 1,
                    'qty_minimum' => $isRange ? (int) ($item['qty_minimum'] ?? 0) : 0,
                    'qty_maximum' => $isRange ? (int) ($item['qty_maximum'] ?? 0) : 0,
                    'qty_stage' => max(1, (int) ($item['qty_stage'] ?? 1)),
                    'unit' => (string) ($item['unit'] ?? ''),
                    'parameter' => trim((string) ($item['parameter'] ?? $this->buildRemoteConfigOptionParameter($subOptions, $type))),
                    'sub' => $subOptions,
                ]);
            })
            ->all();
    }

    private function normalizeRemoteConfigSubOptions(mixed $subOptions, int $optionType): array
    {
        if (! is_array($subOptions)) {
            return [];
        }

        return collect($subOptions)
            ->filter(fn ($sub) => is_array($sub))
            ->values()
            ->map(function (array $sub, int $index) use ($optionType) {
                $rawOptionName = trim((string) ($sub['option_name'] ?? $sub['version'] ?? ''));
                [$optionValue, $optionLabel, $versionLabel] = $this->parseRemoteSubOptionName(
                    $rawOptionName,
                    $optionType,
                    trim((string) ($sub['option_name_first'] ?? $sub['id'] ?? ''))
                );
                $pricing = $this->normalizeRemoteSubPricing($sub['pricing'] ?? $sub['pricings'] ?? []);
                $subId = (int) ($sub['id'] ?? 0);
                $configId = (int) ($sub['config_id'] ?? $sub['configid'] ?? 0);

                return array_merge($sub, [
                    'id' => $subId,
                    'config_id' => $configId,
                    'configid' => $configId > 0 ? $configId : ($sub['configid'] ?? null),
                    'option_name' => $optionLabel !== '' ? $optionLabel : ($optionValue !== '' ? $optionValue : $rawOptionName),
                    'option_name_first' => $optionValue !== '' ? $optionValue : trim((string) ($sub['option_name_first'] ?? $subId)),
                    'version' => $versionLabel !== '' ? $versionLabel : ($optionLabel !== '' ? $optionLabel : $rawOptionName),
                    'hidden' => (int) ($sub['hidden'] ?? 0),
                    'sort_order' => (int) ($sub['sort_order'] ?? $sub['order'] ?? $index),
                    'qty_minimum' => (int) ($sub['qty_minimum'] ?? 0),
                    'qty_maximum' => (int) ($sub['qty_maximum'] ?? 0),
                    'pricing' => $pricing,
                ]);
            })
            ->all();
    }

    private function parseRemoteSubOptionName(string $rawOptionName, int $optionType, string $fallbackValue = ''): array
    {
        $rawOptionName = trim($rawOptionName);
        [$firstPart, $secondPart] = array_pad(explode('|', $rawOptionName, 2), 2, '');

        $optionValue = trim($firstPart) !== '' ? trim($firstPart) : trim($fallbackValue);
        $rawLabel = trim($secondPart) !== '' ? trim($secondPart) : $rawOptionName;
        $rawLabel = $rawLabel !== '' ? $rawLabel : $optionValue;

        if ($optionType === 5) {
            return [$optionValue, $rawLabel, $rawLabel];
        }

        $displayLabel = $rawLabel;
        if (str_contains($rawLabel, '^')) {
            $segments = array_values(array_filter(array_map('trim', explode('^', $rawLabel))));
            $displayLabel = end($segments) ?: $rawLabel;
        }

        return [$optionValue, $displayLabel, $displayLabel];
    }

    private function normalizeRemoteSubPricing(mixed $pricing): array
    {
        if (! is_array($pricing)) {
            return [];
        }

        $pricingData = isset($pricing[0]) && is_array($pricing[0])
            ? (array) $pricing[0]
            : $pricing;

        $normalized = [];
        foreach (self::CONFIG_PRICING_CYCLE_MAP as $sourceKey => $targetKey) {
            if (! array_key_exists($sourceKey, $pricingData) || $pricingData[$sourceKey] === '' || $pricingData[$sourceKey] === null) {
                continue;
            }

            if (! is_numeric($pricingData[$sourceKey])) {
                continue;
            }

            $normalized[$targetKey] = number_format((float) $pricingData[$sourceKey], 2, '.', '');
        }

        if (isset($normalized['one_time']) && ! isset($normalized['onetime'])) {
            $normalized['onetime'] = $normalized['one_time'];
        }

        return $normalized;
    }

    private function buildRemoteConfigOptionParameter(array $subOptions, int $optionType): string
    {
        if (in_array($optionType, self::RANGE_OPTION_TYPES, true)) {
            return '';
        }

        return collect($subOptions)
            ->filter(fn ($sub) => is_array($sub) && (int) ($sub['hidden'] ?? 0) !== 1)
            ->map(function (array $sub) {
                $value = trim((string) ($sub['option_name_first'] ?? $sub['id'] ?? ''));
                $label = trim((string) ($sub['version'] ?? $sub['option_name'] ?? $value));

                return $value !== '' ? "{$value}|{$label}" : '';
            })
            ->filter()
            ->implode(',');
    }

    private function resolveConfigOptionField(array $item, ?string $mappedField, string $nameField, string $displayName): string
    {
        $explicitField = trim($nameField);
        if ($explicitField !== '') {
            return $explicitField;
        }

        $normalizedDisplayName = trim($displayName);
        $lowerDisplayName = Str::lower($normalizedDisplayName);

        if (str_contains($lowerDisplayName, 'ipv6')) {
            return 'ipv6_num';
        }

        if (str_contains($lowerDisplayName, 'ipv4')) {
            return 'ip_num';
        }

        if (str_contains($normalizedDisplayName, '数据盘')) {
            return 'data_disk_size';
        }

        if (str_contains($normalizedDisplayName, '系统盘')) {
            return 'system_disk_size';
        }

        if (str_contains($normalizedDisplayName, '下行带宽')) {
            return 'in_bw';
        }

        if (str_contains($normalizedDisplayName, '上行带宽')) {
            return 'out_bw';
        }

        if ($mappedField !== null && trim($mappedField) !== '') {
            return trim($mappedField);
        }

        $slug = Str::slug($normalizedDisplayName, '_');

        return $slug !== '' ? $slug : $normalizedDisplayName;
    }

    private function resolveCatalogMonthlyPrice(array $product): ?string
    {
        $billingCycle = strtolower(trim((string) ($product['billingcycle'] ?? '')));
        if ($billingCycle !== '' && $billingCycle !== 'monthly') {
            return null;
        }

        return $this->normalizeCatalogAmount($product['product_price'] ?? null);
    }

    private function normalizeCatalogStock(array $product): int
    {
        if ((int) ($product['stock_control'] ?? 0) !== 1) {
            return -1;
        }

        $qty = $product['qty'] ?? null;
        if ($qty === null || $qty === '' || ! is_numeric($qty)) {
            return 0;
        }

        return max((int) $qty, 0);
    }

    private function normalizeCatalogAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
