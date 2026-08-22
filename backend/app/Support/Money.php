<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 金额运算集中工具。
 *
 * 单位为「元」，与 DB 的 decimal(12,2) 及各端 API 契约一致。
 *
 * 实现方式：以原生 float 参与运算，并在每个运算的**末尾一次性**舍入到分位。
 * 这消掉了 0.1 + 0.2 = 0.30000000000000004 这类表示误差——最终舍入会把它归一为 0.30。
 * 注意是「一次舍入」而非「逐项舍入」：add() 先对全部入参求和再舍入，
 * 因此小于一分的多个入参可以累积成一分（如 add(0.004, 0.004) = 0.01）。
 * 各业务若绕过本类自行做 float 运算，就失去这层归一，这是本类存在的意义。
 *
 * 精度边界（本项目金额量级下不构成问题，但需知悉）：
 * - 未使用 bcmath 等高精度扩展。composer.json 未声明 ext-bcmath、README 的扩展清单
 *   也没有它（仅 Docker 镜像装了），在此依赖它会给宝塔等部署引入未声明的硬依赖。
 * - 因此这里不是任意精度的十进制运算，而是「双精度运算 + 分位舍入」。
 *   对 decimal(12,2) 的取值范围与常规明细数量，最终舍入足以吸收中间误差；
 *   真正会失效的是累加项数达到天文量级的场景，本项目不存在。
 * - 除法只做「除后取整到分」，不处理余数分摊：若需把一笔金额拆成多份且各份之和
 *   必须严格等于原额（多明细分摊、按天折算差价等），逐份调用 divide() 会产生
 *   若干分的差额，那类场景需要显式的余数归属策略。
 */
final class Money
{
    private const SCALE = 2;

    /** 金额比较容差（0.0001 元；两侧均已先 round 到分位） */
    private const EPSILON = 0.0001;

    public static function round(mixed $value): float
    {
        return round((float) ($value ?? 0), self::SCALE);
    }

    public static function add(mixed ...$values): float
    {
        return self::round(array_sum(array_map(static fn (mixed $value): float => (float) ($value ?? 0), $values)));
    }

    public static function subtract(mixed $minuend, mixed $subtrahend): float
    {
        return self::round((float) ($minuend ?? 0) - (float) ($subtrahend ?? 0));
    }

    public static function multiply(mixed $a, mixed $b): float
    {
        return self::round((float) ($a ?? 0) * (float) ($b ?? 0));
    }

    public static function divide(mixed $a, mixed $b): float
    {
        $divisor = (float) ($b ?? 0);

        // 除零是调用方错误，显式抛错避免静默返回 0 掩盖真实除零。
        if ($divisor == 0) {
            throw new \DivisionByZeroError('金额除法除数不能为 0');
        }

        return self::round((float) ($a ?? 0) / $divisor);
    }

    /**
     * 金额相等比较（含容差）。
     */
    public static function equals(mixed $a, mixed $b): bool
    {
        return abs(self::round($a) - self::round($b)) <= self::EPSILON;
    }

    public static function format(mixed $value): string
    {
        return number_format(self::round($value), 2, '.', '');
    }
}
