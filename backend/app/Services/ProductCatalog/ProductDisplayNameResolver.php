<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Models\Product;
use Illuminate\Support\Str;

class ProductDisplayNameResolver
{
    private const TYPE_FIELD_MAP = [
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
        16 => 'cpu',
        17 => 'memory',
        18 => 'bw',
        19 => 'system_disk_size',
    ];

    /**
     * @var array<int, string>
     */
    private array $instanceSpecTextCache = [];

    /**
     * @var array<int, string>
     */
    private array $customDisplayNameCache = [];

    public function __construct(
        private readonly ?InstanceSpecCatalogService $instanceSpecCatalogService = null,
    ) {}

    /**
     * 失效单个商品的进程内缓存。
     *
     * 本类现为容器 singleton（此前每个调用点都 new 新实例，缓存从未跨行生效）。
     * 缓存因此变成长命的：长驻进程（队列 Worker、Octane、Artisan）在商品显示名或
     * 规格配置被改写后，若不失效会继续返回旧值。写入侧统一在「商品目录缓存失效」
     * 这个既有信号处调用本方法，见各 forgetSiteCatalogCache()。
     */
    public function forgetProduct(int $productId): void
    {
        unset($this->customDisplayNameCache[$productId], $this->instanceSpecTextCache[$productId]);
    }

    /**
     * 清空全部进程内缓存（商品目录发生批量变更时使用）。
     */
    public function flushCaches(): void
    {
        $this->customDisplayNameCache = [];
        $this->instanceSpecTextCache = [];
    }

    /**
     * @return array{
     *     product_display_name: string,
     *     product_spec_display: string,
     *     custom_display_name: string,
     *     cpu_memory_display: string,
     *     combined_display_name: string,
     *     instance_spec_text: string,
     *     cpu_display: string,
     *     memory_display: string
     * }
     */
    public function resolveForProduct(Product $product, array $configSnapshot = []): array
    {
        $optionContext = $this->buildConfigOptionContext($product);
        $mergedConfig = $this->buildCandidateConfig($product, $configSnapshot, $optionContext);
        $legacyProductName = $this->resolveLegacyProductNameText($product, $configSnapshot);
        $instanceSpecText = array_key_exists('instance_spec_text', $configSnapshot)
            ? trim((string) $configSnapshot['instance_spec_text'])
            : $this->resolveInstanceSpecText((int) $product->id);

        $cpuDisplay = $this->resolveCpuDisplay($product, $mergedConfig, $optionContext);
        $memoryDisplay = $this->resolveMemoryDisplay($product, $mergedConfig, $optionContext);

        if ($cpuDisplay === '' || $memoryDisplay === '') {
            [$fallbackCpuDisplay, $fallbackMemoryDisplay] = $this->extractCpuMemoryFromProductName(
                $legacyProductName
            );
            if ($cpuDisplay === '') {
                $cpuDisplay = $fallbackCpuDisplay;
            }
            if ($memoryDisplay === '') {
                $memoryDisplay = $fallbackMemoryDisplay;
            }
        }

        $cpuMemorySegments = array_values(array_filter([
            $cpuDisplay,
            $memoryDisplay,
        ], static fn (string $segment): bool => trim($segment) !== ''));
        $cpuMemoryDisplay = $cpuMemorySegments !== []
            ? implode(' ', $cpuMemorySegments)
            : '';
        $productSpecDisplay = $instanceSpecText !== ''
            ? $instanceSpecText
            : ($cpuMemoryDisplay !== '' ? $cpuMemoryDisplay : ($legacyProductName !== '' ? $legacyProductName : '未配置规格 #'.(int) $product->id));
        $customDisplayName = $this->resolveCustomDisplayNameText($product, $configSnapshot, [
            $cpuMemoryDisplay,
            $productSpecDisplay,
        ]);
        $productDisplayName = $customDisplayName !== ''
            ? $customDisplayName
            : $productSpecDisplay;
        $combinedDisplayName = $productDisplayName;

        return [
            'product_display_name' => $productDisplayName,
            'product_spec_display' => $productSpecDisplay,
            'custom_display_name' => $customDisplayName,
            'cpu_memory_display' => $cpuMemoryDisplay !== ''
                ? $cpuMemoryDisplay
                : ($instanceSpecText !== '' ? $instanceSpecText : '未配置规格 #'.(int) $product->id),
            'combined_display_name' => $combinedDisplayName !== ''
                ? $combinedDisplayName
                : ($productSpecDisplay !== '' ? $productSpecDisplay : '未配置规格 #'.(int) $product->id),
            'instance_spec_text' => $instanceSpecText,
            'cpu_display' => $cpuDisplay,
            'memory_display' => $memoryDisplay,
        ];
    }

