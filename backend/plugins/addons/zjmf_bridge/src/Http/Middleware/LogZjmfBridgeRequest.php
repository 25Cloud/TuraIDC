<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Http\Middleware;

use TuraIDC\Plugins\Addons\ZjmfBridge\Logging\ZjmfBridgeLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogZjmfBridgeRequest
{
    public function __construct(
        private readonly ZjmfBridgeLogger $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $traceId = trim((string) $request->headers->get('X-Request-Id', ''));
        if ($traceId === '') {
            $traceId = 'zjmf_'.Str::ulid();
        }
        $traceId = mb_substr($traceId, 0, 64);
        $startedAt = microtime(true);

        $request->headers->set('X-Request-Id', $traceId);
        $request->attributes->set('trace_id', $traceId);
        $request->attributes->set('request_id', $traceId);
        $request->attributes->set('request_time', now()->format('Y-m-d H:i:s.u'));

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Id', $traceId);
        $this->logger->record($request, $response, (int) round((microtime(true) - $startedAt) * 1000));

        return $response;
    }
}
