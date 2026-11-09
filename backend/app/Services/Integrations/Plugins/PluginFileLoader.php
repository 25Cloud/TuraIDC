<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

class PluginFileLoader
{
    /**
     * @var array<string, bool>
     */
    private array $loaded = [];

    public function ensureLoaded(PluginManifest $manifest): void
    {
        if (isset($this->loaded[$manifest->basePath])) {
            return;
        }

        foreach (['lib', 'logic', 'controller'] as $directory) {
            $this->requirePhpFilesIn($manifest->basePath.DIRECTORY_SEPARATOR.$directory);
        }

        $vendorAutoload = $manifest->basePath.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
        if (is_file($vendorAutoload)) {
            require_once $vendorAutoload;
        }

        foreach (glob($manifest->basePath.DIRECTORY_SEPARATOR.'*.php') ?: [] as $pluginFile) {
            if (basename($pluginFile) === 'config.php') {
                continue;
            }

            require_once $pluginFile;
        }

        $this->requirePhpFilesIn($manifest->basePath.DIRECTORY_SEPARATOR.'src');

        $this->loaded[$manifest->basePath] = true;
    }

    /**
     * 按「被依赖的声明优先」装载目录下的 PHP 文件。
     *
     * 文件系统返回顺序不可依赖：NTFS 大体按文件名，ext4 走目录哈希，同一份插件代码
     * 在本地不报错、在 Linux 上随机 `Interface "..." not found`。所以这里先排一次序，
     * 再把声明了接口 / trait / enum 的文件整批提前：`implements`、`use` 与
     * `interface A extends B` 都在**声明期**就要求目标已存在。
     */
    private function requirePhpFilesIn(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        foreach ($files as $file) {
            if ($this->declaresLateBoundType($file)) {
                require_once $file;
            }
        }

        foreach ($files as $file) {
            require_once $file;
        }
    }

    /**
     * 文件是否声明了接口 / trait / enum（不排除它同时声明了类）。
     *
     * 误判只会让这个文件提前装载一次，`require_once` 天然去重，装载结果不变；
     * 因此用正则而不是 `token_get_all`，避免每个请求都做一次完整词法分析。
     */
    private function declaresLateBoundType(string $file): bool
    {
        $source = file_get_contents($file);

        if (! is_string($source) || $source === '') {
            return false;
        }

        return preg_match(
            '/^[ \t]*(?:(?:final|abstract|readonly)[ \t]+)*(?:interface|trait|enum)[ \t]+[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*/m',
            $source
        ) === 1;
    }
}
