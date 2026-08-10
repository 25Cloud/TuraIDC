<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AppendSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // 防止 MIME 类型嗅探
        if (! $response->headers->has('X-Content-Type-Options')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        // 防止点击劫持
        if (! $response->headers->has('X-Frame-Options')) {
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
