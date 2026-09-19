<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AppendSecurityHeaders
{
    /**
     * 控制器用来声明「本响应允许被 iframe 嵌入」的内部标记头，不会被回传给浏览器。
     *
     * 服务自定义功能面板要在控制台域名下渲染，必须由控制器按受信来源白名单
     * 自行下发 CSP frame-ancestors；带此标记时中间件不再覆盖点击劫持相关头部。
     */
    public const EMBEDDABLE_HEADER = 'X-Frame-Embeddable';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $embeddable = $response->headers->has(self::EMBEDDABLE_HEADER);
        $response->headers->remove(self::EMBEDDABLE_HEADER);

        // 防止 MIME 类型嗅探
        if (! $response->headers->has('X-Content-Type-Options')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        // 防止点击劫持；可嵌入响应由控制器决定（否则面板会被浏览器判为拒绝连接）
        if (! $embeddable && ! $response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        // 控制 Referrer 泄露
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        // 启用浏览器 XSS 过滤器
        if (! $response->headers->has('X-XSS-Protection')) {
            $response->headers->set('X-XSS-Protection', '1; mode=block');
        }

        // 限制权限策略（Permissions-Policy）
        if (! $response->headers->has('Permissions-Policy')) {
            $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        }

        // Content-Security-Policy：限制脚本/对象/基础URI来源，防御XSS注入
        // API 接口返回 JSON，不需要加载外部脚本；SPA 页面由前端单独配置更宽松的策略
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'"
            );
        }

        return $response;
    }
}
