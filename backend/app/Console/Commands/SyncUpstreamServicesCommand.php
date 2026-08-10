<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\Supplier;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SyncUpstreamServicesCommand extends Command
{
    protected $signature = 'services:sync-upstream
        {--service-ids= : 逗号分隔的服务 ID，仅同步指定服务}
        {--dry-run : 仅输出待同步列表，不实际请求上游}
        {--delay=500 : 每条请求间隔（毫秒），避免触发上游限流}';

    protected $description = '批量从上游拉取服务最新状态（IP、运行状态等）并同步到本地';

    public function handle(ClientServiceConsoleService $consoleService, PluginBindingResolver $bindingResolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $delayMs = max(0, (int) $this->option('delay'));
        $idsRaw = trim((string) $this->option('service-ids'));

        $query = Service::query()
            ->with(['product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires'])
            ->where(function ($builder): void {
                if (Schema::hasTable('service_upstream_bindings')) {
                    $builder->whereExists(function ($subQuery): void {
                        $subQuery
                            ->selectRaw('1')
                            ->from('service_upstream_bindings as sub')
                            ->whereColumn('sub.service_id', 'services.id')
                            ->whereNotNull('sub.upstream_service_id')
                            ->where('sub.upstream_service_id', '<>', '');
                    });

                    return;
                }

                $builder->whereRaw('1 = 0');
            });

        if ($idsRaw !== '') {
            $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
            if (empty($ids)) {
                $this->error('--service-ids 参数格式错误，请传入逗号分隔的整数 ID');

                return self::INVALID;
            }
            $query->whereIn('id', $ids);
        }

        $services = $query->orderBy('id')->get();

        if ($services->isEmpty()) {
            $this->info('未找到符合条件的服务。');

            return self::SUCCESS;
        }

        $this->info("共找到 {$services->count()} 条服务需要同步".($dryRun ? '（dry-run 模式，不写入）' : ''));

        if ($dryRun) {
            $headers = ['ID', '名称', 'upstream_host_id', '供应商'];
            $rows = $services->map(function (Service $s) use ($bindingResolver) {
                $supplier = $bindingResolver->supplierForService($s)
                    ?? ($s->product ? $bindingResolver->supplierForProduct($s->product) : null);

                return [
                    $s->id,
                    $s->name,
                    $bindingResolver->upstreamServiceIdForService($s) ?? '-',
                    $supplier instanceof Supplier ? ($supplier->name ?? '-') : '-',
                ];
            })->all();
            $this->table($headers, $rows);

            return self::SUCCESS;
        }

        $success = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($services as $service) {
            $hostId = (int) (($bindingResolver->upstreamServiceIdForService($service) ?? '') ?: 0);
            $supplier = $bindingResolver->supplierForService($service)
                ?? ($service->product ? $bindingResolver->supplierForProduct($service->product) : null);

            if (! $supplier instanceof Supplier) {
                $this->warn("  [跳过] 服务 #{$service->id}（{$service->name}）：供应商不存在");
                $skipped++;

                continue;
            }

            $this->line("  同步 服务 #{$service->id}（{$service->name}）upstream_host_id={$hostId} ...");

            try {
                // 借助反射调用私有方法
                $ref = new \ReflectionClass($consoleService);

                $fetchMethod = $ref->getMethod('fetchRemoteState');
                $fetchMethod->setAccessible(true);
                $remote = $fetchMethod->invoke($consoleService, $service);

                if (! empty($remote['host']) || ! empty($remote['runtime']) || ! empty($remote['nat'])) {
                    $syncMethod = $ref->getMethod('syncServiceFromRemote');
                    $syncMethod->setAccessible(true);
                    $syncMethod->invoke(
                        $consoleService,
                        $service,
                        $remote['host'] ?? [],
                        $remote['runtime'] ?? [],
                        $remote['nat'] ?? [],
                    );
                    $this->info('    ✓ 同步成功');
                } else {
                    $this->warn('    - 上游返回为空，跳过写入');
                    $skipped++;

                    continue;
                }

                $success++;
            } catch (Throwable $e) {
                $this->error("    ✗ 失败：{$e->getMessage()}");
                Log::error('[services:sync-upstream] 同步失败', [
                    'service_id' => $service->id,
                    'host_id' => $hostId,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $this->newLine();
        $this->info("同步完成：成功 {$success} / 失败 {$failed} / 跳过 {$skipped}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
