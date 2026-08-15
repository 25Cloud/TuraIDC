<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceResolverService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\ClientServiceConsole\ServiceVncService;
use App\Services\System\OperationLogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ServiceVncTokenSecurityTest extends TestCase
{
    public function test_public_vnc_token_payload_exchanges_once_without_password(): void
    {
        Cache::store('redis_volatile')->put('vnc_token:test-token', [
            'service_id' => 12,
            'password' => 'secret-password',
            'username' => 'root',
            'target' => '10.0.0.8:5900',
            'single_use' => true,
            'token_scope' => 'public',
        ], now()->addMinutes(5));

        $service = $this->makeVncService();

        $payload = $service->resolvePublicVncTokenPayload('test-token');

        $this->assertNotSame('test-token', $payload['token']);
        $this->assertSame(12, $payload['service_id']);
        $this->assertSame('/ws/vnc', $payload['relay_path']);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertFalse(Cache::store('redis_volatile')->has('vnc_token:test-token'));
        $this->assertTrue(Cache::store('redis_volatile')->has('vnc_token:'.$payload['token']));

        $relayParams = $service->resolveVncToken((string) $payload['token']);
        // relay 建连仅需 host/port/path，不落明文 VNC 密码到 Redis。
        $this->assertArrayNotHasKey('password', $relayParams);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('VNC 链接已过期或无效，请重新获取');
        $service->resolvePublicVncTokenPayload('test-token');
    }

    public function test_single_use_vnc_token_is_consumed_when_relay_resolves_it(): void
    {
        Cache::store('redis_volatile')->put('vnc_token:test-token', [
            'service_id' => 12,
            'password' => 'secret-password',
            'username' => 'root',
            'target' => '10.0.0.8:5900',
            'single_use' => true,
        ], now()->addMinutes(5));

        $service = $this->makeVncService();

        $params = $service->resolveVncToken('test-token');

        $this->assertSame('secret-password', $params['password']);
        $this->assertFalse(Cache::store('redis_volatile')->has('vnc_token:test-token'));

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('VNC 链接已过期或无效，请重新获取');

        $service->resolveVncToken('test-token');
    }

    public function test_admin_public_vnc_token_is_consumed_but_relay_token_is_reusable(): void
    {
        Cache::store('redis_volatile')->put('vnc_token:admin-token', [
            'service_id' => 34,
            'password' => 'admin-secret',
            'username' => 'administrator',
            'target' => '10.0.0.9:5900',
            'single_use' => false,
            'token_scope' => 'public',
        ], now()->addMinutes(5));

        $service = $this->makeVncService();

        $payload = $service->resolvePublicVncTokenPayload('admin-token');
        $firstParams = $service->resolveVncToken((string) $payload['token']);
        $secondParams = $service->resolveVncToken((string) $payload['token']);

        $this->assertSame(34, $payload['service_id']);
        $this->assertSame('/ws/vnc', $payload['relay_path']);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertFalse(Cache::store('redis_volatile')->has('vnc_token:admin-token'));
        // relay token 复用重连时同样不含明文 VNC 密码。
        $this->assertArrayNotHasKey('password', $firstParams);
        $this->assertArrayNotHasKey('password', $secondParams);
    }

    public function test_vnc_token_exchange_rejects_non_whitelisted_origin(): void
    {
        // 兑换端点 Origin 纵深校验：请求带非本站 Origin 时拒绝。
        $this->getJson('/api/v2/client/vnc-tokens/test-token', [
            'Origin' => 'https://evil.example.com',
        ])->assertForbidden();

        // 无 Origin（同源或非浏览器客户端）放行，交由 token 校验（无效 token 返回 404）。
        $this->getJson('/api/v2/client/vnc-tokens/test-token')
            ->assertStatus(404);
    }

    public function test_public_vnc_token_exchange_log_never_contains_raw_token_or_password(): void
    {
        Cache::store('redis_volatile')->put('vnc_token:log-token', [
            'service_id' => 78,
            'password' => 'log-secret-password',
            'single_use' => true,
            'token_scope' => 'public',
        ], now()->addMinutes(5));

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context): bool {
                $encodedContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return $level === 'info'
                    && $message === '[VNC] 公开 token 已换取 relay token'
                    && is_string($encodedContext)
                    && ! str_contains($encodedContext, 'log-token')
                    && ! str_contains($encodedContext, 'log-secret-password')
                    && isset($context['token_hash'], $context['relay_token_hash'])
                    && ($context['has_password'] ?? null) === true;
            });

        $this->makeVncService()->resolvePublicVncTokenPayload('log-token');
    }

    public function test_admin_vnc_token_can_be_resolved_multiple_times_by_relay(): void
    {
        Cache::store('redis_volatile')->put('vnc_token:admin-relay-token', [
            'service_id' => 56,
            'password' => 'relay-secret',
            'username' => 'administrator',
            'target' => '10.0.0.10:5900',
            'single_use' => false,
        ], now()->addMinutes(5));

        $service = $this->makeVncService();

        $firstParams = $service->resolveVncToken('admin-relay-token');
        $secondParams = $service->resolveVncToken('admin-relay-token');

        $this->assertSame('relay-secret', $firstParams['password']);
        $this->assertSame('relay-secret', $secondParams['password']);
        $this->assertTrue(Cache::store('redis_volatile')->has('vnc_token:admin-relay-token'));
    }

    public function test_service_transform_service_redacts_raw_remote_error_message(): void
    {
        $resolver = $this->createMock(ServiceResolverService::class);
        $resolver->method('resolveGroupedOverviewTypeValue')->willReturn('server');
        $resolver->method('resolveConsoleMode')->willReturn('default');

        $transformService = new ServiceTransformService($resolver);
        $method = (new \ReflectionClass($transformService))->getMethod('sanitizeRemoteErrorMessage');
        $method->setAccessible(true);

        $message = $method->invoke(
            $transformService,
            'cURL error 28: Operation timed out after 10001 milliseconds for https://secret-supplier.example/v1/host'
        );

        $this->assertSame('上游状态同步超时，请稍后重试', $message);
        $this->assertStringNotContainsString('secret-supplier.example', (string) $message);
    }

    public function test_vnc_link_request_refreshes_jwt_once_when_upstream_returns_auth_failure(): void
    {
        $user = new User;
        $user->forceFill(['id' => 1]);

        $service = new Service;
        $service->forceFill(['id' => 321, 'user_id' => 1, 'provision_data' => []]);

        $supplier = new Supplier;
        $supplier->forceFill(['id' => 45]);

        $runtime = new class
        {
            public int $postCalls = 0;

            public int $refreshCalls = 0;

            public array $jwts = [];

            public function post(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
            {
                $this->postCalls++;
                $this->jwts[] = $jwt;

                if ($this->postCalls === 1) {
                    return ['status' => 401, 'msg' => 'jwt expired'];
                }

                return [
                    'status' => 200,
                    'msg' => '获取VNC链接成功',
                    'data' => [
                        'url' => 'wss://vnc.example.test/websockify?password=secret',
                    ],
                ];
            }

            public function refreshJwt(Supplier $supplier): string
            {
                $this->refreshCalls++;

                return 'fresh-jwt';
            }
        };

        $detailService = $this->getMockBuilder(ServiceDetailService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findUserService', 'resolveUpstreamContext', 'assertSuccess', 'extractPayload'])
            ->getMock();
        $detailService->method('findUserService')->willReturn($service);
        $detailService->method('resolveUpstreamContext')->willReturn([$runtime, $supplier, 99, 'stale-jwt']);
        $detailService->method('extractPayload')->willReturnCallback(
            fn (array $response): array => is_array($response['data'] ?? null) ? $response['data'] : $response
        );
        $detailService->method('assertSuccess')->willReturnCallback(function (array $response): void {
            $status = (int) ($response['status'] ?? $response['code'] ?? 0);
            if (! in_array($status, [200, 1001], true)) {
                throw new BusinessException('获取VNC链接失败，主机面板接口暂时不可用', 42200);
            }
        });

        $transformService = $this->createMock(ServiceTransformService::class);
        $transformService->method('canExecuteConsoleActions')->willReturn(true);
        $transformService->method('readCachedConnection')->willReturn([]);
        $transformService->method('transformDetail')->willReturn(['id' => 321]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->once())->method('writeServiceConsoleLog');

        $result = (new ServiceVncService(
            $operationLogService,
            $detailService,
            $transformService,
        ))->getVncUrlForUser($user, 321, ['request_origin' => 'https://console.example.test']);

        $this->assertSame(2, $runtime->postCalls);
        $this->assertSame(1, $runtime->refreshCalls);
        $this->assertSame(['stale-jwt', 'fresh-jwt'], $runtime->jwts);
        $this->assertStringContainsString('/vnc/vnc.html?', $result['url']);
    }

    private function makeVncService(): ServiceVncService
    {
        return new ServiceVncService(
            $this->createMock(OperationLogService::class),
            $this->createMock(ServiceDetailService::class),
            $this->createMock(ServiceTransformService::class),
        );
    }
}
