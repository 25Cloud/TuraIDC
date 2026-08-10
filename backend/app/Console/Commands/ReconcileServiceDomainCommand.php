<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\ServiceMigrationService;
use Illuminate\Console\Command;

class ReconcileServiceDomainCommand extends Command
{
    protected $signature = 'migrate:service:reconcile {--json : 以 JSON 输出结果}';

    protected $description = '执行服务实例域迁移后的总对账';

    public function handle(ServiceMigrationService $service): int
    {
        $service->ensureConnections();

        $serviceInstances = $service->deriveServiceInstancePayloads();
        $lifecycleLogs = $service->deriveServiceLifecycleLogPayloads();
        $operationLogs = $service->deriveServiceOperationLogPayloads();
        $remoteSnapshots = $service->deriveServiceRemoteSnapshotPayloads();

        $summary = [
            'service_instances' => [
                'old_derived' => count($serviceInstances),
                'new' => $service->targetCount('service_instances'),
            ],
            'service_lifecycle_logs' => [
                'old_derived' => count($lifecycleLogs),
                'new' => $service->targetCount('service_lifecycle_logs'),
            ],
            'service_operation_logs' => [
                'old_derived' => count($operationLogs),
                'new' => $service->targetCount('service_operation_logs'),
            ],
            'service_remote_snapshots' => [
                'old_derived' => count($remoteSnapshots),
                'new' => $service->targetCount('service_remote_snapshots'),
            ],
        ];

        $orphans = [
            'service_instances.user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM service_instances WHERE user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
            'service_instances.product_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM service_instances WHERE product_id NOT IN (SELECT id FROM products)'
            )[0]->cnt) ?? 0),
            'service_lifecycle_logs.service_instance_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM service_lifecycle_logs WHERE service_instance_id NOT IN (SELECT id FROM service_instances)'
            )[0]->cnt) ?? 0),
            'service_operation_logs.service_instance_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM service_operation_logs WHERE service_instance_id NOT IN (SELECT id FROM service_instances)'
            )[0]->cnt) ?? 0),
            'service_remote_snapshots.service_instance_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM service_remote_snapshots WHERE service_instance_id NOT IN (SELECT id FROM service_instances)'
            )[0]->cnt) ?? 0),
        ];

        $payload = [
            'summary' => $summary,
            'orphans' => $orphans,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($summary as $table => $counts) {
            $this->line($table.': '.json_encode($counts, JSON_UNESCAPED_UNICODE));
        }

        foreach ($orphans as $key => $count) {
            $this->line($key.' orphan='.$count);
        }

        return self::SUCCESS;
    }
}
