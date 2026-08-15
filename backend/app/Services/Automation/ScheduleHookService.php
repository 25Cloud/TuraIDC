<?php

namespace App\Services\Automation;

use App\Models\IntegrationPlugin;
use App\Services\Automation\Contracts\ScheduleHook;
use App\Services\Automation\Heartbeat\ScheduleDeclarationNormalizer;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

class ScheduleHookService
{
    public const HOOK_BEFORE_CRON = 'before_cron';

    public const HOOK_AFTER_CRON = 'after_cron';

    public const HOOK_BEFORE_DAILY_CRON = 'before_daily_cron';

    public const HOOK_AFTER_DAILY_CRON = 'after_daily_cron';

    public const HOOK_AFTER_FIVE_MINUTE_CRON = 'after_five_minute_cron';

    public const HOOK_AFTER_HALF_HOUR_MINUTE_CRON = 'after_half_hour_minute_cron';

    public const HOOK_TASK_BEFORE = 'task.before';

    public const HOOK_TASK_AFTER = 'task.after';

    public const HOOK_TASK_FAILED = 'task.failed';

    public const HOOK_EVERY_MINUTE = 'tick.every_minute';

    public const HOOK_EVERY_FIVE_MINUTES = 'tick.every_five_minutes';

    public const HOOK_HOURLY = 'tick.hourly';

    public const HOOK_DAILY = 'tick.daily';

    public function __construct(
        private Container $container,
    ) {}

    public function hasListeners(string $hook): bool
    {
        return $this->listenersFor($hook) !== [];
    }

