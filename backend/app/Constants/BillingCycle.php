<?php

declare(strict_types=1);

namespace App\Constants;

use Carbon\Carbon;

/**
 * 计费周期的唯一真源。
 *
 * 收敛前，「周期 → 中文标签」在后端有 11 份副本、前端 6 份，「周期 → 月数」有 8 份，
 * 「周期 → 到期日推进」有 3 份。文案本身一致，但**覆盖范围**长期漂移：
 * 部分副本只认 monthly/quarterly/semiannually/annually，导致 yearly、onetime、free
 * 这些同样会落库的值在管理端服务详情、用户详情、官网与控制台订单详情里直接显示英文原文。
 * 别名（yearly / onetime / biannually）统一在 normalize() 里收口，各调用点不再各自兼容。
 */
final class BillingCycle
{
    public const MONTHLY = 'monthly';

    public const QUARTERLY = 'quarterly';

    public const SEMIANNUALLY = 'semiannually';

    public const ANNUALLY = 'annually';

    public const BIENNIALLY = 'biennially';

    public const TRIENNIALLY = 'triennially';

    public const ONE_TIME = 'one_time';

    public const FREE = 'free';

    /**
     * 历史别名 → 规范值。
     *
     * biannually 是早期前端的拼写错误（正确拼写为 semiannually），已落到部分历史数据里，
     * 只做读取兼容，不作为写入值。
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'onetime' => self::ONE_TIME,
        'one-time' => self::ONE_TIME,
        'yearly' => self::ANNUALLY,
        'biannually' => self::SEMIANNUALLY,
    ];

    /** @var array<string, string> */
    public const LABELS = [
        self::MONTHLY => '月付',
        self::QUARTERLY => '季付',
        self::SEMIANNUALLY => '半年付',
        self::ANNUALLY => '年付',
        self::BIENNIALLY => '两年付',
        self::TRIENNIALLY => '三年付',
        self::ONE_TIME => '一次性',
        self::FREE => '免费',
    ];

    /**
     * 周期对应的自然月数。一次性与免费不产生续期，记 0。
     *
     * @var array<string, int>
     */
    public const MONTHS = [
        self::MONTHLY => 1,
        self::QUARTERLY => 3,
        self::SEMIANNUALLY => 6,
        self::ANNUALLY => 12,
        self::BIENNIALLY => 24,
        self::TRIENNIALLY => 36,
        self::ONE_TIME => 0,
        self::FREE => 0,
    ];

    /**
     * 展示与排序用的规范顺序，由短到长。
     *
     * @var list<string>
     */
    public const ORDER = [
        self::MONTHLY,
        self::QUARTERLY,
        self::SEMIANNUALLY,
        self::ANNUALLY,
        self::BIENNIALLY,
        self::TRIENNIALLY,
        self::ONE_TIME,
        self::FREE,
    ];

    /**
     * 支持下单与续费的周期白名单。
     *
     * 刻意窄于 LABELS：这是业务允许的取值集合（用于校验与下拉枚举），
     * 而 LABELS 是「任何已落库的值该怎么显示」。两者不可互相替代。
     *
     * @var list<string>
     */
    public const RENEWABLE = [
        self::MONTHLY,
        self::QUARTERLY,
        self::SEMIANNUALLY,
        self::ANNUALLY,
    ];

    /**
     * RENEWABLE 子集的「周期 => 标签」。
     *
     * 展开成常量表达式而非调用 labelsFor()，是因为 PHP 的 const 不允许函数调用，
     * 而各调用点需要的是 const（用于 match/array_key_exists 与类常量对外暴露）。
     * 逐项引用 LABELS，文案仍只有一处定义。
     *
     * @var array<string, string>
     */
    public const RENEWABLE_LABELS = [
        self::MONTHLY => self::LABELS[self::MONTHLY],
        self::QUARTERLY => self::LABELS[self::QUARTERLY],
        self::SEMIANNUALLY => self::LABELS[self::SEMIANNUALLY],
        self::ANNUALLY => self::LABELS[self::ANNUALLY],
    ];

