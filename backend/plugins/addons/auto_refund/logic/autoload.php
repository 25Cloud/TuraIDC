<?php

declare(strict_types=1);

/**
 * 插件 PSR-4 自注册（必须在 src/ 之前生效）。
 *
 * 核心 PluginFileLoader 按「lib → logic → controller → 根目录 → src」的顺序
 * require_once 全部 PHP 文件。本插件有 Model / Service / Controller / Request 多层类，
 * 一旦子类先于父类被 require，PHP 会在声明阶段直接 fatal（且报错位置与真实原因无关）。
 * 这里只注册本插件自己的命名空间前缀，让父类与接口按需加载。
 *
 * 只接管 `TuraIDC\Plugins\Addons\AutoRefund\`，不注册任何其它命名空间；
 * 本文件不主动加载业务类。
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'TuraIDC\\Plugins\\Addons\\AutoRefund\\';
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
