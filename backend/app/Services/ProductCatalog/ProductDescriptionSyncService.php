<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Exceptions\BusinessException;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 商品描述的上游同步编排。
 *
 * 描述真源只有 products.description 一个：
 *   - 立即同步：把上游目录里的描述归一后覆盖到本地（显式操作，允许覆盖人工描述）；
 *   - 自动同步：商品级开关 description_sync_enabled 打开后，由定时任务按全局节拍刷新；
 *   - 分组批量：二级 / 三级分组菜单上的批量同步与批量开关，操作对象是分组下商品，
 *     分组本身不另存开关，避免「分组开关」与「商品开关」两套状态互相打架。
 */
final class ProductDescriptionSyncService
{
    public function __construct(
        private readonly ProductSyncService $productSync,
    ) {}

    /**
     * 立即同步指定商品的上游描述。
     *
     * @param  array<int, int>  $productIds
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function syncProducts(array $productIds, bool $overwrite = true): array
    {
        $this->assertColumnsReady();

        $result = $this->productSync->syncUpstreamProductDescriptions($productIds, $overwrite);

        // 手动同步此前完全不留日志：管理员点完只看到前端一句结果，出问题时
        // （例如「明明改了上游描述却没变」）无从判断是拉取失败、上游没描述、
        // 还是内容被判为一致。这里把每个商品的判定结果落一条日志。
        try {
            Log::info('[商品描述同步] 执行完成', [
                'overwrite' => $overwrite,
                'summary' => $result['summary'] ?? [],
                'items' => array_map(static fn (array $item): array => [
                    'id' => $item['id'] ?? null,
                    'status' => $item['status'] ?? '',
                    'changed' => $item['changed'] ?? null,
                    'message' => $item['message'] ?? '',
                ], $result['items'] ?? []),
            ]);
        } catch (\Throwable) {
            // 日志失败不影响同步结果
        }

        if (($result['summary']['synced'] ?? 0) > 0) {
            // 描述进了前台商品目录缓存，不失效的话前台仍展示旧描述。
            app(SiteCatalogCacheInvalidator::class)->flush();
        }

        return $result;
    }

    /**
     * 分组批量同步。level：1=一级分组 2=二级分组 3=三级分组。
     *
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, int>, group_id: int, level: int}
     */
    public function syncGroup(int $groupId, int $level, bool $overwrite = true): array
    {
        $this->assertColumnsReady();

        $productIds = $this->groupProductIds($groupId, $level);
        $result = $this->syncProducts($productIds, $overwrite);
        $result['group_id'] = $groupId;
        $result['level'] = $level;
        $result['summary']['targets'] = count($productIds);

        return $result;
    }

    /**
     * 批量开关商品级自动同步。
     *
     * @param  array<int, int>  $productIds
     * @return array{affected: int, enabled: bool}
     */
    public function setAutoSync(array $productIds, bool $enabled): array
    {
        $this->assertColumnsReady();

        $ids = $this->normalizeIds($productIds);
        if ($ids === []) {
            throw new BusinessException('请至少选择一个商品');
        }

        $affected = Product::query()
            ->whereIn('id', $ids)
            ->update(['description_sync_enabled' => $enabled ? 1 : 0]);

        return ['affected' => $affected, 'enabled' => $enabled];
    }

    /**
     * 分组批量开关自动同步。
     *
     * @return array{affected: int, enabled: bool, group_id: int, level: int, targets: int}
     */
    public function setGroupAutoSync(int $groupId, int $level, bool $enabled): array
    {
        $this->assertColumnsReady();

        $ids = $this->groupProductIds($groupId, $level);
        $result = $this->setAutoSync($ids, $enabled);
        $result['group_id'] = $groupId;
        $result['level'] = $level;
        $result['targets'] = count($ids);

        return $result;
    }

    /**
     * 定时任务入口：只处理已开启自动同步的商品。
     *
     * 优先同步从未同步过的商品，其次同步最久未同步的，避免每次都盯同一批。
     *
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function syncDueProducts(int $limit = 200): array
    {
        $this->assertColumnsReady();

        $ids = Product::query()
            ->where('description_sync_enabled', 1)
            ->orderByRaw('description_synced_at IS NULL DESC')
            ->orderBy('description_synced_at')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return ['items' => [], 'summary' => ['total' => 0, 'synced' => 0, 'changed' => 0, 'skipped' => 0, 'failed' => 0]];
        }

        return $this->syncProducts($ids);
    }

    /**
     * 统计：供「同步设置」弹窗展示。
     *
     * @return array{total: int, auto_sync_enabled: int, synced: int}
     */
    public function stats(): array
    {
        $this->assertColumnsReady();

        return [
            'total' => (int) Product::query()->count(),
            'auto_sync_enabled' => (int) Product::query()->where('description_sync_enabled', 1)->count(),
            'synced' => (int) Product::query()->whereNotNull('description_synced_at')->count(),
        ];
    }

    /**
     * 分组下的商品 id 列表。
     *
     * @return array<int, int>
     */
    private function groupProductIds(int $groupId, int $level): array
    {
        if ($groupId <= 0) {
            return [];
        }

        $query = match ($level) {
            1 => Product::query()->inFirstProductGroup($groupId),
            2 => Product::query()->inSecondProductGroup($groupId),
            default => Product::query()->inCurrentProductGroup($groupId),
        };

        return $query->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, mixed>  $productIds
     * @return array<int, int>
     */
    private function normalizeIds(array $productIds): array
    {
        return collect($productIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 列未迁移时直接给出可执行的提示，而不是抛 SQL 错误。
     */
    private function assertColumnsReady(): void
    {
        if (! self::columnsReady()) {
            throw new BusinessException('商品描述同步功能需要先执行数据库迁移（products.description_sync_enabled）', 42200);
        }
    }

    private static function columnsReady(): bool
    {
        static $ready = null;

        if ($ready === null) {
            $ready = Schema::hasColumn('products', 'description_sync_enabled')
                && Schema::hasColumn('products', 'description_synced_at');
        }

        return $ready;
    }
}
