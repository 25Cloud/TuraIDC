<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 上游库存读缓存：新鲜 / 陈旧 / 失败退避 三层。
 *
 * 为什么是三层，而不是原来那个「单键 15 秒」：
 * - 新鲜（{@see self::FRESH_TTL_SECONDS}）：短时间内重复读同一批商品（商品页会并发查多个商品库存）
 *   不必重复打上游。上游自己的库存缓存最短也是 15 秒，再短没有意义。
 * - 陈旧（{@see self::STALE_TTL_SECONDS}）：窗口与定时任务 `supplier-product-stock-sync`
 *   的心跳槽（15 分钟）对齐。上游抖动或超时时，浏览路径必须还能给出「上一轮同步」的库存数字，
 *   而不是把用户按回本地老快照。
 * - 失败退避（{@see self::FAILURE_BACKOFF_SECONDS}）：上游刚超时/挂掉时，每个请求都去重试
 *   （实测 1.5~3 秒一次）会把商品页拖成「一直加载中」。退避窗口内直接返回陈旧值，不再发请求。
 *
 * 口径（与上游那份改动的第 2 条一致）：缓存里只放**上游原始口径**的库存，未减本地在途预留。
 * 预留由读取方按 `queryOpenStockReservations()` 实时扣减；把净值写进缓存会被下一层再扣一次。
 *
 * 键的形状：`upstream_stock:{层}:{供应商}:{id 集合哈希}`。
 * 库存查询本身就是批量的（一次 /cart 拿一批），因此按「供应商 + 完整 id 集合」聚合成一个键，
 * 不做部分命中拼装：集合变化即换键，命中率由调用方的分批策略决定（见 STOCK_SYNC_CHUNK_SIZE）。
 */
final class UpstreamStockCache
{
    /** 新鲜窗口：与上游库存缓存下限对齐。 */
    public const FRESH_TTL_SECONDS = 15;

    /** 陈旧窗口：与库存同步心跳槽（15 分钟）对齐，保证陈旧值不会比本地快照更旧。 */
    public const STALE_TTL_SECONDS = 900;

    /** 失败退避窗口：上游失败后 60 秒内不再发起新的上游请求。 */
    public const FAILURE_BACKOFF_SECONDS = 60;

    /**
     * 浏览路径触发后台刷新的最小间隔（供应商维度）。
     *
     * 为什么是 60 秒、为什么是「推一次」而不是排队：
     * - 一次真实的上游库存同步是「商品数 / 8 批 × 约 1.5 秒」，动辄几十秒到几分钟。
     *   排队本身不贵，但同一个任务键在 worker 上有 WithoutOverlapping 执行锁，
     *   把浏览流量推进去只会把正常心跳槽那一轮挤成「重复投递」，等于用前台流量打乱后台节奏。
     * - 详情页是最高频页面，热门商品一分钟内会被打开很多次。60 秒能把「数字最多落后一个
     *   心跳槽（15 分钟）」压到「最多落后一分钟」，又不会让队列被同一个任务塞满。
     * - 60 秒与失败退避窗口同量级：上游刚挂时推刷新本来也会立刻失败，节拍一致更好推理。
     * 因此这里只做「60 秒推一次」的去重，不做排队/聚合。
     */
    public const REFRESH_REQUEST_INTERVAL_SECONDS = 60;

    /** 「上次成功取数时间」的保留期：一天内取到过就算有基线，过期视为从未同步。 */
    private const SYNCED_AT_TTL_SECONDS = 86400;

    /** 与库存其它缓存同库：可丢、量小、不需要持久化。 */
    private const STORE = 'redis_volatile';

    /** 命中新鲜缓存 */
    public const SOURCE_FRESH = 'fresh';

    /** 命中陈旧缓存（未打上游） */
    public const SOURCE_STALE = 'stale';

    /** 命中陈旧缓存，但原因是上游刚失败处于退避窗口内 */
    public const SOURCE_BACKOFF = 'backoff';

    /** 本次真的打了一次上游并成功 */
    public const SOURCE_FETCHED = 'fetched';

    /** 三层全空：调用方回退本地快照 */
    public const SOURCE_EMPTY = 'empty';

    /**
     * 只读：绝不发起上游请求。浏览路径（目录 / 详情 / `/stock`）走这里。
     *
     * 返回形状与 remember() 对齐（error 恒为 null），调用方不必分叉处理两种结果。
     *
     * @param  array<int, int|string>  $supplierProductIds
     * @return array{stocks: array<int, int|null>, source: string, error: Throwable|null}
     */
    public function read(int $supplierId, array $supplierProductIds): array
    {
        $ids = $this->normalizeIds($supplierProductIds);

        if ($ids === []) {
            return ['stocks' => [], 'source' => self::SOURCE_EMPTY, 'error' => null];
        }

        $fresh = $this->store()->get($this->key($supplierId, $ids, 'fresh'));
        if (is_array($fresh)) {
            return ['stocks' => $fresh, 'source' => self::SOURCE_FRESH, 'error' => null];
        }

        // 退避窗口内单独标一个来源：值仍然是陈旧的，但调用方据此知道「现在推刷新也只会立刻失败」。
        if ($this->store()->has($this->key($supplierId, $ids, 'blocked'))) {
            return [
                'stocks' => $this->readStale($supplierId, $ids) ?? [],
                'source' => self::SOURCE_BACKOFF,
                'error' => null,
            ];
        }

        $stale = $this->readStale($supplierId, $ids);
        if ($stale !== null) {
            return ['stocks' => $stale, 'source' => self::SOURCE_STALE, 'error' => null];
        }

        return ['stocks' => [], 'source' => self::SOURCE_EMPTY, 'error' => null];
    }

