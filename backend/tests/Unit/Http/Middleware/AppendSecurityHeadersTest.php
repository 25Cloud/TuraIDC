<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\AppendSecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 安全响应头中间件的「可嵌入响应」契约回归。
 *
 * 背景：服务自定义功能面板通过 iframe 在控制台域名下渲染
 * （GET /api/v2/client/services/{id}/console-area/content）。中间件此前无差别下发
 * X-Frame-Options: SAMEORIGIN 与 CSP frame-ancestors 'none'，
 * 浏览器于是直接显示「api.25y.cn 拒绝连接」，面板根本进不来。
 *
 * 契约：
 *   - 默认响应照旧下发点击劫持防护；
 *   - 控制器用 EMBEDDABLE_HEADER 声明可嵌入时，中间件不再覆盖，且该标记不回传浏览器。
 *
 * 继承裸 PHPUnit TestCase：不启动 Laravel，可在任何环境安全执行。
 */
class AppendSecurityHeadersTest extends TestCase
{
    #[Test]
    public function default_response_keeps_clickjacking_protection(): void
    {
        $response = $this->runMiddleware(new Response('{}', 200));

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            (string) $response->headers->get('Content-Security-Policy')
        );
    }

    #[Test]
    public function embeddable_response_omits_frame_headers_and_strips_the_marker(): void
    {
        $response = $this->runMiddleware(new Response('<html></html>', 200, [
            AppendSecurityHeaders::EMBEDDABLE_HEADER => '1',
            'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'self' https://dash.25y.cn",
        ]));

        $this->assertFalse($response->headers->has('X-Frame-Options'), '可嵌入响应不能带 X-Frame-Options');
        $this->assertSame(
            "default-src 'self'; frame-ancestors 'self' https://dash.25y.cn",
            $response->headers->get('Content-Security-Policy'),
            '可嵌入响应的 CSP 由控制器下发，中间件不得覆盖'
        );
        $this->assertFalse(
            $response->headers->has(AppendSecurityHeaders::EMBEDDABLE_HEADER),
            '内部标记头不应回传给浏览器'
        );

        // 其余安全头与响应无关，仍要下发
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    private function runMiddleware(Response $response): Response
    {
        $middleware = new AppendSecurityHeaders;

        return $middleware->handle(Request::create('/api/v2/client/services/3458/console-area/content', 'GET'), fn () => $response);
    }
}
