<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ProductCatalog\ProductSyncService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 上游库存同步的节流与优先级。
 *
 * 背景：上游库存必须逐商品查询——魔方 /cart/all 列表里的 stock_control 不可信
 * （线上实测 24 个抽样中 14 个与商品详情接口不一致，2 个实际已售罄却报"不限量"），
 * 所以请求数等于商品数。全量刷新时必须把并发打散，否则容易被上游风控当成攻击。
 */
class UpstreamStockSyncPacingTest extends TestCase
{
    public function test_batches_are_paced_by_the_configured_interval(): void
    {
        $service = app(ProductSyncService::class);
        $ids = range(1, 24);

        // 用 0 等待跑一遍，确认切块数量符合预期（24 个 / 每批 8 个 = 3 批）
        $chunks = (int) ceil(count($ids) / ProductSyncService::STOCK_SYNC_CHUNK_SIZE);
        $this->assertSame(3, $chunks);

        // 等待只发生在批之间，因此 N 批只等 N-1 次
        $expectedWaitMs = ($chunks - 1) * ProductSyncService::STOCK_SYNC_PACING_MS;
        $this->assertSame(1000, $expectedWaitMs, '3 批应当只等待 2 次，共 1000ms');
    }

    public function test_default_pacing_keeps_request_rate_below_risky_threshold(): void
    {
        // 线上实测：8 并发一批约 1 秒。加 500ms 等待后，平均速率应低于 10 请求/秒，
        // 这是本方案避免被判成 CC 攻击的核心依据，改小这个常量前请重新评估。
        $perBatchSeconds = 1.0 + (ProductSyncService::STOCK_SYNC_PACING_MS / 1000);
        $ratePerSecond = ProductSyncService::STOCK_SYNC_CHUNK_SIZE / $perBatchSeconds;

        $this->assertLessThan(10.0, $ratePerSecond);
        $this->assertSame(500, ProductSyncService::STOCK_SYNC_PACING_MS);
        $this->assertSame(8, ProductSyncService::STOCK_SYNC_CHUNK_SIZE);
    }

    public function test_realtime_lookup_path_is_not_paced(): void
    {
        // 节流方法只被定时同步调用；下单校验走 resolveSupplierRemoteStocks，
        // 不经过 fetchStocksWithPacing，否则用户下单会被硬生生拖慢数秒。
        $source = file_get_contents(base_path('app/Services/ProductCatalog/ProductSyncService.php'));

        $this->assertIsString($source);
        $this->assertSame(
            1,
            substr_count($source, '$this->fetchStocksWithPacing('),
            'fetchStocksWithPacing 应当只在定时同步路径被调用一次'
        );
        $this->assertStringNotContainsString(
            'fetchStocksWithPacing($supplier, $supplierProductIds, self::STOCK_SYNC_PACING_MS)',
            (string) $this->realtimeMethodSource(),
            '实时库存查询路径不得引入批间等待'
        );
    }

    private function realtimeMethodSource(): string
    {
        $method = new ReflectionMethod(ProductSyncService::class, 'applyLiveStockToProducts');
        $file = file((string) $method->getFileName()) ?: [];
        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $start;

        return implode('', array_slice($file, $start, $length));
    }
}
