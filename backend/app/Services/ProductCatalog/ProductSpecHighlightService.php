<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Models\Product;
use App\Models\Setting;
use App\Services\ProductCatalog\Concerns\HandlesProductCatalogHelpers;
use Illuminate\Support\Str;

/**
 * 商品规格亮点提取服务。
 *
 * 维度取值优先级（后者覆盖前者）：
 * 1. 商品名兜底 fromProductName()
 * 2. remark 商品卡片 fromRemark()：解析 <li>键:值</li> 与 <br> 分隔行
 * 3. config_options fromConfigOptions()：单选取首个可见子项；滑块取 qty_minimum（min=0 视为不含）；纯 0 值不展示
 * 4. 后台自定义覆盖 overrideFor()：存在即最高优先
 */
class ProductSpecHighlightService
{
    use HandlesProductCatalogHelpers;

    private const OVERRIDES_GROUP = 'product';

    private const OVERRIDES_KEY = 'spec_highlight_overrides';

    /**
     * 维度定义：key => [label, keywords]。数组顺序即优先级，具体先于宽泛。
     *
     * @var array<string, array{label: string, keywords: list<string>}>
     */
    private const DIMENSIONS = [
        'cpu' => ['label' => 'CPU', 'keywords' => ['cpu', '处理器', '核心', '核数']],
        'memory' => ['label' => '内存', 'keywords' => ['内存', 'ram', 'memory', '运行内存']],
        'system_disk' => ['label' => '系统盘', 'keywords' => ['系统盘', 'system disk', 'system_disk', 'system_disk_size']],
        'data_disk' => ['label' => '数据盘', 'keywords' => ['数据盘', 'data disk', 'data_disk', 'data_disk_size']],
        'disk' => ['label' => '硬盘', 'keywords' => ['硬盘', '磁盘', 'disk', '存储空间', 'storage', 'ssd']],
        'bandwidth_up' => ['label' => '上行带宽', 'keywords' => ['上行带宽', '上行', '上传', 'upload']],
        'bandwidth_down' => ['label' => '下行带宽', 'keywords' => ['下行带宽', '下行', '下载', 'download']],
        'bandwidth' => ['label' => '带宽', 'keywords' => ['带宽', 'bandwidth', 'bw', '峰值带宽', '出口带宽']],
        'traffic' => ['label' => '流量', 'keywords' => ['流量', 'traffic']],
        'defense' => ['label' => '防御', 'keywords' => ['防御', '防护', 'ddos', 'cc防御', '抗攻击']],
        'port' => ['label' => '端口数', 'keywords' => ['端口', 'port', 'nat端口']],
        'connection' => ['label' => '连接数', 'keywords' => ['连接数', 'connection']],
        'node' => ['label' => '节点', 'keywords' => ['节点', 'node']],
        'domain' => ['label' => '绑定域名', 'keywords' => ['绑定域名', '域名', 'domain']],
        'ipv6' => ['label' => 'IPv6', 'keywords' => ['ipv6', 'v6地址', 'v6']],
        'ipv4' => ['label' => 'IPv4', 'keywords' => ['ipv4', 'v4地址', 'ip_num', 'ip地址', 'ip']],
        'route' => ['label' => '线路', 'keywords' => ['线路', 'route', '路由', 'cni2', 'cni', 'bgp']],
        'region' => ['label' => '地区', 'keywords' => ['地区', 'region', '机房', 'area', '区域']],
        'system' => ['label' => '系统', 'keywords' => ['系统', '操作系统', 'os', 'windows', 'linux']],
    ];

