<?php

declare(strict_types=1);

/**
 * 插件 PSR-4 自注册（必须在 src/ 之前生效）。
 *
 * 核心 PluginFileLoader 是按目录顺序 require_once 全部 PHP 文件的，
 * 目录里的“子类先于父类/接口被 require”时，PHP 会在声明阶段直接 fatal
 * （Interface "..." not found）。本插件有 40+ 个类且存在
 * AiProviderInterface、AiTool 这类被多处 extends/implements 的声明，
 * 因此这里注册一个只覆盖本插件命名空间的 autoloader：
 * 之后无论 require 顺序如何，父类与接口都能被按需加载。
 *
 * 只注册自身前缀，不接管任何其它命名空间；文件由核心 PluginFileLoader
 * 统一 require，本文件不主动加载业务类。
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'TuraIDC\\Plugins\\Addons\\AiAssistant\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === false || $relative === '') {
        return;
    }

    $relativePath = str_replace('\\', '/', $relative).'.php';
    $basePath = dirname(__DIR__);

    foreach (['/src/', '/logic/'] as $segment) {
        $path = $basePath.$segment.$relativePath;
        if (is_file($path)) {
            require_once $path;

            return;
        }
    }
});
