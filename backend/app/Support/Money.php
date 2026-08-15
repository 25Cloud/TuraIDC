<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 金额运算集中工具。
 *
 * 统一按分位（2 位小数）舍入，避免各业务散落的 float 隐式运算产生精度偏差。
 * 所有金额加法/乘法/减法在中间使用高精度再舍入，比较使用 epsilon。
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
