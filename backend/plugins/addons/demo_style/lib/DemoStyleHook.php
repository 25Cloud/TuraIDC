<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\DemoStyle\Lib;

use App\Services\Automation\Contracts\ScheduleHook;

final class DemoStyleHook implements ScheduleHook
{
    /**
     * @return array<string, mixed>
     */
    public function handle(string $hook, array $context = []): array
    {
        return [
            'handled' => true,
            'hook' => $hook,
            'addon' => 'demo_style',
            'source' => (string) ($context['source'] ?? 'unknown'),
            'task_key' => $context['task_key'] ?? null,
        ];
    }
}
