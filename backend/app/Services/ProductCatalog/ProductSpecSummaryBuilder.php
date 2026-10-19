<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Models\Product;
use App\Support\ProductConfigOptionPresenter;
use Illuminate\Support\Str;

/**
 * 商品规范规格摘要。
 *
 * 背景：上游商品名把「线路描述」和「规格」混在一起写，写法还各不相同——
 *   「宿迁AMD 锐龙 Threadripper 3960X三线BGP 2G2H」「枣庄BGP 13900K 2核2G」
 *   「襄阳一区 2H2G20M」「宿迁E5-2696V2 8g8h」「内蒙电信 8核8G45M」
 * 核数写 2核 / 2H / 2C / 2核心，内存写 2G / 2GiB / 2048，带宽写 20M / 20Mbps，
 * 有的名字带三级分组、有的只有配置，空格也不统一。同一目录里横向比较时完全对不上。
 *
 * 摘要口径固定为「核数 + 内存 + 带宽」，单位固定 核 / G / Mbps，数字紧跟单位、
 * 段间不加空格（业内通行写法，如 2核4G20Mbps）。摘要只描述规格，不含线路描述
 * ——线路与区域由三级分组和商品的规格行承载，名字里再写一遍是重复。
 *
 * 规格值的来源分两种情况：
 *
 * 1. 商品配置里该维度只有**一个**取值（绝大多数商品）——配置就是权威，直接用它，
 *    不管名字里写没写（「英国伦敦 TCVM - Plus」名字里没有规格，配置里有 2核2G）；
 * 2. 商品配置给的是**规格族**（同一组商品的 CPU 选项是 4核/8核/12核/16核…，
 *    具体哪一档只写在名字里）——这时从商品自己的名字里取，并用规格族做校验：
 *    名字里解析出的数字必须落在该商品的允许取值集合内，落在集合外的数字
 *    （型号 3960X、2696V2、年份 2024 等）一律忽略。
 *
 * 没有 CPU / 内存规格的商品（虚拟主机、CDN、域名……）返回空串，由调用方回退到
 * 原商品名。
 */
class ProductSpecSummaryBuilder
{
    /** 核数单位：上游用 H（2H）与 C（2C）表示核，核心/核/vCPU 同义 */
    private const CPU_UNITS = ['核', '核心', '线程', 'h', 'c', 'vcpu', 'cpu', 'core', 'cores'];

    /**
     * 构造商品规格摘要。
     *
     * @param  array<string, mixed>  $configSnapshot  预留：下单时选定的配置快照
     */
    public function build(Product $product, array $configSnapshot = []): string
    {
        $allowed = $this->resolveAllowedValues($product);

        // 没有处理器规格 → 不是「按规格售卖」的商品，别硬凑摘要。
        if ($allowed['cpu']['labels'] === [] || $allowed['memory']['labels'] === []) {
            return '';
        }

        $name = $this->resolveSourceName($product);
        $fromName = $name === '' ? [] : $this->extractSpecFromName($name, $allowed);

        $cpu = $this->formatCpu($this->resolveDimensionValue('cpu', $allowed, $fromName));
        $memory = $this->formatMemory($this->resolveDimensionValue('memory', $allowed, $fromName));
        if ($cpu === '' || $memory === '') {
            return '';
        }

        $parts = [$cpu, $memory];

        $bandwidth = $this->formatBandwidth($this->resolveDimensionValue('bw', $allowed, $fromName));
        if ($bandwidth !== '') {
            $parts[] = $bandwidth;
        }

        return implode('', $parts);
    }

