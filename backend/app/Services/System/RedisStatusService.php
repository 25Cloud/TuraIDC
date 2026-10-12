<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Exceptions\BusinessException;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Redis 运行状态与运维操作。
 *
 * 只做只读监控与缓存清理：连接参数写在 .env，改动必须重启 PHP 并执行
 * config:clear 才生效，做成后台表单会出现「保存成功但不生效」，反而误导运维。
 * 需要改连接参数时以本服务展示的配置摘要为准去改 .env。
 */
class RedisStatusService
{
    private const FLUSH_LOCK_KEY = 'system:redis:flush:lock';

    private const FLUSH_LOCK_SECONDS = 30;

    public function __construct(
        private readonly OperationLogService $operationLogs,
    ) {}

    public function status(): array
    {
        $connections = $this->connectionSummaries();

        return [
            'available' => $this->extensionLoaded() && $connections !== [],
            'client' => (string) config('database.redis.client', 'phpredis'),
            'prefix' => (string) config('database.redis.options.prefix', ''),
            'cache_store' => (string) config('cache.default', ''),
            'connections' => $connections,
            'server' => $this->serverInfo(),
            'keyspace' => $this->keyspaceInfo(),
            'memory' => $this->memoryInfo(),
            'stats' => $this->serverStats(),
        ];
    }

    /**
     * @return array{id: string, status: string, message: string, detail: array<string, mixed>}
     */
    public function flushCache(?int $actorId = null, ?string $ipAddress = null): array
    {
        if (! $this->extensionLoaded()) {
            throw new BusinessException('PHP redis 扩展未安装，无法清理缓存', 50000, 500);
        }

        $lock = Cache::lock(self::FLUSH_LOCK_KEY, self::FLUSH_LOCK_SECONDS);

        if (! $lock->get()) {
            throw new BusinessException('缓存清理正在进行，请勿重复提交', 40900, 409);
        }

        try {
            $connection = $this->connection('cache') ?? $this->connection('default');

            if ($connection === null) {
                throw new BusinessException('未找到可用的 Redis 连接', 50000, 500);
            }

            $cleared = (bool) $connection->command('flushdb');
            $message = $cleared ? '缓存已清理' : '缓存清理未成功';

            $this->operationLogs->write(
                userId: $actorId,
                userType: 'admin',
                action: 'redis.cache.flush',
                module: 'redis',
                targetId: null,
                detail: [
                    'status' => $cleared ? 'completed' : 'failed',
                    'database' => $this->databaseIndex(),
                ],
                ipAddress: $ipAddress,
            );

            return [
                'id' => 'redis-cache-flush',
                'status' => $cleared ? 'completed' : 'failed',
                'message' => $message,
                'detail' => [
                    'cleared' => $cleared,
                    'database' => $this->databaseIndex(),
                ],
            ];
        } catch (BusinessException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BusinessException('缓存清理失败：'.$exception->getMessage(), 50000, 500);
        } finally {
            $lock->release();
        }
    }

    /**
     * 各连接的连通性摘要。任一连接不可用不应让整个页面 500，因此逐个降级。
     *
     * @return list<array{name: string, database: int, connected: bool, message: string}>
     */
    private function connectionSummaries(): array
    {
        $connections = [];

        foreach (['default', 'cache', 'volatile'] as $name) {
            $connection = $this->connection($name);

            if ($connection === null) {
                $connections[] = [
                    'name' => $name,
                    'database' => 0,
                    'connected' => false,
                    'message' => '未配置',
                ];

                continue;
            }

            $connected = false;
            $message = '';

            try {
                $connected = (bool) $connection->command('ping');
                $message = $connected ? '正常' : '无响应';
            } catch (Throwable $exception) {
                $message = $exception->getMessage();
            }

            $connections[] = [
                'name' => $name,
                'database' => $this->connectionDatabaseIndex($name),
                'connected' => $connected,
                'message' => $message,
            ];
        }

        return $connections;
    }

    /**
     * @return array<string, string>
     */
    private function serverInfo(): array
    {
        $connection = $this->connection('default');

        if ($connection === null) {
            return [];
        }

        try {
            $info = $connection->command('info', 'server');
        } catch (Throwable) {
            return [];
        }

        if (! is_array($info)) {
            return [];
        }

        return collect($info)
            ->filter(static fn (mixed $value): bool => is_scalar($value))
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();
    }

    /**
     * @return list<array{database: string, keys: int, expires: int, avg_ttl: int}>
     */
    private function keyspaceInfo(): array
    {
        $connection = $this->connection('default');

        if ($connection === null) {
            return [];
        }

        try {
            $raw = $connection->command('info', 'keyspace');
        } catch (Throwable) {
            return [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $rows = [];

        foreach ($raw as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'db') || ! is_string($value)) {
                continue;
            }

            $keys = 0;
            $expires = 0;
            $avgTtl = 0;

            // INFO keyspace 返回 "keys=12,expires=3,avg_ttl=0" 形式的紧凑串。
            foreach (explode(',', $value) as $pair) {
                [$pairKey, $pairValue] = array_pad(explode('=', $pair, 2), 2, '0');

                match ($pairKey) {
                    'keys' => $keys = (int) $pairValue,
                    'expires' => $expires = (int) $pairValue,
                    'avg_ttl' => $avgTtl = (int) $pairValue,
                    default => null,
                };
            }

            $rows[] = [
                'database' => substr($key, 2),
                'keys' => $keys,
                'expires' => $expires,
                'avg_ttl' => $avgTtl,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, float>
     */
    private function memoryInfo(): array
    {
        $connection = $this->connection('default');

        if ($connection === null) {
            return [];
        }

        try {
            $raw = $connection->command('info', 'memory');
        } catch (Throwable) {
            return [];
        }

        if (! is_array($raw)) {
            return [];
        }

        return [
            'used_memory_human' => (string) ($raw['used_memory_human'] ?? ''),
            'used_memory_peak_human' => (string) ($raw['used_memory_peak_human'] ?? ''),
            'maxmemory_human' => (string) ($raw['maxmemory_human'] ?? ''),
            'mem_fragmentation_ratio' => (float) ($raw['mem_fragmentation_ratio'] ?? 0),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function serverStats(): array
    {
        $connection = $this->connection('default');

        if ($connection === null) {
            return [];
        }

        try {
            $raw = $connection->command('info', 'stats');
        } catch (Throwable) {
            return [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $hits = (float) ($raw['keyspace_hits'] ?? 0);
        $misses = (float) ($raw['keyspace_misses'] ?? 0);
        $total = $hits + $misses;

        return [
            'uptime_in_days' => (int) ($raw['uptime_in_days'] ?? 0),
            'connected_clients' => (int) ($raw['connected_clients'] ?? 0),
            'keyspace_hits' => $hits,
            'keyspace_misses' => $misses,
            // 命中率为 0 时无意义，用 null 语义（0）表达并由前端隐藏。
            'hit_rate' => $total > 0 ? round($hits / $total, 4) : 0.0,
        ];
    }

    private function connection(string $name): ?Connection
    {
        try {
            /** @var Connection $connection */
            $connection = Redis::connection($name);

            return $connection;
        } catch (Throwable) {
            return null;
        }
    }

    private function connectionDatabaseIndex(string $name): int
    {
        $value = config('database.redis.'.$name.'.database', 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function databaseIndex(): int
    {
        return $this->connectionDatabaseIndex('cache') ?: $this->connectionDatabaseIndex('default');
    }

    private function extensionLoaded(): bool
    {
        return extension_loaded('redis');
    }
}