    public function run(string $hook, array $context = []): array
    {
        $results = [];

        foreach ($this->listenersFor($hook) as $listener) {
            $listenerName = $this->describeListener($listener);

            try {
                $results[] = [
                    'listener' => $listenerName,
                    'status' => 'success',
                    'result' => $this->normalizeResult($this->invokeListener($listener, $hook, $context)),
                ];
            } catch (Throwable $exception) {
                Log::warning('[调度Hook] 执行失败', [
                    'hook' => $hook,
                    'listener' => $listenerName,
                    'task_key' => $context['task_key'] ?? null,
                    'task_name' => $context['task_name'] ?? null,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);

                $results[] = [
                    'listener' => $listenerName,
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function runMany(array $hooks, array $context = []): array
    {
        $results = [];

        foreach (array_values(array_unique(array_filter($hooks))) as $hook) {
            $results[$hook] = $this->run((string) $hook, $context);
        }

        return $results;
    }

    private function listenersFor(string $hook): array
    {
        // 系统配置也支持单个 [ClassName::class, 'method'] 描述器；不能直接
        // array_merge，否则描述器会被拆成两个独立字符串监听器。
        $listeners = ScheduleDeclarationNormalizer::list($this->configuredListenersFor($hook));
        $pluginListeners = $this->pluginListenersFor($hook);

        return array_values(array_filter(array_merge(
            $listeners,
            $pluginListeners,
        )));
    }

    /**
     * Hook 名允许使用点号（例如 task.before），不能直接拼到 config() 的路径中，
     * 否则会被 Laravel 当作嵌套键而读不到 config/schedule_hooks.php 中的字面量键。
     * 同时兼容运行时用 config()->set() 写入嵌套路径的测试和诊断场景。
     *
     * @return array<mixed>
     */
    private function configuredListenersFor(string $hook): array
    {
        $configured = config('schedule_hooks.listeners', []);
        if (! is_array($configured)) {
            return [];
        }

        $literalListeners = $configured[$hook] ?? [];
        if (is_array($literalListeners) && $literalListeners !== []) {
            return $literalListeners;
        }

        $nestedListeners = config("schedule_hooks.listeners.{$hook}", []);
        if (is_array($nestedListeners) && $nestedListeners !== []) {
            return $nestedListeners;
        }

        return is_array($literalListeners) ? $literalListeners : [];
    }

    private function pluginListenersFor(string $hook): array
    {
        try {
            if (! Schema::hasTable('integration_plugins')) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        try {
            $scanner = $this->container->make(PluginScanner::class);
            $fileLoader = $this->container->make(PluginFileLoader::class);
            $plugins = IntegrationPlugin::query()
                ->where('status', IntegrationPlugin::STATUS_ENABLED)
                ->orderBy('domain')
                ->orderBy('slug')
                ->get();
        } catch (Throwable $exception) {
            Log::warning('[调度Hook] 查询已启用插件失败，已跳过插件监听器', [
                'hook' => $hook,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return [];
        }

        $listeners = [];

        $plugins
            ->each(function (IntegrationPlugin $plugin) use ($hook, $scanner, $fileLoader, &$listeners): void {
                try {
                    $manifest = $scanner->find((string) $plugin->domain, (string) $plugin->slug);
                } catch (Throwable) {
                    return;
                }

                if ($manifest === null) {
                    return;
                }

                $hookMap = is_array($manifest->extra['schedule_hooks'] ?? null)
                    ? $manifest->extra['schedule_hooks']
                    : [];
                $definitions = $hookMap[$hook] ?? [];
                if ($definitions === []) {
                    return;
                }

                try {
                    $fileLoader->ensureLoaded($manifest);
                } catch (Throwable $exception) {
                    Log::warning('[调度Hook] 插件监听器文件加载失败', [
                        'domain' => $manifest->domain,
                        'slug' => $manifest->slug,
                        'hook' => $hook,
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);

                    return;
                }

                foreach (ScheduleDeclarationNormalizer::list($definitions) as $definition) {
                    $listeners[] = $definition;
                }
            });

        return $listeners;
    }

    private function invokeListener(mixed $listener, string $hook, array $context): mixed
    {
        if (is_string($listener)) {
            $instance = $this->container->make($listener);

            return $this->invokeInstance($instance, $hook, $context);
        }

        if (is_array($listener)) {
            if (isset($listener['class'])) {
                $instance = $this->container->make((string) $listener['class']);
                $method = (string) ($listener['method'] ?? 'handle');

                if (! is_callable([$instance, $method])) {
                    throw new InvalidArgumentException("调度Hook监听器缺少方法：{$method}");
                }

                return $instance->{$method}($hook, $context);
            }

            if (count($listener) === 2 && is_string($listener[0] ?? null) && is_string($listener[1] ?? null)) {
                $instance = $this->container->make($listener[0]);
                $method = $listener[1];

                if (! is_callable([$instance, $method])) {
                    throw new InvalidArgumentException("调度Hook监听器缺少方法：{$method}");
                }

                return $instance->{$method}($hook, $context);
            }

            if (is_callable($listener)) {
                return $listener($hook, $context);
            }
        }

        if (is_callable($listener)) {
            return $listener($hook, $context);
        }

        throw new InvalidArgumentException('调度Hook监听器配置无效');
    }

    private function invokeInstance(object $instance, string $hook, array $context): mixed
    {
        if ($instance instanceof ScheduleHook) {
            return $instance->handle($hook, $context);
        }

        if (is_callable($instance)) {
            return $instance($hook, $context);
        }

        if (is_callable([$instance, 'handle'])) {
            return $instance->handle($hook, $context);
        }

        throw new InvalidArgumentException('调度Hook监听器必须实现 handle() 或 __invoke()');
    }

    private function describeListener(mixed $listener): string
    {
        if (is_string($listener)) {
            return $listener;
        }

        if (is_array($listener)) {
            if (isset($listener['class'])) {
                return (string) $listener['class'].'@'.(string) ($listener['method'] ?? 'handle');
            }

            if (isset($listener[0], $listener[1]) && is_string($listener[0]) && is_string($listener[1])) {
                return $listener[0].'@'.$listener[1];
            }

            return 'array-listener';
        }

        return is_object($listener) ? $listener::class : gettype($listener);
    }

    private function normalizeResult(mixed $result): mixed
    {
        if ($result === null || is_scalar($result)) {
            return $result;
        }

        if (is_array($result)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeResult($item), $result);
        }

        if (is_object($result)) {
            return [
                'type' => 'object',
                'class' => $result::class,
            ];
        }

        return [
            'type' => gettype($result),
        ];
    }
}
