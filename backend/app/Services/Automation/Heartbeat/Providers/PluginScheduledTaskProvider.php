<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat\Providers;

use App\Models\IntegrationPlugin;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTaskProvider;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PluginScheduledTaskProvider implements ScheduledTaskProvider
{
    public function __construct(
        private Container $container,
        private PluginScanner $scanner,
        private PluginFileLoader $fileLoader,
    ) {}

    public function tasks(): array
    {
        if (! Schema::hasTable('integration_plugins')) {
            return [];
        }

        $tasks = [];

        IntegrationPlugin::query()
            ->where('status', IntegrationPlugin::STATUS_ENABLED)
            ->orderBy('domain')
            ->orderBy('slug')
            ->get()
            ->each(function (IntegrationPlugin $plugin) use (&$tasks): void {
                try {
                    $manifest = $this->scanner->find((string) $plugin->domain, (string) $plugin->slug);
                } catch (\Throwable) {
                    return;
                }

                if ($manifest === null) {
                    return;
                }

                $scheduledTasks = (array) ($manifest->extra['scheduled_tasks'] ?? []);
                if ($scheduledTasks === []) {
                    return;
                }

                try {
                    $this->fileLoader->ensureLoaded($manifest);
                } catch (\Throwable $exception) {
                    Log::warning('[定时任务] 插件任务文件加载失败', [
                        'domain' => $manifest->domain,
                        'slug' => $manifest->slug,
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);

                    return;
                }

                foreach ($scheduledTasks as $definition) {
                    $class = is_array($definition)
                        ? trim((string) ($definition['class'] ?? ''))
                        : trim((string) $definition);

                    if ($class === '') {
                        continue;
                    }

                    try {
                        $task = $this->container->make($class);
                        if (! $task instanceof ScheduledTask) {
                            Log::warning('[定时任务] 插件任务未实现 ScheduledTask 接口', [
                                'domain' => $manifest->domain,
                                'slug' => $manifest->slug,
                                'task_class' => $class,
                            ]);

                            continue;
                        }

                        $tasks[] = $task;
                    } catch (\Throwable $exception) {
                        Log::warning('[定时任务] 插件任务实例化失败', [
                            'domain' => $manifest->domain,
                            'slug' => $manifest->slug,
                            'task_class' => $class,
                            'message' => $exception->getMessage(),
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });

        return $tasks;
    }
}
