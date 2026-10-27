<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 魔方财务上游商品目录的响应缓存。
 *
 * 为什么需要：`products` 表上有 900~1000 行、且每行带 config_options / purchase_requires 这类大 JSON，
 * 下游（魔方财务）会高频轮询 `api/product/proinfo` 做版本比对。实测 `infos()` 一次 500~600ms、
 * `all()` 一次 700~800ms，其中 460ms 全花在一条 `select * from products where status=1` 上。
 * 目录本身只在管理员改商品时变化，所以按版本号缓存整份响应，命中时只剩一次 Redis 读。
 *
 * 失效策略：版本号存在缓存里，商品/三级分组保存或删除时自增；键里带版本号，
 * 因此不需要清理旧键（TTL 到期自然消失）。TTL 兜底 60 秒，覆盖"绕过 Eloquent 直接改库"的情况。
 */
final class UpstreamCatalogCache
{
    private const VERSION_KEY = 'zjmf_upstream:catalog_version';

    private const TTL_SECONDS = 60;

    public static function version(): int
    {
        return max(1, (int) Cache::get(self::VERSION_KEY, 1));
    }

    /**
     * 目录变更后调用：版本号自增即等价于让所有旧响应立刻失效。
     */
    public static function bump(): void
    {
        try {
            Cache::increment(self::VERSION_KEY);

            return;
        } catch (Throwable) {
            // 缓存驱动不支持自增（或键不存在时行为不一致）时退化为写入新值
        }

        try {
            Cache::put(self::VERSION_KEY, self::version() + 1, 86400);
        } catch (Throwable) {
            // 缓存整体不可用时不做任何事：调用方会直接走数据库，功能不受影响
        }
    }

    /**
     * @param  array<string, mixed>  $fingerprint  参与结果的入参（如 pids），保证不同入参不串味
     * @return array<string, mixed>
     */
    public function remember(string $scope, array $fingerprint, callable $resolver): array
    {
        // 测试里不做缓存：array 驱动在同一进程内跨用例共享，而事务回滚会让商品 id 复用，
        // 于是后一个用例可能读到前一个用例的目录快照，制造出与生产无关的假失败。
        if (app()->runningUnitTests()) {
            return $resolver();
        }

        try {
            $key = sprintf(
                'zjmf_upstream:%s:v%d:%s',
                $scope,
                self::version(),
                md5((string) json_encode($fingerprint))
            );

            return Cache::remember($key, self::TTL_SECONDS, $resolver);
        } catch (Throwable) {
            // 缓存不可用（未配置、连接失败）时直接算，性能退化但不影响正确性
            return $resolver();
        }
    }
}
