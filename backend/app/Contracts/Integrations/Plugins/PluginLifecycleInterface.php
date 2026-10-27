<?php

declare(strict_types=1);

namespace App\Contracts\Integrations\Plugins;

/**
 * 插件生命周期钩子（插件标准 v2）。
 *
 * 平台在安装、启用、停用、卸载与升级动作里调用对应钩子；五个方法都必须幂等，
 * 因为安装重试与升级重试会重复调用同一钩子。
 *
 * $context 至少包含：
 * - plugin_id：integration_plugins.id
 * - version：清单里的当前版本
 * - platform_version：平台版本
 *
 * 约定（见 docs/internal/designs/backend/plugin-standard-v2-2026-10-27.md）：
 * - uninstall() 默认只清插件配置，不删业务数据；要删必须另行提供独立命令。
 * - 升级顺序固定为「升级前迁移 → upgrade() → 升级后迁移 → 写回 version」。
 */
interface PluginLifecycleInterface
{
    /**
     * 首次安装：建表、写默认值。
     *
     * @param  array<string, mixed>  $context
     */
    public function install(array $context): void;

    /**
     * 每次启用。
     *
     * @param  array<string, mixed>  $context
     */
    public function enable(array $context): void;

    /**
     * 每次停用。停用不等于删除，业务数据必须保留。
     *
     * @param  array<string, mixed>  $context
     */
    public function disable(array $context): void;

    /**
     * 卸载：默认只清插件配置，不删业务表。
     *
     * @param  array<string, mixed>  $context
     */
    public function uninstall(array $context): void;

    /**
     * 版本升级。
     *
     * @param  array<string, mixed>  $context
     */
    public function upgrade(string $fromVersion, string $toVersion, array $context): void;
}
