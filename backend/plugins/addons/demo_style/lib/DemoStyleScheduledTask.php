<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\DemoStyle\Lib;

use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Data\TaskContext;
use App\Services\Automation\Heartbeat\ScheduleRule;
use App\Services\Automation\ScheduleHookService;

final class DemoStyleScheduledTask implements ScheduledTask
{
    public const KEY = 'addon-demo-style-refresh';

    public const HOOK = 'addons.demo_style.refresh';

    public function __construct(
        private readonly ScheduleHookService $hookService,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Demo Style 扩展刷新';
    }

    public function description(): string
    {
        return '演示 Addon 如何通过插件清单注册心跳定时任务并触发自身 Hook。';
    }

    public function category(): string
    {
        return '功能扩展';
    }

    public function triggers(): array
    {
        return [ScheduleRule::everyTicks(4)];
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(TaskContext $context): array
    {
        $results = $this->hookService->run(self::HOOK, array_replace($context->toLogContext(), [
            'hook' => self::HOOK,
            'addon' => 'demo_style',
        ]));

        return [
            'hook' => self::HOOK,
            'listeners' => count($results),
            'handled' => collect($results)
                ->filter(fn (array $result): bool => ($result['status'] ?? null) === 'success')
                ->count(),
            'results' => $results,
        ];
    }

    public function queue(): string
    {
        return (string) config('queue.turaidc_schedule_queue', 'automation');
    }

    public function timeout(): int
    {
        return 300;
    }

    public function lockTtlSeconds(): int
    {
        return 600;
    }

    public function manualTriggerable(): bool
    {
        return true;
    }
}
