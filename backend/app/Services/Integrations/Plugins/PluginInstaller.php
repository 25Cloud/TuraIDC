<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Exceptions\BusinessException;
use App\Models\IntegrationPlugin;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Providers\CoreScheduledTaskProvider;
use App\Services\Automation\Heartbeat\Providers\LegacyScheduleHookTaskProvider;
use App\Services\Automation\Heartbeat\ScheduleDeclarationNormalizer;
use App\Services\Automation\Heartbeat\ScheduledTaskValidator;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use App\Services\Integrations\Payments\PaymentGatewayRegistry;
use App\Services\Mail\MailDriverManager;
use App\Services\Sms\SmsDriverManager;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use App\Services\Verification\VerificationDriverManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PluginInstaller
{
    public function __construct(
        private readonly PluginScanner $scanner,
        private readonly PluginFileLoader $fileLoader,
        private readonly PluginConfigRepository $configRepository,
        private readonly PluginProviderRegistry $providerRegistry,
        private readonly ScheduledTaskValidator $scheduledTaskValidator,
        private readonly Container $container,
    ) {}

    public function install(string $domain, string $slug): IntegrationPlugin
    {
        $manifest = $this->scanner->requireManifest($domain, $slug);
        $this->assertManifestExecutable($manifest);

        $plugin = DB::transaction(function () use ($manifest): IntegrationPlugin {
            return IntegrationPlugin::query()->updateOrCreate(
                [
                    'domain' => $manifest->domain,
                    'slug' => $manifest->slug,
                ],
                [
                    'plugin_key' => $manifest->key,
                    'name' => $manifest->name,
                    'version' => $manifest->version,
                    'provider_class' => $manifest->providerClass,
                    'entry_class' => $manifest->entryClass,
                    'capabilities_json' => $manifest->capabilities,
                    'config_schema_json' => $manifest->configSchema,
                    'manifest_hash' => $this->scanner->manifestContentHash($manifest->domain, $manifest->slug),
                    'installed_at' => now(),
                ]
            );
        });

        $this->forgetDomainRuntime($manifest->domain);

        return $plugin;
    }

    public function enable(IntegrationPlugin $plugin): IntegrationPlugin
    {
        $manifest = $this->scanner->requireManifest((string) $plugin->domain, (string) $plugin->slug);
        $this->assertManifestExecutable($manifest);

        DB::transaction(function () use ($plugin, $manifest): void {
            // 任务 key 是全局唯一约束；在同一事务内锁住插件注册行并再次校验，
            // 避免两个并发启用请求同时通过预检后留下重复任务。
            $this->assertScheduledTaskInstancesValid($manifest, true);
            $this->assertSingleEnabledDomainAvailable($plugin);
            $this->configRepository->assertConfigReady($plugin, $manifest);

            $plugin->forceFill([
                'status' => IntegrationPlugin::STATUS_ENABLED,
            ])->save();

            $this->syncDriverBinding($plugin, $manifest, true);
        });

        $this->forgetDomainRuntime($manifest->domain);
        try {
            $this->providerRegistry->activate($manifest);
        } catch (\Throwable $exception) {
            // 状态提交后 Provider 才激活；激活失败必须回写 disabled，避免出现“已启用但运行时不可用”的半状态。
            Log::error('[插件] Provider 激活失败，准备回滚启用状态', [
                'domain' => $manifest->domain,
                'slug' => $manifest->slug,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            try {
                DB::transaction(function () use ($plugin, $manifest): void {
                    $plugin->forceFill([
                        'status' => IntegrationPlugin::STATUS_DISABLED,
                    ])->save();
                    $this->syncDriverBinding($plugin, $manifest, false);
                });
            } catch (\Throwable $rollbackException) {
                Log::critical('[插件] Provider 激活失败且状态回滚失败', [
                    'domain' => $manifest->domain,
                    'slug' => $manifest->slug,
                    'activation_message' => $exception->getMessage(),
                    'activation_exception' => $exception::class,
                    'rollback_message' => $rollbackException->getMessage(),
                    'rollback_exception' => $rollbackException::class,
                ]);

                throw new BusinessException('插件启用失败，状态回滚未完成，请联系管理员处理', 42200);
            }

            throw new BusinessException('插件启用失败，Provider 初始化未完成，请检查系统日志', 42200);
        }

        return $plugin->fresh('config') ?? $plugin;
    }

    private function assertSingleEnabledDomainAvailable(IntegrationPlugin $plugin): void
    {
        $domain = (string) $plugin->domain;
        if (! PluginDomain::requiresSingleEnabledPlugin($domain)) {
            return;
        }

        $enabledPlugin = IntegrationPlugin::query()
            ->where('domain', $domain)
            ->where('status', IntegrationPlugin::STATUS_ENABLED)
            ->where('id', '<>', (int) $plugin->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->first(['id', 'name', 'slug']);

        if (! $enabledPlugin instanceof IntegrationPlugin) {
            return;
        }

        throw new BusinessException(self::singleEnabledDomainMessage($enabledPlugin), 42200);
    }

    /**
     * 单启用域的冲突提示。安装流程和管理端列表都要用同一句话，因此放这里共用。
     */
    public static function singleEnabledDomainMessage(IntegrationPlugin $enabledPlugin): string
    {
        $pluginName = trim((string) $enabledPlugin->name) !== ''
            ? (string) $enabledPlugin->name
            : ((string) $enabledPlugin->slug ?: '其他插件');

        return "当前功能域已启用「{$pluginName}」，请先停用后再启用其他插件";
    }

    public function disable(IntegrationPlugin $plugin): IntegrationPlugin
    {
        $manifest = $this->scanner->find((string) $plugin->domain, (string) $plugin->slug);

        if (! $manifest instanceof PluginManifest) {
            DB::transaction(function () use ($plugin): void {
                $plugin->forceFill([
                    'status' => IntegrationPlugin::STATUS_DISABLED,
                ])->save();

                if (Schema::hasTable('integration_plugin_bindings')) {
                    DB::table('integration_plugin_bindings')
                        ->where('plugin_id', (int) $plugin->id)
                        ->update([
                            'status' => 0,
                            'updated_at' => now(),
                        ]);
                }
            });

            $this->forgetDomainRuntime((string) $plugin->domain);

            return $plugin->fresh('config') ?? $plugin;
        }

        DB::transaction(function () use ($plugin, $manifest): void {
            $plugin->forceFill([
                'status' => IntegrationPlugin::STATUS_DISABLED,
            ])->save();

            $this->syncDriverBinding($plugin, $manifest, false);
        });

        $this->forgetDomainRuntime($manifest->domain);

        return $plugin->fresh('config') ?? $plugin;
    }

    private function assertManifestExecutable(PluginManifest $manifest): void
    {
        $this->fileLoader->ensureLoaded($manifest);

        // 插件声明的类必须位于插件目录内：manifest 可被污染，若指向任意已加载类，
        // 会在运行时被实例化/调用（entry、provider、任务、hook 监听器都是执行面）。
        $this->assertPluginClassInsideDirectory($manifest, $manifest->entryClass, '入口');
        if ($manifest->providerClass !== null && trim($manifest->providerClass) !== '') {
            $this->assertPluginClassInsideDirectory($manifest, $manifest->providerClass, '服务提供者');
        }

        if (! class_exists($manifest->entryClass)) {
            throw new BusinessException('插件入口类不存在', 42200);
        }

        if (! method_exists($manifest->entryClass, 'execute')) {
            throw new BusinessException('插件入口类缺少 execute 方法', 42200);
        }

        $this->assertScheduleDeclarationsValid($manifest);
    }

    /**
     * 插件声明的类必须位于插件目录内，防止 manifest 指向任意已加载类（越界执行面）。
     */
    private function assertPluginClassInsideDirectory(PluginManifest $manifest, string $class, string $what): void
    {
        if (! class_exists($class)) {
            throw new BusinessException("插件{$what}类不存在 [{$class}]", 42200);
        }

        $reflection = new \ReflectionClass($class);
        $classFile = $reflection->getFileName();
        if ($classFile === false || trim($classFile) === '') {
            return;  // 无文件（PHP 内部类等），放行
        }

        $basePath = $this->normalizePathForComparison(realpath($manifest->basePath) ?: $manifest->basePath);
        $filePath = $this->normalizePathForComparison(realpath($classFile) ?: $classFile);

        if ($basePath !== '' && ! str_starts_with($filePath, $basePath.'/')) {
            throw new BusinessException("插件{$what}类必须位于插件目录内 [{$class}]", 42200);
        }
    }

    private function normalizePathForComparison(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * 清单里声明的调度任务和 hook 只有到心跳运行时才会被实例化，坏声明的表现是
     * “任务静默不执行”，排查成本很高。安装和启用时先校验一遍，把问题提前暴露。
     */
    private function assertScheduleDeclarationsValid(PluginManifest $manifest): void
    {
        foreach (ScheduleDeclarationNormalizer::list($manifest->extra['scheduled_tasks'] ?? []) as $definition) {
            $class = ScheduleDeclarationNormalizer::className($definition);
            if ($class === '') {
                continue;
            }

            $this->assertPluginClassInsideDirectory($manifest, $class, '定时任务');

            if (! class_exists($class)) {
                throw new BusinessException("插件声明的定时任务类不存在 [{$class}]", 42200);
            }

            if (! is_subclass_of($class, ScheduledTask::class)) {
                throw new BusinessException("插件定时任务未实现 ScheduledTask 接口 [{$class}]", 42200);
            }
        }

        $this->assertScheduledTaskInstancesValid($manifest);

        foreach ((array) ($manifest->extra['schedule_hooks'] ?? []) as $listeners) {
            foreach (ScheduleDeclarationNormalizer::list($listeners) as $definition) {
                $class = ScheduleDeclarationNormalizer::className($definition);
                if ($class === '') {
                    if (is_callable($definition)) {
                        continue;
                    }

                    throw new BusinessException('插件调度 Hook 声明缺少 class', 42200);
                }

                $this->assertPluginClassInsideDirectory($manifest, $class, '调度 Hook 监听器');

                if (! class_exists($class)) {
                    throw new BusinessException("插件声明的调度 Hook 类不存在 [{$class}]", 42200);
                }

                $method = ScheduleDeclarationNormalizer::methodName($definition);
                $this->assertPublicHookMethod($class, $method ?: 'handle');
            }
        }
    }

    private function assertPublicHookMethod(string $class, string $method): void
    {
        if (! method_exists($class, $method)) {
            throw new BusinessException("插件调度 Hook 缺少 {$method} 方法 [{$class}]", 42200);
        }

        try {
            $reflection = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            throw new BusinessException("插件调度 Hook 方法不可读取 [{$class}@{$method}]", 42200);
        }

        if (! $reflection->isPublic()) {
            throw new BusinessException("插件调度 Hook 方法必须为 public [{$class}@{$method}]", 42200);
        }
    }

    /**
     * 实例化并校验任务契约，同时拒绝与系统任务冲突的 key。
     *
     * @throws BusinessException
     */
    private function assertScheduledTaskInstancesValid(PluginManifest $manifest, bool $lockPluginRows = false): void
    {
        $knownKeys = $this->knownTaskKeys($manifest, $lockPluginRows);

        foreach (ScheduleDeclarationNormalizer::list($manifest->extra['scheduled_tasks'] ?? []) as $definition) {
            $class = ScheduleDeclarationNormalizer::className($definition);
            if ($class === '') {
                throw new BusinessException('插件定时任务声明缺少 class', 42200);
            }

            try {
                $task = $this->container->make($class);
                if (! $task instanceof ScheduledTask) {
                    throw new BusinessException("插件定时任务未实现 ScheduledTask 接口 [{$class}]", 42200);
                }

                $this->scheduledTaskValidator->validate($task, "插件 {$manifest->domain}/{$manifest->slug}");
                $key = trim($task->key());
                if (isset($knownKeys[$key])) {
                    throw new BusinessException("插件定时任务 key 冲突：{$key}", 42200);
                }

                $knownKeys[$key] = true;
            } catch (BusinessException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw new BusinessException("插件定时任务实例化失败 [{$class}]：{$exception->getMessage()}", 42200);
            }
        }
    }

    /** @return array<string, bool> */
    private function knownTaskKeys(PluginManifest $candidate, bool $lockPluginRows = false): array
    {
        $keys = [];

        foreach ([CoreScheduledTaskProvider::class, LegacyScheduleHookTaskProvider::class] as $providerClass) {
            try {
                $provider = $this->container->make($providerClass);
                foreach ($provider->tasks() as $task) {
                    if (trim($task->key()) !== '') {
                        $keys[trim($task->key())] = true;
                    }
                }
            } catch (\Throwable $exception) {
                if ($lockPluginRows) {
                    // 启用阶段必须能确认系统任务 key；否则不能把“注册成功”建立在
                    // 一个不完整的冲突集合上，待系统任务恢复后才发现插件任务被跳过。
                    Log::error('[插件] 系统定时任务 key 校验失败，已拒绝启用插件', [
                        'provider' => $providerClass,
                        'domain' => $candidate->domain,
                        'slug' => $candidate->slug,
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);

                    throw new BusinessException('系统定时任务校验不可用，无法启用插件', 42200);
                }

                // 这里仅用于预检；正常注册链路会记录 Provider 本身的故障。
            }
        }

        try {
            if (! Schema::hasTable('integration_plugins')) {
                return $keys;
            }

            $query = IntegrationPlugin::query();
            if (! $lockPluginRows) {
                $query
                    ->where('status', IntegrationPlugin::STATUS_ENABLED)
                    ->where(function ($nested) use ($candidate): void {
                        $nested
                            ->where('domain', '<>', $candidate->domain)
                            ->orWhere('slug', '<>', $candidate->slug);
                    });
            } else {
                // 锁住所有注册行（包括当前候选插件），使跨 domain 的全局 task key 校验串行化。
                $query
                    ->orderBy('id')
                    ->lockForUpdate();
            }

            $plugins = $query->get(['domain', 'slug', 'status']);

            foreach ($plugins as $plugin) {
                if ((string) $plugin->domain === (string) $candidate->domain
                    && (string) $plugin->slug === (string) $candidate->slug) {
                    continue;
                }

                if ((int) $plugin->status !== IntegrationPlugin::STATUS_ENABLED) {
                    continue;
                }

                try {
                    $manifest = $this->scanner->find((string) $plugin->domain, (string) $plugin->slug);
                    if (! $manifest instanceof PluginManifest) {
                        continue;
                    }
                    $this->fileLoader->ensureLoaded($manifest);

                    foreach (ScheduleDeclarationNormalizer::list($manifest->extra['scheduled_tasks'] ?? []) as $definition) {
                        $class = ScheduleDeclarationNormalizer::className($definition);
                        if ($class === '') {
                            continue;
                        }
                        $task = $this->container->make($class);
                        if ($task instanceof ScheduledTask && trim($task->key()) !== '') {
                            $keys[trim($task->key())] = true;
                        }
                    }
                } catch (\Throwable) {
                    // 坏的既有插件由运行时 Provider 隔离，不应阻止当前插件完成独立预检。
                }
            }
        } catch (BusinessException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($lockPluginRows) {
                // 启用阶段的串行化锁不可用时必须拒绝启用，不能退化为无保护预检。
                Log::error('[插件] 定时任务 key 校验锁不可用，已拒绝启用插件', [
                    'domain' => $candidate->domain,
                    'slug' => $candidate->slug,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);

                throw new BusinessException('插件定时任务 key 校验不可用，无法启用插件', 42200);
            }

            // 数据表不可用时保留核心 key 校验；启用事务本身会报告数据库故障。
        }

        return $keys;
    }

    /**
     * 兼容 `Foo::class`、`[Foo::class, 'handle']` 和 `['class' => Foo::class, 'method' => 'handle']`。
     */
    private function syncDriverBinding(IntegrationPlugin $plugin, PluginManifest $manifest, bool $enabled): void
    {
        if (! Schema::hasTable('integration_plugin_bindings')) {
            return;
        }

        $binding = is_array($manifest->extra['driver_binding'] ?? null) ? $manifest->extra['driver_binding'] : [];
        $bindingKey = trim((string) ($binding['binding_key'] ?? ''));
        if ($bindingKey === '') {
            return;
        }

        $identity = [
            'domain' => $manifest->domain,
            'binding_type' => trim((string) ($binding['binding_type'] ?? 'global')) ?: 'global',
            'bindable_type' => trim((string) ($binding['bindable_type'] ?? 'setting')) ?: 'setting',
            'bindable_id' => (int) ($binding['bindable_id'] ?? 0),
            'binding_key' => $bindingKey,
        ];

        $payload = [
            'plugin_id' => (int) $plugin->id,
            'provider_key' => trim((string) ($binding['provider_key'] ?? '')) ?: $manifest->key,
            'priority' => (int) ($binding['priority'] ?? 0),
            'status' => $enabled ? 1 : 0,
            'updated_at' => now(),
        ];

        $query = DB::table('integration_plugin_bindings')->where($identity);
        if ($query->exists()) {
            $query->update($payload);

            return;
        }

        DB::table('integration_plugin_bindings')->insert(array_merge($identity, $payload, [
            'created_at' => now(),
        ]));
    }

    private function forgetDomainRuntime(string $domain): void
    {
        match ($domain) {
            PluginDomain::PAYMENT => $this->forgetInstances(PaymentGatewayRegistry::class, PaymentGatewayManager::class),
            PluginDomain::VERIFICATION => $this->forgetInstances(VerificationDriverManager::class),
            PluginDomain::MAIL => $this->forgetInstances(MailDriverManager::class),
            PluginDomain::SMS => $this->forgetInstances(SmsDriverManager::class),
            PluginDomain::UPSTREAM => $this->forgetInstances(ProviderRegistry::class, ProviderResolver::class),
            default => null,
        };
    }

    private function forgetInstances(string ...$classes): void
    {
        foreach ($classes as $class) {
            $this->container->forgetInstance($class);
        }
    }
}
