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
            $value = $this->cleanDimensionValue((string) ($merged[$key] ?? ''));
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

        // 面板型商品（虚拟主机 / CDN）没有 CPU / 内存语义：商品名里的「12T」是
        // WEB 空间、「100M」是数据库空间。按云主机规则推断会得到「12T高防主机 =
        // 内存 12T」这种假规格，因此这里只对计算型商品做 CPU/内存推断。
        if (! $this->isPanelProduct($product)) {
            if (preg_match('/(\d+(?:\.\d+)?)\s*(?:核|v?cpu|cores?|core)(?![a-z])/iu', $name, $match) === 1) {
                $dimensions['cpu'] = $this->normalizeNumeric($match[1]).'核';
            } elseif (preg_match('/(\d+)\s*[cC](?=[\s-]*\d)/u', $name, $match) === 1) {
                // 上游常见写法「4C4G20M」：C 就是核，之前只认「核 / CPU」，
                // 这类商品名会漏掉 CPU 规格。
                $dimensions['cpu'] = $this->normalizeNumeric($match[1]).'核';
            }

            if (preg_match('/(\d+(?:\.\d+)?)\s*(?:gb|g)(?![a-z])(?!\s*(?:防御|防护))/iu', $name, $match) === 1) {
                $dimensions['memory'] = $this->normalizeNumeric($match[1]).'G';
            } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(?:tb|t)(?![a-z])(?!\s*(?:流量))/iu', $name, $match) === 1) {
                $dimensions['memory'] = $this->normalizeNumeric($match[1]).'T';
            }
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
     * remark 商品卡片提取。
     *
     * 上游（魔方）商品的 description 是一段被 HTML 转义过的卡片片段，历史导入把它原样
     * 存进了 remark，例如 `&lt;li&gt;硬盘：30G SSD&lt;/li&gt;`。此前直接按 `</li>` 切分，
     * 转义后的文本一个标签都匹配不上，整段 remark 等于没读——描述里只剩商品名能推断的
     * 内存/带宽，硬盘、防御、流量、线路全部丢失。这里先解码实体，再按 li/br 切行，
     * 最后一行里同时尝试「键:值」与自由文本数字两种解析。
     *
     * @return array<string, string>
     */
    private function fromRemark(Product $product): array
    {
        $remark = trim((string) ($product->remark ?? ''));
        if ($remark === '') {
            return [];
        }

        // remark 在 varchar(255) 上存过，末尾可能被截断成半个多字节字符或半个标签
        // （如 `<span class`）。先洗掉非法字节，再在解码后清掉不完整标签。
        $remark = $this->scrubUtf8($remark);
        $decoded = html_entity_decode($remark, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/<br\s*\/?>/iu', "\n", $decoded) ?? $decoded;
        $decoded = preg_replace('/<\/li\s*>/iu', "\n", $decoded) ?? $decoded;
        // 标签替换成空格而不是直接删：`4G<span>INTEL</span>` 直接 strip_tags 会粘成
        // 「4GINTEL」，后面的数字正则就匹配不到了。
        $text = preg_replace('/<[^>]*>/u', ' ', $decoded) ?? $decoded;
        // 截断产生的残缺标签（没有闭合的 `>`）再清一次
        $text = preg_replace('/<[^>]*$/mu', ' ', $text) ?? $text;
        $text = $this->scrubUtf8(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $dimensions = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
            if ($line === '') {
                continue;
            }

            // 「硬盘：30G SSD」「防御：200Gbps」这类带维度名的行优先
            foreach ($this->parseKeyValueLine($line) as $key => $value) {
                $dimensions[$key] = $value;
            }

            // 「配置：4核 4G」这类没有维度名、但数字本身就是规格的行，
            // 再做一次自由文本提取；已有维度不覆盖。
            foreach ($this->extractDimensionsFromText($line) as $key => $value) {
                if (! isset($dimensions[$key]) || trim((string) $dimensions[$key]) === '') {
                    $dimensions[$key] = $value;
                }
            }
        }

        return $dimensions;
    }

    /**
     * 从一段自由文本里提取规格数字（商品名 / remark 的说明行共用同一套写法）。
     *
     * @return array<string, string>
     */
    private function extractDimensionsFromText(string $text): array
    {
        $dimensions = [];

        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:核|v?cpu|cores?|core)(?![a-z])/iu', $text, $match) === 1) {
            $dimensions['cpu'] = $this->normalizeNumeric($match[1]).'核';
        } elseif (preg_match('/(\d+)\s*[cC](?=[\s-]*\d)/u', $text, $match) === 1) {
            $dimensions['cpu'] = $this->normalizeNumeric($match[1]).'核';
        }

        // 容量行（硬盘 / 磁盘 / 存储 / 系统盘 / 数据盘）里的「30G」不是内存，
        // 不能因为只看到 G 就当成内存规格。
        $isCapacityLine = preg_match('/(?:硬盘|磁盘|存储|系统盘|数据盘)/u', $text) === 1;

        if (! $isCapacityLine
            && preg_match('/(\d+(?:\.\d+)?)\s*(?:gb|g)(?![a-z])/iu', $text, $match) === 1
            && preg_match('/'.preg_quote($match[0], '/').'\s*(?:bps|防御|防护|流量)/iu', $text) !== 1
        ) {
            $dimensions['memory'] = $this->normalizeNumeric($match[1]).'G';
        }

        if (preg_match('/(?:带宽)\s*[:：]?\s*([0-9.]+\s*[MG](?:bps)?(?:峰值|共享)?)/iu', $text, $match) === 1) {
            $dimensions['bandwidth'] = trim($match[1]);
        }

        if (preg_match('/(?:防御|防护|抗攻击)\s*[:：]?\s*([0-9.]+\s*[GT](?:bps)?)/iu', $text, $match) === 1) {
            $dimensions['defense'] = trim($match[1]);
        }

        if (preg_match('/(?:流量)\s*[:：]?\s*(不限流量|不限|无限|[0-9.]+\s*[TGM](?:B)?)/iu', $text, $match) === 1) {
            $dimensions['traffic'] = trim($match[1]);
        }

        if (preg_match('/(?:硬盘|磁盘|存储)\s*[:：]?\s*([0-9.]+\s*[TGM](?:B)?(?:\s*SSD)?)/iu', $text, $match) === 1) {
            $dimensions['disk'] = trim($match[1]);
        }

        if (preg_match('/(?:线路)\s*[:：]\s*([^\s，,;；]+)/u', $text, $match) === 1) {
            $dimensions['route'] = trim($match[1]);
        }

        if (preg_match('/(?:地区|机房|区域)\s*[:：]\s*([^\s，,;；]+)/u', $text, $match) === 1) {
            $dimensions['region'] = trim($match[1]);
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
        $value = $this->cleanDimensionValue($match[2]);
        if ($key === null || $value === '' || $this->isPlaceholderValue($value)) {
            return [];
        }

        return [$key => $value];
    }

    /**
     * 维度取值清洗：洗掉非法 UTF-8、残缺标签与多余空白。
     * remark 是被 varchar(255) 截断过的，末行常带 `<span class` 这类残片。
     */
    private function cleanDimensionValue(string $value): string
    {
        $value = $this->scrubUtf8($value);
        $value = preg_replace('/<[^>]*(?:>|$)/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        // mb_scrub 会把截断处的非法字节换成 '?'，这里把首尾残留的替换字符去掉
        $value = preg_replace('/^\?+|\?+$/u', '', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B，,;；");
    }

    /**
     * 丢弃非法 UTF-8 字节。
     *
     * 截断的 remark 会在多字节字符中间断开；mb_scrub 默认替换成 '?'，会留下
     * 「??20M峰值」这种脏值，json_encode 也可能因残留字节失败。这里优先用
     * iconv //IGNORE 直接丢弃，再做一次编码校验兜底。
     */
    private function scrubUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $scrubbed = function_exists('iconv')
            ? iconv('UTF-8', 'UTF-8//IGNORE', $value)
            : mb_scrub($value, 'UTF-8');

        if ($scrubbed === false) {
            $scrubbed = mb_scrub($value, 'UTF-8');
        }

        return mb_check_encoding($scrubbed, 'UTF-8')
            ? $scrubbed
            : (string) preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xC2-\xF4][\x80-\xBF]*/u', '', $scrubbed);
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

            $key = $this->matchDimension($this->resolveDimensionSource($option));
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

    /**
 * 维度匹配取值的来源：名称优先，field 仅作兜底。
     *
     * 魔方允许 field 复用——虚拟主机与 CDN 的全部配置项 field 都是 cpu。
     * 先读 field 会把「绑定域名数」「WEB空间」「流量限制」统统算成 CPU 亮点，
     * 名称才是规格语义。
     */
    /**
     * 面板型商品：虚拟主机与 CDN。
     *
     * 它们没有 CPU / 内存语义，操作入口全部由上游自定义区域承载，
     * 商品名里的容量数字是空间和带宽，不能按计算型商品解读。
     */
    private function isPanelProduct(Product $product): bool
    {
        return in_array(
            strtolower(trim((string) $product->product_type)),
            ['web_hosting', 'hostingaccount', 'hosting', 'cdn'],
            true
        );
    }

    private function resolveDimensionSource(array $option): string
    {
        return (string) ($option['name'] ?? $option['option_name'] ?? $option['spec_key'] ?? $option['field'] ?? '');
    }

    private function resolveConfigOptionValue(array $option): string
    {
        // 先取子项展示值：下拉 / 单选 / 多选的真实规格在 sub 里。
        //
        // 此前先判断 qty_minimum 字段是否存在——升级后的导入数据给每个配置项都补了
        // qty_minimum（非滑块为 0），于是所有下拉项都在这里被当成「最小值为 0」直接
        // 返回空串，描述里连 CPU / 内存 / 硬盘都出不来。现在只有确实没有可用子项的
        // 范围型配置项，才按 qty_minimum 取值。
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

        $minimum = $option['qty_minimum'] ?? null;
        if (is_numeric($minimum) && (float) $minimum > 0) {
            $unit = trim((string) ($option['suffix_text'] ?? $option['unit'] ?? ''));

            return $this->normalizeNumeric((string) $minimum).($unit !== '' ? $unit : '');
        }

        return '';
    }

    /**
     * 子项的展示文案：优先 version / option_name 这类可读文本，纯数字是传给上游的
     * 参数值（如 os 的 "12"），不能当规格展示。
     */
    private function resolveSubValue(array $sub): string
    {
        $version = trim((string) ($sub['version'] ?? ''));
        if ($version !== '') {
            // 魔方 OS 子项形如 "CentOS^CentOS-7.6.1810-x64"，^ 后才是真正的版本名
            if (str_contains($version, '^')) {
                $segments = array_values(array_filter(array_map('trim', explode('^', $version))));
                $label = end($segments);

                return $label !== false && $label !== '' ? $label : $version;
            }

            return $version;
        }

        foreach (['option_name', 'label', 'name', 'option_name_first', 'value'] as $key) {
            $value = trim((string) ($sub[$key] ?? ''));
            if ($value !== '' && preg_match('/^\d+$/', $value) !== 1) {
                return $value;
            }
        }

        // 全是数字时（IP 数量、内存 MB 等）保留原值
        foreach (['option_name_first', 'value', 'id'] as $key) {
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