    /**
     * 各规格维度的允许取值。
     *
     * labels 是配置项文案里的取值（含单位），values 是子项传参值（常是裸数字）。
     * 两者都用于校验名字里解析出的数字是否合法。
     *
     * @return array{cpu: array{labels: array<int, array{number: float, unit: string}>, values: array<int, float>}, memory: array{labels: array<int, array{number: float, unit: string}>, values: array<int, float>}, bw: array{labels: array<int, array{number: float, unit: string}>, values: array<int, float>}}
     */
    private function resolveAllowedValues(Product $product): array
    {
        $allowed = [
            'cpu' => ['labels' => [], 'values' => []],
            'memory' => ['labels' => [], 'values' => []],
            'bw' => ['labels' => [], 'values' => []],
        ];

        foreach ((array) ($product->config_options ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $dimension = $this->resolveDimension($option);
            if ($dimension === '') {
                continue;
            }

            foreach ($this->collectOptionLabels($option) as $label) {
                $allowed[$dimension]['labels'][(string) $label['number']] = $label;
            }

            foreach ($this->collectOptionValues($option) as $value) {
                $allowed[$dimension]['values'][(string) $value] = $value;
            }
        }

        return $allowed;
    }

    /**
     * 配置项文案里的取值（带单位），例如「2核」「2G」「20Mbps」「10M上行 80M下行」。
     *
     * @param  array<string, mixed>  $option
     * @return array<int, array{number: float, unit: string}>
     */
    private function collectOptionLabels(array $option): array
    {
        $labels = [];

        $texts = [];
        foreach ((array) ($option['sub'] ?? $option['sub_items'] ?? []) as $sub) {
            if (! is_array($sub) || (int) ($sub['hidden'] ?? 0) === 1) {
                continue;
            }
            $texts[] = (string) ($sub['option_name'] ?? '');
            $texts[] = (string) ($sub['version'] ?? '');
        }

        foreach ($texts as $text) {
            foreach ($this->parseNumberWithUnit($text) as $parsed) {
                $labels[(string) $parsed['number']] = $parsed;
            }
        }

        return array_values($labels);
    }

    /**
     * 配置项的传参值（常是裸数字，如内存 2048、CPU 4）。
     *
     * @param  array<string, mixed>  $option
     * @return array<int, float>
     */
    private function collectOptionValues(array $option): array
    {
        $values = [];

        $texts = [(string) ($option['value'] ?? '')];
        foreach ((array) ($option['sub'] ?? $option['sub_items'] ?? []) as $sub) {
            if (! is_array($sub) || (int) ($sub['hidden'] ?? 0) === 1) {
                continue;
            }
            $texts[] = (string) ($sub['option_name_first'] ?? $sub['value'] ?? '');
        }

        foreach ($texts as $text) {
            if (preg_match('/\d+(?:\.\d+)?/', $text, $matches) === 1) {
                $values[(string) $matches[0]] = (float) $matches[0];
            }
        }

        return array_values($values);
    }

    /**
     * 判定配置项属于哪个规格维度（cpu / memory / bw），不属于规格返回空串。
     *
     * @param  array<string, mixed>  $option
     */
    private function resolveDimension(array $option): string
    {
        $field = $this->normalizeField((string) ($option['field'] ?? ''));
        $name = trim((string) ($option['name'] ?? $option['option_name'] ?? ''));

        if ($field === 'cpu' && $this->nameMatches($name, ProductConfigOptionPresenter::CPU_KEYWORDS)) {
            return 'cpu';
        }

        if ($field === 'memory' && $this->nameMatches($name, ProductConfigOptionPresenter::MEMORY_KEYWORDS)) {
            return 'memory';
        }

        if ($this->isBandwidthOption($field, $name, (int) ($option['option_type'] ?? 0))) {
            return 'bw';
        }

        return '';
    }

    /**
     * 带宽配置项判定。
     *
     * field 是上游的传参键、允许复用（虚拟主机的全部配置项 field 都是 cpu），
     * 所以除了字段名还要看名称：只有名称也带带宽语义的才认。
     */
    private function isBandwidthOption(string $field, string $name, int $optionType): bool
    {
        $bandwidthNames = ['带宽', '网速', '速度', 'bandwidth', 'speed'];

        if ($field !== '' && $name !== '' && ! $this->nameMatches($name, $bandwidthNames)) {
            return false;
        }

        if (in_array($field, ['bw', 'band', 'bandwidth', 'speed', 'in_bw', 'out_bw'], true)) {
            return true;
        }

        return in_array($optionType, [10, 11, 18], true) && $this->nameMatches($name, $bandwidthNames);
    }

    /**
     * 取某个维度的规格值。
     *
     * 配置里只有唯一取值时用配置（权威）；是规格族时用名字里解析出的值。
     *
     * @param  array<string, array{labels: array<int, array{number: float, unit: string}>, values: array<int, float>}>  $allowed
     * @param  array<string, array{number: float, unit: string, upload?: float, download?: float}>  $fromName
     * @return array{number: float, unit: string, upload?: float, download?: float}|null
     */
    private function resolveDimensionValue(string $dimension, array $allowed, array $fromName): ?array
    {
        $labels = $allowed[$dimension]['labels'] ?? [];

        if (count($labels) === 1) {
            return array_values($labels)[0];
        }

        if ($labels === []) {
            return null;
        }

        return $fromName[$dimension] ?? null;
    }

    /**
     * 从名字里解析规格。
     *
     * @param  array<string, array{labels: array<int, array{number: float, unit: string}>, values: array<int, float>}>  $allowed
     * @return array<string, array{number: float, unit: string, upload?: float, download?: float}>
     */
    private function extractSpecFromName(string $name, array $allowed): array
    {
        $spec = [];

        // 上下行写法优先：「10M上行 80M下行」要按上下行分别读，不能当成两个并列值。
        if (preg_match('/(\d+(?:\.\d+)?)\s*[A-Za-z]*\s*上行\s*(\d+(?:\.\d+)?)\s*[A-Za-z]*\s*下行/u', $name, $matches) === 1) {
            $upload = (float) $matches[1];
            $download = (float) $matches[2];
            if ($this->containsNumber($allowed['bw'], $upload) && $this->containsNumber($allowed['bw'], $download)) {
                $spec['bw'] = [
                    'number' => $upload,
                    'unit' => 'M',
                    'upload' => $upload,
                    'download' => $download,
                ];
            }
        }

        // 中文单位必须排在 [A-Za-z]* 前面：后者能匹配空串，放在前面会把「2核」
        // 拆成「2 + 无单位」，核数就永远认不出来。
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(核|核心|线程|[A-Za-z]*)/u', $name, $tokenMatches, PREG_SET_ORDER) === false) {
            return $spec;
        }

        foreach ($tokenMatches as $token) {
            $number = (float) $token[1];
            $unit = strtolower(trim($token[2] ?? ''));

            if ($unit === '') {
                continue;
            }

            $dimension = $this->resolveTokenDimension($unit, $number, $allowed);
            if ($dimension === '' || isset($spec[$dimension])) {
                continue;
            }

            $spec[$dimension] = ['number' => $number, 'unit' => $unit];
        }

        return $spec;
    }

