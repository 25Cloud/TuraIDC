<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfAuthManager;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfCapabilityMemory;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfCompatibilityProfile;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceTransport;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfProductConfigGateway;

/**
 * 商品配置双通道：api/product/prodetail（新老都有）↔ cart/get_product_config（老站），
 * 失败自动换一条，并把走通的那条记下来。
 */
class ZjmfProductConfigGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'zjmf_finance')
        );

        config(['idc.hosting_panel_api.jwt_cache_store' => 'array']);
        // 只清本用例自己的探测记忆，不动全局缓存（Redis 共享时会被其他用例读到）
        (new ZjmfCapabilityMemory)->forget(931, 'product_config');
    }

    private function supplier(array $providerConfig = []): Supplier
    {
        $supplier = (new Supplier)->forceFill([
            'id' => 931,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://zjmf.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
        ]);
        $supplier->setAttribute('provider_config', $providerConfig);

        return $supplier;
    }

    /**
     * @param  array<string, array<string, mixed>|\Closure>  $canned
     */
    private function transport(array $canned, array &$calls): ZjmfFinanceTransport
    {
        $inner = new class($canned, $calls) extends HostingPanelApiTransport
        {
            /** @var array<string, array<string, mixed>|\Closure> */
            private array $canned;

            /** @var array<int, array<string, mixed>> */
            private array $calls;

            public function __construct(array $canned, array &$calls)
            {
                $this->canned = $canned;
                $this->calls = &$calls;
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
                $this->calls[] = compact('method', 'uri', 'payload', 'query');

                if ($method === 'POST' && $uri === '/zjmf_api_login') {
                    return ['status' => 200, 'jwt' => 'zjmf-test-jwt'];
                }

                $handler = $this->canned[$method.' '.$uri] ?? null;
                if ($handler === null) {
                    throw new BusinessException('供应商接口返回异常页面，未解析到有效数据（HTTP 404）');
                }

                return is_callable($handler) ? $handler($method, $uri, $payload, $query) : $handler;
            }

            public function requestWithMeta(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                return [
                    'response' => $this->request($supplier, $method, $uri, $payload, $jwt, $headers, $query),
                    'headers' => [],
                    'http_code' => 200,
                    'content_type' => 'application/json',
                ];
            }

            public function parallelGet(Supplier $supplier, array $requests, ?string $jwt = null, array $headers = []): array
            {
                $responses = [];
                foreach ($requests as $alias => $request) {
                    $this->calls[] = [
                        'method' => 'GET',
                        'uri' => (string) $request['uri'],
                        'payload' => [],
                        'query' => (array) ($request['query'] ?? []),
                    ];
                    $handler = $this->canned['GET '.$request['uri']] ?? null;
                    if ($handler === null) {
                        continue;
                    }
                    $responses[(string) $alias] = [
                        'response' => is_callable($handler) ? $handler('GET', (string) $request['uri'], [], (array) ($request['query'] ?? [])) : $handler,
                    ];
                }

                return $responses;
            }
        };

        return new ZjmfFinanceTransport($inner, new ZjmfAuthManager($inner), null, new ZjmfCapabilityMemory);
    }

    /**
     * 真实 3.8.0 api/product/prodetail 的骨架（只保留我们会读的键）。
     *
     * @return array<string, mixed>
     */
    private function prodetailPayload(int ...$productIds): array
    {
        $detail = [];
        foreach ($productIds as $productId) {
            $detail[(string) $productId] = [
                'id' => $productId,
                'type' => 'dcimcloud',
                'name' => '上游商品 '.$productId,
                'host' => '',
                'stock_control' => 1,
                'qty' => 6,
                'allow_qty' => 0,
                'pay_type' => '{"pay_type":"recurring"}',
                'customfields' => [],
                'product_pricings' => [[
                    'type' => 'product',
                    'relid' => $productId,
                    'monthly' => '5.00',
                    'msetupfee' => '0.00',
                    'code' => 'CNY',
                ]],
                'config_groups' => [[
                    'id' => 94,
                    'name' => '配置',
                    'options' => [[
                        'id' => 10946181,
                        'gid' => 94,
                        'option_name' => 'area|数据中心',
                        'option_type' => 1,
                        'hidden' => 0,
                        'sub' => [[
                            'id' => 41876374,
                            'config_id' => 10946181,
                            'option_name' => '25|CN^镇江^BGP',
                            'pricings' => [['monthly' => '0.00']],
                        ]],
                    ]],
                ]],
                'config_links' => [94],
                'advanced' => [],
            ];
        }

        return ['status' => 200, 'msg' => '请求成功', 'data' => ['detail' => $detail]];
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyCartPayload(int $productId): array
    {
        return [
            'status' => 200,
            'msg' => '请求成功',
            'data' => [
                'products' => ['id' => $productId, 'stock_control' => 0, 'qty' => -1, 'allow_qty' => 0],
                'product_pricings' => [['type' => 'product', 'monthly' => '9.90', 'code' => 'CNY']],
                'config_groups' => [],
                'customfields' => [],
            ],
        ];
    }

    public function test_prodetail_response_is_normalized_into_legacy_envelope(): void
    {
        $calls = [];
        $transport = $this->transport([
            'GET /api/product/prodetail' => $this->prodetailPayload(559),
        ], $calls);

        $gateway = new ZjmfProductConfigGateway($transport);
        $envelope = $gateway->fetch($this->supplier(), 559);

        $this->assertSame(200, $envelope['status']);
        $this->assertSame(559, $envelope['data']['products']['id']);
        $this->assertSame('5.00', $envelope['data']['product_pricings'][0]['monthly']);
        $this->assertSame('area|数据中心', $envelope['data']['config_groups'][0]['options'][0]['option_name']);
        $this->assertSame(94, $envelope['data']['config_links'][0]);

        $prodetailCall = collect($calls)->firstWhere('uri', '/api/product/prodetail');
        $this->assertSame([559], $prodetailCall['query']['pids']);
        // 走通一次之后不再打 cart/get_product_config
        $this->assertNull(collect($calls)->firstWhere('uri', '/cart/get_product_config'));
    }

    public function test_falls_back_to_cart_when_prodetail_is_missing(): void
    {
        $calls = [];
        $transport = $this->transport([
            'GET /cart/get_product_config' => $this->legacyCartPayload(560),
        ], $calls);

        $gateway = new ZjmfProductConfigGateway($transport);
        $supplier = $this->supplier(['version_profile' => 'legacy_376']);
        $envelope = $gateway->fetch($supplier, 560);

        $this->assertSame('9.90', $envelope['data']['product_pricings'][0]['monthly']);

        // 老版本档位先打 cart，命中后不应再浪费一次 prodetail
        $this->assertSame('/cart/get_product_config', collect($calls)->firstWhere('method', 'GET')['uri']);
        $this->assertNull(collect($calls)->firstWhere('uri', '/api/product/prodetail'));
    }

    public function test_remembered_endpoint_is_tried_first_on_next_call(): void
    {
        $calls = [];
        $transport = $this->transport([
            'GET /cart/get_product_config' => $this->legacyCartPayload(561),
        ], $calls);

        $gateway = new ZjmfProductConfigGateway($transport);
        $supplier = $this->supplier();

        // auto 档位先打 prodetail 404，回退 cart 成功并记住 cart
        $first = $gateway->fetch($supplier, 561);
        $this->assertSame(200, $first['status']);
        $this->assertSame(1, collect($calls)->where('uri', '/api/product/prodetail')->count());

        $calls = [];
        $gateway->fetch($supplier, 561);

        $this->assertSame(0, collect($calls)->where('uri', '/api/product/prodetail')->count());
        $this->assertSame(1, collect($calls)->where('uri', '/cart/get_product_config')->count());
    }

    public function test_fetch_many_uses_one_prodetail_request_per_chunk(): void
    {
        $calls = [];
        $transport = $this->transport([
            'GET /api/product/prodetail' => fn (string $method, string $uri, array $payload, array $query) => $this->prodetailPayload(...array_map('intval', (array) ($query['pids'] ?? []))),
        ], $calls);

        $gateway = new ZjmfProductConfigGateway($transport);
        $map = $gateway->fetchMany($this->supplier(), [559, 560, 561]);

        $this->assertSame([559, 560, 561], array_keys($map));
        $this->assertSame(1, collect($calls)->where('uri', '/api/product/prodetail')->count());
        $this->assertSame([559, 560, 561], collect($calls)->firstWhere('uri', '/api/product/prodetail')['query']['pids']);
    }

    public function test_both_endpoints_failing_produces_actionable_error(): void
    {
        $calls = [];
        $gateway = new ZjmfProductConfigGateway($this->transport([], $calls));

        try {
            $gateway->fetch($this->supplier(), 562);
            $this->fail('expected BusinessException');
        } catch (BusinessException $exception) {
            $this->assertStringContainsString('api/product/prodetail', $exception->getMessage());
            $this->assertStringContainsString('cart/get_product_config', $exception->getMessage());
            $this->assertStringContainsString('3.8.0', $exception->getMessage());
        }
    }

    public function test_normalizer_returns_null_for_unknown_product(): void
    {
        $this->assertNull(ZjmfProductConfigGateway::legacyEnvelopeFromProdetail(999, $this->prodetailPayload(559)));
        $this->assertNull(ZjmfProductConfigGateway::legacyEnvelopeFromProdetail(559, ['status' => 400, 'msg' => '商品ID不合法']));
    }

    public function test_legacy_profile_pins_cart_first_even_if_memory_says_otherwise(): void
    {
        $calls = [];
        $transport = $this->transport([
            'GET /api/product/prodetail' => $this->prodetailPayload(563),
        ], $calls);

        $gateway = new ZjmfProductConfigGateway($transport);
        $supplier = $this->supplier(['version_profile' => 'legacy_376']);
        $envelope = $gateway->fetch($supplier, 563);

        // 老站没有 prodetail 也不影响：cart 失败后回退 prodetail 照样拿到配置
        $this->assertSame(563, $envelope['data']['products']['id']);
        $this->assertSame('/cart/get_product_config', collect($calls)->firstWhere('method', 'GET')['uri']);
    }

    public function test_profile_defaults_apply_to_unhydrated_supplier(): void
    {
        $supplier = (new Supplier)->forceFill(['id' => 940, 'api_url' => 'https://zjmf.example.test']);

        $this->assertSame(
            ZjmfCompatibilityProfile::VERSION_AUTO,
            ZjmfCompatibilityProfile::fromSupplier($supplier)->version()
        );
    }
}
