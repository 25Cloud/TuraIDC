<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\PluginMarketService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

/**
 * 插件市场 monorepo 条目（`repo` + `path` + `ref`）的回归。
 *
 * 背景：索引规范里写着一插件随主仓发布就用 `repo + path + ref`，但安装器此前只读
 * `repo` / `tag` / `sha`，这类条目执行 `plugin:market:install` 直接报「缺少 tag/sha」。
 * 更要紧的是下一层：monorepo 存档里**每个插件都有自己的 `config.php`，而且深度相同**，
 * 原来的「取深度最小的 config.php」会随手挑中另一个插件 —— 用 A 插件的代码装成 B 插件。
 * 所以这里锁三件事：
 *   1. 索引条目的 `path` / `ref` 会被保留（不被 normalize 丢掉）；
 *   2. `{ref}` 解析优先级 sha → tag → ref，都没有就报可读错误；
 *   3. 带 `path` 时按 path 精确落位，且拒绝 `..` 之类的越界路径。
 */
class PluginMarketMonorepoEntryTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'plugin-market-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            File::deleteDirectory($this->tmp);
        }

        Cache::flush();

        parent::tearDown();
    }

    public function test_index_entries_keep_path_and_ref(): void
    {
        $this->fakeIndex([
            [
                'slug' => 'ai_assistant',
                'domain' => 'addons',
                'name' => 'AI 助手',
                'repo' => '25Cloud/TuraIDC',
                'path' => 'backend/plugins/addons/ai_assistant',
                'ref' => 'main',
            ],
        ]);

        $entry = app(PluginMarketService::class)->findEntry('ai_assistant');

        $this->assertSame('backend/plugins/addons/ai_assistant', $entry['path']);
        $this->assertSame('main', $entry['ref']);
    }

    public function test_archive_ref_prefers_sha_then_tag_then_ref(): void
    {
        $service = app(PluginMarketService::class);

        $this->assertSame('deadsbeef', $this->archiveRef($service, [
            'sha' => 'deadsbeef',
            'tag' => 'v1.0.0',
            'ref' => 'main',
        ]));
        $this->assertSame('refs/tags/v1.0.0', $this->archiveRef($service, [
            'tag' => 'v1.0.0',
            'ref' => 'main',
        ]));
        // monorepo 的裸分支名要转成 refs/heads，GitHub archive 才认
        $this->assertSame('refs/heads/main', $this->archiveRef($service, ['ref' => 'main']));
        // 条目自己写全 refs/ 时原样保留
        $this->assertSame('refs/heads/release/2026', $this->archiveRef($service, ['ref' => 'refs/heads/release/2026']));

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('缺少 tag/sha/ref');
        $this->archiveRef($service, ['repo' => 'a/b']);
    }

    public function test_monorepo_entry_resolves_the_declared_subdirectory_not_another_plugin(): void
    {
        // 一个 monorepo 存档：两个插件，config.php 深度完全相同
        $top = $this->tmp.DIRECTORY_SEPARATOR.'TuraIDC-main';
        foreach (['ai_assistant', 'demo_style'] as $slug) {
            $dir = $top.'/backend/plugins/addons/'.$slug;
            File::ensureDirectoryExists($dir);
            File::put($dir.'/config.php', "<?php return ['info' => ['slug' => '{$slug}']];");
        }

        $root = $this->locate($service = app(PluginMarketService::class), $this->tmp, 'backend/plugins/addons/ai_assistant');

        $expected = $top.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, 'backend/plugins/addons/ai_assistant');
        $this->assertSame($expected, $root);
        $manifest = require $root.'/config.php';
        $this->assertSame('ai_assistant', $manifest['info']['slug']);
    }

    public function test_monorepo_entry_rejects_paths_outside_the_archive(): void
    {
        $top = $this->tmp.DIRECTORY_SEPARATOR.'repo-main';
        File::ensureDirectoryExists($top.'/backend');
        File::put($top.'/backend/config.php', "<?php return ['info' => []];");

        $service = app(PluginMarketService::class);

        foreach (['../../etc', '/etc', 'backend/../../etc', 'backend//plugins'] as $bad) {
            try {
                $this->locate($service, $this->tmp, $bad);
                $this->fail('越界 path 应当被拒绝：'.$bad);
            } catch (BusinessException $exception) {
                $this->assertStringContainsString('path', (string) $exception->getMessage());
            }
        }
    }

    public function test_monorepo_entry_reports_a_missing_subdirectory(): void
    {
        File::ensureDirectoryExists($this->tmp.'/repo-main/backend/plugins/addons/other');
        File::put($this->tmp.'/repo-main/backend/plugins/addons/other/config.php', "<?php return ['info' => []];");

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('不存在条目声明的 path');

        $this->locate(app(PluginMarketService::class), $this->tmp, 'backend/plugins/addons/ai_assistant');
    }

    public function test_independent_repo_entry_still_resolves_without_path(): void
    {
        $top = $this->tmp.DIRECTORY_SEPARATOR.'turaidc-plugin-ai-assistant-abc123';
        File::ensureDirectoryExists($top);
        File::put($top.'/config.php', "<?php return ['info' => ['slug' => 'ai_assistant']];");

        $root = $this->locate(app(PluginMarketService::class), $this->tmp, '');

        $this->assertSame($top, $root);
    }

    /**
     * @param  list<array<string, mixed>>  $plugins
     */
    private function fakeIndex(array $plugins): void
    {
        Http::fake([
            '*' => Http::response([
                'schema' => 1,
                'schema_version' => 2,
                'updated_at' => '2026-10-29T00:00:00+08:00',
                'plugins' => $plugins,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function archiveRef(PluginMarketService $service, array $entry): string
    {
        $method = (new ReflectionClass($service))->getMethod('archiveRef');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $entry, 'my_plugin');
    }

    private function locate(PluginMarketService $service, string $extractDir, string $path): string
    {
        $method = (new ReflectionClass($service))->getMethod('locatePluginRoot');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $extractDir, $path);
    }
}
