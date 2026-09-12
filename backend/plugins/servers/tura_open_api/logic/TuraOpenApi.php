<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\TuraOpenApi\Logic;

use App\Constants\InvoiceStatus;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Upstream\Contracts\ProvidesBatchStatusSync;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesInvoiceRenewal;
use App\Services\Upstream\Contracts\ProvidesOrderProvisioning;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\ProvidesRenewalRecovery;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use App\Services\Upstream\Contracts\ProvidesSupplierBalance;
use App\Services\Upstream\Contracts\ProvidesSupplierFormSchema;
use App\Services\Upstream\Contracts\UpstreamDriver;
use Illuminate\Support\Facades\Log;
use TuraIDC\Plugins\Servers\TuraOpenApi\Lib\TuraOpenApiClient;

/**
 * TuraIDC 开放接口上游驱动：把上游实例的 /api/v2/open 网关封装成本地供应商能力，
 * 让纯自有协议的 TuraIDC↔TuraIDC 复合对接（无限级转售链）闭环。
 *
 * 开通是异步的：驱动先在上游下单（幂等键 tura-open-provision-{orderId}，队列重试
 * 复用同一键命中上游幂等兜底，不重复扣上游余额）并余额支付，然后轮询上游账单/
 * 服务状态直到 Active。轮询超时直接抛出，由开通队列按 tries 重试继续轮询。
 */
class TuraOpenApi implements ProvidesBatchStatusSync, ProvidesConsoleCatalog, ProvidesConsoleRuntime, ProvidesInvoiceRenewal, ProvidesOrderProvisioning, ProvidesProvisioning, ProvidesRenewal, ProvidesRenewalRecovery, ProvidesStatusSync, ProvidesSupplierBalance, ProvidesSupplierFormSchema, UpstreamDriver
{
    public const KEY = 'tura_open_api';

    public const LABEL = 'TuraIDC 开放接口';

    /** 目录/状态同步分页大小（开放 API 上限 200） */
    private const LIST_PAGE_SIZE = 200;

    private const LIST_MAX_PAGES = 25;

    /** 开通轮询：上游账单映射 + 服务开通，单轮预算约 150s，超出交给队列重试 */
    private const PROVISION_POLL_ATTEMPTS = 50;

    private const PROVISION_POLL_INTERVAL_SECONDS = 3;

    private const CAPABILITIES = [
        ProvidesBatchStatusSync::class,
        ProvidesConsoleCatalog::class,
        ProvidesConsoleRuntime::class,
        ProvidesInvoiceRenewal::class,
        ProvidesOrderProvisioning::class,
        // 基契约必须保留：编排层以 ProvidesProvisioning/ProvidesRenewal/ProvidesStatusSync
        // 作为能力门槛，子接口通过继承满足 instanceof，但 supports() 需要显式列出
        ProvidesProvisioning::class,
        ProvidesRenewal::class,
        ProvidesRenewalRecovery::class,
        ProvidesStatusSync::class,
        ProvidesSupplierBalance::class,
    ];

    public function __construct(
        private readonly ?TuraOpenApiClient $client = null,
        private ?\App\Services\Integrations\Plugins\PluginBindingResolver $bindingResolver = null,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return self::LABEL;
    }

