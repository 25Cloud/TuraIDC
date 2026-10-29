<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PluginMakeCommand;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

/**
 * 插件骨架生成（`plugin:make`）的回归。
 *
 * 这是「模板契约」的锚点：文档承诺「生成的骨架立即可用、可被安装启用」，而脚手架模板
 * （`backend/stubs/plugin/*.stub`）与独立模板仓库是同源的两份产物，改一处就必须保证
 * 另一处不会漂移。此前没有任何用例覆盖它。
 *
 * 锁三件事：
 *   1. 生成的文件齐全（清单 / 入口 / README / 可选 Provider 与定时任务）；
 *   2. 生成的 `config.php` 能被 require 出来、必填字段齐全，且 `--task` 真的把任务类
 *      写进 `extra.scheduled_tasks`（插件周期能力只能走清单，不能靠核心 schedule）；
 *   3. README 不留 `{{...}}` 占位符——模板里新增段落时最容易漏的正是这一处。
 */
class PluginMakeCommandTest extends TestCase
{
    private Filesystem $files;

    private string $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        // demo_ 前缀：插件中心不展示、不允许通过管理端安装，适合做一次性校验产物
        $this->target = base_path('plugins/addons/demo_tpl_check');
        $this->files->deleteDirectory($this->target);
    }

    protected function tearDown(): void
    {
        // 必须清理：残留目录会污染后续用例的插件扫描结果
        $this->files->deleteDirectory($this->target);

        parent::tearDown();
    }

    public function test_it_generates_a_complete_plugin_skeleton_with_a_manifest_declared_task(): void
    {
        $this->artisan('plugin:make', [
            'domain' => 'addons',
            'slug' => 'demo_tpl_check',
            '--name' => '模板校验',
            '--provider' => true,
            '--task' => true,
            '--force' => true,
        ])->assertSuccessful();

        foreach ([
            'config.php',
            'DemoTplCheckPlugin.php',
            'README.md',
            'lib/DemoTplCheckScheduledTask.php',
            'src/Providers/DemoTplCheckServiceProvider.php',
        ] as $relative) {
            $this->assertFileExists($this->target.'/'.$relative, '骨架缺少文件：'.$relative);
        }

        $manifest = require $this->target.'/config.php';

        $this->assertSame('addons', $manifest['info']['domain']);
        $this->assertSame('demo_tpl_check', $manifest['info']['slug']);
        $this->assertSame('demo_tpl_check', $manifest['info']['key']);
        $this->assertSame('模板校验', $manifest['info']['name']);
        $this->assertNotEmpty($manifest['info']['entry']);
        $this->assertArrayHasKey('config', $manifest);

        // --task 必须落到清单里：核心不得 schedule 插件命令
        $tasks = $manifest['info']['extra']['scheduled_tasks'] ?? [];
        $this->assertCount(1, $tasks);
        $this->assertStringEndsWith('Lib\DemoTplCheckScheduledTask', (string) $tasks[0]);

        // --provider 同样写进清单
        $this->assertStringEndsWith('Providers\DemoTplCheckServiceProvider', (string) $manifest['info']['provider']);
    }

    public function test_generated_readme_has_no_unresolved_placeholders(): void
    {
        $this->artisan('plugin:make', [
            'domain' => 'addons',
            'slug' => 'demo_tpl_check',
            '--name' => '模板校验',
            '--force' => true,
        ])->assertSuccessful();

        $readme = (string) $this->files->get($this->target.'/README.md');

        $this->assertStringNotContainsString('{{', $readme, 'README 模板还有未替换的占位符');
        $this->assertStringContainsString('# 模板校验', $readme);
        // 模板必须把「周期能力只能走清单」这条边界带下去，否则新插件会重犯同一类错误
        $this->assertStringContainsString('scheduled_tasks', $readme);
    }

    public function test_it_rejects_an_invalid_slug(): void
    {
        $this->artisan('plugin:make', [
            'domain' => 'addons',
            'slug' => 'Not-Snake-Case',
        ])->assertFailed();

        $this->assertDirectoryDoesNotExist($this->target);
    }

    public function test_the_command_is_registered(): void
    {
        $this->assertArrayHasKey('plugin:make', \Illuminate\Support\Facades\Artisan::all());
        $this->assertInstanceOf(PluginMakeCommand::class, \Illuminate\Support\Facades\Artisan::all()['plugin:make']);
    }
}
