<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfAuthManager;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceTransport;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfStatusService;

/**
 * ZJMF 插件链路的电源状态降级回归。
 *
 * 电源状态是辅助信息，实例详情才是主数据：读电源失败只能降级成「仅同步实例详情」，
 * 不能中断整条状态同步——返回结果里要有 host，失败原因单独放在 runtime_error。
 * 上游实例真的不存在/已删除（实例详情读不到）仍然必须判失败，结果里给 error。
 *
 * 用例只桩掉传输层，不连数据库。
 */
final class ZjmfPowerStatusRuntimeDegradationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'idc.hosting_panel_api.jwt_cache_store' => 'array',
        ]);
        Cache::clearResolvedInstances();
        Cache::store('array')->flush();

        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'zjmf_finance')
        );
    }

    #[Test]
    public function runtime_failure_keeps_host_detail_and_reports_the_reason(): void
    {
        $service = new ZjmfStatusService($this->makeZjmfTransport($this->fakeTransport(
            runtimeResponse: ['status' => 500, 'msg' => '上游电源接口异常'],
        )));

        $result = $service->syncServiceStatuses($this->makeSupplier(), [
            [
                'service_id' => 501,
                'host_id' => 7788,
            ],
        ]);

        $perService = $result['services'][501] ?? [];

        // 电源状态读不到：实例详情照常返回，失败原因单独给出，不再中断整条同步。
        $this->assertArrayNotHasKey('error', $perService);
        $this->assertSame('Active', $perService['host']['domainstatus'] ?? null);
        $this->assertSame('srv501.example.test', $perService['host']['domain'] ?? null);
        $this->assertSame([], $perService['runtime'] ?? null);
        $this->assertSame('读取电源状态失败，请稍后重试', $perService['runtime_error'] ?? null);
    }

    #[Test]
    public function host_detail_failure_still_fails_the_whole_service(): void
    {
        $service = new ZjmfStatusService($this->makeZjmfTransport($this->fakeTransport(
            hostData: [],
        )));

        $result = $service->syncServiceStatuses($this->makeSupplier(), [
            [
                'service_id' => 501,
                'host_id' => 7788,
            ],
        ]);

        $perService = $result['services'][501] ?? [];

        // 实例详情是主数据：读不到就是真故障，整条同步判失败。
        $this->assertSame('读取主机详情失败：上游未返回实例数据', $perService['error'] ?? null);
        $this->assertArrayNotHasKey('host', $perService);
        $this->assertArrayNotHasKey('runtime_error', $perService);
    }

    /**
     * @param  array<string, mixed>  $runtimeResponse
     * @param  array<string, mixed>|null  $hostData
     */
    private function fakeTransport(array $runtimeResponse = [], ?array $hostData = null): HostingPanelApiTransport
    {
        $hostData ??= [
            'domain' => 'srv501.example.test',
            'domainstatus' => 'Active',
            'nextduedate' => 1785600000,
        ];

        return new class($runtimeResponse, $hostData) extends HostingPanelApiTransport
        {
            public function __construct(
                private readonly array $runtimeResponse,
                private readonly array $hostData,
            ) {
                parent::__construct();
            }

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                return match ($uri) {
                    '/zjmf_api_login' => [
                        'status' => 200,
                        'code' => 200,
                        'jwt' => 'jwt-degrade-test',
                    ],
                    '/provision/default' => $this->runtimeResponse,
                    default => [
                        'status' => 404,
                        'msg' => 'not found',
                    ],
                };
            }

            public function parallelGet(Supplier $supplier, array $requests, ?string $jwt = null, array $headers = []): array
            {
                return [
                    'detail_501' => [
                        'status_code' => 200,
                        'response' => [
                            'status' => 200,
                            'data' => [
                                'host_data' => $this->hostData,
                            ],
                        ],
                    ],
                ];
            }
        };
    }

    private function makeSupplier(): Supplier
    {
        $supplier = new Supplier;
        $supplier->forceFill([
            'id' => 1001,
            'interface_type' => 'zjmf_finance_api',
            // 登录入口对空凭据前置拒服务，夹具需提供真实形态的凭据（HTTP 层已被桩替换）
            'api_username' => 'fixture-account',
            'api_key' => 'fixture-secret',
        ]);

        return $supplier;
    }

    private function makeZjmfTransport(HostingPanelApiTransport $transport): ZjmfFinanceTransport
    {
        return new ZjmfFinanceTransport(
            $transport,
            new ZjmfAuthManager($transport),
        );
    }
}
