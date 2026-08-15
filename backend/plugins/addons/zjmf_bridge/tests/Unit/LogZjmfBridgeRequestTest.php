<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use TuraIDC\Plugins\Addons\ZjmfBridge\Http\Middleware\LogZjmfBridgeRequest;
use TuraIDC\Plugins\Addons\ZjmfBridge\Logging\ZjmfBridgeLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class LogZjmfBridgeRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest(PluginDomain::ADDONS, 'zjmf_bridge')
        );
    }

    public function test_it_generates_and_propagates_request_id(): void
    {
        $logger = new class extends ZjmfBridgeLogger
        {
            public array $payload = [];

            public function record(Request $request, Response $response, int $latencyMs): void
            {
                $this->payload = [
                    'request_id' => (string) $request->attributes->get('request_id', ''),
                    'trace_id' => (string) $request->attributes->get('trace_id', ''),
                    'request_time' => (string) $request->attributes->get('request_time', ''),
                    'latency_ms' => $latencyMs,
                    'status' => $response->getStatusCode(),
                ];
            }
        };

        $middleware = new LogZjmfBridgeRequest($logger);
        $request = Request::create('/zjmf/v1/system/health', 'GET');

        $response = $middleware->handle($request, static fn (): JsonResponse => new JsonResponse(['code' => 0], 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('zjmf_', (string) $response->headers->get('X-Request-Id'));
        $this->assertSame($response->headers->get('X-Request-Id'), $logger->payload['request_id']);
        $this->assertSame($logger->payload['request_id'], $logger->payload['trace_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $logger->payload['request_time']);
        $this->assertGreaterThanOrEqual(0, $logger->payload['latency_ms']);
    }
}
