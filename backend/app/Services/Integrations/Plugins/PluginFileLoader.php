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

    private function requirePhpFilesIn(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                require_once $file->getPathname();
            }
        }
    }
}
