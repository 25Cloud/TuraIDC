<?php

declare(strict_types=1);

namespace App\Support;

class ProductProvisionHostname
{
    public const MODE_SYSTEM = 'system';

    public const MODE_FIXED = 'fixed';

    public const MODE_PREFIX = 'prefix';

    private const DEFAULT_POOL = '0123456789';

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
            ? max(4, min(63, (int) $rule['length']))
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

    public static function buildGenerationRule(
        array $systemRule = [],
        array $productRule = [],
        array $upstreamRule = []
    ): array {
        $prefix = self::sanitizePrefix((string) ($systemRule['prefix'] ?? ''));
        if ($prefix === '') {
            $prefix = 'srv';
        }

        $length = self::normalizeLength($systemRule['length'] ?? 12, $prefix);
        $pool = self::normalizePool((string) ($systemRule['pool'] ?? self::DEFAULT_POOL));

        $mode = trim((string) ($productRule['mode'] ?? self::MODE_SYSTEM));
        if (! in_array($mode, self::modes(), true)) {
            $mode = self::MODE_SYSTEM;
        }

        if ($mode === self::MODE_PREFIX) {
            $productPrefix = self::sanitizePrefix((string) ($productRule['value'] ?? ''));
            if ($productPrefix !== '') {
                $prefix = $productPrefix;
            }

            $length = self::normalizeLength($productRule['length'] ?? $length, $prefix);
        }

        $upstreamPrefix = self::sanitizePrefix((string) ($upstreamRule['prefix'] ?? ''));
        if ($upstreamPrefix !== '') {
            $prefix = $upstreamPrefix;
        }

        $upstreamLength = (int) ($upstreamRule['length'] ?? 0);
        if ($upstreamLength > 0) {
            $length = max($length, self::normalizeLength($upstreamLength, $prefix));
        } else {
            $length = self::normalizeLength($length, $prefix);
        }

        $upstreamPool = self::normalizePool((string) ($upstreamRule['pool'] ?? ''));
        if ($upstreamPool !== '') {
            $pool = $upstreamPool;
        }

        return [
            'prefix' => $prefix,
            'length' => self::normalizeLength($length, $prefix),
            'pool' => $pool !== '' ? $pool : self::DEFAULT_POOL,
        ];
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

    private static function normalizeLength(mixed $value, string $prefix): int
    {
        $length = is_numeric($value) ? (int) $value : 12;

        return max(4, min(63, max($length, mb_strlen($prefix))));
    }
}
