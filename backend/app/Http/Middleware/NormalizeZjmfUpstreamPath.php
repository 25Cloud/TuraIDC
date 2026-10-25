<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 归一化 /api/v2/zjmf 路径中的连续斜杠。
 *
 * 魔方财务 zjmfCurl 的多数调用点带前导斜杠传 path（如 "/dcim/on"、
 * "/provision/default"，见 zjmf376 app/common/logic/Host.php），拼接后的
 * 请求路径形如 /api/v2/zjmf//dcim/on。Nginx 默认 merge_slashes 会折叠双
 * 斜杠，但 Apache、显式关闭 merge_slashes 的 Nginx 与 php artisan serve
 * 等环境会原样透传，Laravel 路由匹配不上即 404，下游全部显示「接口不存在」。
 *
 * 因此必须挂在全局中间件栈（路由解析之前）改写；挂在路由组内时路由匹配
 * 已经失败，改写来不及。
 */
class NormalizeZjmfUpstreamPath
{
    private const PREFIX = '/api/v2/zjmf';

    public function handle(Request $request, Closure $next): Response
    {
        $uri = (string) $request->server->get('REQUEST_URI', '');

        $split = explode('?', $uri, 2);
        $path = $split[0];

        if (! preg_match('#^'.preg_quote(self::PREFIX, '#').'(/|$)#', $path) || ! str_contains($path, '//')) {
            return $next($request);
        }

        $normalized = (string) preg_replace('#/{2,}#', '/', $path);
        $server = $request->server->all();
        $server['REQUEST_URI'] = $normalized.(isset($split[1]) && $split[1] !== '' ? '?'.$split[1] : '');

        // duplicate() 会重置 pathInfo/requestUri 缓存（Symfony Request::duplicate），
        // 保证后续路由按归一化后的 URI 匹配，而不是读到改写前的旧值。
        return $next($request->duplicate(server: $server));
    }
}