    /**
     * @param  array<string, mixed>  $configSnapshot
     * @param  array<string, mixed>|null  $optionContext
     * @return array<string, string>
     */
    private function buildCandidateConfig(Product $product, array $configSnapshot, ?array $optionContext = null): array
    {
        $normalized = $this->normalizeScalarConfig($configSnapshot);
        $defaultConfig = $this->normalizeScalarConfig(
            (array) (($product->purchase_requires ?? [])['upstream_default_config'] ?? [])
        );
        $optionDefaults = is_array($optionContext)
            ? (array) ($optionContext['defaults'] ?? [])
            : $this->resolveOptionDefaults($product);
        $fallbackConfig = [];

        foreach (['cpu', 'memory'] as $field) {
            if (! isset($normalized[$field]) || $normalized[$field] === '') {
                if (isset($optionDefaults[$field]) && $optionDefaults[$field] !== '') {
                    $normalized[$field] = $optionDefaults[$field];

                    continue;
                }

                if (isset($defaultConfig[$field]) && $defaultConfig[$field] !== '') {
                    $normalized[$field] = $defaultConfig[$field];

                    continue;
                }
            }
        }

        foreach (['cpu', 'memory'] as $field) {
            if (isset($normalized[$field]) && $normalized[$field] !== '') {
                continue;
            }

            $fallbackValue = is_array($optionContext)
                ? trim((string) (($optionContext['first_visible'] ?? [])[$field] ?? ''))
                : $this->resolveFirstVisibleOptionValue($product, $field);
            if ($fallbackValue !== '') {
                $fallbackConfig[$field] = $fallbackValue;
            }
        }

        foreach ($this->resolveSourceSplitFallbackConfig($product) as $field => $value) {
            if (isset($normalized[$field]) && $normalized[$field] !== '') {
                continue;
            }

            $normalized[$field] = $value;
        }

        foreach ($fallbackConfig as $field => $value) {
            if (isset($normalized[$field]) && $normalized[$field] !== '') {
                continue;
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function normalizeScalarConfig(array $config): array
    {
        $normalized = [];

        foreach ($config as $field => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $key = $this->normalizeConfigField((string) $field);
            $text = trim((string) $value);

            if ($key === '' || $text === '') {
                continue;
            }

            $normalized[$key] = Str::limit($text, 100, '');
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function resolveOptionDefaults(Product $product): array
    {
        $defaults = [];

        foreach ((array) ($product->config_options ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $field = $this->parseField($option);
            if (! in_array($field, ['cpu', 'memory'], true) || isset($defaults[$field])) {
                continue;
            }

            $defaultValue = $this->resolveConfiguredDefaultValue($option);
            if ($defaultValue !== '') {
                $defaults[$field] = $defaultValue;
            }
        }

        return $defaults;
    }

    /**
     * @return array<string, string>
     */
    private function resolveSourceSplitFallbackConfig(Product $product): array
    {
        $split = (array) (($product->purchase_requires ?? [])['upstream_split'] ?? []);
        $sourceProductId = (int) ($split['source_product_id'] ?? 0);
        if ($sourceProductId <= 0 || $sourceProductId === (int) $product->id) {
            return [];
        }

        $sourceProduct = Product::query()
            ->select(['id', 'purchase_requires', 'config_options'])
            ->find($sourceProductId);
        if (! $sourceProduct instanceof Product) {
            return [];
        }

        $config = $this->buildCandidateConfig($sourceProduct, [], $this->buildConfigOptionContext($sourceProduct));
        [$sourceCpuDisplay, $sourceMemoryDisplay] = $this->extractCpuMemoryFromProductName(
            $this->resolveLegacyProductNameText($sourceProduct, [])
        );

        if (($config['cpu'] ?? '') === '' && $sourceCpuDisplay !== '') {
            $config['cpu'] = $sourceCpuDisplay;
        }

        if (($config['memory'] ?? '') === '' && $sourceMemoryDisplay !== '') {
            $config['memory'] = $sourceMemoryDisplay;
        }

        return $config;
    }

    private function resolveFirstVisibleOptionValue(Product $product, string $field): string
    {
        foreach ((array) ($product->config_options ?? []) as $option) {
            if (! is_array($option) || $this->parseField($option) !== $field) {
                continue;
            }

            foreach ((array) ($option['sub'] ?? []) as $sub) {
                if (! is_array($sub) || (int) ($sub['hidden'] ?? 0) === 1) {
                    continue;
                }

                $value = $this->resolveSubItemValue($sub);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function resolveConfiguredDefaultValue(array $option): string
    {
        foreach (['default_value', 'default'] as $key) {
            $normalized = $this->normalizeConfiguredDefaultValue($option[$key] ?? null);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        $visibleSubs = [];
        foreach ((array) ($option['sub'] ?? []) as $sub) {
            if (! is_array($sub) || (int) ($sub['hidden'] ?? 0) === 1) {
                continue;
            }

            $visibleSubs[] = $sub;

            if ($this->isDefaultSubItem($sub)) {
                return $this->resolveSubItemValue($sub);
            }
        }

        if (count($visibleSubs) === 1) {
            return $this->resolveSubItemValue($visibleSubs[0]);
        }

        $visibleUiSubs = [];
        foreach ((array) ($option['sub_items'] ?? []) as $sub) {
            if (! is_array($sub) || (int) ($sub['hidden'] ?? 0) === 1) {
                continue;
            }

            $visibleUiSubs[] = $sub;

            if ($this->isDefaultSubItem($sub)) {
                return $this->resolveSubItemValue($sub);
            }
        }

        if ($visibleSubs === [] && count($visibleUiSubs) === 1) {
            return $this->resolveSubItemValue($visibleUiSubs[0]);
        }

        return '';
    }

    /**
     * @return array{
     *     defaults: array<string, string>,
     *     first_visible: array<string, string>,
     *     labels: array<string, array<string, string>>
     * }
     */
    private function buildConfigOptionContext(Product $product): array
    {
        $context = [
            'defaults' => [],
            'first_visible' => [],
            'labels' => [],
        ];

        foreach ((array) ($product->config_options ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $field = $this->parseField($option);
            if (! in_array($field, ['cpu', 'memory'], true)) {
                continue;
            }

            if (! isset($context['defaults'][$field])) {
                $defaultValue = $this->resolveConfiguredDefaultValue($option);
                if ($defaultValue !== '') {
                    $context['defaults'][$field] = $defaultValue;
                }
            }

            foreach ((array) ($option['sub'] ?? []) as $sub) {
                if (! is_array($sub) || (int) ($sub['hidden'] ?? 0) === 1) {
                    continue;
                }

                $subValue = $this->resolveSubItemValue($sub);
                if ($subValue !== '' && ! isset($context['first_visible'][$field])) {
                    $context['first_visible'][$field] = $subValue;
                }

                $label = trim((string) ($sub['version'] ?? $sub['option_name'] ?? $sub['label'] ?? $sub['option_name_first'] ?? $sub['value'] ?? $sub['id'] ?? ''));
                if ($label === '') {
                    continue;
                }

                foreach ([
                    $sub['id'] ?? '',
                    $sub['option_name_first'] ?? $sub['value'] ?? $sub['id'] ?? '',
                    $sub['option_name'] ?? $sub['version'] ?? $sub['label'] ?? '',
                ] as $candidate) {
                    $key = Str::lower(trim((string) $candidate));
                    if ($key !== '' && ! isset($context['labels'][$field][$key])) {
                        $context['labels'][$field][$key] = $label;
                    }
                }
            }

            foreach ($this->parseParameterOptions((string) ($option['parameter'] ?? '')) as $parameterOption) {
                $label = trim((string) ($parameterOption['label'] ?? ''));
                if ($label === '') {
                    continue;
                }

                foreach ([
                    $parameterOption['id'] ?? '',
                    $parameterOption['value'] ?? '',
                    $parameterOption['label'] ?? '',
                ] as $candidate) {
                    $key = Str::lower(trim((string) $candidate));
                    if ($key !== '' && ! isset($context['labels'][$field][$key])) {
                        $context['labels'][$field][$key] = $label;
                    }
                }
            }
        }

        return $context;
    }

    private function normalizeConfiguredDefaultValue(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        $compact = Str::lower(preg_replace('/\s+/u', '', $normalized) ?? '');
        if (in_array($compact, ['-', '--', '—', '——', 'n/a', 'null', 'none'], true)) {
            return '';
        }

        if (Str::startsWith($compact, '不传递')) {
            return '';
        }

        return $normalized;
    }

    private function isDefaultSubItem(array $sub): bool
    {
        foreach (['is_default', 'default', 'selected'] as $key) {
            $value = $sub[$key] ?? null;
            if ($value === true || $value === 1 || $value === '1') {
                return true;
            }

            if (is_string($value) && in_array(Str::lower(trim($value)), ['true', 'yes', 'on'], true)) {
                return true;
            }
        }

        return false;
    }

    private function resolveSubItemValue(array $sub): string
    {
        foreach (['option_name_first', 'value', 'id', 'option_name', 'label', 'version', 'name'] as $key) {
            $value = trim((string) ($sub[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveCpuDisplay(Product $product, array $config, ?array $optionContext = null): string
    {
        $value = trim((string) ($config['cpu'] ?? ''));
        if ($value === '') {
            return '';
        }

        $label = $this->resolveOptionLabel($product, 'cpu', $value, $optionContext);
        $number = $this->extractPrimaryNumber($label !== '' ? $label : $value);

        if ($number === '') {
            return '';
        }

        return $this->normalizeNumericString($number).' vCPU';
    }

    private function resolveMemoryDisplay(Product $product, array $config, ?array $optionContext = null): string
    {
        $value = trim((string) ($config['memory'] ?? ''));
        if ($value === '') {
            return '';
        }

        $label = $this->resolveOptionLabel($product, 'memory', $value, $optionContext);

        if ($label !== '') {
            $display = $this->normalizeMemoryDisplay($label, true);
            if ($display !== '') {
                return $display;
            }
        }

        return $this->normalizeMemoryDisplay($value, false);
    }

    private function resolveOptionLabel(Product $product, string $field, string $selectedValue, ?array $optionContext = null): string
    {
        $normalizedSelectedValue = Str::lower(trim($selectedValue));
        if ($normalizedSelectedValue === '') {
            return '';
        }

        if (is_array($optionContext)) {
            return trim((string) (($optionContext['labels'] ?? [])[$field][$normalizedSelectedValue] ?? ''));
        }

        foreach ((array) ($product->config_options ?? []) as $option) {
            if (! is_array($option) || $this->parseField($option) !== $field) {
                continue;
            }

            foreach ((array) ($option['sub'] ?? []) as $sub) {
                if (! is_array($sub) || (int) ($sub['hidden'] ?? 0) === 1) {
                    continue;
                }

                $candidates = array_filter([
                    Str::lower(trim((string) ($sub['id'] ?? ''))),
                    Str::lower(trim((string) ($sub['option_name_first'] ?? $sub['value'] ?? $sub['id'] ?? ''))),
                    Str::lower(trim((string) ($sub['option_name'] ?? $sub['version'] ?? $sub['label'] ?? ''))),
                ], static fn (string $candidate): bool => $candidate !== '');

                if (! in_array($normalizedSelectedValue, $candidates, true)) {
                    continue;
                }

                return trim((string) ($sub['version'] ?? $sub['option_name'] ?? $sub['label'] ?? $sub['option_name_first'] ?? $sub['value'] ?? $sub['id'] ?? ''));
            }

            foreach ($this->parseParameterOptions((string) ($option['parameter'] ?? '')) as $parameterOption) {
                $candidates = array_filter([
                    Str::lower(trim((string) ($parameterOption['id'] ?? ''))),
                    Str::lower(trim((string) ($parameterOption['value'] ?? ''))),
                    Str::lower(trim((string) ($parameterOption['label'] ?? ''))),
                ], static fn (string $candidate): bool => $candidate !== '');

                if (in_array($normalizedSelectedValue, $candidates, true)) {
                    return trim((string) ($parameterOption['label'] ?? $selectedValue));
                }
            }
        }

        return '';
    }

    /**
     * @return array<int, array{id: string, value: string, label: string}>
     */
    private function parseParameterOptions(string $parameter): array
    {
        $text = trim(str_replace("\r", "\n", $parameter));
        if ($text === '') {
            return [];
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
        $segments = count($lines) > 1 ? $lines : $this->splitParameterSegments($text);
        $options = [];

        foreach ($segments as $segment) {
            $pipePosition = strpos($segment, '|');
            if ($pipePosition === false) {
                $value = trim($segment);
                if ($value === '') {
                    continue;
                }

                $options[] = [
                    'id' => $value,
                    'value' => $value,
                    'label' => $value,
                ];

                continue;
            }

            $value = trim(substr($segment, 0, $pipePosition));
            $label = trim(substr($segment, $pipePosition + 1));
            if ($value === '' && $label === '') {
                continue;
            }

            $options[] = [
                'id' => $value,
                'value' => $value,
                'label' => $label !== '' ? $label : $value,
            ];
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function splitParameterSegments(string $parameter): array
    {
        $segments = [];
        $buffer = '';

        foreach (array_filter(array_map('trim', explode(',', $parameter))) as $part) {
            $buffer = $buffer === '' ? $part : ($buffer.','.$part);

            if (str_contains($buffer, '|')) {
                $segments[] = $buffer;
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $segments[] = $buffer;
        }

        return $segments;
    }

    private function normalizeMemoryDisplay(string $value, bool $preferSmallNumberAsGigabytes): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(T|TB)\b/i', $normalized, $matches) === 1) {
            return $this->normalizeNumericString($matches[1]).'T';
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(G|GB)\b/i', $normalized, $matches) === 1) {
            return $this->normalizeNumericString($matches[1]).'G';
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(M|MB)\b/i', $normalized, $matches) === 1) {
            return $this->formatMemoryFromMegabytes((int) round((float) $matches[1]));
        }

        if (! is_numeric($normalized)) {
            $number = $this->extractPrimaryNumber($normalized);
            if ($number === '') {
                return '';
            }

            return $preferSmallNumberAsGigabytes
                ? $this->normalizeNumericString($number).'G'
                : $this->normalizeMemoryDisplay($number, $preferSmallNumberAsGigabytes);
        }

        $number = (float) $normalized;
        if ($number <= 0) {
            return '';
        }

        $roundedNumber = (int) round($number);
        if ($roundedNumber >= 1024) {
            return $this->formatMemoryFromMegabytes($roundedNumber);
        }

        if ($preferSmallNumberAsGigabytes || $roundedNumber < 128) {
            return $this->normalizeNumericString($roundedNumber).'G';
        }

        return $roundedNumber.'M';
    }

    private function formatMemoryFromMegabytes(int $value): string
    {
        if ($value <= 0) {
            return '';
        }

        if ($value < 1024) {
            return $value.'M';
        }

        if ($value % 1024 === 0) {
            return ((string) ($value / 1024)).'G';
        }

        return $value.'M';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function extractCpuMemoryFromProductName(string $productName): array
    {
        $normalizedName = trim($productName);
        if ($normalizedName === '') {
            return ['', ''];
        }

        $patterns = [
            '/(\d+(?:\.\d+)?)\s*(?:v?cpu|核|c|h)\s*[-_\/ ]*(\d+(?:\.\d+)?)\s*(g|gb|m|mb)\b/iu',
            '/(\d+(?:\.\d+)?)\s*(?:c|h|核)\s*(\d+(?:\.\d+)?)\s*(g|gb|m|mb)\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedName, $matches) !== 1) {
                continue;
            }

            $cpuDisplay = $this->normalizeNumericString($matches[1]).' vCPU';
            $memoryDisplay = $this->normalizeMemoryDisplay($matches[2].$matches[3], false);

            return [$cpuDisplay, $memoryDisplay];
        }

        if (preg_match('/(?:^|[^\d])(\d+(?:\.\d+)?)\s*(g|gb|m|mb)\b/iu', $normalizedName, $matches) === 1) {
            return ['', $this->normalizeMemoryDisplay($matches[1].$matches[2], false)];
        }

        return ['', ''];
    }

    private function resolveLegacyProductNameText(Product $product, array $configSnapshot = []): string
    {
        $candidates = [
            $configSnapshot['combined_display_name'] ?? '',
            $configSnapshot['product_spec_display'] ?? '',
            $configSnapshot['product_display_name'] ?? '',
            $configSnapshot['legacy_product_name'] ?? '',
        ];

        $rawAttributes = $product->getAttributes();
        if (array_key_exists('name', $rawAttributes)) {
            $candidates[] = $rawAttributes['name'] ?? '';
        }
        if (array_key_exists('remark', $rawAttributes)) {
            $candidates[] = $rawAttributes['remark'] ?? '';
        }
        if (array_key_exists('supplier_product_name', $rawAttributes)) {
            $candidates[] = $rawAttributes['supplier_product_name'] ?? '';
        }

        $candidates[] = $product->getRawOriginal('name');
        $candidates[] = $product->getRawOriginal('remark');
        $candidates[] = $product->getRawOriginal('supplier_product_name');
        $candidates[] = ((array) (($product->purchase_requires ?? [])['upstream_split'] ?? []))['source_product_name'] ?? '';

        $segments = collect($candidates)
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        if ($segments !== []) {
            return implode(' ', $segments);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $configSnapshot
     * @param  array<int, string>  $defaultCandidates
     */
    private function resolveCustomDisplayNameText(Product $product, array $configSnapshot = [], array $defaultCandidates = []): string
    {
        $candidates = [
            $configSnapshot['custom_display_name'] ?? '',
        ];

        $rawAttributes = $product->getAttributes();
        if (array_key_exists('custom_display_name', $rawAttributes)) {
            $candidates[] = $rawAttributes['custom_display_name'] ?? '';
        }

        $candidates[] = $product->getRawOriginal('custom_display_name');

        foreach ($candidates as $candidate) {
            $normalized = trim((string) $candidate);
            if ($normalized !== '') {
                $squished = Str::squish($normalized);
                if (! $this->isDefaultCustomDisplayName($squished, $defaultCandidates)) {
                    return $squished;
                }
            }
        }

        $productId = (int) $product->id;
        if ($productId > 0 && Product::optionalSelectColumns(['custom_display_name']) !== []) {
            if (! array_key_exists($productId, $this->customDisplayNameCache)) {
                $this->customDisplayNameCache[$productId] = trim((string) Product::query()
                    ->whereKey($productId)
                    ->value('custom_display_name'));
            }

            $cachedCustomDisplayName = trim($this->customDisplayNameCache[$productId]);
            $squished = $cachedCustomDisplayName !== '' ? Str::squish($cachedCustomDisplayName) : '';

            return $squished !== '' && ! $this->isDefaultCustomDisplayName($squished, $defaultCandidates)
                ? $squished
                : '';
        }

        return '';
    }

    private function resolveInstanceSpecText(int $productId): string
    {
        if ($productId <= 0) {
            return '';
        }

        if (! array_key_exists($productId, $this->instanceSpecTextCache)) {
            $service = $this->resolveInstanceSpecCatalogService();
            $resolved = $service->resolveProductSpecMap([$productId]);
            $this->instanceSpecTextCache[$productId] = trim((string) ($resolved[$productId]['instance_spec_text'] ?? ''));
        }

        return $this->instanceSpecTextCache[$productId];
    }

    private function resolveInstanceSpecCatalogService(): InstanceSpecCatalogService
    {
        if ($this->instanceSpecCatalogService instanceof InstanceSpecCatalogService) {
            return $this->instanceSpecCatalogService;
        }

        return new InstanceSpecCatalogService;
    }

    private function parseField(array $item): string
    {
        $field = $this->normalizeConfigField((string) ($item['field'] ?? ''));
        if ($field !== '') {
            return $field;
        }

        $type = (int) ($item['option_type'] ?? -1);
        if (isset(self::TYPE_FIELD_MAP[$type])) {
            return self::TYPE_FIELD_MAP[$type];
        }

        $source = (string) ($item['option_name'] ?? $item['spec_key'] ?? '');
        $parts = explode('|', $source);

        return $this->normalizeConfigField((string) ($parts[0] ?? ''));
    }

    private function normalizeConfigField(string $field): string
    {
        $normalized = Str::lower(trim($field));
        $normalized = str_replace(['-', ' '], '_', $normalized);

        return match ($normalized) {
            'cpu', 'vcpu', 'core', 'cores', 'cpu_core', 'cpu_cores', 'cpu_num', 'cpu_number', 'core_num', 'cores_num' => 'cpu',
            'memory', 'ram', 'mem', 'memory_size', 'mem_size', 'ram_size', 'memory_num', 'ram_num' => 'memory',
            default => $normalized,
        };
    }

    private function extractPrimaryNumber(string $value): string
    {
        if (preg_match('/\d+(?:\.\d+)?/', $value, $matches) !== 1) {
            return '';
        }

        return $matches[0];
    }

    private function normalizeNumericString(float|int|string $value): string
    {
        $number = (float) $value;

        if ((float) ((int) $number) === $number) {
            return (string) ((int) $number);
        }

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    /**
     * @param  array<int, string>  $defaultCandidates
     */
    private function isDefaultCustomDisplayName(string $customDisplayName, array $defaultCandidates): bool
    {
        $customComparable = $this->normalizeSlugComparable($customDisplayName);
        if ($customComparable === '') {
            return false;
        }

        foreach ($defaultCandidates as $candidate) {
            if ($customComparable === $this->normalizeSlugComparable((string) $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSlugComparable(string $value): string
    {
        $normalized = Str::lower(preg_replace('/[\s_-]+/u', '', trim($value)) ?? trim($value));
        $normalized = preg_replace('/(\d+(?:\.\d+)?)(?:v?cpu|cores?|core|h|c|核)(?![a-z])/u', '$1vcpu', $normalized) ?? $normalized;
        $normalized = preg_replace('/(\d+(?:\.\d+)?)(?:gb|g)(?![a-z])/u', '$1gib', $normalized) ?? $normalized;
        $normalized = preg_replace('/(\d+(?:\.\d+)?)(?:mb|m)(?![a-z])/u', '$1mib', $normalized) ?? $normalized;

        return $normalized;
    }
}
