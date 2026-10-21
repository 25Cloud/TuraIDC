<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\RainyunRcs\Lib;

use App\Exceptions\BusinessException;

final class RainyunValues
{
    public static function integer(mixed $value, string $label, int $min = 1, int $max = 2147483647): int
    {
        $valid = is_int($value) || (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1);
        throw_if(! $valid || (float) $value < $min || (float) $value > $max, new BusinessException($label.'无效', 42200));

        return (int) $value;
    }

    public static function months(string $cycle): int
    {
        return match ($cycle) {
            'monthly' => 1,
            'quarterly' => 3,
            'semiannually' => 6,
            'annually' => 12,
            default => throw new BusinessException('当前操作只支持月付、季付、半年付和年付', 42200),
        };
    }

    public static function cents(mixed $value, string $label = '金额'): int
    {
        $text = (string) $value;
        throw_if(preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/D', $text, $m) !== 1, new BusinessException($label.'必须为非负金额，最多两位小数', 42200));

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function boolean(mixed $value, string $label): bool
    {
        $result = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        throw_if($result === null || is_array($value), new BusinessException($label.'必须为开关值', 42200));

        return $result;
    }
}
