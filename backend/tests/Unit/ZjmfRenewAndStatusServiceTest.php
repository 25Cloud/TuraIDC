<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfAuthManager;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceTransport;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfRenewService;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfStatusService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ZjmfRenewAndStatusServiceTest extends TestCase
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
    public function renew_service_invoice_uses_zjmf_same_system_payment_flow_when_order_is_paid(): void
    {
        $fakeTransport = new class extends HostingPanelApiTransport
        {
            public array $requests = [];

            public array $textRequests = [];

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                $this->requests[] = [
                    'method' => $method,
                    'uri' => $uri,
                    'payload' => $payload,
                    'jwt' => $jwt,
                    'headers' => $headers,
                    'query' => $query,
                ];

                return match ($uri) {
                    '/zjmf_api_login' => [
                        'status' => 200,
                        'code' => 200,
                        'jwt' => 'jwt-renew-test',
                    ],
                    '/host/renew' => [
                        'status' => 200,
                        'data' => [
                            'invoiceid' => 8899,
                            'payment' => 'credit',
                        ],
                    ],
                    '/check_order' => [
                        'status' => 1000,
                    ],
                    '/host/header' => [
                        'status' => 200,
                        'data' => [
                            'host_data' => [
                                'id' => 7788,
                                'domain' => 'srv7788.example.test',
                                'domainstatus' => 'Active',
                                'nextduedate' => 1785600000,
                            ],
                        ],
                    ],
                    default => [
                        'status' => 404,
                        'msg' => 'not found',
                    ],
                };
            }

            public function requestText(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): string {
                $this->textRequests[] = [
                    'method' => $method,
                    'uri' => $uri,
                    'payload' => $payload,
                    'jwt' => $jwt,
                    'headers' => $headers,
                    'query' => $query,
                ];

                return '<!doctype html><html><body>payment submitted</body></html>';
            }
        };

        $service = new ZjmfRenewService($this->makeZjmfTransport($fakeTransport));
        $result = $service->renewServiceInvoice($this->makeSupplier(), 7788, 'monthly');

        $this->assertSame(8899, $result['upstream_invoice_id']);
        $this->assertSame(1000, $result['fund_status']);
        $this->assertTrue($result['payment_completed']);
        $this->assertSame('Active', $result['host_detail']['domainstatus'] ?? null);

        $renewRequest = collect($fakeTransport->requests)->firstWhere('uri', '/host/renew');
        $this->assertSame('POST', $renewRequest['method'] ?? null);
        $this->assertSame([
            'hostid' => 7788,
            'billingcycles' => 'monthly',
            'duration' => 1,
        ], $renewRequest['payload'] ?? null);

        $paymentRequest = $fakeTransport->textRequests[0] ?? null;
        $this->assertSame('POST', $paymentRequest['method'] ?? null);
        $this->assertSame('/pay', $paymentRequest['uri'] ?? null);
        $this->assertSame([
            'invoiceid' => 8899,
            'use_credit' => 1,
            'payment' => 'credit',
            'use_credit_limit' => 0,
        ], $paymentRequest['payload'] ?? null);
        $this->assertSame([
            'action' => 'billing',
            'pay' => 'true',
        ], $paymentRequest['query'] ?? null);

        $checkOrderRequest = collect($fakeTransport->requests)->firstWhere('uri', '/check_order');
        $this->assertSame('POST', $checkOrderRequest['method'] ?? null);
        $this->assertSame(['id' => 8899], $checkOrderRequest['payload'] ?? null);
    }

    #[Test]
    public function renew_service_invoice_keeps_local_fulfillment_pending_when_order_is_not_paid(): void
    {
        $fakeTransport = new class extends HostingPanelApiTransport
        {
            public array $requests = [];

            public array $textRequests = [];

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                $this->requests[] = [
                    'method' => $method,
                    'uri' => $uri,
                    'payload' => $payload,
                    'jwt' => $jwt,
                    'headers' => $headers,
                    'query' => $query,
                ];

                return match ($uri) {
                    '/zjmf_api_login' => [
                        'status' => 200,
                        'code' => 200,
                        'jwt' => 'jwt-renew-pending-test',
                    ],
                    '/host/renew' => [
                        'status' => 200,
                        'data' => [
                            'invoiceid' => 8899,
                            'payment' => 'credit',
                        ],
                    ],
                    '/check_order' => [
                        'status' => 200,
                    ],
                    default => [
                        'status' => 404,
                        'msg' => 'not found',
                    ],
                };
            }

            public function requestText(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): string {
                $this->textRequests[] = [
                    'method' => $method,
                    'uri' => $uri,
                    'payload' => $payload,
                    'jwt' => $jwt,
                    'headers' => $headers,
                    'query' => $query,
                ];

                return '<!doctype html><html><body>payment submitted</body></html>';
            }
        };

        $service = new ZjmfRenewService($this->makeZjmfTransport($fakeTransport));
        $result = $service->renewServiceInvoice($this->makeSupplier(), 7788, 'monthly');

        $this->assertSame(8899, $result['upstream_invoice_id']);
        $this->assertSame(200, $result['fund_status']);
        $this->assertFalse($result['payment_completed']);
        $this->assertSame([], $result['host_detail']);

        $this->assertSame('/pay', $fakeTransport->textRequests[0]['uri'] ?? null);
        $this->assertSame([
            'action' => 'billing',
            'pay' => 'true',
        ], $fakeTransport->textRequests[0]['query'] ?? null);
        $checkOrderRequest = collect($fakeTransport->requests)->firstWhere('uri', '/check_order');
        $this->assertSame('POST', $checkOrderRequest['method'] ?? null);
        $this->assertSame(['id' => 8899], $checkOrderRequest['payload'] ?? null);
        $this->assertNull(collect($fakeTransport->requests)->firstWhere('uri', '/host/header'));
    }

    #[Test]
    public function sync_service_statuses_returns_platform_writable_snapshot_payloads(): void
    {
        $fakeTransport = new class extends HostingPanelApiTransport
        {
            public array $batchRequests = [];

            public array $requests = [];

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                $this->requests[] = [
                    'method' => $method,
                    'uri' => $uri,
                    'payload' => $payload,
                    'jwt' => $jwt,
                    'headers' => $headers,
                ];

                return match ($uri) {
                    '/zjmf_api_login' => [
                        'status' => 200,
                        'code' => 200,
                        'jwt' => 'jwt-status-test',
                    ],
                    '/provision/default' => [
                        'status' => 200,
                        'data' => [
                            'status' => 'on',
                            'des' => '开机',
                        ],
                    ],
                    default => [
                        'status' => 404,
                        'msg' => 'not found',
                    ],
                };
            }

            public function parallelGet(Supplier $supplier, array $requests, ?string $jwt = null, array $headers = []): array
            {
                $this->batchRequests = $requests;

                return [
                    'detail_501' => [
                        'status_code' => 200,
                        'response' => [
                            'status' => 200,
                            'data' => [
                                'host_data' => [
                                    'domain' => 'srv501.example.test',
                                    'domainstatus' => 'Active',
                                    'productid' => '9001',
                                    'productname' => 'Zjmf VPS',
                                    'dedicatedip' => '203.0.113.10',
                                    'assignedips' => ['203.0.113.10', '203.0.113.11'],
                                    'config_option' => ['cpu' => 2],
                                    'os' => 'CentOS',
                                    'username' => 'root',
                                    'password' => 'Secret123',
                                    'port' => '22',
                                    'internalip' => '10.0.0.5',
                                    'nextduedate' => 1785600000,
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        $service = new ZjmfStatusService($this->makeZjmfTransport($fakeTransport));
        $result = $service->syncServiceStatuses($this->makeSupplier(), [
            [
                'service_id' => 501,
                'host_id' => 7788,
            ],
        ]);

        $this->assertSame('/host/header', $fakeTransport->batchRequests['detail_501']['uri'] ?? null);
        $this->assertSame(['host_id' => 7788, 'source' => 'API'], $fakeTransport->batchRequests['detail_501']['query'] ?? null);
        $this->assertArrayNotHasKey('runtime_501', $fakeTransport->batchRequests);
        $runtimeRequest = collect($fakeTransport->requests)->firstWhere('uri', '/provision/default');
        $this->assertSame('POST', $runtimeRequest['method'] ?? null);
        $this->assertSame(['id' => 7788, 'func' => 'status'], $runtimeRequest['payload'] ?? null);
        $this->assertSame([
            'content-type: application/x-www-form-urlencoded',
            'Authorization: Bearer jwt-status-test',
        ], $runtimeRequest['headers'] ?? null);
        $this->assertSame('Active', $result['services'][501]['host']['domainstatus'] ?? null);
        $this->assertSame(9001, $result['services'][501]['host']['product_id'] ?? null);
        $this->assertSame(['203.0.113.10', '203.0.113.11'], $result['services'][501]['host']['assignedips'] ?? null);
        $this->assertSame('on', $result['services'][501]['runtime']['status'] ?? null);
        $this->assertSame('开机', $result['services'][501]['runtime']['des'] ?? null);
    }

    private function makeSupplier(): Supplier
    {
        $supplier = new Supplier;
        $supplier->forceFill([
            'id' => 1001,
            'interface_type' => 'zjmf_finance_api',
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