    /**
     * 单位 + 数字 → 维度。是否合法由允许集合判断，单位只负责缩小范围。
     *
     * @param  array<string, array{labels: array<int, array{number: float, unit: string}>, values: array<int, float>}>  $allowed
     */
    private function resolveTokenDimension(string $unit, float $number, array $allowed): string
    {
        if (in_array($unit, self::CPU_UNITS, true)) {
            return 'cpu';
        }

        // M / G 既可能是内存也可能是带宽（「2G」是内存、「20M」是带宽），
        // 靠「数字落在哪个允许集合里」区分。
        if (in_array($unit, ['m', 'mb', 'mib', 'g', 'gb', 'gib'], true)) {
            if ($this->containsNumber($allowed['memory'], $number)) {
                return 'memory';
            }

            if ($this->containsNumber($allowed['bw'], $number)) {
                return 'bw';
            }

            return '';
        }

        if (in_array($unit, ['t', 'tb'], true)) {
            return $this->containsNumber($allowed['memory'], $number) ? 'memory' : '';
        }

        if (in_array($unit, ['k', 'kb', 'mbps', 'gbps', 'kbps'], true)) {
            return $this->containsNumber($allowed['bw'], $number) ? 'bw' : '';
        }

        return '';
    }

    /**
     * 数字是否落在该维度的允许集合内（配置文案取值 ∪ 传参值）。
     *
     * @param  array{labels: array<int, array{number: float, unit: string}>, values: array<int, float>}  $dimension
     */
    private function containsNumber(array $dimension, float $number): bool
    {
        foreach ($dimension['labels'] as $label) {
            if (abs($label['number'] - $number) < 0.0001) {
                return true;
            }
        }

        foreach ($dimension['values'] as $value) {
            if (abs($value - $number) < 0.0001) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{number: float, unit: string, upload?: float, download?: float}|null  $value
     */
    private function formatCpu(?array $value): string
    {
        if ($value === null) {
            return '';
        }

        return $this->normalizeNumber($value['number']).'核';
    }

    /**
     * @param  array{number: float, unit: string, upload?: float, download?: float}|null  $value
     */
    private function formatMemory(?array $value): string
    {
        if ($value === null) {
            return '';
        }

        $number = $value['number'];
        $unit = $value['unit'];

        if (in_array($unit, ['t', 'tb'], true)) {
            return $this->normalizeNumber($number).'T';
        }

        if (in_array($unit, ['m', 'mb', 'mib'], true)) {
            return $number >= 1024
                ? $this->normalizeNumber($number / 1024).'G'
                : $this->normalizeNumber($number).'M';
        }

        if ($unit === '') {
            // 裸数字按 MB 理解（上游内存传参值常是 2048）。
            return $number >= 1024
                ? $this->normalizeNumber($number / 1024).'G'
                : $this->normalizeNumber($number).'M';
        }

        return $this->normalizeNumber($number).'G';
    }

    /**
     * @param  array{number: float, unit: string, upload?: float, download?: float}|null  $value
     */
    private function formatBandwidth(?array $value): string
    {
        if ($value === null) {
            return '';
        }

        if (isset($value['upload'], $value['download'])) {
            $upload = $this->formatSingleBandwidth($value['upload'], 'M');
            $download = $this->formatSingleBandwidth($value['download'], 'M');

            if ($upload === '' || $download === '') {
                return '';
            }

            return $upload === $download ? $upload : '上行'.$upload.'/下行'.$download;
        }

        return $this->formatSingleBandwidth($value['number'], $value['unit']);
    }

    /**
     * 带宽单位用 Mbps 而不是 M：内存也是 M（「1核512M」），紧挨着写会看不出边界
     * （512M100M）。G 级别的带宽保留 Gbps。
     */
    private function formatSingleBandwidth(float $number, string $unit): string
    {
        $normalized = $this->normalizeNumber($number);

        if (str_starts_with($unit, 'k')) {
            return $normalized.'Kbps';
        }

        if (str_starts_with($unit, 'g')) {
            return $normalized.'Gbps';
        }

        return $normalized.'Mbps';
    }

    /**
     * 解析文案里的「数字 + 单位」片段。
     *
     * @return array<int, array{number: float, unit: string}>
     */
    private function parseNumberWithUnit(string $text): array
    {
        $parsed = [];

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(核|核心|线程|[A-Za-z]*)/u', $text, $matches, PREG_SET_ORDER) === false) {
            return $parsed;
        }

        foreach ($matches as $match) {
            $unit = strtolower(trim($match[2] ?? ''));
            if ($unit === '') {
                continue;
            }
            $parsed[] = ['number' => (float) $match[1], 'unit' => $unit];
        }

        return $parsed;
    }

    /**
     * 名字来源：优先运营/同步写下来的自定义名，其次 remark。
     *
     * 直接读 attributes 而不是 getRawOriginal：后者只读 original 数组，
     * 对 new Product([...]) 这类未落库实例恒为 null（单测与命令里都要复用）。
     */
    private function resolveSourceName(Product $product): string
    {
        $attributes = $product->getAttributes();

        foreach ([$attributes['custom_display_name'] ?? null, $attributes['remark'] ?? null] as $candidate) {
            $normalized = trim((string) $candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $keywords
     */
    private function nameMatches(string $name, array $keywords): bool
    {
        $value = Str::lower(trim($name));
        if ($value === '') {
            return true;
        }

        foreach ($keywords as $keyword) {
            $needle = Str::lower(trim((string) $keyword));
            if ($needle !== '' && str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeField(string $field): string
    {
        $normalized = Str::lower(trim($field));
        $normalized = str_replace(['-', ' '], '_', $normalized);

        return match ($normalized) {
            'cpu', 'vcpu', 'core', 'cores', 'cpu_core', 'cpu_cores', 'cpu_num', 'cpu_number', 'core_num', 'cores_num' => 'cpu',
            'memory', 'ram', 'mem', 'memory_size', 'mem_size', 'ram_size', 'memory_num', 'ram_num' => 'memory',
            default => $normalized,
        };
    }

    private function normalizeNumber(float|int|string $value): string
    {
        $number = (float) $value;

        if ((float) ((int) $number) === $number) {
            return (string) ((int) $number);
        }

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
