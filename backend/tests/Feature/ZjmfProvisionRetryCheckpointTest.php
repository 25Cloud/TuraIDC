<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Provisioning\ProvisionService;
use App\Services\System\SettingService;
use App\Services\Upstream\Contracts\ProvidesOrderProvisioning;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfAuthManager;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceTransport;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfProvisionService;

/**
 * ZJMF 开通幂等回查 / 开通检查点跨失败重试的回归（真实插件链路）。
 *
 * 事故形状（与上游 caiwu 2975d32e 同一个坑）：
 * 1. `/v1/invoices/{id}` 的账单支付状态在 `data.invoices.status`，顶层 `data.status` 是业务码 200。
 *    按顶层读会恒得到 "200"，已付账单被当成未付 → 重试又去 `/fund` 补付 →
 *    上游回 400「Bill paid」→ 幂等回查直接失败，充值后也永远开不出来。
 * 2. 账单查询/fund 用裸 `get()/post()` 会丢 HTTP 状态（http_code=0 恒判失败；
 *    反过来 5xx + 业务码看似正常又会被误判成支付成功）。
 * 3. 结算成功后上游已经生成订单/账单/host，付款失败（供应商余额不足）时若不先落检查点，
 *    重试就只剩主机名可依，账单与实例标识双双丢失，只能重开一单。
 *
 * 上游一律走假 transport，不发真实请求。
 */
class ZjmfProvisionRetryCheckpointTest extends TestCase
{
    use DatabaseTransactions;

    private const UPSTREAM_INVOICE_ID = 77;

    private const UPSTREAM_HOST_ID = 601;

