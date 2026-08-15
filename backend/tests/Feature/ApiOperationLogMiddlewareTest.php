<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Http\Middleware\LogOperation;
use App\Services\System\OperationLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class ApiOperationLogMiddlewareTest extends TestCase
{
    public function test_it_logs_get_api_requests_for_guest_users(): void
    {
        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->once())
            ->method('write')
            ->with(
                null,
                'guest',
                'GET api/v2/site/config',
                'config',
                null,
                $this->callback(function (array $detail): bool {
                    $this->assertSame(['scene' => 'home'], $detail['params'] ?? []);
                    $this->assertSame(200, $detail['status'] ?? null);
                    $this->assertSame('trace-get-001', $detail['request_id'] ?? null);
                    $this->assertSame('GET', $detail['method'] ?? null);
                    $this->assertSame('api/v2/site/config', $detail['path'] ?? null);
                    $this->assertIsInt($detail['duration_ms'] ?? null);
                    $this->assertGreaterThanOrEqual(0, $detail['duration_ms']);
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', (string) ($detail['request_time'] ?? ''));
                    $this->assertSame('CodexTest/1.0', $detail['user_agent'] ?? null);
                    $this->assertNotEmpty($detail['service'] ?? '');

                    return true;
                }),
                '127.0.0.1',
            );

        $middleware = new LogOperation($operationLogService);
        $request = Request::create('/api/v2/site/config', 'GET', ['scene' => 'home']);
        $request->headers->set('X-Request-Id', 'trace-get-001');
        $request->headers->set('User-Agent', 'CodexTest/1.0');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $response = $middleware->handle($request, static fn () => new JsonResponse(['code' => 0], 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('trace-get-001', $response->headers->get('X-Request-Id'));
    }

    public function test_it_logs_business_exceptions_for_api_requests(): void
    {
        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->once())
            ->method('write')
            ->with(
                null,
                'guest',
                'POST api/v2/client/login',
                'login',
                null,
                $this->callback(function (array $detail): bool {
                    $this->assertSame('[REDACTED]', $detail['params']['account'] ?? null);
                    $this->assertSame('[REDACTED]', $detail['params']['password'] ?? null);
                    $this->assertSame(422, $detail['status'] ?? null);
                    $this->assertNotEmpty($detail['request_id'] ?? '');
                    $this->assertSame('POST', $detail['method'] ?? null);
                    $this->assertSame('api/v2/client/login', $detail['path'] ?? null);
                    $this->assertIsInt($detail['duration_ms'] ?? null);
                    $this->assertSame('BusinessException', $detail['exception'] ?? null);
                    $this->assertSame('鐧诲綍澶辫触', $detail['exception_message'] ?? null);

                    return true;
                }),
                '127.0.0.1',
            );

        $middleware = new LogOperation($operationLogService);
        $request = Request::create('/api/v2/client/login', 'POST', [
            'account' => 'guest@example.com',
            'password' => 'plain-secret',
        ]);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('鐧诲綍澶辫触');

        $middleware->handle($request, static function () {
            throw new BusinessException('鐧诲綍澶辫触', 42200, 422);
        });
    }

    public function test_it_skips_successful_polling_api_requests(): void
    {
        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->never())->method('write');

        $middleware = new LogOperation($operationLogService);
        $pollingUris = [
            '/api/v2/client/verification/status?certify_id=cert-001',
            '/api/v2/client/recharge/PAY202607012124408903XS43L8N/status?poll_token=poll-001',
            '/api/v2/client/invoices/1433/pay/alipay/status?payment_no=PAY202607012124408903XS43L8N&poll_token=poll-002',
        ];

        foreach ($pollingUris as $uri) {
            $request = Request::create($uri, 'GET');

            $response = $middleware->handle($request, static fn () => new JsonResponse(['code' => 0], 200));

            $this->assertSame(200, $response->getStatusCode());
        }
    }

    public function test_it_keeps_failed_polling_api_requests(): void
    {
        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->once())
            ->method('write')
            ->with(
                null,
                'guest',
                'GET api/v2/client/verification/status',
                'verification',
                null,
                $this->callback(function (array $detail): bool {
                    $this->assertSame(403, $detail['status'] ?? null);

                    return true;
                }),
                '127.0.0.1',
            );

        $middleware = new LogOperation($operationLogService);
        $request = Request::create('/api/v2/client/verification/status', 'GET', ['certify_id' => 'cert-001']);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $response = $middleware->handle($request, static fn () => new JsonResponse(['code' => 40300], 403));

        $this->assertSame(403, $response->getStatusCode());
    }
}
