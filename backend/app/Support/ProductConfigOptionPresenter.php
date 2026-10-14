<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * 商品配置项呈现补齐器（只读）。
 *
 * 上游把「滑块范围」「购买提示文字」等信息同步到商品时，是直接整段写入
 * products.config_options 的。补齐能力修好之后，新同步的数据会带上
 * option_mode / qty_step / unit / text_content / submit_field 等字段；
 * 但历史商品在库里仍是旧结构，只重新拉一次模板才会被覆盖。
 *
 * 这里对读取到的配置项做一次幂等的补齐：
 *   - option_mode 为空时按 option_type 推导（区间型 → range，否则 select）；
 *   - 识别「明确禁止」这类购买提示，归为 text 并取出提示文案；
 *   - 区间型父项缺范围时回退到唯一子项的 qty_minimum/qty_maximum；
 *   - 区间型缺步长时按 qty_step → qty_stage → 子项 → 1 依次回退；
 *   - 子项缺真实传参值时，从父项 parameter（值|文案,值|文案）反查；
 *   - 分配商品内唯一的 form_key（field 被上游复用时按 config_id 补位）。
 *
 * 关于 field：魔方（智简魔方财务）里 field 只是一个可读标签、允许重复——
 * 虚拟主机与 CDN 的全部配置项 field 都是 cpu。商品↔配置项的关联靠
 * shd_product_config_links 的配置项 id，下单也按 configoption 的配置项 id
 * 传值，field 从来不是标识。凡是拿 field 当唯一标识或当 CPU/内存语义用的地方，
 * 都会把「绑定域名数」「WEB空间」误当成处理器规格（参见 isProcessorSpecOption）。
 *
 * 全部为读取时计算，不写库。判据与 TuraOpenApi 的同步归一化保持一致，
 * 同步后写入的新结构本身就是完整的，这里的补齐对其为无操作。
 */
final class ProductConfigOptionPresenter
{
    /** 处理器规格关键词。与官网前端 machineSpecResolver 的 CPU_KEYWORDS 同口径。 */
    public const CPU_KEYWORDS = ['cpu', 'core', '处理器', '核'];

    /** 内存规格关键词。与官网前端 machineSpecResolver 的 MEMORY_KEYWORDS 同口径。 */
    public const MEMORY_KEYWORDS = ['memory', 'ram', '内存'];
    /** 区间型（滑块）配置项 option_type */
    public const RANGE_OPTION_TYPES = [4, 7, 9, 11, 14, 15, 16, 17, 18, 19];

    /**
     * 归一化时采用的区间型取值（4=quantity，取自 ZProductService 官方映射）。
     *
     * 历史数据把区间项存成了字符串 'quantity'，而下游（SiteProductDetailResource
     * 等）普遍按 (int) 取值，'quantity' 会被转成 0 —— 购买页因此判不出区间型，
     * 把滑块渲染成「没有子项的选择项」。
     */
    public const CANONICAL_RANGE_TYPE = 4;

    /** 文字提示型配置项的呈现类型 */
    public const MODE_TEXT = 'text';

    /**
     * 提示项名称关键词。与插件侧保持一致：仅靠名称命中会误伤取值是真实
     * 规格的项（如「说明：2核」），因此还需配合下面的结构判据取或。
     */
    private const TEXT_NOTICE_NAME_KEYWORDS = [
        '明确禁止', '禁止', '须知', '提示', '说明', '警告', '声明', '温馨',
    ];

    /**
     * 补齐整组配置项。
     *
     * @param  mixed  $configOptions
     * @return array<int, array<string, mixed>>
     */
    public static function present(mixed $configOptions): array
    {
        if (! is_array($configOptions)) {
            return [];
        }

        $items = Collection::make($configOptions)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => self::presentOne($item))
            ->values()
            ->all();