    /**
     * 可发起上游请求。下单严格校验与后台定时同步走这里。
     *
     * 失败不抛出：把原始异常放进 `error` 交回调用方决定（严格校验要转成业务异常，
     * 定时同步要记账并跳过），这样同一份节流/退避逻辑不用写两遍。
     *
     * @param  array<int, int|string>  $supplierProductIds
     * @param  callable(array<int, int>): array<int, int|null>  $fetch
     * @return array{stocks: array<int, int|null>, source: string, error: Throwable|null}
     */
    public function remember(int $supplierId, array $supplierProductIds, callable $fetch): array
    {
        $ids = $this->normalizeIds($supplierProductIds);

        if ($ids === []) {
            return ['stocks' => [], 'source' => self::SOURCE_EMPTY, 'error' => null];
        }

        $fresh = $this->store()->get($this->key($supplierId, $ids, 'fresh'));
        if (is_array($fresh)) {
            return ['stocks' => $fresh, 'source' => self::SOURCE_FRESH, 'error' => null];
        }

        if ($this->store()->has($this->key($supplierId, $ids, 'blocked'))) {
            return [
                'stocks' => $this->readStale($supplierId, $ids) ?? [],
                'source' => self::SOURCE_BACKOFF,
                'error' => null,
            ];
        }

        try {
            $stocks = $fetch($ids);
        } catch (Throwable $exception) {
            $this->store()->put(
                $this->key($supplierId, $ids, 'blocked'),
                true,
                now()->addSeconds(self::FAILURE_BACKOFF_SECONDS)
            );

            return [
                'stocks' => $this->readStale($supplierId, $ids) ?? [],
                'source' => self::SOURCE_STALE,
                'error' => $exception,
            ];
        }

        $this->store()->put(
            $this->key($supplierId, $ids, 'fresh'),
            $stocks,
            now()->addSeconds(self::FRESH_TTL_SECONDS)
        );
        $this->store()->put(
            $this->key($supplierId, $ids, 'stale'),
            $stocks,
            now()->addSeconds(self::STALE_TTL_SECONDS)
        );
        // 上游恢复后立刻放行下一次真实请求，不必等退避窗口自然过期。
        $this->store()->forget($this->key($supplierId, $ids, 'blocked'));
        $this->markSynced($supplierId);

        return ['stocks' => $stocks, 'source' => self::SOURCE_FETCHED, 'error' => null];
    }

    /**
     * 记录一次「真的从上游取到过库存」的时间（供应商维度）。
     *
     * 只由成功取数路径调用：缓存/快照兜底都不算同步过，否则浏览一次就把自己的触发条件刷掉。
     */
    public function markSynced(int $supplierId): void
    {
        $this->store()->put(
            $this->syncedAtKey($supplierId),
            now()->getTimestamp(),
            now()->addSeconds(self::SYNCED_AT_TTL_SECONDS)
        );
    }

    /**
     * 是否需要为这个供应商补一次后台刷新：距上次成功取数「未知或已超过 {@see REFRESH_REQUEST_INTERVAL_SECONDS}」。
     */
    public function refreshRequestDue(int $supplierId): bool
    {
        $lastSyncedAt = $this->store()->get($this->syncedAtKey($supplierId));

        if (! is_numeric($lastSyncedAt)) {
            return true;
        }

        return (now()->getTimestamp() - (int) $lastSyncedAt) >= self::REFRESH_REQUEST_INTERVAL_SECONDS;
    }

    /**
     * 抢占这次刷新请求：用 Cache::add 的原子性做「同一供应商 60 秒内只推一次」。
     *
     * 为什么不是先读再加：详情页会并发被多个请求打开，先查再写必然漏。
     * 拿不到就直接跳过——晚一分钟再刷比排队刷十次更划算。
     */
    public function claimRefreshRequest(int $supplierId): bool
    {
        return (bool) $this->store()->add(
            $this->refreshLockKey($supplierId),
            now()->getTimestamp(),
            now()->addSeconds(self::REFRESH_REQUEST_INTERVAL_SECONDS)
        );
    }

    private function syncedAtKey(int $supplierId): string
    {
        return 'upstream_stock:synced_at:'.$supplierId;
    }

    private function refreshLockKey(int $supplierId): string
    {
        return 'upstream_stock:refresh_lock:'.$supplierId;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int|null>|null
     */
    private function readStale(int $supplierId, array $ids): ?array
    {
        $stale = $this->store()->get($this->key($supplierId, $ids, 'stale'));

        return is_array($stale) ? $stale : null;
    }

    /**
     * 候选商品 id 归一：去重、丢弃非正、排序后参与键。
     *
     * 排序是必须的：同一批商品因查询顺序不同会算出不同的键，缓存命中率直接塌掉。
     *
     * @param  array<int, int|string>  $supplierProductIds
     * @return array<int, int>
     */
    private function normalizeIds(array $supplierProductIds): array
    {
        $ids = [];

        foreach ($supplierProductIds as $supplierProductId) {
            $normalized = (int) $supplierProductId;
            if ($normalized > 0) {
                $ids[$normalized] = $normalized;
            }
        }

        $ids = array_values($ids);
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function key(int $supplierId, array $ids, string $layer): string
    {
        return sprintf('upstream_stock:%s:%d:%s', $layer, $supplierId, sha1(implode(',', $ids)));
    }

    private function store(): Repository
    {
        return Cache::store(self::STORE);
    }
}
