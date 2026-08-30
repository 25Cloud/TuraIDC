<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DatabaseSchema
{
    /**
     * @var array<string, bool>
     */
    private static array $objectExists = [];

    public static function hasTableOrView(string $name): bool
    {
        $connection = DB::connection();
        $cacheKey = implode(':', [
            $connection->getName(),
            $connection->getDatabaseName(),
            strtolower($name),
        ]);

        if (array_key_exists($cacheKey, self::$objectExists)) {
            return self::$objectExists[$cacheKey];
        }

        if (Schema::hasTable($name)) {
            return self::$objectExists[$cacheKey] = true;
        }

        return self::$objectExists[$cacheKey] = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => (bool) $connection->selectOne(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                [$name]
            ),
            'sqlite' => (bool) $connection->selectOne(
                "SELECT 1 FROM sqlite_master WHERE name = ? AND type IN ('table', 'view') LIMIT 1",
                [$name]
            ),
            default => false,
        };
    }

    /**
     * 与 Schema::hasTable() 语义完全一致（**不含视图**），只是把结果按连接+库名记忆化。
     *
     * 存在的意义：Schema::hasTable() 每次调用都会打一条 information_schema 查询，
     * 而本仓多处在循环里做兼容性判断（如 MemberLevelService 每个用户判一次
     * user_referrals、ProductTypeService 单次请求判 22 次），实测这些查询能占到
     * 接口耗时的四分之三。语义严格等价，因此可安全替换既有的 Schema::hasTable()。
     *
     * 注意与 hasTableOrView() 的区别：那个把视图也算存在，不能互换。
     */
    public static function hasTable(string $name): bool
    {
        $connection = DB::connection();
        $cacheKey = implode(':', [
            'table',
            $connection->getName(),
            $connection->getDatabaseName(),
            strtolower($name),
        ]);

        if (array_key_exists($cacheKey, self::$objectExists)) {
            return self::$objectExists[$cacheKey];
        }

        return self::$objectExists[$cacheKey] = Schema::hasTable($name);
    }

    public static function hasColumn(string $object, string $column): bool
    {
        $connection = DB::connection();
        $cacheKey = implode(':', [
            $connection->getName(),
            $connection->getDatabaseName(),
            strtolower($object),
            strtolower($column),
        ]);

        if (array_key_exists($cacheKey, self::$objectExists)) {
            return self::$objectExists[$cacheKey];
        }

        if (Schema::hasColumn($object, $column)) {
            return self::$objectExists[$cacheKey] = true;
        }

        return self::$objectExists[$cacheKey] = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => (bool) $connection->selectOne(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                [$object, $column]
            ),
            'sqlite' => (bool) $connection->selectOne(
                'SELECT 1 FROM pragma_table_info(?) WHERE name = ? LIMIT 1',
                [$object, $column]
            ),
            default => false,
        };
    }

    public static function resetCache(): void
    {
        self::$objectExists = [];
    }
}
