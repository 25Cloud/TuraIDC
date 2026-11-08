<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Client\V2\ServiceConsoleAreaController;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use App\Services\ClientServiceConsole\ServiceConsoleAreaService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 自定义控制台面板的 HTML 净化和响应头契约。
 *
 * 上游页面在同源 iframe 中渲染，必须先变成被动 HTML，再由本地静态运行时提供必要的
 * 兼容能力；这里不依赖上游网络或数据库内容，直接锁住净化器和面板头部的边界。
 */
class ClientServiceConsoleAreaSecurityTest extends TestCase
{
    #[Test]
    public function upstream_executable_html_is_removed_by_dom_sanitizer(): void
    {
        $html = <<<'HTML'
        <div class="safe" onclick="alert(1)">正文</div>
        <script>alert('script')</script>
        <scr<script>ipt>recombined()</scr</script>ipt>
        <a href="java&#x09;script:alert(2)">危险链接</a>
        <img src="javascript:alert(3)" onerror="alert(4)" style="background:url(https://evil.test/x)">
        <form action="javascript:alert(5)"><input name="ok" value="1"></form>
        <iframe src="https://evil.test"></iframe>
        HTML;

        $output = $this->sanitize($html);

        $this->assertStringContainsString('正文', $output);
        $this->assertStringContainsString('name="ok"', $output);
        $this->assertStringNotContainsStringIgnoringCase('<script', $output);
        $this->assertStringNotContainsString("alert('script')", $output);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $output);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $output);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $output);
        $this->assertStringNotContainsStringIgnoringCase('iframe', $output);
        $this->assertStringNotContainsString('style=', $output);
    }

    #[Test]
    public function panel_headers_disable_inline_eval_and_arbitrary_network_sources(): void
    {
        $controller = new ServiceConsoleAreaController(
            $this->createMock(ClientServiceConsoleService::class)
        );
        $method = new ReflectionMethod(ServiceConsoleAreaController::class, 'panelResponseHeaders');
        $method->setAccessible(true);

        /** @var array<string, string> $headers */
        $headers = $method->invoke($controller);
        $csp = (string) ($headers['Content-Security-Policy'] ?? '');

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("script-src-attr 'none'", $csp);
        $this->assertStringContainsString("style-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertDoesNotMatchRegularExpression(
            '/(?:default-src|script-src|style-src|img-src|font-src|connect-src|media-src)[^;]*(?:http:|https:)/i',
            $csp,
            '资源指令不得放行任意 http/https；frame-ancestors 可继续使用配置的精确来源'
        );
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertSame('no-referrer', $headers['Referrer-Policy'] ?? null);
        $this->assertStringContainsString('no-store', (string) ($headers['Cache-Control'] ?? ''));
    }

    #[Test]
    public function proxied_assets_are_private_and_not_cached_by_browsers(): void
    {
        $service = $this->createMock(ClientServiceConsoleService::class);
        $service->method('getConsoleAreaAssetForTicket')->willReturn([
            'body' => 'body { color: red; }',
            'content_type' => 'text/css; charset=UTF-8',
        ]);

        $controller = new ServiceConsoleAreaController($service);
        $response = $controller->asset(Request::create('/api/v2/client/services/1/console-area/asset', 'GET', [
            'path' => '/vendor/panel.css',
        ]), 1);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('0', $response->headers->get('Expires'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    private function sanitize(string $html): string
    {
        $service = (new ReflectionClass(ServiceConsoleAreaService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ServiceConsoleAreaService::class, 'sanitizeModulePage');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $html);
    }
}
