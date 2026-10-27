<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Contracts\Integrations\Plugins\PluginLifecycleInterface;
use App\Exceptions\BusinessException;
use Illuminate\Contracts\Container\Container;

/**
 * 插件生命周期调用器（插件标准 v2 的平台侧入口）。
 *
 * 为什么单独一个类、而不是直接塞进 PluginInstaller：
 * - 现有 v1 插件没有 lifecycle，调用器返回 null 时是纯 no-op，不会改变既有安装行为；
 * - 清单里的 v2 字段（lifecycle / manifest_version）当前由 PluginScanner 透传前会被丢弃，
 *   这里直接读一次清单文件取 `info.lifecycle`，避免为了一个可选字段改动所有插件共用的
 *   PluginManifest 构造函数与安装路径。
 *
 * 幂等由插件实现负责，调用器只负责“类存在、在插件目录内、实现了接口”三道校验，
 * 校验不通过直接抛 BusinessException，让安装/升级失败可见，而不是静默跳过。
 */
final class PluginLifecycleRunner
{
    /** @var array<string, PluginLifecycleInterface|null> */
    private array $instances = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function install(PluginManifest $manifest, array $context = []): void
    {
        $lifecycle = $this->resolve($manifest);
        if ($lifecycle === null) {
            return;
        }

        $lifecycle->install($this->withVersion($manifest, $context));
    }

    public function enable(PluginManifest $manifest, array $context = []): void
    {
        $lifecycle = $this->resolve($manifest);
        if ($lifecycle === null) {
            return;
        }

        $lifecycle->enable($this->withVersion($manifest, $context));
    }

    public function disable(PluginManifest $manifest, array $context = []): void
    {
        $lifecycle = $this->resolve($manifest);
        if ($lifecycle === null) {
            return;
        }

        $lifecycle->disable($this->withVersion($manifest, $context));
    }

    public function uninstall(PluginManifest $manifest, array $context = []): void
    {
        $lifecycle = $this->resolve($manifest);
        if ($lifecycle === null) {
            return;
        }

        $lifecycle->uninstall($this->withVersion($manifest, $context));
    }

    /**
     * 升级钩子：按插件标准 v2 的顺序，迁移由调用方负责，这里只跑 upgrade()。
     */
    public function upgrade(PluginManifest $manifest, string $fromVersion, string $toVersion, array $context = []): void
    {
        $lifecycle = $this->resolve($manifest);
        if ($lifecycle === null) {
            return;
        }

        $lifecycle->upgrade($fromVersion, $toVersion, $this->withVersion($manifest, $context));
    }

    /**
     * 解析清单里声明的生命周期类；没有声明（v1 插件）时返回 null。
     */
    public function resolve(PluginManifest $manifest): ?PluginLifecycleInterface
    {
        $cacheKey = $manifest->domain . ':' . $manifest->slug;
        if (array_key_exists($cacheKey, $this->instances)) {
            return $this->instances[$cacheKey];
        }

        $className = $this->lifecycleClass($manifest);
        if ($className === null) {
            return $this->instances[$cacheKey] = null;
        }

        if (! is_subclass_of($className, PluginLifecycleInterface::class)) {
            throw new BusinessException(
                "插件生命周期类必须实现 PluginLifecycleInterface [{$className}]",
                42200
            );
        }

        /** @var PluginLifecycleInterface $instance */
        $instance = $this->container->make($className);

        return $this->instances[$cacheKey] = $instance;
    }

    /**
     * 只认清单文件里的 `info.lifecycle`；文件不可读或未声明时返回 null。
     */
    private function lifecycleClass(PluginManifest $manifest): ?string
    {
        $configPath = $manifest->configPath();
        if (! is_file($configPath)) {
            return null;
        }

        $payload = require $configPath;
        if (! is_array($payload)) {
            return null;
        }

        $info = is_array($payload['info'] ?? null) ? $payload['info'] : [];
        $className = trim((string) ($info['lifecycle'] ?? $payload['lifecycle'] ?? ''));

        return $className !== '' ? $className : null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withVersion(PluginManifest $manifest, array $context): array
    {
        return array_merge([
            'version' => $manifest->version,
            'domain' => $manifest->domain,
            'slug' => $manifest->slug,
            'platform_version' => (string) (app()->version() ?: ''),
        ], $context);
    }
}
