<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 上游只读结果的单飞缓存（single-flight）。
 *
 * 为什么需要：打开一个实例的详情页，前端会同时发出十几条读接口
 * （详情、连接、能力、监控、安全组、NAT、重装项、操作日志…），每条都带 20–300 秒缓存。
 * 平时都命中缓存很快，但只要缓存过期的那一瞬间进来 N 个请求，它们会**同时** miss，
 * 于是**一起**同步打上游；上游单次 5–25 秒，用户看到的就是「刷新一下等二十秒」。
 * 生产日志里同一秒同一 IP 打到 client/services 的请求高达 12–16 条，慢请求也全部集中在这些接口。
 *
 * 做法：miss 后先抢一把锁。抢到的请求去打上游并回填缓存，其余请求等它回填后直接读缓存；
 * 等不到锁就自己打，退化成原来的行为——**绝不因为锁把请求拖得更慢或卡死**。
 *
 * 只用于幂等只读查询。写操作后要立刻看见新值的场景，调用方仍需自己 forget 对应键。
 */
final class UpstreamReadCache
{
    /** 等锁上限（秒）：拿不到就直接自己打，避免慢请求叠成更慢 */
    private const LOCK_WAIT_SECONDS = 3;

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $fetch
     * @return TValue
     */
    public static function remember(string $key, int $ttlSeconds, Closure $fetch): mixed
    {
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached;
        }

        $lock = Cache::lock('upstream-read:'.sha1($key), max($ttlSeconds, 5) + 10);

        try {
            return $lock->block(self::LOCK_WAIT_SECONDS, function () use ($key, $ttlSeconds, $fetch) {
                // 二次检查：等锁期间可能已被别的请求回填
                $again = Cache::get($key);

                if ($again !== null) {
                    return $again;
                }

                $value = $fetch();
                Cache::put($key, $value, now()->addSeconds($ttlSeconds));

                return $value;
            });
        } catch (LockTimeoutException) {
            // 没抢到锁：自己打上游，保持原有可用性
            return $fetch();
        } catch (Throwable $exception) {
            // 锁后端抖动（redis 断连等）不能让读接口跟着失败
            report($exception);

            return $fetch();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // 未持有锁（超时路径）时释放会抛，忽略即可
            }
        }
    }
}