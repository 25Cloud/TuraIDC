<?php

declare(strict_types=1);

namespace App\Support;

class ProductProvisionHostname
{
    public const MODE_SYSTEM = 'system';

    public const MODE_FIXED = 'fixed';

    public const MODE_PREFIX = 'prefix';

    private const DEFAULT_POOL = '0123456789';

    /**
     * 开通主机名的默认硬上限。
     *
     * 为什么是 16：上游（魔方财务）把主机名直接当虚拟主机的 MySQL 用户名下发，
     * 而 MySQL 5.6/5.7 的用户名上限是硬编码的 16 字符（8.0 才放宽到 32）。
     * 上游商品一旦把前缀设长（例如紫熙云的 `chengdumn`），再叠加随机位数就会
     * 撞穿这个上限，客户在 phpMyAdmin 里连不上库，只能拿到「用户名过长」的报错。
     * 因此这里不是「建议值」而是护栏：所有来源算出来的长度都要被它钳住。
     */
    public const DEFAULT_MAX_LENGTH = 16;

    public const MIN_LENGTH = 4;

    public const MAX_LENGTH_CEILING = 63;

    public static function modes(): array
    {
        return [
            self::MODE_SYSTEM,
            self::MODE_FIXED,
            self::MODE_PREFIX,
        ];
    }

    public static function fromPurchaseRequires(array $purchaseRequires = []): array
    {
        $rule = is_array($purchaseRequires['provision_hostname'] ?? null)
            ? $purchaseRequires['provision_hostname']
            : [];

        $mode = trim((string) ($rule['mode'] ?? self::MODE_SYSTEM));
        if (! in_array($mode, self::modes(), true)) {
            $mode = self::MODE_SYSTEM;
        }

        $length = isset($rule['length']) && is_numeric($rule['length'])
            ? max(self::MIN_LENGTH, min(self::MAX_LENGTH_CEILING, (int) $rule['length']))
            : 12;

        return [
            'mode' => $mode,
            'value' => trim((string) ($rule['value'] ?? '')),
            'length' => $length,
            'is_customized' => $mode !== self::MODE_SYSTEM,
            'label' => self::labelOf($mode),
        ];
    }

    public static function labelOf(string $mode): string
    {
        return match (trim($mode)) {
            self::MODE_FIXED => '固定主机名',
            self::MODE_PREFIX => '前缀主机名',
            default => '跟随上游',
        };
    }

    public static function summary(array $rule = []): string
    {
        $resolved = self::fromPurchaseRequires([
            'provision_hostname' => $rule,
        ]);

        return match ($resolved['mode']) {
            self::MODE_FIXED => $resolved['value'] !== ''
                ? '固定：'.$resolved['value']
                : self::labelOf($resolved['mode']),
            self::MODE_PREFIX => $resolved['value'] !== ''
                ? '前缀：'.$resolved['value'].' / 长度 '.$resolved['length']
                : self::labelOf($resolved['mode']),
            default => self::labelOf($resolved['mode']),
        };
    }

