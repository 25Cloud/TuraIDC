<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Data\TaskContext;
use App\Services\Automation\Heartbeat\ScheduleRule;
use App\Services\Automation\ScheduleHookService;
use App\Services\Upstream\ProviderKey;

final class ZjmfScheduledAuthRefreshTask implements ScheduledTask
{
    public const KEY = 'refresh-zjmf-finance-auth';

    public const HOOK = 'plugins.zjmf_finance.auth_refresh';

    public function __construct(
        private readonly ScheduleHookService $hookService,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'ZJMF 财务认证刷新';
    }

    public function description(): string
    {
        return '由 ZJMF 财务插件定时刷新上游 JWT 会话，减少登录态过期导致的接口失败。';
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
        return 600;
    }

    public function lockTtlSeconds(): int
    {
        return 660;
    }

    public function manualTriggerable(): bool
    {
        return true;
    }

    private function summarizeResults(array $results): array
    {
        $summary = [
            'matched' => 0,
            'refreshed' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($results as $result) {
            if (($result['status'] ?? null) === 'failed') {
                $summary['failed']++;

                continue;
            }

            $payload = is_array($result['result'] ?? null) ? $result['result'] : [];
            foreach (array_keys($summary) as $key) {
                if (isset($payload[$key]) && is_numeric($payload[$key])) {
                    $summary[$key] += (int) $payload[$key];
                }
            }
        }

        return $summary;
    }
}
