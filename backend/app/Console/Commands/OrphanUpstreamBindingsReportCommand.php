<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 只读诊断：报告同一服务存在多条上游绑定（旧 host 变孤儿）的情况。
 *
 * 背景：并发重试/开通失败可让同一服务在 service_upstream_bindings 产生多条绑定行
 * （每次新 host 追加一行），状态同步/续费/控制台只认最新一条，旧 host 在上游成为
 * 无本地引用的孤儿资源。本命令只输出报告，不删除、不修改任何数据；
 * 是否回收孤儿实例需产品确认清理策略后另行实施。
 */
class OrphanUpstreamBindingsReportCommand extends Command
{
    protected $signature = 'services:orphan-upstream-bindings
        {--service-id= : 仅统计指定服务 ID}';

    protected $description = '报告同一服务存在多条上游绑定的孤儿 host（只读诊断）';

    public function handle(): int
    {
        if (! Schema::hasTable('service_upstream_bindings')) {
            $this->info('绑定表不存在，跳过。');

            return self::SUCCESS;
        }

        $query = DB::table('service_upstream_bindings')
            ->select(['id', 'service_id', 'plugin_id', 'provider_key', 'upstream_service_id', 'created_at'])
            ->orderBy('service_id')
            ->orderBy('plugin_id')
            ->orderByDesc('id');

        $serviceId = (int) $this->option('service-id');
        if ($serviceId > 0) {
            $query->where('service_id', $serviceId);
        }

        $rows = $query->get();

        $groups = $rows->groupBy(
            fn (object $row): string => (int) $row->service_id.':'.(int) $row->plugin_id
        );

        $orphans = [];
        $servicesWithOrphans = 0;

        foreach ($groups as $group) {
            $items = $group->values();
            if ($items->count() <= 1) {
                continue;
            }

            $servicesWithOrphans++;
            $current = $items->first();

            foreach ($items->slice(1) as $row) {
                $orphans[] = [
                    'id' => $row->id,
                    'service_id' => $row->service_id,
                    'provider' => (string) $row->provider_key,
                    'orphan_host_id' => $row->upstream_service_id,
                    'current_host_id' => $current->upstream_service_id,
                    'created_at' => (string) $row->created_at,
                ];
            }
        }

        if ($orphans === []) {
            $this->info('未发现孤儿绑定（每个服务最多一条上游绑定）。');

            return self::SUCCESS;
        }

        $this->table(['绑定ID', '服务ID', 'provider', '孤儿host', '当前host', '创建时间'], $orphans);
        $this->line(sprintf(
            '共 %d 个服务存在 %d 条孤儿绑定；这些上游实例不再被状态同步/续费/控制台引用，需人工核实后决定是否回收。',
            $servicesWithOrphans,
            count($orphans)
        ));

        return self::SUCCESS;
    }
}