    /**
     * 归一化「开通主机名」的生成规则。
     *
     * 入参三层来源，优先级从低到高：系统规则 → 商品规则 → 上游规则。
     * 两个全局开关注入在 `$systemRule` 里：
     * - `max_length`：所有来源算出的总长度都被它钳住（见 DEFAULT_MAX_LENGTH 的注释）；
     * - `unify_prefix`：开启后直接用系统前缀与系统长度，商品与上游规则整体失效。
     *
     * @param  array<string, mixed>  $systemRule
     * @param  array<string, mixed>  $productRule
     * @param  array<string, mixed>  $upstreamRule
     * @return array{prefix: string, length: int, pool: string, max_length: int, unify_prefix: bool}
     */
    public static function buildGenerationRule(
        array $systemRule = [],
        array $productRule = [],
        array $upstreamRule = []
    ): array {
        $maxLength = self::normalizeMaxLength($systemRule['max_length'] ?? self::DEFAULT_MAX_LENGTH);
        $unifyPrefix = self::toBool($systemRule['unify_prefix'] ?? false);

        $prefix = self::sanitizePrefix((string) ($systemRule['prefix'] ?? ''));
        if ($prefix === '') {
            $prefix = 'srv';
        }

        $length = self::normalizeLength($systemRule['length'] ?? 12, $prefix, $maxLength);
        $pool = self::normalizePool((string) ($systemRule['pool'] ?? self::DEFAULT_POOL));

        // 统一前缀：把「上游商品前缀不可控」这件事一次性收口。
        // 关闭时仍按三层合并，开启后商品前缀与上游前缀都不再参与。
        if ($unifyPrefix) {
            return [
                'prefix' => $prefix,
                'length' => $length,
                'pool' => $pool !== '' ? $pool : self::DEFAULT_POOL,
                'max_length' => $maxLength,
                'unify_prefix' => true,
            ];
        }

        $mode = trim((string) ($productRule['mode'] ?? self::MODE_SYSTEM));
        if (! in_array($mode, self::modes(), true)) {
            $mode = self::MODE_SYSTEM;
        }

        if ($mode === self::MODE_PREFIX) {
            $productPrefix = self::sanitizePrefix((string) ($productRule['value'] ?? ''));
            if ($productPrefix !== '') {
                $prefix = $productPrefix;
            }

            $length = self::normalizeLength($productRule['length'] ?? $length, $prefix, $maxLength);
        }

        $upstreamPrefix = self::sanitizePrefix((string) ($upstreamRule['prefix'] ?? ''));
        if ($upstreamPrefix !== '') {
            $prefix = $upstreamPrefix;
        }

        $upstreamLength = (int) ($upstreamRule['length'] ?? 0);
        if ($upstreamLength > 0) {
            $length = max($length, self::normalizeLength($upstreamLength, $prefix, $maxLength));
        } else {
            $length = self::normalizeLength($length, $prefix, $maxLength);
        }

        $upstreamPool = self::normalizePool((string) ($upstreamRule['pool'] ?? ''));
        if ($upstreamPool !== '') {
            $pool = $upstreamPool;
        }

        return [
            'prefix' => $prefix,
            'length' => self::normalizeLength($length, $prefix, $maxLength),
            'pool' => $pool !== '' ? $pool : self::DEFAULT_POOL,
            'max_length' => $maxLength,
            'unify_prefix' => false,
        ];
    }

    /**
     * 把任意来源算出的主机名裁到上限内，并保证前缀不会被随机位挤掉。
     *
     * 生成器先拼 `前缀 + 随机位`，这里再统一收口：一旦超过上限，优先压缩随机位，
     * 随机位压到 0 还超才截前缀——截前缀会破坏「同名规则可读性」，
     * 但短前缀永远比超长用户名安全。
     */
    public static function clamp(string $hostname, int $maxLength): string
    {
        $maxLength = self::normalizeMaxLength($maxLength);
        $hostname = trim($hostname);

        if ($hostname === '' || mb_strlen($hostname) <= $maxLength) {
            return $hostname;
        }

        return mb_substr($hostname, 0, $maxLength);
    }

    private static function sanitizePrefix(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z]+/', '', trim($value)) ?? '';
        $value = mb_strtolower($value);

        return mb_substr($value, 0, 10);
    }

    private static function normalizePool(string $value): string
    {
        $result = '';

        if (preg_match('/[0-9]/', $value) === 1) {
            $result .= '0123456789';
        }

        if (preg_match('/[A-Z]/', $value) === 1) {
            $result .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }

        if (preg_match('/[a-z]/', $value) === 1) {
            $result .= 'abcdefghijklmnopqrstuvwxyz';
        }

        return $result;
    }

    private static function normalizeMaxLength(mixed $value): int
    {
        $max = is_numeric($value) ? (int) $value : self::DEFAULT_MAX_LENGTH;

        return max(self::MIN_LENGTH, min(self::MAX_LENGTH_CEILING, $max));
    }

    private static function normalizeLength(mixed $value, string $prefix, int $maxLength = self::DEFAULT_MAX_LENGTH): int
    {
        $length = is_numeric($value) ? (int) $value : 12;
        $length = max(self::MIN_LENGTH, min(self::MAX_LENGTH_CEILING, $length));

        // 前缀本身已经超上限时，长度只能取上限（前缀随后会被截断），
        // 不能反过来把长度顶到前缀长度——那正是 21 位主机名的来源。
        $length = max($length, min(mb_strlen($prefix), $maxLength));

        return min($length, $maxLength);
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 'true', 'on', 'yes'], true);
    }
}