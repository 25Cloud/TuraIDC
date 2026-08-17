<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Data\TaskContext;
use App\Services\Automation\Heartbeat\ScheduleRule;
use App\Services\Automation\ScheduleHookService;
use App\Services\Upstream\ProviderKey;

final class ZjmfInventoryAndServiceSyncTask implements ScheduledTask
{
    public const KEY = 'sync-zjmf-finance-inventory-and-services';

    public const HOOK = 'plugins.zjmf_finance.inventory_service_sync';

    public function __construct(
        private readonly ScheduleHookService $hookService,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'ZJMF 财务库存与服务同步';
    }

    public function description(): string
    {
        return '由 ZJMF 财务插件每 15 分钟拉取一次商品库存，并同步已购买服务的状态、连接和运行快照。';
    }

    public function category(): string
    {
        return '供应商接口';
    }

    public function triggers(): array
    {
        return [ScheduleRule::everyTicks(1)];
    }

    public function handle(TaskContext $context): array
    {
        $results = $this->hookService->run(self::HOOK, array_replace($context->toLogContext(), [
            'hook' => self::HOOK,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
        ]));

        return array_replace($this->summarizeResults($results), [
            'hook' => self::HOOK,
            'results' => $results,
        ]);
    }

    public function queue(): string
    {
        return (string) config('queue.turaidc_schedule_queue', 'automation');
    }

    public function timeout(): int
    {
        return 1200;
    }

    public function lockTtlSeconds(): int
    {
        return 1800;
    }

    public function manualTriggerable(): bool
    {
        return true;
    }

    private function summarizeResults(array $results): array
    {
        $summary = [
            'listeners' => count($results),
            'failed_listeners' => 0,
            'products' => [],
            'services' => [],
        ];

        foreach ($results as $result) {
            if (($result['status'] ?? null) === 'failed') {
                $summary['failed_listeners']++;

                continue;
            }

            $payload = is_array($result['result'] ?? null) ? $result['result'] : [];
            if (is_array($payload['products'] ?? null)) {
                $summary['products'] = $this->mergeNumericSummary($summary['products'], $payload['products']);
            }
            if (is_array($payload['services'] ?? null)) {
                $summary['services'] = $this->mergeNumericSummary($summary['services'], $payload['services']);
            }
        }

        return $summary;
    }

    private function mergeNumericSummary(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (is_numeric($value)) {
                $left[$key] = (int) ($left[$key] ?? 0) + (int) $value;

                continue;
            }

            if (! array_key_exists($key, $left)) {
                $left[$key] = $value;
            }
        }

        return $left;
    }
}