    protected function setUp(): void
    {
        parent::setUp();

        config(['idc.hosting_panel_api.jwt_cache_store' => 'array']);
        Cache::flush();

        // 插件类由运行时的 PluginFileLoader 按 require 加载，Feature 测试里先手动载入
        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'zjmf_finance')
        );
    }

    public function test_paid_upstream_invoice_skips_fund_during_idempotent_recheck(): void
    {
        // 账单已付：幂等回查只允许确认既有实例，绝不能再次 /fund（否则上游回 400 Bill paid）
        [$order, $supplier, $service, $hostname] = $this->makeBoundContext(withCheckpoint: true);

        $upstream = $this->makeUpstreamFake($hostname);
        $upstream->invoiceStatus = 'Paid';

        $result = $this->makeProvisionService($upstream, $supplier)->provisionOrder($order, $supplier, $service->fresh());

        $uris = array_column($upstream->metaCalls, 'uri');

        $this->assertContains('/v1/invoices/'.self::UPSTREAM_INVOICE_ID, $uris);
        $this->assertNotContains('/v1/invoices/'.self::UPSTREAM_INVOICE_ID.'/fund', $uris, '账单已付时不得再调补付接口');
        $this->assertNotContains('/cart/settle', $uris, '已有实例时不得重新结算');
        $this->assertNotContains('/cart/add_to_shop', $uris, '已有实例时不得重新加购');
        $this->assertSame(self::UPSTREAM_HOST_ID, (int) $result['upstream_host_id']);
    }

    public function test_unpaid_upstream_invoice_is_funded_once_during_idempotent_recheck(): void
    {
        // 账单未付（上一轮付款失败）：允许补付一次，且不得重新结算
        [$order, $supplier, $service, $hostname] = $this->makeBoundContext(withCheckpoint: true);

        $upstream = $this->makeUpstreamFake($hostname);
        $upstream->invoiceStatus = 'Unpaid';

        $result = $this->makeProvisionService($upstream, $supplier)->provisionOrder($order, $supplier, $service->fresh());

        $fundCalls = array_values(array_filter(
            array_column($upstream->metaCalls, 'uri'),
            static fn (string $uri): bool => $uri === '/v1/invoices/'.self::UPSTREAM_INVOICE_ID.'/fund'
        ));

        $this->assertCount(1, $fundCalls, '账单未付时应补付一次');
        $this->assertNotContains('/cart/settle', array_column($upstream->metaCalls, 'uri'));
        $this->assertSame(self::UPSTREAM_HOST_ID, (int) $result['upstream_host_id']);
    }

    public function test_retry_after_insufficient_balance_never_provisions_a_second_host(): void
    {
        // 首次开通：结算成功（上游已建单）但供应商余额不足
        [$order, $supplier, $service, $hostname] = $this->makeBoundContext(withCheckpoint: false);

        $upstream = $this->makeUpstreamFake($hostname);
        $upstream->creditShortage = true;
        $upstream->invoiceStatus = 'Unpaid';

        try {
            $this->makeProvisionService($upstream, $supplier)->provisionOrder($order, $supplier, $service);
            $this->fail('供应商余额不足必须失败');
        } catch (BusinessException) {
        }

        $afterFailure = (array) ($service->fresh()->provision_data ?? []);
        $this->assertSame(self::UPSTREAM_INVOICE_ID, (int) ($afterFailure['upstream_invoice_id'] ?? 0), '失败后账单标识必须落库');
        $this->assertSame(self::UPSTREAM_HOST_ID, (int) ($afterFailure['upstream_host_id'] ?? 0), '失败后实例标识必须落库');

        // 充值后重试：检查点完整，必须走幂等回查确认既有实例并补付账单，不得重新开通
        $upstream->metaCalls = [];
        $upstream->creditShortage = false;

        $result = $this->makeProvisionService($upstream, $supplier)->provisionOrder($order, $supplier, $service->fresh());

        $uris = array_column($upstream->metaCalls, 'uri');
        $this->assertNotContains('/cart/add_to_shop', $uris, '不得重新加购');
        $this->assertNotContains('/cart/settle', $uris, '不得重新结算，否则会开出第二台主机');
        $this->assertContains('/v1/invoices/'.self::UPSTREAM_INVOICE_ID.'/fund', $uris);
        $this->assertSame(self::UPSTREAM_HOST_ID, (int) $result['upstream_host_id']);
        $this->assertSame(
            (int) ($service->fresh()->provision_data['upstream_invoice_id'] ?? 0),
            self::UPSTREAM_INVOICE_ID,
            '重试后账单标识不得丢失',
        );
    }

    public function test_upstream_5xx_on_invoice_query_is_treated_as_failure(): void
    {
        // 业务码/账单状态都看着正常，但 HTTP 502：不能判成功
        [$order, $supplier, $service, $hostname] = $this->makeBoundContext(withCheckpoint: true);

        $upstream = $this->makeUpstreamFake($hostname);
        $upstream->invoiceStatus = 'Paid';
        $upstream->invoiceHttpCode = 502;

        try {
            $this->makeProvisionService($upstream, $supplier)->provisionOrder($order, $supplier, $service->fresh());
            $this->fail('账单查询 HTTP 502 必须判失败');
        } catch (BusinessException $exception) {
            $this->assertStringContainsString('无法确认已创建的上游实例状态', $exception->getMessage());
        }

        $this->assertNotContains('/v1/invoices/'.self::UPSTREAM_INVOICE_ID.'/fund', array_column($upstream->metaCalls, 'uri'));
    }

    public function test_upstream_5xx_on_fund_is_treated_as_failure(): void
    {
        // 补付接口回 502 但业务码是 1001：不得当成已支付
        [$order, $supplier, $service, $hostname] = $this->makeBoundContext(withCheckpoint: true);

        $upstream = $this->makeUpstreamFake($hostname);
        $upstream->invoiceStatus = 'Unpaid';
        $upstream->fundHttpCode = 502;

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('无法确认已创建的上游实例状态');

        $this->makeProvisionService($upstream, $supplier)->provisionOrder($order, $supplier, $service->fresh());
    }

    public function test_failure_write_back_keeps_driver_checkpoint(): void
    {
        // 核心失败回写不得用调用方旧快照覆盖驱动已落库的检查点。
        // 注意：downstream_token 之类的回调密钥会被 PluginBindingResolver::sanitizeServiceProvisionData
        // 有意剔除，这里只钉「账单标识 + 实例标识」——那正是本次缺陷丢掉的落库项。
        [$order, $supplier, $service] = $this->makeBoundContext(withCheckpoint: false);

        $driver = new class implements ProvidesOrderProvisioning, UpstreamDriver
        {
            /** @var array<string, mixed> 最后一次 provisionOrder 收到的既有实例数据 */
            public array $seenProvisionData = [];

            public function key(): string
            {
                return 'fake_zjmf_checkpoint';
            }

            public function label(): string
            {
                return 'ZJMF 检查点测试驱动';
            }

            public function capabilities(): array
            {
                return [ProvidesProvisioning::class, ProvidesOrderProvisioning::class];
            }

            public function supports(string $capability): bool
            {
                return $capability === ProvidesProvisioning::class
                    || $capability === ProvidesOrderProvisioning::class;
            }

            public function resolve(string $capability): ?object
            {
                return $this->supports($capability) ? $this : null;
            }

            public function provisionOrder(Order $order, Supplier $supplier, ?Service $existingService = null): array
            {
                $this->seenProvisionData = (array) ($existingService?->provision_data ?? []);

                // 模拟驱动：上游结算成功即自行落库检查点（真实插件就是这么做的）
                $fresh = $existingService?->fresh();
                if ($fresh instanceof Service) {
                    $fresh->forceFill([
                        'provision_data' => array_merge((array) ($fresh->provision_data ?? []), [
                            'upstream_invoice_id' => 77,
                            'upstream_host_id' => 601,
                            'upstream_host_ids' => [601],
                        ]),
                    ])->save();
                }

                // 付款失败（供应商余额不足）
                throw new BusinessException('余额不足：账单需35.00，现余额0.00');
            }
        };

        $bindingResolver = $this->makeBindingResolver($supplier);
        $provisionService = new ProvisionService(
            new ProviderResolver(new ProviderRegistry([$driver]), $bindingResolver),
            app(SettingService::class),
            $bindingResolver,
        );

        try {
            $provisionService->retryFailedProvision($order->fresh());
            $this->fail('余额不足必须失败');
        } catch (BusinessException) {
        }

        $afterFailure = (array) ($service->fresh()->provision_data ?? []);
        $this->assertSame(77, (int) ($afterFailure['upstream_invoice_id'] ?? 0), '失败回写不得抹掉账单标识');
        $this->assertSame(601, (int) ($afterFailure['upstream_host_id'] ?? 0), '失败回写不得抹掉实例标识');
        $this->assertNotSame('', (string) ($afterFailure['provision_error'] ?? ''), '失败原因仍要落库');
    }

    public function test_retry_reuses_existing_host_when_checkpoint_already_persisted(): void
    {
        // 检查点已在库时，重试入口不得清掉账单/实例标识（否则会重新下单）
        [$order, $supplier, $service] = $this->makeBoundContext(withCheckpoint: true);

        $driver = new class implements ProvidesOrderProvisioning, UpstreamDriver
        {
            public bool $called = false;

            public array $seenProvisionData = [];

            public function key(): string
            {
                return 'fake_zjmf_checkpoint';
            }

            public function label(): string
            {
                return 'ZJMF 检查点测试驱动';
            }

            public function capabilities(): array
            {
                return [ProvidesProvisioning::class, ProvidesOrderProvisioning::class];
            }

            public function supports(string $capability): bool
            {
                return $capability === ProvidesProvisioning::class
                    || $capability === ProvidesOrderProvisioning::class;
            }

            public function resolve(string $capability): ?object
            {
                return $this->supports($capability) ? $this : null;
            }

            public function provisionOrder(Order $order, Supplier $supplier, ?Service $existingService = null): array
            {
                $this->called = true;
                $this->seenProvisionData = (array) ($existingService?->provision_data ?? []);

                return [
                    'upstream_invoice_id' => (int) ($this->seenProvisionData['upstream_invoice_id'] ?? 0),
                    'upstream_host_id' => (int) ($this->seenProvisionData['upstream_host_id'] ?? 0),
                    'upstream_host_ids' => (array) ($this->seenProvisionData['upstream_host_ids'] ?? []),
                    'requested_host' => (string) ($this->seenProvisionData['requested_host'] ?? ''),
                    'host_detail' => ['id' => 601, 'domainstatus' => 'Active'],
                ];
            }
        };

        $bindingResolver = $this->makeBindingResolver($supplier);
        $provisionService = new ProvisionService(
            new ProviderResolver(new ProviderRegistry([$driver]), $bindingResolver),
            app(SettingService::class),
            $bindingResolver,
        );

        $result = $provisionService->retryFailedProvision($order->fresh());

        $this->assertTrue($driver->called);
        $this->assertSame(77, (int) ($driver->seenProvisionData['upstream_invoice_id'] ?? 0), '重试入口不得清掉账单标识');
        $this->assertSame(601, (int) ($driver->seenProvisionData['upstream_host_id'] ?? 0), '重试入口不得清掉实例标识');
        $this->assertSame(601, (int) ($result->provision_data['upstream_host_id'] ?? 0));
        $this->assertSame((string) $service->order_id, (string) $result->order_id);
    }

    /**
     * 造一套「已付款订单 + 待开通服务」。
     *
     * @param  array<string, mixed>  $extraProvisionData
     * @return array{0: Order, 1: Supplier, 2: Service, 3: string}
     */
    private function makeBoundContext(bool $withCheckpoint, array $extraProvisionData = []): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $hostname = 'ckpt-'.strtolower($suffix).'.example.test';

        $user = User::factory()->create();

        $supplier = Supplier::query()->create([
            'name' => 'ZJMF 检查点测试供应商 '.$suffix,
            'code' => 'SUPCK'.$suffix,
            'status' => 1,
        ]);

        // suppliers 表没有 api_url/api_username/api_key 列：生产上是由绑定解析器
        // 把凭据解密注入模型属性的。夹具用同样的形态（内存模型带 id）喂给插件。
        $supplierForCalls = (new Supplier)->forceFill([
            'id' => (int) $supplier->id,
            'name' => (string) $supplier->name,
            'code' => (string) $supplier->code,
            'status' => 1,
            'api_url' => 'https://upstream.example.test',
            'api_username' => 'ckpt-account',
            'api_key' => 'ckpt-key',
        ]);

        $product = Product::query()->create([
            'product_type' => 'cloud_server',
            'custom_display_name' => 'ZJMF 检查点测试商品 '.$suffix,
            'purchase_requires' => [],
            'pricing' => ['monthly' => '35.00'],
            'config_options' => [],
            'auto_setup' => 1,
            'status' => 1,
        ]);

        $order = Order::query()->create([
            'order_no' => 'ZCK'.$suffix,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'type' => 'new',
            'billing_cycle' => 'monthly',
            'amount' => '35.00',
            'paid_amount' => '35.00',
            'quantity' => 1,
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
            'config_snapshot' => ['hostname' => $hostname],
        ]);

        $provisionData = $extraProvisionData;
        if ($withCheckpoint) {
            $provisionData = array_merge($provisionData, [
                'upstream_invoice_id' => self::UPSTREAM_INVOICE_ID,
                'upstream_host_id' => self::UPSTREAM_HOST_ID,
                'upstream_host_ids' => [self::UPSTREAM_HOST_ID],
                'requested_host' => $hostname,
            ]);
        }

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'order_id' => (int) $order->id,
            'name' => 'ZJMF 检查点测试服务 '.$suffix,
            'domain' => $hostname,
            'billing_cycle' => 'monthly',
            'amount' => '35.00',
            'status' => ServiceStatus::PENDING,
            'provision_data' => $provisionData,
        ]);

        $order->forceFill(['service_id' => (int) $service->id])->save();
        $order->setRelation('product', $product);

        return [$order->fresh(), $supplierForCalls, $service, $hostname];
    }

    private function makeBindingResolver(Supplier $supplier): PluginBindingResolver
    {
        return new class($supplier) extends PluginBindingResolver
        {
            public function __construct(
                private readonly Supplier $fakeSupplier,
            ) {}

            public function providerKeyForProduct(Product $product): ?string
            {
                return 'fake_zjmf_checkpoint';
            }

            public function providerKeyForSupplier(Supplier $supplier): ?string
            {
                return 'fake_zjmf_checkpoint';
            }

            public function supplierForProduct(Product $product): ?Supplier
            {
                return $this->fakeSupplier;
            }

            public function supplierIdForProduct(Product $product): ?int
            {
                return (int) $this->fakeSupplier->id;
            }

            public function supplierWithRuntimeCredentials(Supplier $supplier, bool $includeSecrets = true, ?string $providerKey = null): Supplier
            {
                return $this->fakeSupplier;
            }

            public function upstreamProductIdForProduct(Product $product): ?string
            {
                return '11';
            }

            public function upstreamServiceIdForService(Service $service): ?string
            {
                return null;
            }
        };
    }

    /**
     * 假上游：只回答开通链路会问到的端点，其余一律 404。
     */
    private function makeUpstreamFake(string $hostname): HostingPanelApiTransport
    {
        return new class($hostname) extends HostingPanelApiTransport
        {
            /** @var array<int, array<string, mixed>> */
            public array $metaCalls = [];

            public string $invoiceStatus = 'Paid';

            public int $invoiceHttpCode = 200;

            public int $fundHttpCode = 200;

            public bool $creditShortage = false;

            public function __construct(private readonly string $hostname) {}

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                if ($uri === '/zjmf_api_login') {
                    return ['status' => 200, 'msg' => 'ok', 'jwt' => 'jwt-checkpoint-test'];
                }

                return ['status' => 404, 'msg' => 'unexpected request: '.$uri];
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
                $this->metaCalls[] = ['method' => $method, 'uri' => $uri, 'payload' => $payload, 'query' => $query];

                // 与上游真实响应同形：账单状态在 data.invoices.status，顶层 status 是业务码
                if ($uri === '/v1/invoices/77') {
                    return [
                        'response' => [
                            'status' => 200,
                            'msg' => 'ok',
                            'data' => ['invoices' => ['status' => $this->invoiceStatus]],
                        ],
                        'headers' => [],
                        'http_code' => $this->invoiceHttpCode,
                        'content_type' => 'application/json',
                    ];
                }

                if ($uri === '/v1/invoices/77/fund') {
                    return [
                        'response' => ['status' => 1001, 'msg' => '支付完成', 'data' => ['hostid' => [601]]],
                        'headers' => [],
                        'http_code' => $this->fundHttpCode,
                        'content_type' => 'application/json',
                    ];
                }

                if ($uri === '/apply_credit' && $this->creditShortage) {
                    return [
                        'response' => [
                            'status' => 400,
                            'msg' => '余额不足：账单需35.00，现余额0.00,上游账单#77完成支付后即可开通',
                        ],
                        'headers' => [],
                        'http_code' => 200,
                        'content_type' => 'application/json',
                    ];
                }

                return match ($uri) {
                    '/host/header' => [
                        'response' => [
                            'status' => 200,
                            'msg' => 'ok',
                            'data' => ['host_data' => [
                                'id' => 601,
                                'domain' => $this->hostname,
                                'domainstatus' => 'Active',
                                'productid' => 11,
                            ]],
                        ],
                        'headers' => [],
                        'http_code' => 200,
                        'content_type' => 'application/json',
                    ],
                    '/cart/get_product_config' => [
                        'response' => [
                            'status' => 200,
                            'msg' => 'ok',
                            'data' => ['product_pricings' => [['relid' => 11, 'currency' => 1]]],
                        ],
                        'headers' => [],
                        'http_code' => 200,
                        'content_type' => 'application/json',
                    ],
                    '/cart/add_to_shop' => [
                        'response' => ['status' => 200, 'msg' => 'ADD SUCCESS', 'data' => []],
                        'headers' => [],
                        'http_code' => 200,
                        'content_type' => 'application/json',
                    ],
                    '/cart/settle' => [
                        'response' => [
                            'status' => 200,
                            'msg' => 'ok',
                            'data' => ['invoiceid' => 77, 'hostid' => [601]],
                        ],
                        'headers' => [],
                        'http_code' => 200,
                        'content_type' => 'application/json',
                    ],
                    '/apply_credit' => [
                        'response' => ['status' => 1001, 'msg' => '支付完成', 'data' => ['hostid' => [601]]],
                        'headers' => [],
                        'http_code' => 200,
                        'content_type' => 'application/json',
                    ],
                    '/cart/clear' => [
                        'response' => ['status' => 200, 'msg' => 'SUCCESS MESSAGE'],
                        'headers' => [],
                        'http_code' => 200,
                        'content_type' => 'application/json',
                    ],
                    '/host/list' => [
                        'response' => ['status' => 200, 'msg' => 'ok', 'data' => ['list' => []]],
                        'headers' => [],
                        'http_code' => 200,
                        'content_type' => 'application/json',
                    ],
                    default => [
                        'response' => ['status' => 404, 'msg' => 'unexpected meta: '.$uri],
                        'headers' => [],
                        'http_code' => 404,
                        'content_type' => 'application/json',
                    ],
                };
            }
        };
    }

    private function makeProvisionService(HostingPanelApiTransport $upstream, Supplier $supplier): ZjmfProvisionService
    {
        $transport = new ZjmfFinanceTransport($upstream, new ZjmfAuthManager($upstream));

        return new ZjmfProvisionService($transport, $this->makeBindingResolver($supplier));
    }
}
