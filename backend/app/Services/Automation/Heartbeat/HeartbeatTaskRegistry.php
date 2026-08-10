<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTaskProvider;
use App\Services\Automation\Heartbeat\Providers\CoreScheduledTaskProvider;
use App\Services\Automation\Heartbeat\Providers\LegacyScheduleHookTaskProvider;
use App\Services\Automation\Heartbeat\Providers\PluginScheduledTaskProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class HeartbeatTaskRegistry
{
    /**
     * @var array<string, ScheduledTask>|null
     */
    private ?array $tasks = null;

    /**
     * @var list<class-string<ScheduledTaskProvider>>
     */
    private const PROVIDERS = [
        CoreScheduledTaskProvider::class,
        LegacyScheduleHookTaskProvider::class,
        PluginScheduledTaskProvider::class,
    ];

    public function __construct(
        private Container $container,
    ) {}

    /**
     * @return list<ScheduledTask>
     */
    public function enabledTasks(): array
    {
        return array_values($this->tasksByKey());
    }

    public function get(string $taskKey): ScheduledTask
    {
        $taskKey = trim($taskKey);
        $tasks = $this->tasksByKey();

        if (! isset($tasks[$taskKey])) {
            throw new InvalidArgumentException('不支持的任务：'.$taskKey);
        }

        return $tasks[$taskKey];
    }

    public function supports(string $taskKey): bool
    {
        $taskKey = trim($taskKey);

        return $taskKey !== '' && isset($this->tasksByKey()[$taskKey]);
    }

    public function supportsManualTrigger(string $taskKey): bool
    {
        $taskKey = trim($taskKey);
        $tasks = $this->tasksByKey();

        return isset($tasks[$taskKey]) && $tasks[$taskKey]->manualTriggerable();
    }

    /**
     * @return array<string, ScheduledTask>
     */
    private function tasksByKey(): array
    {
        if ($this->tasks !== null) {
            return $this->tasks;
        }

        $resolved = [];

        foreach (self::PROVIDERS as $providerClass) {
            $provider = $this->container->make($providerClass);
            if (! $provider instanceof ScheduledTaskProvider) {
                continue;
            }

            foreach ($provider->tasks() as $task) {
                $key = trim($task->key());
                if ($key === '') {
                    throw new InvalidArgumentException('定时任务 key 不能为空');
                }

                if (isset($resolved[$key])) {
                    throw new InvalidArgumentException("定时任务 key 重复：{$key}");
                }

                $resolved[$key] = $task;
            }
        }

        uasort($resolved, static fn (ScheduledTask $left, ScheduledTask $right): int => [$left->category(), $left->title(), $left->key()] <=> [$right->category(), $right->title(), $right->key()]);

        return $this->tasks = $resolved;
    }
}
