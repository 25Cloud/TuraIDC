<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginManifest;
use PHPUnit\Framework\TestCase;
use TuraProbeOrderTrait\AaGreeter;

/**
 * 插件目录里的 PHP 文件由核心 PluginFileLoader 逐个 require，装载顺序不能依赖文件系统。
 *
 * 背景：本地 NTFS 的目录返回顺序差不多按文件名（接口恰好排在实现类前面），Linux 上是
 * 目录哈希序，于是同一份插件代码在 CI 上随机 `Interface "..." not found`——PHP 在
 * **声明期**就要解析 implements / use。这里用「实现类文件名排在接口前面」的目录复现该顺序。
 */
class PluginFileLoaderDeclarationOrderTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'plugin-loader-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_implementation_sorted_before_its_interface_still_loads(): void
    {
        $this->writeFiles([
            'lib/AaConsumer.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace TuraProbeOrderInterface;

                final class AaConsumer implements ZzContract
                {
                }
                PHP,
            'lib/ZzContract.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace TuraProbeOrderInterface;

                interface ZzContract
                {
                }
                PHP,
        ]);

        $this->loadPlugin();

        // 第二个参数 false：不触发自动加载，证明这两个声明确实是被本加载器 require 进来的。
        $this->assertTrue(interface_exists('TuraProbeOrderInterface\ZzContract', false));
        $this->assertTrue(class_exists('TuraProbeOrderInterface\AaConsumer', false));
    }

    public function test_class_sorted_before_its_trait_still_loads(): void
    {
        $this->writeFiles([
            'logic/AaGreeter.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace TuraProbeOrderTrait;

                final class AaGreeter
                {
                    use ZzSaysHello;
                }
                PHP,
            'logic/ZzSaysHello.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace TuraProbeOrderTrait;

                trait ZzSaysHello
                {
                    public function hello(): string
                    {
                        return 'hello';
                    }
                }
                PHP,
        ]);

        $this->loadPlugin();

        $this->assertTrue(trait_exists('TuraProbeOrderTrait\ZzSaysHello', false));
        $this->assertTrue(class_exists('TuraProbeOrderTrait\AaGreeter', false));
        $this->assertSame('hello', (new AaGreeter)->hello());
    }

    public function test_plugin_root_files_are_loaded_after_subdirectories(): void
    {
        $this->writeFiles([
            'lib/AaPayload.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace TuraProbeOrderRoot;

                final class AaPayload
                {
                }
                PHP,
            'ZzPlugin.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace TuraProbeOrderRoot;

                final class ZzPlugin
                {
                }
                PHP,
        ]);

        $this->loadPlugin();

        $this->assertTrue(class_exists('TuraProbeOrderRoot\AaPayload', false));
        $this->assertTrue(class_exists('TuraProbeOrderRoot\ZzPlugin', false));
    }

    /**
     * @param  array<string, string>  $files
     */
    private function writeFiles(array $files): void
    {
        foreach ($files as $relativePath => $contents) {
            $path = $this->basePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $directory = dirname($path);

            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($path, $contents.PHP_EOL);
        }

        // config.php 由加载器跳过，放一个能证明「跳过」的文件。
        file_put_contents($this->basePath.DIRECTORY_SEPARATOR.'config.php', "<?php\n\nreturn [];\n");
    }

    private function loadPlugin(): void
    {
        $manifest = new PluginManifest(
            domain: 'servers',
            slug: 'probe',
            key: 'probe',
            name: '探针插件',
            version: '1.0.0',
            entryClass: 'TuraProbeOrder\Probe',
            providerClass: null,
            capabilities: [],
            configSchema: [],
            basePath: $this->basePath,
        );

        (new PluginFileLoader)->ensureLoaded($manifest);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($path);
    }
}
