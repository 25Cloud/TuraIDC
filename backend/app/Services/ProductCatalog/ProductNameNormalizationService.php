<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Models\Product;
use App\Support\ProductCatalogName;
use App\Support\ProductGroupHierarchyFields;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 商品展示名规范化：把 custom_display_name 统一成「三级分组名 + 规范规格摘要」。
 *
 * 上游商品名是把线路描述和规格混着写的自由文本（「宿迁AMD 锐龙 Threadripper
 * 3960X三线BGP 2G2H」「枣庄BGP 13900K 2核2G」「2vCPU2GiB」），对接同步进来的
 * 就是这些名字，同目录里横向比较完全对不上。
 *
 * 这里只做「对接之后」的加工，不改同步逻辑本身（同步仍按既有权口径把上游名写进
 * custom_display_name）：把已落库的名字重新算成
 *   三级分组名（缺失时二级分组名） + 规范规格摘要
 * 例如「宿迁BGP Threadripper 3960X 2核2G30Mbps」。算不出规格摘要的商品
 * （虚拟主机 / CDN / 挂机宝等）保持原名不动。
 *
 * 幂等：名字已经是新格式时不会重复写库，可以反复执行。
 *
 * 注意：它不看名字来源，会把运营手工起的名字也按规则重算，所以只作为运维命令
 * 手动执行（同步完新商品后跑一次），不挂进调度。
 */
class ProductNameNormalizationService
{
    private readonly ProductSpecSummaryBuilder $specSummaryBuilder;

    public function __construct(?ProductSpecSummaryBuilder $specSummaryBuilder = null)
    {
        $this->specSummaryBuilder = $specSummaryBuilder ?? new ProductSpecSummaryBuilder;
    }

    /**
     * 只读预览：不改库。
     *
     * @return array<string, mixed>
     */
    public function inspect(int $status = 1, int $sample = 20): array
    {
        $collected = $this->collect($status);

        return [
            'mode' => 'dry-run',
            'summary' => $collected['summary'],
            'samples' => array_slice($collected['changes'], 0, max(1, $sample)),
            'skipped' => array_slice($collected['skipped'], 0, max(1, $sample)),
            'too_long' => $collected['too_long'],
        ];
    }

    /**
     * 执行改名：先写备份文件，再分批更新，最后失效商品目录缓存。
     *
     * @return array<string, mixed>
     */
    public function execute(int $status = 1, int $chunk = 200): array
    {
        $collected = $this->collect($status);
        $changes = $collected['changes'];

        if ($changes === []) {
            return [
                'mode' => 'execute',
                'summary' => $collected['summary'],
                'updated' => 0,
                'backup_path' => '',
            ];
        }

        $backupPath = $this->writeBackup($changes);

        foreach (array_chunk($changes, max(1, $chunk)) as $batch) {
            DB::transaction(function () use ($batch): void {
                foreach ($batch as $change) {
                    DB::table('products')
                        ->where('id', $change['id'])
                        ->update([
                            'custom_display_name' => $change['new'],
                            'updated_at' => now(),
                        ]);
                }
            });
        }

        // 走 query builder 不会触发模型事件，商品目录缓存要显式失效，
        // 口径与 AppServiceProvider::invalidateCatalogCacheOnCatalogChanges 保持一致。
        Cache::tags(['site-products'])->flush();
        Cache::forget('coupon_product_group_tree_v1');

        return [
            'mode' => 'execute',
            'summary' => $collected['summary'],
            'updated' => count($changes),
            'backup_path' => $backupPath,
        ];
    }

    /**
     * 扫描商品并算出改名清单。
     *
     * @return array{summary: array<string, int>, changes: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>, too_long: array<int, array<string, mixed>>}
     */
    private function collect(int $status): array
    {
        $query = Product::query()
            ->with('productGroup.secondProductGroup.firstProductGroup')
            ->orderBy('id');

        if ($status >= 0) {
            $query->where('status', $status);
        }

        $products = $query->get();

        $changes = [];
        $skipped = [];
        $tooLong = [];

        foreach ($products as $product) {
            $currentName = trim((string) $product->getRawOriginal('custom_display_name'));
            $summary = $this->specSummaryBuilder->build($product);

            if ($summary === '') {
                $skipped[] = [
                    'id' => (int) $product->id,
                    'name' => $currentName,
                    'reason' => '无规格摘要',
                ];

                continue;
            }

            $newName = ProductCatalogName::compose(ProductGroupHierarchyFields::fromProduct($product), [
                'product_spec_summary' => $summary,
                'product_display_name' => '',
            ]);

            if ($newName === '') {
                $skipped[] = [
                    'id' => (int) $product->id,
                    'name' => $currentName,
                    'reason' => '无法组装展示名',
                ];

                continue;
            }

            if ($newName === $currentName) {
                $skipped[] = [
                    'id' => (int) $product->id,
                    'name' => $currentName,
                    'reason' => '与现名相同',
                ];

                continue;
            }

            // custom_display_name 是 varchar(190)，超长的留给人工处理，不静默截断。
            if (mb_strlen($newName) > 190) {
                $tooLong[] = [
                    'id' => (int) $product->id,
                    'name' => $currentName,
                    'new' => $newName,
                    'length' => mb_strlen($newName),
                ];

                continue;
            }

            $changes[] = [
                'id' => (int) $product->id,
                'old' => $currentName,
                'new' => $newName,
            ];
        }

        return [
            'summary' => [
                'scanned' => $products->count(),
                'pending' => count($changes),
                'skipped' => count($skipped),
                'too_long' => count($tooLong),
            ],
            'changes' => $changes,
            'skipped' => $skipped,
            'too_long' => $tooLong,
        ];
    }

    /**
     * 把旧名写成可回滚的 SQL，落在 storage/app 下（不进仓库）。
     *
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function writeBackup(array $changes): string
    {
        $directory = storage_path('app/product-name-normalizations');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/product-names-'.date('Ymd-His').'.sql';
        $lines = ["-- 商品展示名规范化前的 custom_display_name（回滚用）"];

        foreach ($changes as $change) {
            $lines[] = sprintf(
                'UPDATE products SET custom_display_name = %s WHERE id = %d;',
                DB::getPdo()->quote((string) $change['old']),
                (int) $change['id'],
            );
        }

        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }
}
