<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Connection;
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
        $cacheKey = self::cacheKey($connection, $name);

        if ($cacheKey !== null && array_key_exists($cacheKey, self::$objectExists)) {
            return self::$objectExists[$cacheKey];
        }

        $result = self::resolveTableOrView($connection, $name);

        if ($cacheKey !== null) {
            self::$objectExists[$cacheKey] = $result;
        }

        return $result;
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
        $cacheKey = self::cacheKey($connection, 'table:'.$name);

        if ($cacheKey !== null && array_key_exists($cacheKey, self::$objectExists)) {
            return self::$objectExists[$cacheKey];
        }

        $result = Schema::hasTable($name);

        if ($cacheKey !== null) {
            self::$objectExists[$cacheKey] = $result;
        }

        return $result;
    }

    public static function hasColumn(string $object, string $column): bool
    {
        $connection = DB::connection();
        $cacheKey = self::cacheKey($connection, $object.':'.$column);

        if ($cacheKey !== null && array_key_exists($cacheKey, self::$objectExists)) {
            return self::$objectExists[$cacheKey];
        }

        $result = self::resolveColumn($connection, $object, $column);

        if ($cacheKey !== null) {
            self::$objectExists[$cacheKey] = $result;
        }

        return $result;
    }

    public static function resetCache(): void
    {
        self::$objectExists = [];
    }

    /**
     * 记忆化键；内存库返回 null 表示不记忆化。
     *
     * SQLite 的 `:memory:`（以及 MySQL 的 MEMORY 引擎）库每条连接都是一个全新库，
     * 库名在重连前后完全相同。记忆化会让先前的探测结果泄漏到新库上——
     * 测试里表现为「单跑绿、全量红」的随机失败，生产上则表现为运行时切换连接后误判表存在。
     * 这类库本身没有 information_schema 查询开销，记忆化只带来串味风险，没有收益。
     */
    private static function cacheKey(Connection $connection, string $suffix): ?string
    {
        $database = (string) $connection->getDatabaseName();

        if ($database === ':memory:' || $database === '') {
            return null;
        }

        return implode(':', [
            $connection->getName(),
            $database,
            strtolower($suffix),
        ]);
    }

    private static function resolveTableOrView(Connection $connection, string $name): bool
    {
        if (Schema::hasTable($name)) {
            return true;
        }

        return match ($connection->getDriverName()) {
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

    private static function resolveColumn(Connection $connection, string $object, string $column): bool
    {
        if (Schema::hasColumn($object, $column)) {
            return true;
        }

        return match ($connection->getDriverName()) {
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
}
