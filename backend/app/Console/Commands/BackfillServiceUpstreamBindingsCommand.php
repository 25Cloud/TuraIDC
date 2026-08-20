<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 回填 service_upstream_bindings（幂等）：
 * 1. 优先解析老站 shd_host.notes 中的 `ID：xxx`（存量已开通服务）
 * 2. 其余服务按供应商调用上游 `/host/list` 分页拉取，按 domain（主机名）精确匹配
 * 3. 写入 service_upstream_bindings，恢复控制台完整管理能力（upstream.host_id > 0）
 */
class BackfillServiceUpstreamBindingsCommand extends Command
{
    private const HOST_LIST_LIMIT = 100;

    protected $signature = 'services:backfill-upstream-bindings
        {--service-ids= : 逗号分隔的服务 ID，仅处理指定服务}
        {--dry-run : 只输出待绑定列表，不写入数据库}';

    protected $description = '回填服务实例的上游绑定（notes ID + 上游 /host/list 按域名匹配）';

    public function handle(PluginBindingResolver $bindingResolver, HostingPanelApiTransport $transport): int
    {
        if (! Schema::hasTable('service_upstream_bindings') || ! Schema::hasTable('product_upstream_bindings')) {
            $this->error('service_upstream_bindings / product_upstream_bindings 表不存在');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $idsRaw = trim((string) $this->option('service-ids'));

        $services = Service::query()
            ->with(['product' => fn ($query) => $query->with('upstreamBindings')])
            ->where(function ($query): void {
                $query->whereExists(function ($subQuery): void {
                    $subQuery->selectRaw('1')
                        ->from('product_upstream_bindings as pub')
                        ->whereColumn('pub.product_id', 'services.product_id')
                        ->where('pub.status', 1);
                });
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('service_upstream_bindings as sub')
                    ->whereColumn('sub.service_id', 'services.id')
                    ->whereNotNull('sub.upstream_service_id')
                    ->where('sub.upstream_service_id', '<>', '');
            })
            ->orderBy('id');

        if ($idsRaw !== '') {
            $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
            if ($ids === []) {
                $this->error('--service-ids 参数格式错误，请传入逗号分隔的整数 ID');

                return self::INVALID;
            }
            $services->whereIn('id', $ids);
        }

        $services = $services->get();
        $total = $services->count();
        if ($total === 0) {
            $this->info('没有待回填的服务实例');

            return self::SUCCESS;
        }

        $this->info("待回填服务 {$total} 条（".($dryRun ? '预检模式' : '实际写入').'）');

        // 老站 shd_host.notes 的 `ID：xxx` 映射（旧库存在时优先，服务 id 与老站 host id 一致）
        $legacyHostIdMap = $this->legacyNotesHostIdMap($services->pluck('id')->all());

        // 按供应商缓存 /host/list 分页结果，避免重复拉取
        $hostIndexBySupplier = [];

        $bound = 0;
        $failed = 0;
        foreach ($services as $service) {
            try {
                $hostId = $this->resolveUpstreamHostId($service, $legacyHostIdMap, $transport, $hostIndexBySupplier);
                if ($hostId === null) {
                    $failed++;
                    $this->warn("服务 #{$service->id} 未匹配到上游主机（domain: {$service->domain}）");
                    continue;
                }

                $binding = $this->writeBinding($service, $hostId, $dryRun);
                if ($binding !== null) {
                    $bound++;
                } else {
                    $failed++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->warn("服务 #{$service->id} 处理失败：{$exception->getMessage()}");
            }
        }

        $this->newLine();
        $this->info($dryRun ? '预检完成：' : '回填完成：');
        $this->line('待/已绑定: '.$bound);
        $this->line('未匹配/失败: '.$failed);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $serviceIds
     * @return array<int, int> service id => upstream host id（来自老站 notes）
     */
    private function legacyNotesHostIdMap(array $serviceIds): array
    {
        $map = [];
        if ($serviceIds === []) {
            return $map;
        }

        $sourceConnection = (string) config('catalog_migration.source_connection', 'mysql');
        try {
            $rows = DB::connection($sourceConnection)
                ->table('shd_host')
                ->select(['id', 'notes'])
                ->whereIn('id', $serviceIds)
                ->get();
        } catch (\Throwable $exception) {
            return $map;
        }

        foreach ($rows as $row) {
            $hostId = $this->extractNotesUpstreamHostId((string) ($row->notes ?? ''));
            if ($hostId > 0) {
                $map[(int) $row->id] = $hostId;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $legacyHostIdMap
     * @param  array<int, array<int, int>>  $hostIndexBySupplier
     */
    private function resolveUpstreamHostId(Service $service, array $legacyHostIdMap, HostingPanelApiTransport $transport, array &$hostIndexBySupplier): ?int
    {
        $productBinding = $service->product?->upstreamBindings?->firstWhere('status', 1);
        if ($productBinding === null) {
            return null;
        }

        // 1. 老站 notes 中的 `ID：xxx`
        $notesId = $legacyHostIdMap[(int) $service->id] ?? 0;
        if ($notesId > 0) {
            return $notesId;
        }

        // 2. 上游 /host/list 按 domain 精确匹配
        $supplier = $this->supplierForProductBinding((int) $productBinding->id);
        if (! $supplier instanceof Supplier) {
            return null;
        }

        $supplierId = (int) $supplier->id;
        if (! isset($hostIndexBySupplier[$supplierId])) {
            $hostIndexBySupplier[$supplierId] = $this->fetchHostIndex($supplier, $transport);
        }

        $domain = strtolower(trim((string) $service->domain));
        if ($domain === '') {
            return null;
        }

        return $hostIndexBySupplier[$supplierId][$domain] ?? null;
    }

    private function supplierForProductBinding(int $productBindingId): ?Supplier
    {
        $row = DB::table('product_upstream_bindings as pub')
            ->join('supplier_plugin_bindings as spb', 'spb.id', '=', 'pub.supplier_plugin_binding_id')
            ->where('pub.id', $productBindingId)
            ->first(['spb.supplier_id']);

        $supplierId = (int) (($row->supplier_id ?? 0) ?: 0);

        return $supplierId > 0 ? Supplier::query()->find($supplierId) : null;
    }

    /**
     * @return array<string, int> domain => host id
     */
    private function fetchHostIndex(Supplier $supplier, HostingPanelApiTransport $transport): array
    {
        $index = [];
        $page = 1;

        while (true) {
            $response = $transport->get($supplier, '/host/list', null, [
                'page' => $page,
                'limit' => self::HOST_LIST_LIMIT,
            ]);

            $payload = $this->extractPayload($response);
            $hosts = is_array($payload['host'] ?? null) ? $payload['host'] : [];
            if ($hosts === []) {
                break;
            }

            foreach ($hosts as $host) {
                if (! is_array($host)) {
                    continue;
                }

                $domain = strtolower(trim((string) ($host['domain'] ?? '')));
                $id = (int) ($host['id'] ?? 0);
                if ($domain !== '' && $id > 0) {
                    $index[$domain] = $id;
                }
            }

            if (count($hosts) < self::HOST_LIST_LIMIT) {
                break;
            }
            $page++;
        }

        return $index;
    }

    private function extractPayload(array $response): array
    {
        foreach (['data', 'payload'] as $key) {
            if (is_array($response[$key] ?? null)) {
                return $response[$key];
            }
        }

        return $response;
    }

    private function writeBinding(Service $service, int $upstreamHostId, bool $dryRun): ?int
    {
        $productBinding = $service->product?->upstreamBindings?->firstWhere('status', 1);
        if ($productBinding === null) {
            return null;
        }

        $supplierBinding = DB::table('supplier_plugin_bindings')->where('id', $productBinding->supplier_plugin_binding_id)->first();
        if ($supplierBinding === null) {
            return null;
        }

        $now = now();

        if ($dryRun) {
            return (int) $productBinding->id;
        }

        DB::table('service_upstream_bindings')->updateOrInsert(
            [
                'service_id' => (int) $service->id,
                'plugin_id' => (int) $supplierBinding->plugin_id,
                'upstream_service_id' => (string) $upstreamHostId,
            ],
            [
                'product_upstream_binding_id' => (int) $productBinding->id,
                'supplier_plugin_binding_id' => (int) $supplierBinding->id,
                'provider_key' => (string) $supplierBinding->provider_key,
                'backfill_batch_id' => 'cli_'.date('YmdHis'),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $row = DB::table('service_upstream_bindings')
            ->where('service_id', (int) $service->id)
            ->where('plugin_id', (int) $supplierBinding->plugin_id)
            ->where('upstream_service_id', (string) $upstreamHostId)
            ->first(['id']);

        return $row === null ? null : (int) $row->id;
    }

    /**
     * 从老站 notes 提取上游主机 ID（兼容全角/半角冒号）。
     */
    private function extractNotesUpstreamHostId(string $notes): int
    {
        if (trim($notes) === '') {
            return 0;
        }

        $needle = 'ID：';
        $offset = mb_strpos($notes, $needle);
        if ($offset === false) {
            $needle = 'ID:';
            $offset = mb_strpos($notes, $needle);
        }
        if ($offset === false) {
            return 0;
        }

        $suffix = mb_substr($notes, $offset + mb_strlen($needle));
        if (preg_match('/^\s*(\d+)/', $suffix, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
