<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTaskProvider;
use App\Services\Automation\Heartbeat\Providers\CoreScheduledTaskProvider;
use App\Services\Automation\Heartbeat\Providers\LegacyScheduleHookTaskProvider;
use App\Services\Automation\Heartbeat\Providers\PluginScheduledTaskProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class HeartbeatTaskRegistry
{
    /** @var array<string, ScheduledTask>|null */
    private ?array $tasks = null;

    /** @var list<class-string<ScheduledTaskProvider>> */
    private const PROVIDERS = [
        CoreScheduledTaskProvider::class,
        LegacyScheduleHookTaskProvider::class,
        PluginScheduledTaskProvider::class,
    ];

    public function __construct(
        private Container $container,
        private ?ScheduledTaskValidator $validator = null,
    ) {}

    /** @return list<ScheduledTask> */
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

    /** @return array<string, ScheduledTask> */
    private function tasksByKey(): array
    {
        if ($this->tasks !== null) {
            return $this->tasks;
        }

        $resolved = [];

        foreach (self::PROVIDERS as $providerClass) {
            try {
                $provider = $this->container->make($providerClass);
                if (! $provider instanceof ScheduledTaskProvider) {
                    throw new InvalidArgumentException('定时任务 Provider 未实现约定接口');
                }

                $providerTasks = $provider->tasks();
            } catch (\Throwable $exception) {
                // 单个 Provider 的注册故障不能阻断其余任务和心跳存活。
                Log::error('[定时任务] Provider 注册失败，已隔离该 Provider', [
                    'provider' => $providerClass,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);

                continue;
            }

            foreach ((array) $providerTasks as $task) {
                try {
                    $task = $this->normalizeTask($task);

                    // 核心任务、旧 Hook 与插件任务必须经过同一份运行契约校验；
                    // 插件 Provider 已在扫描时校验，这里再做一次运行时防护，覆盖配置热变更和长驻 Worker。
                    $this->taskValidator()->validate($task, $providerClass);
                    $key = trim($task->key());
                    if ($key === '') {
                        throw new InvalidArgumentException('定时任务 key 不能为空');
                    }

                    if (isset($resolved[$key])) {
                        if ($providerClass === PluginScheduledTaskProvider::class) {
                            Log::error('[定时任务] 插件任务 key 与现有任务冲突，已跳过插件任务', [
                                'task' => $key,
                            ]);

                            continue;
                        }

                        throw new InvalidArgumentException("定时任务 key 重复：{$key}");
                    }

                    $resolved[$key] = $task;
                } catch (\Throwable $exception) {
                    Log::error('[定时任务] 单个任务注册失败，已跳过', [
                        'provider' => $providerClass,
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);
                }
            }
        }

        try {
            uasort(
                $resolved,
                static fn (ScheduledTask $left, ScheduledTask $right): int => [$left->category(), $left->title(), $left->key()] <=> [$right->category(), $right->title(), $right->key()],
            );
        } catch (\Throwable $exception) {
            // 元数据读取属于插件可扩展代码；排序失败不能让核心任务注册和心跳整体失效。
            Log::error('[定时任务] 任务排序失败，保留当前注册顺序', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }

        return $this->tasks = $resolved;
    }

    private function taskValidator(): ScheduledTaskValidator
    {
        return $this->validator ??= $this->container->make(ScheduledTaskValidator::class);
    }

    private function normalizeTask(mixed $task): ScheduledTask
    {
        if (! $task instanceof ScheduledTask) {
            throw new InvalidArgumentException('定时任务未实现约定接口');
        }

        return $task;
    }
}