    /**
     * RENEWABLE 子集的「周期 => 月数」。
     *
     * @var array<string, int>
     */
    public const RENEWABLE_MONTHS = [
        self::MONTHLY => self::MONTHS[self::MONTHLY],
        self::QUARTERLY => self::MONTHS[self::QUARTERLY],
        self::SEMIANNUALLY => self::MONTHS[self::SEMIANNUALLY],
        self::ANNUALLY => self::MONTHS[self::ANNUALLY],
    ];

    /**
     * 别名归一 + 去空白 + 转小写。无法识别时原样返回（保留原值便于排查脏数据）。
     */
    public static function normalize(?string $cycle): string
    {
        $normalized = strtolower(trim((string) $cycle));

        return self::ALIASES[$normalized] ?? $normalized;
    }

    /**
     * 周期 → 中文标签。未知值返回 $fallback；$fallback 为空串时回退到原始入参。
     */
    public static function label(?string $cycle, string $fallback = ''): string
    {
        $normalized = self::normalize($cycle);

        if (isset(self::LABELS[$normalized])) {
            return self::LABELS[$normalized];
        }

        if ($fallback !== '') {
            return $fallback;
        }

        return $normalized !== '' ? $normalized : '';
    }

    /**
     * 周期 → 月数。未知周期返回 null，便于调用方区分「0 个月」与「不认识」。
     */
    public static function months(?string $cycle): ?int
    {
        return self::MONTHS[self::normalize($cycle)] ?? null;
    }

    /**
     * 排序序号，值越小越靠前；未知周期排在所有已知周期之后。
     */
    public static function sortRank(?string $cycle): int
    {
        $index = array_search(self::normalize($cycle), self::ORDER, true);

        return $index === false ? count(self::ORDER) : $index;
    }

    /**
     * 按周期推进到期时间。未知周期返回 null，由调用方决定兜底。
     *
     * 不统一走 addMonths()：原实现对 annually/biennially/triennially 用的是 addYear(s)，
     * 与 addMonths(12/24/36) 在月末边界上语义相同但调用形态不同，此处照原样保留，
     * 避免收敛顺手改掉了跨闰年的取整口径。
     */
    public static function advance(Carbon $base, ?string $cycle): ?Carbon
    {
        return match (self::normalize($cycle)) {
            self::MONTHLY => $base->copy()->addMonth(),
            self::QUARTERLY => $base->copy()->addMonths(3),
            self::SEMIANNUALLY => $base->copy()->addMonths(6),
            self::ANNUALLY => $base->copy()->addYear(),
            self::BIENNIALLY => $base->copy()->addYears(2),
            self::TRIENNIALLY => $base->copy()->addYears(3),
            default => null,
        };
    }

    /**
     * 取指定周期子集的「周期 => 标签」映射，保持 $cycles 给定的顺序。
     *
     * 供白名单场景使用：既保留窄集合语义，又让文案只有一处定义。
     *
     * @param  list<string>  $cycles
     * @return array<string, string>
     */
    public static function labelsFor(array $cycles): array
    {
        $labels = [];

        foreach ($cycles as $cycle) {
            $normalized = self::normalize($cycle);
            if (isset(self::LABELS[$normalized])) {
                $labels[$normalized] = self::LABELS[$normalized];
            }
        }

        return $labels;
    }

    /**
     * 取指定周期子集的「周期 => 月数」映射，保持 $cycles 给定的顺序。
     *
     * @param  list<string>  $cycles
     * @return array<string, int>
     */
    public static function monthsFor(array $cycles): array
    {
        $months = [];

        foreach ($cycles as $cycle) {
            $normalized = self::normalize($cycle);
            if (isset(self::MONTHS[$normalized])) {
                $months[$normalized] = self::MONTHS[$normalized];
            }
        }

        return $months;
    }
}
