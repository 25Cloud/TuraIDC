<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use TuraIDC\Plugins\Captcha\Cap\Lib\CapCaptchaService;

class CaptchaCapPluginTest extends TestCase
{
    private const CONFIG = [
        'server_address' => 'https://captcha.example.test',
        'site_id' => 'site-abc123',
        'secret_key' => 'secret-value',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // 插件类走 PluginFileLoader 的 require_once 加载，不在 PSR-4 autoload 内，
        // 直接 new 之前必须先确保文件已载入。
        $manifest = app(PluginScanner::class)->requireManifest('captcha', 'cap');
        app(PluginFileLoader::class)->ensureLoaded($manifest);
    }

    private function service(): CapCaptchaService
    {
        return new CapCaptchaService;
    }

    // ============================================================
    // captcha.verify —— 核心防线：fail-closed
    // ============================================================

    public function test_verify_accepts_when_upstream_returns_success(): void
    {
        Http::fake([
            'captcha.example.test/*' => Http::response(['success' => true]),
        ]);

        $result = $this->service()->execute([
            'action' => 'captcha.verify',
            'payload' => ['token' => 'cap-token-123'],
            'config' => self::CONFIG,
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['verified']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://captcha.example.test/site-abc123/siteverify'
                && $request['secret'] === 'secret-value'
                && $request['response'] === 'cap-token-123';
        });
    }

    public function test_verify_rejects_when_upstream_returns_non_2xx(): void
    {
        Http::fake([
            'captcha.example.test/*' => Http::response('service error', 503),
        ]);

        $result = $this->service()->execute([
            'action' => 'captcha.verify',
            'payload' => ['token' => 'cap-token-123'],
            'config' => self::CONFIG,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('upstream_http_error', $result['data']['error_type']);
        $this->assertSame(503, $result['data']['status']);
    }

    public function test_verify_rejects_when_upstream_throws_connection_exception(): void
    {
        Http::fake([
            'captcha.example.test/*' => fn () => throw new ConnectionException('Connection refused'),
        ]);

        $result = $this->service()->execute([
            'action' => 'captcha.verify',
            'payload' => ['token' => 'cap-token-123'],
            'config' => self::CONFIG,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('connection_error', $result['data']['error_type']);
    }

    public function test_verify_rejects_when_upstream_returns_success_false(): void
    {
        Http::fake([
            'captcha.example.test/*' => Http::response(['success' => false]),
        ]);

        $result = $this->service()->execute([
            'action' => 'captcha.verify',
            'payload' => ['token' => 'invalid-token'],
            'config' => self::CONFIG,
        ]);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['data']['verified']);
    }

    public function test_verify_rejects_when_plugin_not_configured(): void
    {
        $result = $this->service()->execute([
            'action' => 'captcha.verify',
            'payload' => ['token' => 'cap-token-123'],
            'config' => [],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('人机验证插件配置不完整', $result['message']);
        Http::assertNothingSent();
    }

    public function test_verify_rejects_when_token_missing(): void
    {
        Http::fake([
            'captcha.example.test/*' => Http::response(['success' => true]),
        ]);

        $result = $this->service()->execute([
            'action' => 'captcha.verify',
            'payload' => [],
            'config' => self::CONFIG,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('人机验证参数不完整', $result['message']);
        Http::assertNothingSent();
    }

    // ============================================================
    // captcha.config —— 配置提示
    // ============================================================

    public function test_config_exposes_provider_and_endpoint(): void
    {
        $result = $this->service()->execute([
            'action' => 'captcha.config',
            'payload' => [],
            'config' => self::CONFIG,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('cap', $result['data']['provider']);
        $this->assertSame('site-abc123', $result['data']['captcha_id']);
        $this->assertSame('https://captcha.example.test/site-abc123/', $result['data']['api_endpoint']);
        $this->assertTrue($result['data']['enabled']);
    }

    public function test_config_reports_disabled_when_missing_secret(): void
    {
        $result = $this->service()->execute([
            'action' => 'captcha.config',
            'payload' => [],
            'config' => [
                'server_address' => 'https://captcha.example.test',
                'site_id' => 'site-abc123',
                'secret_key' => '',
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['enabled']);
    }

    // ============================================================
    // captcha.script —— 脚本代理缓存（失败不影响验证主链路）
    // ============================================================

    public function test_script_returns_cached_content_without_upstream_call(): void
    {
        \Illuminate\Support\Facades\Cache::put(
            \App\Support\CacheKey::capScript(),
            'cached<script>',
            now()->addMinutes(10)
        );

        $result = $this->service()->execute([
            'action' => 'captcha.script',
            'payload' => [],
            'config' => self::CONFIG,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('cached<script>', $result['data']['content']);
        Http::assertNothingSent();
    }

    public function test_execute_rejects_unknown_action(): void
    {
        $result = $this->service()->execute([
            'action' => 'captcha.unknown',
            'payload' => [],
            'config' => self::CONFIG,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('不支持的人机验证动作', $result['message']);
    }
}