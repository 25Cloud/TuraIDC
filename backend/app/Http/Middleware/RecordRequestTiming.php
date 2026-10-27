<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 记录服务端真实处理耗时。
 *
 * 为什么需要它：nginx access log 没有 $request_time，php-fpm 的 slowlog 只覆盖
 * 超过 request_slowlog_timeout 的样本，导致「哪个接口慢」长期只能靠猜。这里在
 * 全局最外层打一次时间戳，成本是两次 microtime()，换来两件事：
 *
 * 1. 每个 API 响应都带 X-Response-Time-Ms，运维和压测脚本可以直接读到；
 * 2. 超过 app.slow_request_threshold_ms 的请求写一条 [慢请求] 警告日志，
 *    含 request_id，可与 [api] 未处理异常 / operation_logs 对上。
 *
 * 只做计时与写日志，不查库、不解析 body，放在中间件栈最外层也不拖性能。
 * 流式响应（VNC relay、面板资源代理）只统计到响应头生成，不包含实际传输时间。
 */
class RecordRequestTiming
{
    /**
     * 响应头名称：值为本次请求在 PHP 侧的完整耗时（毫秒）。
     */
    public const HEADER = 'X-Response-Time-Ms';

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        $response->headers->set(self::HEADER, (string) $durationMs);

        $threshold = (int) config('app.slow_request_threshold_ms', 1000);
        if ($threshold <= 0 || $durationMs < $threshold) {
            return $response;
        }

        // 慢请求按 path 归一化后再写，避免 services/1234、services/1235 这类
        // 只有 ID 不同的路径把日志刷成一片，运维看不出是哪个接口慢。
        Log::warning('[慢请求]', [
            'request_id' => (string) ($request->attributes->get('request_id')
                ?: $request->header('X-Request-Id', '')),
            'method' => $request->method(),
            'path' => $this->normalizePath($request->path()),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
        ]);

        return $response;
    }

    private function normalizePath(string $path): string
    {
        // 纯数字段替换成 {id}，只影响日志聚合，不参与路由匹配。
        return (string) preg_replace('#/(\d+)(?=/|$)#', '/{id}', $path);
    }
}