    /**
     * 解析商品规格亮点（按维度定义顺序输出）。
     *
     * @return array<int, array{key: string, label: string, value: string}>
     */
    public function resolveHighlightsForProduct(Product $product): array
    {
        $merged = $this->mergeDimensions(
            $this->fromProductName($product),
            $this->fromRemark($product),
            $this->fromConfigOptions($product),
            $this->overridesFor((int) $product->id),
        );

        $merged = $this->dropRedundantDimensions($merged);

        $result = [];
        foreach (self::DIMENSIONS as $key => $definition) {
            $value = trim((string) ($merged[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $result[] = [
                'key' => $key,
                'label' => $definition['label'],
                'value' => $value,
            ];
        }

        return $result;
    }

    /**
     * 一行式规格亮点摘要。CPU/内存有独立列，不重复进摘要。
     */
    public function resolveHighlightText(Product $product): string
    {
        return collect($this->resolveHighlightsForProduct($product))
            ->reject(fn (array $item): bool => in_array($item['key'], ['cpu', 'memory'], true))
            ->map(fn (array $item): string => $item['label'].' '.$item['value'])
            ->implode('，');
    }

    /**
     * 全部规格维度（管理端编辑弹窗用）。
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function dimensionOptions(): array
    {
        return collect(self::DIMENSIONS)
            ->map(fn (array $definition, string $key): array => [
                'key' => $key,
                'label' => $definition['label'],
            ])
            ->values()
            ->all();
    }

    /**
     * 后台自定义覆盖（不存在返回空数组）。
     *
     * @return array<string, string>
     */
    public function overridesFor(int $productId): array
    {
        $all = json_decode((string) Setting::getValue(self::OVERRIDES_GROUP, self::OVERRIDES_KEY, ''), true);

        return is_array($all[$productId] ?? null) ? $all[$productId] : [];
    }

    /**
     * 保存后台覆盖；items 为空表示清空该商品覆盖。保存后失效站点商品缓存。
     *
     * @param  array<string, string>  $items
     */
    public function saveOverrides(int $productId, array $items): void
    {
        $all = json_decode((string) Setting::getValue(self::OVERRIDES_GROUP, self::OVERRIDES_KEY, ''), true);
        $all = is_array($all) ? $all : [];

        $normalized = $this->normalizeOverrideItems($items);
        if ($normalized === []) {
            unset($all[$productId]);
        } else {
            $all[$productId] = $normalized;
        }

        Setting::setValue(
            self::OVERRIDES_GROUP,
            self::OVERRIDES_KEY,
            json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->forgetSiteCatalogCache();
    }

    /**
     * @param  array<string, string>  $items
     * @return array<string, string>
     */
    private function normalizeOverrideItems(array $items): array
    {
        $normalized = [];
        foreach (self::DIMENSIONS as $key => $_definition) {
            $value = trim((string) ($items[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $normalized[$key] = mb_substr($value, 0, 40);
        }

        return $normalized;
    }

    /**
     * 合并各来源（后者覆盖前者）。
     *
     * @param  array<string, string>  ...$sources
     * @return array<string, string>
     */
    private function mergeDimensions(array ...$sources): array
    {
        $merged = [];
        foreach ($sources as $source) {
            foreach ($source as $key => $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    /**
     * 去重冗余维度：已有细分维度时丢弃通用项。
     *
     * @param  array<string, string>  $dimensions
     * @return array<string, string>
     */
    private function dropRedundantDimensions(array $dimensions): array
    {
        if (isset($dimensions['system_disk']) || isset($dimensions['data_disk'])) {
            unset($dimensions['disk']);
        }

        if (isset($dimensions['bandwidth'])) {
            unset($dimensions['bandwidth_up'], $dimensions['bandwidth_down']);
        }

        return $dimensions;
    }

    /**
     * 商品名兜底提取。
     *
     * @return array<string, string>
     */
    private function fromProductName(Product $product): array
    {
        $name = trim((string) ($product->custom_display_name ?? ''));
        if ($name === '') {
            $name = trim((string) $product->getRawOriginal('name'));
        }
        if ($name === '') {
            $name = trim((string) ($product->getAttribute('supplier_product_name') ?? ''));
        }

        if ($name === '') {
            return [];
        }

        $dimensions = [];

        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:核|v?cpu|cores?|core)(?![a-z])/iu', $name, $match) === 1) {
            $dimensions['cpu'] = $this->normalizeNumeric($match[1]).'核';
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:gb|g)(?![a-z])(?!\s*(?:防御|防护))/iu', $name, $match) === 1) {
            $dimensions['memory'] = $this->normalizeNumeric($match[1]).'G';
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(?:tb|t)(?![a-z])(?!\s*(?:流量))/iu', $name, $match) === 1) {
            $dimensions['memory'] = $this->normalizeNumeric($match[1]).'T';
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*m\b(?!\s*(?:流量))/iu', $name, $match) === 1) {
            $dimensions['bandwidth'] = $this->normalizeNumeric($match[1]).'M';
        }

        if (preg_match('/(\d+)\s*(?:端口|个端口|port)/iu', $name, $match) === 1) {
            $dimensions['port'] = $match[1].'端口';
        }

        if (preg_match('/(?:防御|防护|抗攻击)\s*(\d+(?:\.\d+)?)\s*(?:g|gb)?/iu', $name, $match) === 1) {
            $dimensions['defense'] = $this->normalizeNumeric($match[1]).'G防御';
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(?:g|gb)\s*(?:防御|防护)/iu', $name, $match) === 1) {
            $dimensions['defense'] = $this->normalizeNumeric($match[1]).'G防御';
        }

        return $dimensions;
    }

    /**
     * remark 商品卡片提取（<li>键:值</li> 与 <br> 分隔行）。
     *
     * @return array<string, string>
     */
    private function fromRemark(Product $product): array
    {
        $remark = trim((string) ($product->remark ?? ''));
        if ($remark === '') {
            return [];
        }

        $dimensions = [];
        $segments = preg_split('/<\/?li[^>]*>|<br\s*\/?>/iu', $remark) ?: [];
        foreach ($segments as $segment) {
            $line = trim(strip_tags($segment));
            if ($line === '') {
                continue;
            }

            foreach ($this->parseKeyValueLine($line) as $key => $value) {
                $dimensions[$key] = $value;
            }
        }

        return $dimensions;
    }

    /**
     * 解析单行「键:值」。
     *
     * @return array<string, string>
     */
    private function parseKeyValueLine(string $line): array
    {
        if (preg_match('/^\s*(.+?)\s*[:：]\s*(.+?)\s*$/', $line, $match) !== 1) {
            return [];
        }

        $key = $this->matchDimension($match[1]);
        $value = trim($match[2]);
        if ($key === null || $value === '' || $this->isPlaceholderValue($value)) {
            return [];
        }

        return [$key => $value];
    }

    /**
     * config_options 提取：单选取首个可见子项；滑块取 qty_minimum（min=0 视为不含）；纯 0 值不展示。
     *
     * @return array<string, string>
     */
    private function fromConfigOptions(Product $product): array
    {
        $dimensions = [];

        foreach ((array) ($product->config_options ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $key = $this->matchDimension((string) ($option['field'] ?? $option['spec_key'] ?? $option['option_name'] ?? $option['name'] ?? ''));
            if ($key === null) {
                continue;
            }

            $value = $this->resolveConfigOptionValue($option);
            if ($value === '') {
                continue;
            }

            $dimensions[$key] = $value;
        }

        return $dimensions;
    }

    private function resolveConfigOptionValue(array $option): string
    {
        if (array_key_exists('qty_minimum', $option)) {
            $minimum = $option['qty_minimum'];
            if ($minimum === null || $minimum === '' || (float) $minimum <= 0) {
                return '';
            }

            $unit = trim((string) ($option['suffix_text'] ?? $option['unit'] ?? ''));

            return $this->normalizeNumeric((string) $minimum).($unit !== '' ? $unit : '');
        }

        foreach ([$option['sub'] ?? [], $option['sub_items'] ?? []] as $subOptions) {
            foreach ((array) $subOptions as $sub) {
                if (! is_array($sub) || (int) ($sub['hidden'] ?? 0) === 1) {
                    continue;
                }

                $value = $this->resolveSubValue($sub);
                if ($value !== '' && ! $this->isPlaceholderValue($value)) {
                    return $value;
                }
            }
        }

        return '';
    }

    private function resolveSubValue(array $sub): string
    {
        foreach (['option_name_first', 'value', 'id', 'option_name', 'label', 'version', 'name'] as $key) {
            $value = trim((string) ($sub[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function isPlaceholderValue(string $value): bool
    {
        $compact = Str::lower(preg_replace('/\s+/u', '', $value) ?? $value);

        return $compact === '0' || in_array($compact, ['-', '--', '—', '——', 'n/a', 'null', 'none', '无', '不含', '不提供'], true);
    }

    /**
     * 把文本匹配到规格维度 key（按定义顺序，具体先于宽泛）。
     */
    private function matchDimension(string $text): ?string
    {
        $normalized = Str::lower(trim($text));
        if ($normalized === '') {
            return null;
        }

        foreach (self::DIMENSIONS as $key => $definition) {
            foreach ($definition['keywords'] as $keyword) {
                if (str_contains($normalized, Str::lower($keyword))) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function normalizeNumeric(string $value): string
    {
        $number = (float) $value;

        if ((float) ((int) $number) === $number) {
            return (string) ((int) $number);
        }

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
