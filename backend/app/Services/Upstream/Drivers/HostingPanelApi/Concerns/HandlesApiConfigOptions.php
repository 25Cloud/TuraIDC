<?php

declare(strict_types=1);

namespace App\Services\Upstream\Drivers\HostingPanelApi\Concerns;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait HandlesApiConfigOptions
{
    public function getProductConfigTemplate(Supplier $supplier, int $productId): array
    {
        $catalog = $this->getProductCatalog($supplier);
        $product = collect($catalog['products'] ?? [])->first(
            fn (array $item) => (int) ($item['id'] ?? 0) === $productId
        );

        if (! is_array($product)) {
            throw new BusinessException('未找到对应的供应商商品', 40400);
        }

        if (! $this->supportsConfigTemplate($product)) {
            throw new BusinessException('当前供应商商品类型暂不支持自动拉取配置项', 42200);
        }

        // 优先从 /v1/productsconfig 拉取真实配置项
        $configOptions = $this->fetchRealConfigOptions($supplier, $productId);

        // 若接口无数据则降级到正则提取
        if (empty($configOptions)) {
            $configOptions = collect($this->buildCloudConfigTemplate($product))
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

    /**
     * 从官方 /v1/productsconfig 拉取真实配置项；若上游缺失或返回空，再回退到前台页面接口。
     */
    public function fetchRealConfigOptions(Supplier $supplier, int $productId): array
    {
        $jwt = $this->login($supplier);
        $response = $this->get($supplier, '/v1/productsconfig', $jwt, ['product_id' => $productId]);
        $officialConfigOptions = $this->normalizeRemoteConfigOptions($this->extractApiConfigOptions($response, $productId));

        if ($officialConfigOptions !== []) {
            return $officialConfigOptions;
        }

        $storefrontConfigOptions = $this->fetchStorefrontConfigOptions($supplier, $productId);
        if ($storefrontConfigOptions !== []) {
            return $this->normalizeRemoteConfigOptions($storefrontConfigOptions);
        }

        return [];
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
        $rootUrl = $this->resolveSupplierRootUrl($supplier);
        $results = [];

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $responses = $this->parallelGet(
                $supplier,
                collect($chunk)->mapWithKeys(fn (int $productId) => [
                    (string) $productId => [
                        'uri' => $rootUrl.'/cart/get_product_config',
                        'query' => ['pid' => $productId],
                    ],
                ])->all()
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
        $jwt = $this->login($supplier);
        $results = [];

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $responses = $this->parallelGet(
                $supplier,
                collect($chunk)->mapWithKeys(fn (int $productId) => [
                    (string) $productId => [
                        'uri' => '/v1/productsconfig',
                        'query' => ['product_id' => $productId],
                    ],
                ])->all(),
                $jwt
            );

            foreach ($chunk as $productId) {
                $response = $responses[(string) $productId]['response'] ?? null;
                $results[$productId] = is_array($response)
                    ? $this->extractApiProductStock($response, $productId)
                    : null;
            }
        }

        return $results;
    }

    private function fetchStorefrontConfigOptions(Supplier $supplier, int $productId): array
    {
        try {
            $response = $this->get(
                $supplier,
                $this->resolveSupplierRootUrl($supplier).'/cart/get_product_config',
                null,
                ['pid' => $productId]
            );
        } catch (\Throwable $exception) {
            Log::info('[主机面板接口] 前台配置项接口不可用，回退到开放 API', [
                'supplier_id' => $supplier->id,
                'product_id' => $productId,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        return $this->extractStorefrontConfigOptionsFromResponse($response);
    }

    public function resolveSupplierRootUrl(Supplier $supplier): string
    {
        $baseUrl = trim((string) $supplier->api_url);
        $parts = parse_url($baseUrl);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return rtrim($baseUrl, '/');
        }

        $rootUrl = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $rootUrl .= ':'.$parts['port'];
        }

        return $rootUrl;
    }

    private function extractApiConfigOptions(array $response, int $productId): array
    {
        $product = $this->extractApiProductData($response, $productId);

        if (is_array($product)) {
            return is_array($product['configoptions'] ?? null) ? $product['configoptions'] : [];
        }

        return [];
    }

    private function extractApiProductStock(array $response, int $productId): ?array
    {
        $product = $this->extractApiProductData($response, $productId);
        if (! is_array($product)) {
            return null;
        }

        return [
            'stock_control' => (int) ($product['stock_control'] ?? 0),
            'qty' => is_numeric($product['qty'] ?? null) ? max((int) $product['qty'], 0) : null,
            'stock' => $this->normalizeCatalogStock($product),
            'allow_qty' => (int) ($product['allow_qty'] ?? 0),
        ];
    }

    private function extractApiProductData(array $response, int $productId): ?array
    {
        foreach ($response['data']['first_group'] ?? [] as $firstGroup) {
            foreach ($firstGroup['group'] ?? [] as $group) {
                foreach ($group['products'] ?? [] as $product) {
                    if ((int) ($product['id'] ?? 0) === $productId) {
                        return is_array($product) ? $product : null;
                    }
                }
            }
        }

        return null;
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
}
