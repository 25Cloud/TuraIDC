<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 官网商品列表（`GET /api/v2/site/products`）的响应缓存。
 *
 * 为什么需要：这个接口每次请求要跑 9 条 SQL（约 110ms）再把 20 条商品逐条组装成
 * 资源对象（约 290ms），生产实测 350~420ms。前台每次翻页、切分组都会打一次，
 * 而商品目录只在管理员改商品时变化。
 *
 * 失效策略：写入 `site-products` 标签，`AppServiceProvider` 已在 Product / 三级分组
 * 等模型保存或删除时 flush 该标签，因此改价、上下架立刻生效；60 秒 TTL 兜底
 * 「绕过 Eloquent 直接改库」和设置项变更。
 */
final class SiteProductListCache
{
    private const TAG = 'site-products';

    private const TTL_SECONDS = 60;

    /**
     * @param  array<string, mixed>  $filters  来自 ListProductsRequest::validated()
     * @return array<string, mixed>
     */
    public function remember(array $filters, ?int $userId, callable $resolver): array
    {
        // 测试里不做缓存：array 驱动在同一进程内跨用例共享，事务回滚后商品 id 会复用，
        // 读到上一个用例的快照只会制造假失败。
        if (app()->runningUnitTests()) {
            return $resolver();
        }

        $key = sprintf(
            'site:products:v1:%d:%s',
            max(0, (int) $userId),
            md5((string) json_encode($this->normalizeFilters($filters)))
        );

        try {
            return Cache::tags([self::TAG])->remember($key, self::TTL_SECONDS, $resolver);
        } catch (Throwable $exception) {
            // 缓存驱动不支持标签或 Redis 不可用：直接算，功能不受影响。
            // 必须留日志，否则"缓存悄悄失效、接口一直慢"这种问题查不到根因。
            Log::warning('[官网商品列表缓存] 读写失败，本次直接查库', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return $resolver();
        }
    }

    /**
     * 过滤顺序不影响结果，排序后再参与 key，避免同一份查询因参数顺序不同而多存一份。
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        ksort($filters);

        return array_map(
            static fn (mixed $value): mixed => is_array($value) ? array_values($value) : $value,
            $filters
        );
    }
}
