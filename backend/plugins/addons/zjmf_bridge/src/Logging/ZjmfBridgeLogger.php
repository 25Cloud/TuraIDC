<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Logging;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ZjmfBridgeLogger
{
    public function record(Request $request, Response $response, int $latencyMs): void
    {
        $payload = [
            'request_time' => (string) $request->attributes->get('request_time', now()->format('Y-m-d H:i:s.u')),
            'trace_id' => (string) $request->attributes->get('trace_id', ''),
            'request_id' => (string) $request->attributes->get('request_id', $request->headers->get('X-Request-Id', '')),
            'service' => (string) config('app.name', 'turaidc-backend'),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'mapped_target' => (string) $request->route()?->getActionName(),
            'actor_type' => (string) $request->attributes->get('zjmf_actor_type', ''),
            'actor_id' => (string) $request->attributes->get('zjmf_actor_id', ''),
            'app_id' => (string) $request->attributes->get('zjmf_app_id', ''),
            'scope' => (string) $request->attributes->get('zjmf_scope', ''),
            'ip' => (string) $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'http_status' => $response->getStatusCode(),
            'latency_ms' => $latencyMs,
        ];

        try {
            $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($line)) {
                file_put_contents(storage_path('logs/zjmf_bridge.log'), $line.PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        } catch (Throwable) {
            // Logging must never change Bridge business responses.
        }
    }
}
