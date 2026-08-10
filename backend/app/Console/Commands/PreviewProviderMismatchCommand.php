<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PreviewProviderMismatchCommand extends Command
{
    protected $signature = 'upstream:preview-provider-mismatch';

    protected $description = '只读预览服务绑定与供应商/商品绑定 provider_key 不一致的记录';

    public function handle(): int
    {
        $rows = DB::select(<<<'SQL'
            SELECT
                s.id AS service_id,
                sub.provider_key AS service_provider_key,
                COALESCE(spb.provider_key, pub.provider_key) AS expected_provider_key,
                COALESCE(spb.supplier_id, pub_spb.supplier_id) AS supplier_id,
                s.product_id,
                pub.upstream_product_id,
                sub.upstream_service_id
            FROM services s
            JOIN service_upstream_bindings sub
                ON sub.service_id = s.id
            LEFT JOIN supplier_plugin_bindings spb
                ON spb.id = sub.supplier_plugin_binding_id
            LEFT JOIN product_upstream_bindings pub
                ON pub.id = sub.product_upstream_binding_id
            LEFT JOIN supplier_plugin_bindings pub_spb
                ON pub_spb.id = pub.supplier_plugin_binding_id
            WHERE sub.provider_key IS NOT NULL
              AND sub.provider_key != ''
              AND COALESCE(spb.provider_key, pub.provider_key, '') != ''
              AND sub.provider_key != COALESCE(spb.provider_key, pub.provider_key)
            ORDER BY s.id
        SQL);

        if (empty($rows)) {
            $this->info('未发现 provider_key 错配记录。');

            return self::SUCCESS;
        }

        $this->warn(sprintf('发现 %d 条 provider_key 错配记录（只读，未执行任何写库操作）：', count($rows)));
        $this->newLine();

        $headers = ['service_id', 'service_provider_key', 'expected_provider_key', 'supplier_id', 'product_id', 'upstream_product_id', 'upstream_service_id'];
        $tableRows = array_map(fn ($row) => [
            $row->service_id,
            $row->service_provider_key,
            $row->expected_provider_key,
            $row->supplier_id,
            $row->product_id,
            $row->upstream_product_id,
            $row->upstream_service_id,
        ], $rows);

        $this->table($headers, $tableRows);
        $this->newLine();
        $this->warn('以上为 dry-run 输出，未修改任何数据。如需执行修复，请另行确认。');

        return self::SUCCESS;
    }
}