    public function capabilities(): array
    {
        return self::CAPABILITIES;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true)
            && $this instanceof $capability;
    }

    public function resolve(string $capability): ?object
    {
        return $this->supports($capability) ? $this : null;
    }

    public function supplierFormSchema(): array
    {
        return [
            'help' => '对接上游 TuraIDC 实例的开放接口：接口地址填上游站点根地址，API 密钥填在上游「API 密钥」页创建的完整密钥（仅创建时展示一次）。上游商品通过「商品目录同步」导入，转售价格在本地下调后销售。',
            'fields' => [
                [
                    'key' => 'api_url',
                    'label' => '上游站点地址',
                    'type' => 'url',
                    'required' => true,
                    'placeholder' => 'https://upstream.example.com',
                ],
                [
                    'key' => 'api_key',
                    'label' => 'API 密钥（tura_ 开头完整密钥）',
                    'type' => 'password',
                    'required' => true,
                    'secret' => true,
                    'placeholder' => '编辑时留空则保持原密钥',
                    'description' => '需具备 products/orders/services/finance 读写权限，且上游账户余额充足。',
                ],
            ],
        ];
    }

    /**
     * 插件运行时统一入口（管理端连接检测/元数据/能力解析走这里）。
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];

        return match ($action) {
            'server.metadata' => [
                'success' => true,
                'action' => $action,
                'data' => [
                    'key' => $this->key(),
                    'label' => $this->label(),
                    'capabilities' => $this->capabilities(),
                ],
            ],
            'server.supports' => [
                'success' => true,
                'action' => $action,
                'data' => [
                    'supported' => $this->supports((string) ($payload['capability'] ?? '')),
                ],
            ],
            'server.resolve_capability' => [
                'success' => true,
                'action' => $action,
                'data' => [
                    'resolved' => $this->resolve((string) ($payload['capability'] ?? '')),
                ],
            ],
            'server.supplier_form_schema' => [
                'success' => true,
                'action' => $action,
                'data' => $this->supplierFormSchema(),
            ],
            'server.health_check' => [
                'success' => true,
                'action' => $action,
                'data' => [
                    'healthy' => true,
                    'provider_key' => $this->key(),
                    'message' => 'TuraIDC 开放接口插件加载正常',
                ],
            ],
            default => [
                'success' => false,
                'action' => $action,
                'message' => '不支持的上游插件动作',
                'data' => [],
            ],
        };
    }

    /* ---------------------------------- 目录 ---------------------------------- */

    public function getProductCatalog(Supplier $supplier): array
    {
        $list = $this->fetchServiceProductList($supplier);

        $products = [];
        foreach ($list as $item) {
            $products[] = $this->catalogProduct($item);
        }

        return [
            'groups' => [],
            'products' => $products,
        ];
    }

    /**
     * 目录导入不返回价格（开放 API 按密钥账户计价），按选中商品逐周期报价补齐。
     * 本地导入价格以月付报价为基准推导，上游非月付折扣会丢失（见插件 README）。
     *
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<int, int>  $selectedIds
     * @return array<int, array<string, mixed>>
     */
    public function hydrateSelectedPricing(Supplier $supplier, array $products, array $selectedIds): array
    {
        $selected = collect($selectedIds)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->flip();
        if ($selected->isEmpty()) {
            return $products;
        }

        $cycles = ['monthly', 'quarterly', 'semiannually', 'annually'];

        return collect($products)->map(function (array $product) use ($supplier, $selected, $cycles): array {
            $productId = (int) ($product['id'] ?? 0);
            if ($productId <= 0 || ! $selected->has($productId)) {
                return $product;
            }

            $amounts = [];
            foreach ($cycles as $cycle) {
                try {
                    $quote = $this->client()->get($supplier, "/api/v2/open/products/{$productId}/quotes", [
                        'billing_cycle' => $cycle,
                        'quantity' => 1,
                    ]);
                } catch (BusinessException) {
                    // 该周期不可售（如仅一次性商品），跳过
                    continue;
                }

                $amount = trim((string) ($quote['total_amount'] ?? ''));
                if ($amount !== '' && is_numeric($amount)) {
                    $amounts[$cycle] = $amount;
                }
            }

            if ($amounts === []) {
                return $product;
            }

            // 周期探测按 monthly 在前插入，取第一个可用周期作为本地导入的基准价
            $baseCycle = (string) array_key_first($amounts);

            return array_replace($product, [
                'monthly_price' => $amounts[$baseCycle] ?? null,
                'product_price' => $amounts[$baseCycle] ?? null,
                'billingcycle' => $baseCycle,
                'setup_fee' => '0.00',
            ]);
        })->values()->all();
    }

    public function fetchRealConfigOptions(Supplier $supplier, int $productId): array
    {
        // 开放 API 不暴露上游配置项，导入商品不带自定义配置
        return ['product_id' => $productId, 'config_options' => []];
    }

    public function fetchBatchProductConfigOptions(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        $items = [];
        foreach ($productIds as $productId) {
            $items[(int) $productId] = ['product_id' => (int) $productId, 'config_options' => []];
        }

        return $items;
    }

    public function fetchBatchProductStocks(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        $stockById = [];
        foreach ($this->fetchServiceProductList($supplier) as $item) {
            $stockById[(int) ($item['id'] ?? 0)] = (int) ($item['stock'] ?? -1);
        }

        $items = [];
        foreach ($productIds as $productId) {
            $stock = $stockById[(int) $productId] ?? -1;
            $items[(int) $productId] = [
                'stock' => $stock,
                'qty' => $stock,
                'stock_control' => $stock >= 0 ? 1 : 0,
            ];
        }

        return $items;
    }

    public function getProductConfigTemplate(Supplier $supplier, int $productId): array
    {
        return [
            'product_id' => $productId,
            'config_options' => [],
        ];
    }

    public function getProductProvisionConfig(Supplier $supplier, int $productId): array
    {
        return [
            'status' => 200,
            'data' => [
                'id' => $productId,
                'rule' => [],
                'show' => 0,
            ],
        ];
    }

    /* ---------------------------------- 开通 ---------------------------------- */

    public function provisionOrder(Order $order, Supplier $supplier, ?Service $existingService = null): array
    {
        $productId = $this->resolveUpstreamProductId($order);
        $billingCycle = trim((string) $order->billing_cycle);
        $hostname = trim((string) data_get($order->config_snapshot, 'hostname', ''));
        throw_if($billingCycle === '', new BusinessException('本地订单缺少计费周期，无法在上游开通', 42200));

        $idempotencyKey = 'tura-open-provision-'.$order->id;

        // 1) 报价换取 quote_token（金额由上游服务端计价，下游不可篡改）
        $quote = $this->client()->get($supplier, "/api/v2/open/products/{$productId}/quotes", [
            'billing_cycle' => $billingCycle,
            'quantity' => 1,
            'config' => $hostname !== '' ? ['hostname' => $hostname] : [],
        ]);
        $quoteToken = trim((string) ($quote['quote_token'] ?? ''));
        throw_if($quoteToken === '', new BusinessException('上游未返回报价凭证，无法下单', 42200));

        // 2) 下单（幂等键固定：队列重试重放命中上游幂等兜底，返回同一账单）
        $orderPayload = [
            'product_id' => $productId,
            'billing_cycle' => $billingCycle,
            'quantity' => 1,
            'config' => $hostname !== '' ? ['hostname' => $hostname] : [],
            'quote_token' => $quoteToken,
            'idempotency_key' => $idempotencyKey,
        ];
        $invoice = $this->client()->post($supplier, '/api/v2/open/orders', $orderPayload);
        $invoiceId = (int) ($invoice['id'] ?? 0);
        throw_if($invoiceId <= 0, new BusinessException('上游未返回账单标识，无法继续开通', 42200));

        // 3) 余额支付（重试时账单可能已支付，跳过）
        if ((int) ($invoice['status'] ?? 0) !== InvoiceStatus::PAID) {
            $this->client()->post($supplier, "/api/v2/open/orders/{$invoiceId}/pay");
        }

        // 4) 轮询上游账单直到已支付且映射出服务实例（上游履约是异步的）
        $serviceId = 0;
        for ($attempt = 0; $attempt < self::PROVISION_POLL_ATTEMPTS; $attempt++) {
            $remoteInvoice = $this->client()->get($supplier, "/api/v2/open/orders/{$invoiceId}");
            if ((int) ($remoteInvoice['status'] ?? 0) === InvoiceStatus::PAID) {
                $serviceId = (int) ($remoteInvoice['service_id'] ?? 0);
                if ($serviceId > 0) {
                    break;
                }
            }

            sleep(self::PROVISION_POLL_INTERVAL_SECONDS);
        }
        throw_if($serviceId <= 0, new BusinessException('上游开通仍在进行，等待下次重试继续轮询', 42200));

        // 5) 轮询上游服务直到 Active
        $serviceDetail = [];
        for ($attempt = 0; $attempt < self::PROVISION_POLL_ATTEMPTS; $attempt++) {
            $serviceDetail = $this->client()->get($supplier, "/api/v2/open/services/{$serviceId}");
            if ((int) ($serviceDetail['status'] ?? 0) === ServiceStatus::ACTIVE) {
                break;
            }

            sleep(self::PROVISION_POLL_INTERVAL_SECONDS);
        }
        if ((int) ($serviceDetail['status'] ?? 0) !== ServiceStatus::ACTIVE) {
            Log::warning('[TuraIDC 开放接口] 上游服务尚未 Active，交由状态同步收敛', [
                'order_id' => (int) $order->id,
                'upstream_invoice_id' => $invoiceId,
                'upstream_service_id' => $serviceId,
            ]);
        }

        return [
            'requested_host' => $hostname,
            'upstream_invoice_id' => $invoiceId,
            'upstream_host_ids' => [$serviceId],
            'upstream_host_id' => $serviceId,
            'host_detail' => $this->hostDetailFromService($serviceDetail),
        ];
    }

    /* ---------------------------------- 续费 ---------------------------------- */

    public function renewServiceInvoice(Supplier $supplier, int $hostId, string $billingCycle): array
    {
        $response = $this->client()->post($supplier, "/api/v2/open/services/{$hostId}/renew", [
            'billing_cycle' => trim($billingCycle),
        ]);

        $invoiceId = (int) ($response['invoice_id'] ?? 0);
        $paid = (bool) ($response['paid'] ?? false);
        throw_if(! $paid, new BusinessException('上游续费账单未支付完成，请检查供应商余额', 42200));

        return [
            'upstream_invoice_id' => $invoiceId,
            'upstream_amount' => (string) ($response['amount'] ?? ''),
            'payment_completed' => true,
            'host_detail' => $this->hostDetailFromService(
                $this->client()->get($supplier, "/api/v2/open/services/{$hostId}")
            ),
        ];
    }

    public function recoverRenewInvoice(Supplier $supplier, int $hostId, int $upstreamInvoiceId): ?array
    {
        if ($upstreamInvoiceId <= 0) {
            return null;
        }

        $invoice = $this->client()->get($supplier, "/api/v2/open/orders/{$upstreamInvoiceId}");
        $status = (int) ($invoice['status'] ?? 0);

        // 上游续费接口是「建单 + 余额支付」一步完成；若响应丢失时账单尚未支付
        // （极端窗口），这里补一次余额支付再确认，支付是幂等安全的（已支付会报错但状态不变）。
        if ($status !== InvoiceStatus::PAID) {
            try {
                $this->client()->post($supplier, "/api/v2/open/orders/{$upstreamInvoiceId}/pay");
                $invoice = $this->client()->get($supplier, "/api/v2/open/orders/{$upstreamInvoiceId}");
                $status = (int) ($invoice['status'] ?? 0);
            } catch (BusinessException $exception) {
                Log::warning('[TuraIDC 开放接口] 续费恢复补支付失败', [
                    'upstream_invoice_id' => $upstreamInvoiceId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($status !== InvoiceStatus::PAID) {
            return [
                'payment_completed' => false,
                'fund_error' => '上游续费账单尚未支付完成，请检查供应商余额',
            ];
        }

        return [
            'payment_completed' => true,
            'host_detail' => $this->hostDetailFromService(
                $this->client()->get($supplier, "/api/v2/open/services/{$hostId}")
            ),
        ];
    }

    /* --------------------------------- 状态同步 -------------------------------- */

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function syncServiceStatuses(Supplier $supplier, array $items, int $chunkSize = 10): array
    {
        // 服务列表是轻量端点（分页拉取），逐台详情是控制台投影且带缓存副作用，不适合批量同步
        $upstreamServices = [];
        foreach ($this->pageThroughServiceList($supplier) as $item) {
            $upstreamServices[(int) ($item['id'] ?? 0)] = $item;
        }

        $services = [];
        foreach ($items as $item) {
            $serviceId = (int) ($item['service_id'] ?? 0);
            $hostId = (int) ($item['upstream_host_id'] ?? $item['host_id'] ?? 0);
            if ($serviceId <= 0 || $hostId <= 0) {
                continue;
            }

            $remote = $upstreamServices[$hostId] ?? null;
            if ($remote === null) {
                $services[$serviceId] = ['error' => '上游不存在该实例，可能已被删除'];

                continue;
            }

            $services[$serviceId] = [
                'host' => $this->hostPayloadFromListItem($remote),
                'runtime' => [],
            ];
        }

        return [
            'jwt' => 'tura-open:'.$this->supplierFingerprint($supplier),
            'services' => $services,
        ];
    }

    /* ---------------------------------- 余额 ---------------------------------- */

    public function getBalance(Supplier $supplier): array
    {
        $data = $this->client()->get($supplier, '/api/v2/open/balance');

        return [
            'balance' => (string) ($data['balance'] ?? ''),
            'currency' => (string) ($data['currency'] ?? 'CNY'),
        ];
    }

    /* -------------------------------- 控制台运行时 ------------------------------ */

    public function login(Supplier $supplier): string
    {
        // Bearer API 密钥随请求携带，无需会话令牌；这里做一次凭据存在性校验
        if (trim((string) $supplier->getAttribute('api_key')) === '') {
            throw new BusinessException('上游开放接口 API 密钥未配置，请先补全供应商凭据', 42200);
        }

        return 'tura-open:'.md5((string) $supplier->id);
    }

    public function refreshJwt(Supplier $supplier): string
    {
        return $this->login($supplier);
    }

    public function getHostDetail(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $detail = $this->client()->get($supplier, "/api/v2/open/services/{$hostId}");

        return [
            'status' => 200,
            'data' => [
                'host' => $this->hostPayloadFromDetail($detail),
            ],
        ];
    }

    public function powerAction(Supplier $supplier, int $hostId, string $action, ?string $jwt = null): array
    {
        $this->client()->post($supplier, "/api/v2/open/services/{$hostId}/power", [
            'action' => trim($action),
        ]);

        return [
            'status' => 200,
            'msg' => '电源指令已提交上游',
        ];
    }

    public function getModuleStatus(Supplier $supplier, int $hostId, string $type = 'host', ?string $jwt = null): array
    {
        // 开放 API 不提供模块级状态（重装进度/电源状态），返回空载荷由控制台按未知处理
        return [
            'status' => 200,
            'data' => [
                'host_id' => $hostId,
                'type' => $type,
                'power_state' => '',
            ],
        ];
    }

    public function getReinstallOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $data = $this->client()->get($supplier, "/api/v2/open/services/{$hostId}/reinstall-options");

        return [
            'status' => 200,
            'data' => [
                'os' => is_array($data['os'] ?? null) ? $data['os'] : [],
                'os_group' => is_array($data['os_groups'] ?? null) ? $data['os_groups'] : [],
            ],
        ];
    }

    public function reinstall(Supplier $supplier, int $hostId, string $osId, ?string $jwt = null): array
    {
        $this->client()->post($supplier, "/api/v2/open/services/{$hostId}/reinstall", [
            'os_id' => trim($osId),
        ]);

        return [
            'status' => 200,
            'msg' => '重装指令已提交上游',
        ];
    }

    public function getSupportedModules(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        // 开放 API 链路不支持自定义模块透传
        return [
            'status' => 200,
            'data' => [
                'modules' => [],
            ],
        ];
    }

    public function get(Supplier $supplier, string $uri, ?string $jwt = null, array $query = [], array $headers = []): array
    {
        throw new BusinessException('当前上游链路不支持该操作（TuraIDC 开放接口为高层协议，不走通用 REST）', 42200);
    }

    public function put(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
    {
        throw new BusinessException('当前上游链路不支持该操作（TuraIDC 开放接口为高层协议，不走通用 REST）', 42200);
    }

    /* --------------------------------- 内部辅助 -------------------------------- */

    private function client(): TuraOpenApiClient
    {
        return $this->client ?? new TuraOpenApiClient;
    }

    private function resolveUpstreamProductId(Order $order): int
    {
        // 上游商品 ID 的真源是 product_upstream_bindings（商品表无该列）
        $product = $order->product;
        $upstreamProductId = (int) ($this->bindingResolver()
            ->upstreamProductIdForProduct($product) ?? 0);
        throw_if(
            $upstreamProductId <= 0 || ! $product instanceof Product,
            new BusinessException('商品未绑定上游开放接口商品，无法自动开通', 42200)
        );

        return $upstreamProductId;
    }

    private function bindingResolver(): \App\Services\Integrations\Plugins\PluginBindingResolver
    {
        return $this->bindingResolver ??= app(\App\Services\Integrations\Plugins\PluginBindingResolver::class);
    }

    /**
     * 拉取上游在售商品全量列表（分页）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchServiceProductList(Supplier $supplier): array
    {
        $items = [];
        foreach ($this->pageThroughList($supplier, '/api/v2/open/products') as $item) {
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pageThroughServiceList(Supplier $supplier): array
    {
        return $this->pageThroughList($supplier, '/api/v2/open/services');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pageThroughList(Supplier $supplier, string $uri): array
    {
        $items = [];
        for ($page = 1; $page <= self::LIST_MAX_PAGES; $page++) {
            $payload = $this->client()->get($supplier, $uri, [
                'page' => $page,
                'page_size' => self::LIST_PAGE_SIZE,
            ]);

            $list = is_array($payload['list'] ?? null) ? $payload['list'] : [];
            foreach ($list as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            $total = (int) ($payload['total'] ?? 0);
            if (count($items) >= $total || $list === []) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function catalogProduct(array $item): array
    {
        $stock = (int) ($item['stock'] ?? -1);

        return [
            'id' => (int) ($item['id'] ?? 0),
            'name' => trim((string) ($item['name'] ?? '')),
            'type' => trim((string) ($item['product_type'] ?? 'server')),
            'type_label' => 'TuraIDC 转售商品',
            'description' => '来自上游 TuraIDC 开放接口的转售商品。',
            'billingcycle' => 'monthly',
            'product_price' => null,
            'monthly_price' => null,
            'setup_fee' => '0.00',
            'allow_qty' => 1,
            'stock_control' => $stock >= 0 ? 1 : 0,
            'qty' => $stock,
            'stock' => $stock,
            'first_group_name' => '',
            'group_name' => 'TuraIDC 转售',
        ];
    }

    /**
     * 上游服务详情（GET /services/{id}）→ 主机载荷。
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function hostDetailFromService(array $detail): array
    {
        return [
            'domain' => trim((string) ($detail['domain'] ?? ($detail['hostname'] ?? ''))),
            'domainstatus' => $this->domainStatusFromServiceStatus((int) ($detail['status'] ?? 0)),
            'nextduedate' => trim((string) ($detail['expires_at'] ?? '')),
            'product_name' => trim((string) ($detail['display_name'] ?? '')),
            'connection' => is_array($detail['connection'] ?? null) ? $detail['connection'] : [],
        ];
    }

    /**
     * 上游服务列表项 → 状态同步主机载荷（列表不含 IP/凭据，已由开通时快照缓存）。
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function hostPayloadFromListItem(array $item): array
    {
        return [
            'domainstatus' => $this->domainStatusFromServiceStatus((int) ($item['status'] ?? 0)),
            'domain' => trim((string) ($item['name'] ?? '')),
            'product_name' => trim((string) ($item['product_name'] ?? '')),
            'nextduedate' => trim((string) ($item['expires_at'] ?? '')),
        ];
    }

    /**
     * 上游服务状态（本地 ServiceStatus 常量）→ 通用主机 domainstatus 文本，
     * 与状态同步的 resolveServiceStatusFromUpstream 语义对齐。
     */
    private function domainStatusFromServiceStatus(int $status): string
    {
        return match ($status) {
            ServiceStatus::ACTIVE => 'Active',
            ServiceStatus::SUSPENDED => 'Suspended',
            ServiceStatus::EXPIRED => 'Expired',
            ServiceStatus::CANCELLED => 'Cancelled',
            default => 'Pending',
        };
    }

    private function supplierFingerprint(Supplier $supplier): string
    {
        return substr(md5((string) $supplier->id), 0, 8);
    }
}
