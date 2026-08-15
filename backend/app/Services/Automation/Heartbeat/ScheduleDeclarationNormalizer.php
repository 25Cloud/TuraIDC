<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

/**
 * 将插件清单中的单个声明和声明列表统一为列表，避免关联数组描述器被逐字段遍历。
 */
final class ScheduleDeclarationNormalizer
{
    /**
     * @return list<mixed>
     */
    public static function list(mixed $declarations): array
    {
        if ($declarations === null || $declarations === []) {
            return [];
        }

        if (! is_array($declarations)) {
            return [$declarations];
        }

        if (self::isDescriptor($declarations)) {
            return [$declarations];
        }

        return array_values($declarations);
    }

    public static function className(mixed $definition): string
    {
        if (is_string($definition)) {
            return trim($definition);
        }

        if (! is_array($definition)) {
            return '';
        }

        $class = $definition['class'] ?? $definition[0] ?? '';

        return is_string($class) ? trim($class) : '';
    }

    public static function methodName(mixed $definition, string $default = 'handle'): string
    {
        if (! is_array($definition)) {
            return $default;
        }

        $method = $definition['method'] ?? $definition[1] ?? $default;

        return is_string($method) ? (trim($method) ?: $default) : $default;
    }

    private static function isDescriptor(array $definition): bool
    {
        if (array_key_exists('class', $definition)) {
            return true;
        }

        // 兼容 [ClassName::class, 'method']；两个类名组成的普通列表不能被误判。
        if (! array_is_list($definition) || count($definition) !== 2) {
            return false;
        }

        $class = $definition[0] ?? null;
        $method = $definition[1] ?? null;

        // PHP 类名大小写不敏感，不能仅凭方法名的大小写判断。
        // 两项都已解析为类时，优先按两个声明处理，避免小写短类名被吞掉。
        if (is_string($class) && is_string($method)
            && class_exists($class)
            && class_exists($method)
            && ! method_exists($class, $method)) {
            return false;
        }

        return is_string($class)
            && is_string($method)
            && (class_exists($class) || str_contains($class, '\\') || preg_match('/^[A-Z_]/', $class) === 1)
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method) === 1;
    }
}
