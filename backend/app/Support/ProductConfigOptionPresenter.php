<?php

namespace App\Support;

use Illuminate\Support\Collection;

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
 *   - 子项缺真实传参值时，从父项 parameter（值|文案,值|文案）反查。
 *
 * 全部为读取时计算，不写库。判据与 TuraOpenApi 的同步归一化保持一致，
 * 同步后写入的新结构本身就是完整的，这里的补齐对其为无操作。
 */
final class ProductConfigOptionPresenter
{
    /** 区间型（滑块）配置项 option_type */
    public const RANGE_OPTION_TYPES = [4, 7, 9, 11, 14, 15, 16, 17, 18, 19];

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

        return Collection::make($configOptions)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => self::presentOne($item))
            ->values()
            ->all();
    }

    /**
     * 补齐单个配置项。
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function presentOne(array $item): array
    {
        $type = (int) ($item['option_type'] ?? 0);
        $isRange = in_array($type, self::RANGE_OPTION_TYPES, true);
        $parameter = trim((string) ($item['parameter'] ?? ''));
        $subOptions = self::presentSubOptions($item['sub'] ?? $item['sub_items'] ?? [], $parameter);
        $name = trim((string) ($item['name'] ?? $item['option_name'] ?? ''));
        $isTextNotice = self::isTextNotice($name, $subOptions);

        [$rangeMin, $rangeMax] = self::resolveRangeBounds($item, $subOptions, $isRange);

        $item['option_mode'] = self::resolveMode($item, $isRange, $isTextNotice);
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
     * 或整个配置项只有一条子项且子项文案是一句长句（正常规格值都是
     * 「16核」「500G」这类短值，不会用整句中文）。
     *
     * @param  array<int, array<string, mixed>>  $subOptions
     */
    public static function isTextNotice(string $name, array $subOptions): bool
    {
        foreach (self::TEXT_NOTICE_NAME_KEYWORDS as $keyword) {
            if (str_contains($name, $keyword)) {
                return true;
            }
        }

        if (count($subOptions) !== 1) {
            return false;
        }

        $onlySubName = trim((string) ($subOptions[0]['option_name'] ?? ''));

        return mb_strlen($onlySubName) >= 12
            && preg_match('/[\x{4e00}-\x{9fa5}]/u', $onlySubName) === 1;
    }

    /**
     * 区间型优先于既有取值模式判定，提示项优先级最高：
     * 上游可能给提示项也带上区间型 option_type，但它本质是文字。
     *
     * @param  array<string, mixed>  $item
     */
    private static function resolveMode(array $item, bool $isRange, bool $isTextNotice): string
    {
        $mode = trim((string) ($item['option_mode'] ?? ''));

        if ($mode === self::MODE_TEXT || $isTextNotice) {
            return self::MODE_TEXT;
        }

        if ($mode !== '') {
            return $mode;
        }

        return $isRange ? 'range' : 'select';
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
