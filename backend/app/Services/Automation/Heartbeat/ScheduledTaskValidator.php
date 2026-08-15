<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\TriggerRule;
use App\Services\Automation\Heartbeat\Rules\CronRule;
use Cron\CronExpression;
use InvalidArgumentException;

/**
 * 校验系统与插件任务之间必须共同遵守的运行契约。
 * 插件在启用时校验一次，运行时再次校验以防长驻 Worker 读取到旧缓存或坏声明。
 */
final class ScheduledTaskValidator
{
    public function validate(ScheduledTask $task, string $source = ''): void
    {
        $prefix = $source !== '' ? $source.'：' : '';
        $declaredKey = $task->key();
        $key = trim($declaredKey);
        if ($key === '' || $declaredKey !== $key || mb_strlen($key) > 120) {
            throw new InvalidArgumentException($prefix.'定时任务 key 不能为空且不能超过 120 个字符');
        }

        foreach ([
            'title' => $task->title(),
            'description' => $task->description(),
            'category' => $task->category(),
        ] as $field => $value) {
            if (trim((string) $value) === '') {
                throw new InvalidArgumentException($prefix."定时任务 {$field} 不能为空 [{$key}]");
            }
        }

        $rules = $task->triggers();
        if ($rules === []) {
            throw new InvalidArgumentException("{$prefix}定时任务至少需要一个触发规则 [{$key}]");
        }

        foreach ($rules as $rule) {
            $this->validateUnknownRule($rule, $key, $prefix);
        }

        $declaredQueue = $task->queue();
        $queue = trim($declaredQueue);
        if ($queue === '' || $declaredQueue !== $queue || ! in_array($queue, $this->consumableQueues(), true)) {
            throw new InvalidArgumentException("{$prefix}定时任务队列未配置为可消费队列：{$queue} [{$key}]");
        }

        $timeout = $task->timeout();
        if ($timeout < 1 || $timeout > max(1, (int) config('queue.turaidc_worker_max_timeout', 3600))) {
            throw new InvalidArgumentException("{$prefix}定时任务 timeout 超出允许范围 [{$key}]");
        }

        $lockTtl = $task->lockTtlSeconds();
        if ($lockTtl < $timeout + 60) {
            throw new InvalidArgumentException("{$prefix}定时任务 lock TTL 必须至少比 timeout 多 60 秒 [{$key}]");
        }

        $maxLockTtl = max(
            3600,
            (int) config('queue.turaidc_worker_max_timeout', 3600) * 2,
        );
        if ($lockTtl > $maxLockTtl) {
            throw new InvalidArgumentException("{$prefix}定时任务 lock TTL 超出允许上限 {$maxLockTtl} 秒 [{$key}]");
        }

        $driver = (string) config('queue.default', 'sync');
        $retryAfter = (int) config("queue.connections.{$driver}.retry_after", 0);
        if ($retryAfter > 0 && $retryAfter <= $timeout + 60) {
            throw new InvalidArgumentException("{$prefix}队列 retry_after 必须大于任务 timeout 加安全余量 [{$key}]");
        }
    }

    /** @return list<string> */
    public function consumableQueues(): array
    {
        $queues = [];
        foreach ([
            (string) config('queue.turaidc_business_queues', ''),
            (string) config('queue.turaidc_schedule_queue', ''),
        ] as $configured) {
            foreach (explode(',', $configured) as $queue) {
                $queue = trim($queue);
                if ($queue !== '' && ! in_array($queue, $queues, true)) {
                    $queues[] = $queue;
                }
            }
        }

        return $queues;
    }

    private function validateRule(TriggerRule $rule, string $taskKey, string $prefix): void
    {
        $expression = trim($rule->describe());
        if ($expression === '') {
            throw new InvalidArgumentException("{$prefix}触发规则描述不能为空 [{$taskKey}]");
        }

        // CronExpression 会校验语法；心跳只在每 15 分钟运行，因此分钟字段不能声明 5/10 等槽位。
        if ($rule instanceof CronRule) {
            try {
                CronExpression::factory($expression);
            } catch (\Throwable $exception) {
                throw new InvalidArgumentException("{$prefix}无效 cron 表达式：{$expression} [{$taskKey}]", 0, $exception);
            }

            $parts = preg_split('/\s+/', $expression) ?: [];
            if (count($parts) !== 5) {
                throw new InvalidArgumentException("{$prefix}cron 必须包含 5 个字段：{$expression} [{$taskKey}]");
            }

            $minute = (string) $parts[0];
            foreach (preg_split('/\s*,\s*/', $minute) ?: [] as $value) {
                $value = trim($value);
                if ($value === '' || preg_match('/^\*\/(15|30|45)$/', $value) === 1) {
                    continue;
                }

                if (preg_match('/^\d+$/', $value) === 1 && in_array((int) $value, [0, 15, 30, 45], true)) {
                    continue;
                }

                throw new InvalidArgumentException("{$prefix}cron 分钟必须对齐 00/15/30/45：{$expression} [{$taskKey}]");
            }
        }
    }

    private function validateUnknownRule(mixed $rule, string $taskKey, string $prefix): void
    {
        if (! $rule instanceof TriggerRule) {
            throw new InvalidArgumentException("{$prefix}定时任务包含无效触发规则 [{$taskKey}]");
        }

        $this->validateRule($rule, $taskKey, $prefix);
    }
}
