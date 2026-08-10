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
