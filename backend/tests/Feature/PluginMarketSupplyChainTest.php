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
 * 插件市场供应链边界：来源、清单解析和包摘要都必须在执行插件代码前完成。
 */
class PluginMarketSupplyChainTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'plugins.market.trusted_hosts' => ['raw.githubusercontent.com', 'github.com', 'codeload.github.com'],
            'plugins.market.trusted_repositories' => ['25Cloud/example-plugin', '25Cloud/approved-plugin'],
            'plugins.market.raw_mirror' => '',
            'plugins.market.download_mirror' => '',
        ]);
        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'plugin-market-supply-chain-'.bin2hex(random_bytes(4));
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

    public function test_default_mirrors_are_disabled(): void
    {
        $config = require base_path('config/plugins.php');

        $this->assertSame('', $config['market']['raw_mirror']);
        $this->assertSame('', $config['market']['download_mirror']);
        $this->assertContains('raw.githubusercontent.com', $config['market']['trusted_hosts']);
        $this->assertContains('github.com', $config['market']['trusted_hosts']);
        $this->assertContains('codeload.github.com', $config['market']['trusted_hosts']);
        $this->assertContains('25cloud/turaidc-plugin-ai-assistant', $config['market']['trusted_repositories']);
    }

    public function test_index_source_outside_allowlist_is_rejected_before_request(): void
    {
        Http::fake();
        config(['plugins.market.index_url' => 'https://evil.example/plugins.json']);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('不在可信源 allowlist 内');

        try {
            app(PluginMarketService::class)->fetchIndex(true);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_archive_repository_allowlist_is_enforced_before_request(): void
    {
        Http::fake();
        config(['plugins.market.trusted_repositories' => ['25Cloud/approved-plugin']]);

        $method = (new ReflectionClass(PluginMarketService::class))->getMethod('downloadArchive');
        $method->setAccessible(true);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('来源仓库不在可信源 allowlist 内');

        try {
            $method->invoke(app(PluginMarketService::class), [
                'repo' => 'evil/unapproved-plugin',
                'tag' => 'v1.0.0',
                'sha' => str_repeat('a', 40),
                'sha256' => str_repeat('a', 64),
            ], 'unsafe_plugin', $this->tmp);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_empty_repository_allowlist_fails_closed(): void
    {
        Http::fake();
        config(['plugins.market.trusted_repositories' => []]);

        $method = (new ReflectionClass(PluginMarketService::class))->getMethod('downloadArchive');
        $method->setAccessible(true);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('来源仓库不在可信源 allowlist 内');

        try {
            $method->invoke(app(PluginMarketService::class), [
                'repo' => '25Cloud/example-plugin',
                'tag' => 'v1.0.0',
                'sha' => str_repeat('a', 40),
                'sha256' => str_repeat('a', 64),
            ], 'safe_plugin', $this->tmp);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_php_manifest_is_rejected_without_executing_it(): void
    {
        $marker = $this->tmp.DIRECTORY_SEPARATOR.'manifest-side-effect';
        $pluginRoot = $this->tmp.DIRECTORY_SEPARATOR.'plugin';
        File::ensureDirectoryExists($pluginRoot);
        File::put($pluginRoot.DIRECTORY_SEPARATOR.'config.php', '<?php file_put_contents('.var_export($marker, true).", 'executed'); return ['info' => ['domain' => 'addons', 'slug' => 'unsafe']];");

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('禁止使用可执行 config.php');

        try {
            $this->invokeReadManifest($pluginRoot, 'unsafe');
        } finally {
            $this->assertFileDoesNotExist($marker);
        }
    }

    public function test_json_manifest_is_read_as_pure_data(): void
    {
        $pluginRoot = $this->tmp.DIRECTORY_SEPARATOR.'plugin';
        File::ensureDirectoryExists($pluginRoot);
        File::put($pluginRoot.DIRECTORY_SEPARATOR.'plugin.json', json_encode([
            'info' => [
                'domain' => 'addons',
                'slug' => 'safe_plugin',
                'name' => '安全插件',
            ],
        ], JSON_THROW_ON_ERROR));

        $manifest = $this->invokeReadManifest($pluginRoot, 'safe_plugin');

        $this->assertSame('addons', $manifest['domain']);
        $this->assertSame('safe_plugin', $manifest['info']['slug']);
    }

    public function test_archive_sha256_mismatch_is_rejected(): void
    {
        $archive = $this->tmp.DIRECTORY_SEPARATOR.'plugin.zip';
        File::put($archive, 'package-content');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('SHA-256 摘要不匹配');

        $method = (new ReflectionClass(PluginMarketService::class))->getMethod('assertArchiveDigest');
        $method->setAccessible(true);
        $method->invoke(app(PluginMarketService::class), $archive, hash('sha256', 'different-content'), 'safe_plugin');
    }

    public function test_archive_without_sha256_is_rejected(): void
    {
        $archive = $this->tmp.DIRECTORY_SEPARATOR.'plugin.zip';
        File::put($archive, 'package-content');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('缺少 SHA-256 摘要');

        $method = (new ReflectionClass(PluginMarketService::class))->getMethod('assertArchiveDigest');
        $method->setAccessible(true);
        $method->invoke(app(PluginMarketService::class), $archive, '', 'safe_plugin');
    }

    public function test_archive_sha256_is_verified_after_download(): void
    {
        $body = 'zip-content';
        $digest = hash('sha256', $body);
        Http::fake([
            'https://github.com/*' => Http::response($body, 200),
        ]);

        $method = (new ReflectionClass(PluginMarketService::class))->getMethod('downloadArchive');
        $method->setAccessible(true);
        $path = $method->invoke(app(PluginMarketService::class), [
            'repo' => '25Cloud/example-plugin',
            'tag' => 'v1.0.0',
            'sha' => str_repeat('a', 40),
            'sha256' => $digest,
        ], 'safe_plugin', $this->tmp);

        $this->assertSame($digest, hash_file('sha256', $path));
        @unlink($path);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://github.com/25Cloud/example-plugin/archive/'.str_repeat('a', 40).'.zip');
    }

    public function test_archive_redirects_are_revalidated_against_the_host_allowlist(): void
    {
        $body = 'zip-content';
        Http::fakeSequence()
            ->push('', 302, ['Location' => 'https://codeload.github.com/25Cloud/example-plugin/zip/'.str_repeat('a', 40)])
            ->push($body, 200);

        $method = (new ReflectionClass(PluginMarketService::class))->getMethod('downloadArchive');
        $method->setAccessible(true);
        $path = $method->invoke(app(PluginMarketService::class), [
            'repo' => '25Cloud/example-plugin',
            'sha' => str_repeat('a', 40),
            'sha256' => hash('sha256', $body),
        ], 'safe_plugin', $this->tmp);

        $this->assertSame($body, (string) file_get_contents($path));
        @unlink($path);
    }

    public function test_archive_redirect_to_untrusted_host_is_rejected_before_follow_up_request(): void
    {
        Http::fakeSequence()
            ->push('', 302, ['Location' => 'https://evil.example/archive.zip']);

        $method = (new ReflectionClass(PluginMarketService::class))->getMethod('downloadArchive');
        $method->setAccessible(true);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('不在可信源 allowlist 内');

        try {
            $method->invoke(app(PluginMarketService::class), [
                'repo' => '25Cloud/example-plugin',
                'sha' => str_repeat('a', 40),
                'sha256' => str_repeat('a', 64),
            ], 'safe_plugin', $this->tmp);
        } finally {
            $this->assertCount(1, Http::recorded());
        }
    }

    /**
     * @return array{domain: string, info: array<string, mixed>}
     */
    private function invokeReadManifest(string $pluginRoot, string $slug): array
    {
        $method = (new ReflectionClass(PluginMarketService::class))->getMethod('readManifest');
        $method->setAccessible(true);

        /** @var array{domain: string, info: array<string, mixed>} $manifest */
        $manifest = $method->invoke(app(PluginMarketService::class), $pluginRoot, $slug);

        return $manifest;
    }
}