        return self::assignFormKeys($items);
    }

    /**
     * 分配商品内唯一的表单键 form_key。
     *
     * field 是上游的传参键，会被复用到与规格无关的项上：虚拟主机与 CDN 的
     * 全部配置项（绑定域名数、WEB空间、带宽、流量、连接数…）field 都是 cpu。
     * 本系统各处（官网表单、订单计价、上游提交）历来直接拿 field 当键，
     * 于是这些项互相覆盖 —— 官网只渲染第一个、其余项消失，订单只记一个值、
     * 计价取到的是最后一项的价格。
     *
     * 这里给出稳定的唯一键：field 本身唯一时原样返回（云主机等存量商品
     * 行为不变），重复时用上游配置项 ID（config_id，其次自增 id）补位。
     * 上游提交仍在 buildUpstreamProvisionConfig 里映射回 field，不依赖本键。
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private static function assignFormKeys(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $field = trim((string) ($item['field'] ?? ''));
            if ($field !== '') {
                $counts[$field] = ($counts[$field] ?? 0) + 1;
            }
        }

        $used = [];
        foreach ($items as $index => $item) {
            $field = trim((string) ($item['field'] ?? ''));

            if ($field === '') {
                $items[$index]['form_key'] = '';
                continue;
            }

            $formKey = $field;
            if (($counts[$field] ?? 0) > 1) {
                $identity = trim((string) ($item['config_id'] ?? ''))
                    ?: trim((string) ($item['id'] ?? ''))
                    ?: (string) ($index + 1);
                $formKey = $field.'#'.$identity;
            }

            $unique = $formKey;
            for ($suffix = 2; isset($used[$unique]); $suffix++) {
                $unique = $formKey.'-'.$suffix;
            }

            $used[$unique] = true;
            $items[$index]['form_key'] = $unique;
        }

        return $items;
    }

    /**
     * 判定配置项是否区间型（滑块/数量）。
     *
     * 正常值是 RANGE_OPTION_TYPES 里的数值。额外兼容 option_type='quantity'：
     * 后台编辑页历史上把 range 项存成这个字符串，而 (int)'quantity' 是 0，
     * 直接比对会被判成非区间型 —— 数量范围退化成 0~0 的滑块。
     * 新写入的数据已改用数值 4，这条只用于读存量。
     */
    /**
     * 配置项是否真的是处理器／内存规格。
     *
     * 魔方的 field 只是可读标签、允许复用（虚拟主机与 CDN 的全部配置项
     * field 都是 cpu），不能当标识用。只凭 field 判定会把「绑定域名数」
     * 「WEB空间」当成处理器规格，进而写进商品名和规格亮点——虚拟主机
     * 「12T高防主机」就是这样被标成「内存 12T」的。
     *
     * 因此名称必须同时命中；名称为空时没有第二判据，退回只看 field。
     *
     * @param  list<string>  $keywords
     */
    /**
     * 按「配置项 id => 值」组装提交给上游的配置。
     *
     * 口径对齐魔方（zjmf376 app/common/logic/Host.php:104-109）：
     *   $post_data["configoption"][$config_upstream] = $sub->upstream_id;  // 单选
     *   $post_data["configoption"][$config_upstream] = $qty;               // 区间
     * 键是配置项的上游 id、值是子项 id 或数量；field 只在本系统内用于取回提交值。
     *
     * 对端 TuraIDC 的 HandlesOrderCalculation::normalizeConfig 同样只按配置项 id
     * 与子项 id 匹配提交键（键是 field 会被整段丢弃），所以这里必须用 id。
     * 本地配置项的 id 就是上游实例的配置项 id —— 同步时由上游响应原样落库。
     *
     * @param  array<string, mixed>  $submitted  以 form_key / field 为键的提交值
     * @return array<int, mixed>  配置项 id => 子项 id 或数量
     */
    public static function submitConfigByOptionId(mixed $configOptions, array $submitted): array
    {
        $payload = [];

        foreach (self::present($configOptions) as $item) {
            $optionId = (int) ($item['id'] ?? 0);
            if ($optionId <= 0) {
                continue;
            }

            $value = self::pickSubmittedValue($item, $submitted);
            if ($value === null || $value === '') {
                continue;
            }

            // 提示型配置项（如「明确禁止」）只用于前台展示购买须知，不参与提交。
            // 这里按配置项逐项判断即可：上游复用 field 之后，原先那种「按 field
            // 聚合、该 field 下全是提示项才过滤」的做法已经区分不出真实规格项了。
            if (trim((string) ($item['option_mode'] ?? '')) === self::MODE_TEXT) {
                continue;
            }

            $isRange = self::isRangeOptionType($item['option_type'] ?? 0)
                || trim((string) ($item['option_mode'] ?? '')) === 'range';

            if ($isRange) {
                // 区间型传数量本身
                if (is_numeric($value) && (float) $value > 0) {
                    $payload[$optionId] = (int) $value;
                }

                continue;
            }

            // 单选型传子项 id
            $subId = self::resolveSubIdByValue($item, (string) $value);
            if ($subId > 0) {
                $payload[$optionId] = $subId;
            }
        }

        return $payload;
    }

    /**
     * 取提交值：优先按商品内唯一的 form_key，再退回 field（存量快照两种都有）。
     *
     * @param  array<string, mixed>  $submitted
     */
    private static function pickSubmittedValue(array $item, array $submitted): mixed
    {
        foreach ([$item['form_key'] ?? '', $item['field'] ?? ''] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && array_key_exists($candidate, $submitted)) {
                return $submitted[$candidate];
            }
        }

        return null;
    }

    /**
     * 把提交值反查成子项 id。提交值通常是 option_name_first 这类文案
     * （官网提交的就是它），上游协议要的却是子项 id。
     *
     * @param  array<string, mixed>  $item
     */
    private static function resolveSubIdByValue(array $item, string $value): int
    {
        $candidate = trim($value);
        if ($candidate === '') {
            return 0;
        }

        foreach ((array) ($item['sub'] ?? []) as $sub) {
            if (! is_array($sub)) {
                continue;
            }

            foreach (['value', 'option_name_first', 'option_name', 'version', 'label'] as $field) {
                if (trim((string) ($sub[$field] ?? '')) !== $candidate) {
                    continue;
                }

                $subId = (int) ($sub['id'] ?? 0);
                if ($subId > 0) {
                    return $subId;
                }
            }
        }

        // 提交值本身就是子项 id
        return ctype_digit($candidate) ? (int) $candidate : 0;
    }

    public static function isProcessorSpecOption(array $item, array $keywords): bool
    {
        return self::isProcessorSpecField(
            (string) ($item['field'] ?? ''),
            (string) ($item['name'] ?? $item['option_name'] ?? ''),
            $keywords
        );
    }

    /**
     * isProcessorSpecOption 的显式字段版。
     *
     * 传入的 field 可以是解析后的结果（调用方可能先用 option_type 兜底推出过
     * field），名称判据照旧生效——虚拟主机的配置项 option_type 是 6，经
     * TYPE_FIELD_MAP 映射同样是 cpu，只校验原始 field 拦不住。
     *
     * @param  list<string>  $keywords
     */
    public static function isProcessorSpecField(?string $field, string $name, array $keywords): bool
    {
        if (! self::containsAnyKeyword((string) $field, $keywords)) {
            return false;
        }

        $name = trim($name);

        return $name === '' || self::containsAnyKeyword($name, $keywords);
    }

    /**
     * @param  list<string>  $keywords
     */
    private static function containsAnyKeyword(string $text, array $keywords): bool
    {
        $value = Str::lower(trim($text));
        if ($value === '') {
            return false;
        }

        foreach ($keywords as $keyword) {
            $needle = Str::lower(trim((string) $keyword));
            if ($needle !== '' && str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function isRangeOptionType(mixed $optionType): bool
    {
        if (strtolower(trim((string) $optionType)) === 'quantity') {
            return true;
        }

        return in_array((int) $optionType, self::RANGE_OPTION_TYPES, true);
    }

    /**
     * 补齐单个配置项。
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function presentOne(array $item): array
    {
        $isRange = self::isRangeOptionType($item['option_type'] ?? 0);
        $parameter = trim((string) ($item['parameter'] ?? ''));
        $subOptions = self::presentSubOptions($item['sub'] ?? $item['sub_items'] ?? [], $parameter);
        $name = trim((string) ($item['name'] ?? $item['option_name'] ?? ''));
        $isTextNotice = self::isTextNotice($name, $subOptions);

        [$rangeMin, $rangeMax] = self::resolveRangeBounds($item, $subOptions, $isRange);

        $item['option_mode'] = self::resolveMode($item, $isRange, $isTextNotice);

        // option_type 一并归一化，否则区间语义会在下游丢失。
        //
        // 下游（SiteProductDetailResource 等）普遍按 (int) 读 option_type，
        // 而历史数据里区间项存的是字符串 'quantity'，(int) 后为 0：
        // 购买页的 QTY_OPTION_TYPES 判定落空，滑块被渲染成没有子项的选择项。
        // 这里统一成一个真实的区间型数值，让所有下游都不必各自兼容历史写法。
        if ($isRange) {
            $rawType = (int) ($item['option_type'] ?? 0);
            $item['option_type'] = in_array($rawType, self::RANGE_OPTION_TYPES, true)
                ? $rawType
                : self::CANONICAL_RANGE_TYPE;
        }

        $item['qty_minimum'] = $rangeMin;
        $item['qty_maximum'] = $rangeMax;
        $item['qty_step'] = self::resolveRangeStep($item, $subOptions, $isRange);
        $item['unit'] = trim((string) ($item['unit'] ?? $item['suffix_text'] ?? ''));
        $item['submit_field'] = trim((string) ($item['submit_field'] ?? $item['field'] ?? ''));
        $item['text_content'] = $isTextNotice
            ? self::resolveTextContent($subOptions, $parameter)
            : trim((string) ($item['text_content'] ?? $item['description'] ?? ''));
        $item['sub'] = $subOptions;

        if (array_key_exists('sub_items', $item)) {
            $item['sub_items'] = $subOptions;
        }

        return $item;
    }

    /**
     * 判定配置项是否实为「购买提示文字」。
     *
     * 上游没有专门的提示类型，只能从结构反推：命中提示关键词，
     * 且整个配置项只有一条子项、子项文案是一句长句（正常规格值都是
     * 「16核」「500G」这类短值，不会用整句中文）。
     *
     * 关键词命中也要求满足上述结构判据：有些真实规格的名称里带
     * 「说明」「备注」等词（如「带宽说明」），只看关键词会把它误判成提示项，
     * 导致真实规格不参与计价。
     *
     * @param  array<int, array<string, mixed>>  $subOptions
     */
    public static function isTextNotice(string $name, array $subOptions): bool
    {
        if (count($subOptions) !== 1) {
            return false;
        }

        $onlySubName = trim((string) ($subOptions[0]['option_name'] ?? ''));
        // 结构判据：单子项 + 整句中文提示文案
        if (mb_strlen($onlySubName) < 12
            || preg_match('/[\x{4e00}-\x{9fa5}]/u', $onlySubName) !== 1) {
            return false;
        }

        foreach (self::TEXT_NOTICE_NAME_KEYWORDS as $keyword) {
            if (str_contains($name, $keyword)) {
                return true;
            }
        }

        // 单子项且取值是整句中文，本身就是提示文案，不要求名称命中关键词
        return true;
    }

    /**
     * 区间型优先于既有取值模式判定，提示项优先级最高：
     * 上游可能给提示项也带上区间型 option_type，但它本质是文字。
     *
     * @param  array<string, mixed>  $item
     */
    private static function resolveMode(array $item, bool $isRange, bool $isTextNotice): string
    {
        // 上游已明确给了模式就以此为准。提示项靠结构反推，反推可能误伤
        // 真实规格（如名称含「说明」的单子项规格），不该反过来覆盖上游的显式配置。
        $mode = trim((string) ($item['option_mode'] ?? ''));
        if ($mode !== '') {
            return $mode;
        }

        return $isTextNotice ? self::MODE_TEXT : ($isRange ? 'range' : 'select');
    }

    /**
     * 区间范围既可能挂在父项上，也可能只挂在唯一子项上（上游「数据盘」
     * 就是父项为 0、子项带 0~500）。父项缺失时回退子项，
     * 否则后台编辑弹窗的「数据范围」会是空的。
     *
     * @param  array<int, array<string, mixed>>  $subOptions
     * @return array{0: int, 1: int}
     */
    private static function resolveRangeBounds(array $item, array $subOptions, bool $isRange): array
    {
        $min = (int) ($item['qty_minimum'] ?? 0);
        $max = (int) ($item['qty_maximum'] ?? 0);

        if (! $isRange || $max > 0) {
            return [$min, $max];
        }

        $onlySub = count($subOptions) === 1 ? $subOptions[0] : [];
        $subMin = (int) ($onlySub['qty_minimum'] ?? 0);
        $subMax = (int) ($onlySub['qty_maximum'] ?? 0);

        return $subMax > 0 ? [$subMin, $subMax] : [$min, $max];
    }

    /**
     * 步长按 qty_step → qty_stage → 唯一子项 → 1 依次回退。
     *
     * @param  array<int, array<string, mixed>>  $subOptions
     */
    private static function resolveRangeStep(array $item, array $subOptions, bool $isRange): int
    {
        $step = (int) ($item['qty_step'] ?? 0);
        if ($step > 0) {
            return $step;
        }

        $step = (int) ($item['qty_stage'] ?? 0);
        if ($step > 0) {
            return $step;
        }

        if (! $isRange) {
            return 1;
        }

        $onlySub = count($subOptions) === 1 ? $subOptions[0] : [];
        $subStep = (int) ($onlySub['qty_step'] ?? $onlySub['qty_stage'] ?? 0);

        return $subStep > 0 ? $subStep : 1;
    }

    /**
     * 取提示项要展示的文案：优先唯一子项文案，其次 parameter 的文案段。
     *
     * @param  array<int, array<string, mixed>>  $subOptions
     */
    private static function resolveTextContent(array $subOptions, string $parameter): string
    {
        $onlySubName = trim((string) ($subOptions[0]['option_name'] ?? ''));
        if ($onlySubName !== '') {
            return $onlySubName;
        }

        if ($parameter === '') {
            return '';
        }

        $labels = [];
        foreach (explode(',', $parameter) as $pair) {
            $parts = explode('|', $pair);
            $labels[] = trim((string) end($parts));
        }

        return trim(implode('、', array_filter($labels, fn (string $label): bool => $label !== '')));
    }

    /**
     * 补齐子项：从父项 parameter 反查真实传参值，注入 value/option_name_first。
     *
     * 上游 sub 只有自增 id（如 OS 的 14557200），真实传参值在父项 parameter
     * 里（12|CentOS,62|CentOS…）。若只给 id，本地一保存就会把 id 当参数
     * 写回 parameter，上游将无法识别。
     *
     * @param  mixed  $subOptions
     * @return array<int, array<string, mixed>>
     */
    public static function presentSubOptions(mixed $subOptions, string $parameter = ''): array
    {
        if (! is_array($subOptions)) {
            return [];
        }

        $parameterMap = self::parseParameterPairs($parameter);

        return Collection::make($subOptions)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item) use ($parameterMap): array {
                $label = trim((string) ($item['option_name'] ?? $item['label'] ?? ''));
                $submitValue = trim((string) ($item['value'] ?? ''));

                if ($submitValue === '' && $label !== '') {
                    $submitValue = trim((string) ($parameterMap[$label] ?? ''));
                }

                if ($submitValue === '') {
                    $submitValue = trim((string) ($item['option_name_first'] ?? ''));
                }

                if ($submitValue === '') {
                    $submitValue = (string) ($item['id'] ?? '');
                }

                $item['value'] = $submitValue;
                $item['option_name_first'] = $submitValue;

                return $item;
            })
            ->values()
            ->all();
    }

    /**
     * 解析 parameter（值|文案,值|文案）为 文案 => 值。
     *
     * @return array<string, string>
     */
    public static function parseParameterPairs(string $parameter): array
    {
        $parameter = trim($parameter);
        if ($parameter === '') {
            return [];
        }

        $map = [];
        foreach (explode(',', $parameter) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }

            $parts = explode('|', $pair);
            $value = trim((string) $parts[0]);
            $label = trim((string) ($parts[1] ?? ''));

            if ($label !== '' && $value !== '') {
                $map[$label] = $value;
            }
        }

        return $map;
    }
}
