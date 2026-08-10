<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * 用户余额流水聚合统计的缓存键管理。
 *
 * 读路径走 Cache::remember + 短 TTL，任何写入余额变动的地方调用
 * UserBalanceCache::forget($userId) 立即失效，保证用户体感的准确性。
 */
final class UserBalanceCache
{
    /** TTL 偏短：用户支付/退款后通常会刷新页面，过期即重算开销可接受 */
    public const SUMMARY_TTL_SECONDS = 60;

    public static function summaryKey(int $userId): string
    {
        return CacheKey::userBalanceSummary($userId);
    }

    public static function forget(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        Cache::forget(self::summaryKey($userId));
    }
}
