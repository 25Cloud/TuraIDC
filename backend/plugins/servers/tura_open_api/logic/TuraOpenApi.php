<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\TuraOpenApi\Logic;

use App\Constants\InvoiceStatus;
use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\ProductCatalog\ProductCatalogService;
use App\Services\Upstream\Contracts\ProvidesBatchStatusSync;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesInvoiceRenewal;
use App\Services\Upstream\Contracts\ProvidesOrderProvisioning;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesRenewableCycleFiltering;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\ProvidesRenewalRecovery;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use App\Services\Upstream\Contracts\ProvidesSupplierBalance;
use App\Services\Upstream\Contracts\ProvidesSupplierFormSchema;
use App\Services\Upstream\Contracts\ProvidesUpstreamQuoting;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Support\ProductConfigOptionPresenter;
use Illuminate\Support\Facades\Cache;
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
class TuraOpenApi implements ProvidesBatchStatusSync, ProvidesConsoleCatalog, ProvidesConsoleRuntime, ProvidesInvoiceRenewal, ProvidesOrderProvisioning, ProvidesProvisioning, ProvidesRenewableCycleFiltering, ProvidesRenewal, ProvidesRenewalRecovery, ProvidesStatusSync, ProvidesSupplierBalance, ProvidesSupplierFormSchema, ProvidesUpstreamQuoting, UpstreamDriver
{
    public const KEY = 'tura_open_api';

    public const LABEL = 'TuraIDC 开放接口';

    /** 目录/状态同步分页大小（开放 API 上限 200） */
    private const LIST_PAGE_SIZE = 200;

    private const LIST_MAX_PAGES = 25;

    /** 站点公开目录分页大小：上游校验上限是 50，传 100 会被拒（422 page_size） */
    private const SITE_PAGE_SIZE = 50;

    private const SITE_MAX_PAGES = 10;

    /** 站点目录补全的兜底预算：分组数与请求数任一超限时停止，用已拿到的部分 */
    private const SITE_MAX_GROUPS = 80;

    private const SITE_MAX_REQUESTS = 240;

    /** 站点分组缓存时长（秒）：货架不常变，但补全要遍历 30+ 次请求 */
    private const SITE_GROUP_CACHE_TTL = 21600;

    /** 站点分组接口异常时的空结果缓存时长（秒），避免反复重试整轮遍历 */
    private const SITE_GROUP_FAILURE_CACHE_TTL = 60;
    /** 上游实时报价缓存：价格分钟级不变，缓冲前台频繁的价格预览请求 */
    private const QUOTE_CACHE_TTL = 60;

    /** 区间型配置项 option_type（与系统商品配置项的语义一致）：这些项按数量区间取值而非下拉 */
    private const SITE_RANGE_OPTION_TYPES = [4, 7, 9, 11, 14, 15, 16, 17, 18, 19];

    /**
     * 文字提示型配置项的 option_mode。
     *
     * 上游把「明确禁止」这类购买须知做成了普通单选配置项（field=cpu、option_type=6、
     * 唯一子项的 option_name 就是整句禁令），本地若按 select 渲染会变成一个叫
     * 「明确禁止」的下拉框，既看不出是提示文字，还会把这段文案当 cpu 的取值提交上游、
     * 覆盖真实 CPU 配置。此类项统一归为 text：只展示、不计价、不回传。
     */
    public const OPTION_MODE_TEXT = 'text';

    
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
        // 续费周期过滤：上游可续周期由 /services/{id}/renewals 真实返回，避免本地
        // 放出上游并不支持的周期。
        ProvidesRenewableCycleFiltering::class,
        ProvidesRenewal::class,
        ProvidesRenewalRecovery::class,
        ProvidesStatusSync::class,
        ProvidesSupplierBalance::class,
        ProvidesUpstreamQuoting::class,
    ];

    /** 目录导入/续费探测用的标准周期序（与 hydrateSelectedPricing 保持一致） */
    private const STANDARD_BILLING_CYCLES = ['monthly', 'quarterly', 'semiannually', 'annually'];

    /** 上游电源动作白名单：与上游 /services/{id}/power 的服务端校验保持一致 */
    private const POWER_ACTIONS = ['on', 'off', 'reboot', 'hard_off', 'hard_reboot'];

    /**
     * 本地常见电源动作别名 → 上游标准动作。
     * 开放接口只接受白名单内的动作，别名在下游归一后再上行，避免把上游 422 原样抛给用户。
     *
     * @var array<string, string>
     */
    private const POWER_ACTION_ALIASES = [
        'start' => 'on',
        'boot' => 'on',
        'power_on' => 'on',
        'poweron' => 'on',
        'stop' => 'off',
        'shutdown' => 'off',
        'power_off' => 'off',
        'poweroff' => 'off',
        'restart' => 'reboot',
        'force_off' => 'hard_off',
        'hard_shutdown' => 'hard_off',
        'force_restart' => 'hard_reboot',
        'hard_restart' => 'hard_reboot',
    ];

    /** config_snapshot 中属于本地元数据、不应提交给上游的键 */
    private const NON_UPSTREAM_CONFIG_KEYS = [
        'product_full_path',
        'product_path_segments',
        'first_product_group_name',
        'second_product_group_name',
        'third_product_group_name',
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
                    'label' => 'API 密钥',
                    'type' => 'password',
                    'required' => true,
                    'secret' => true,
                    'placeholder' => '编辑时留空则保持原密钥',
                    'description' => '在上游「API 密钥」页创建后复制 secret 部分（页面展示为 tura_ 前缀 + 随机标识，需填其下方的完整密钥串）。需具备 products/orders/services/finance 读写权限，且上游账户余额充足。',
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
                'data' => $this->healthCheck(),
            ],
            // 供应商卡片上的两个动作：与 ZJMF / 康乐插件使用同一动作名与返回结构，
            // 管理端据此刷新卡片与批量导入上游商品。
            'server.supplier.refresh_card' => $this->refreshSupplierCard($action, $request),
            'server.supplier.bulk_connect' => $this->bulkConnectSupplierProducts($action, $request, $payload),
            default => [
                'success' => false,
                'action' => $action,
                'message' => '不支持的上游插件动作',
                'data' => [],
            ],
        };
    }

    /**
     * 插件自检（PluginRuntimeRegistry::healthCheck 以无参方式调用）。
     *
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        return [
            'healthy' => true,
            'provider_key' => $this->key(),
            'message' => 'TuraIDC 开放接口插件加载正常',
            'details' => [
                'capabilities' => count(self::CAPABILITIES),
                'account_fields' => ['api_url', 'api_key'],
            ],
        ];
    }

    /**
     * 供应商卡片：与其它上游插件共用同一卡片契约（title/subtitle/status/fields/actions），
     * 由管理端统一渲染。
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function renderCard(Supplier $supplier, array $context = []): array
    {
        $binding = is_array($context['binding'] ?? null) ? (array) $context['binding'] : [];
        $remote = is_array($context['remote'] ?? null) ? (array) $context['remote'] : [];

        $balance = array_key_exists('balance', $remote)
            ? '¥ '.$this->moneyText($remote['balance'])
            : '-';
        $lastUpdated = $this->formatCardDateTime(
            $context['checked_at']
                ?? $remote['checked_at']
                ?? $binding['last_checked_at']
                ?? $supplier->updated_at
                ?? null
        );
        // 开放接口没有「账号」概念：卡片只暴露上游站点，密钥属于敏感凭据，
        // 一旦在列表里明文展示就等于把上游账户权限摊在页面上，故不再输出。
        $site = $this->upstreamSiteLabel($supplier, $binding);
        $hasCredentials = $this->hasSupplierCredentials($supplier, $binding);
        $enabled = (int) ($supplier->status ?? 0) === 1;

        return [
            'title' => trim((string) ($supplier->name ?? '')) ?: $this->label(),
            'subtitle' => $this->label(),
            'status' => [
                'label' => $enabled ? '启用中' : '已停用',
                'theme' => $enabled ? 'success' : 'default',
                'variant' => 'light',
            ],
            'fields' => [
                [
                    'key' => 'upstream_site',
                    'label' => '上游站点',
                    'value' => $site !== '' ? $site : '-',
                ],
                [
                    'key' => 'upstream_balance',
                    'label' => '上游余额',
                    'value' => $balance,
                ],
                [
                    'key' => 'updated_at',
                    'label' => '最近更新时间',
                    'value' => $lastUpdated,
                ],
            ],
            'actions' => [
                [
                    'key' => 'refresh_card',
                    'label' => '同步余额',
                    'action' => 'supplier.remote_metric.refresh',
                    'request_action' => 'server.supplier.refresh_card',
                    'theme' => 'primary',
                    'variant' => 'text',
                    'disabled' => ! $hasCredentials,
                    'disabled_reason' => '接口配置不完整，暂时无法同步余额',
                ],
                [
                    'key' => 'bulk_connect',
                    'label' => '批量导入/对接',
                    'action' => 'supplier.batch_connect',
                    'request_action' => 'server.supplier.bulk_connect',
                    'variant' => 'text',
                    'disabled' => ! $hasCredentials,
                    'disabled_reason' => '接口配置不完整，暂时无法批量对接商品',
                ],
            ],
        ];
    }

    /**
     * 上游可续周期：直接取续费预览返回的周期集合，上游不可达时返回 null，
     * 由调用方回退本地周期（约定见 ProvidesRenewableCycleFiltering）。
     *
     * @return list<string>|null
     */
    public function renewableCycles(Supplier $supplier, int $hostId): ?array
    {
        try {
            $preview = $this->client()->get($supplier, "/api/v2/open/services/{$hostId}/renewals");
        } catch (BusinessException $exception) {
            Log::warning('[TuraIDC 开放接口] 续费周期探测失败，回退本地周期集合', [
                'supplier_id' => (int) $supplier->id,
                'host_id' => $hostId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $cycles = [];
        foreach ((array) ($preview['cycles'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $cycle = trim((string) ($item['billing_cycle'] ?? ''));
            if ($cycle !== '') {
                $cycles[] = $cycle;
            }
        }

        $cycles = array_values(array_unique($cycles));

        return $cycles !== [] ? $cycles : null;
    }

    /* ---------------------------------- 目录 ---------------------------------- */

    /**
     * 商品目录 = 开放接口的商品清单 + 站点公开目录的货架分组。
     *
     * 开放接口（/api/v2/open/products）是下单用的真源，但它只投影
     * id/name/product_type/stock 四个字段——实测 /products/{id} 详情同样只有这四个，
     * /product-groups 直接 404，货架分组根本不在开放协议里。
     *
     * 上游站点自己那套公开目录接口（/api/v2/site/product-groups/*）却带完整的
     * 一/二/三级分组，且商品 ID 与开放接口是同一套（实测 808 个站点商品 100% 落在
     * 开放接口的 1095 个里）。所以这里以开放接口为准、用站点目录补分组：
     *  - 命中站点目录的商品 → 真实三级路径（云电脑 / 华中 / 湖北襄阳 电信）；
     *  - 未命中的（前台未上架，实测 287 个）→ 从商品名推导机房/系列兜底。
     */
    public function getProductCatalog(Supplier $supplier): array
    {
        $list = $this->fetchServiceProductList($supplier);
        $sitePaths = $this->siteGroupPaths($supplier);

        $products = [];
        $grouped = [];

        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }

            $product = $this->catalogProduct($item, $sitePaths[(int) ($item['id'] ?? 0)] ?? null);
            if ((int) ($product['id'] ?? 0) <= 0) {
                continue;
            }

            $products[] = $product;
            // 有真实层级时按完整路径聚合，UI 上是「一级 / 二级 / 三级」的嵌套树
            $grouped[$product['group_label']][] = $product;
        }

        return [
            'groups' => $this->catalogGroups($grouped),
            'products' => $products,
        ];
    }

    /**
     * 上游站点公开目录里的「商品 ID → 分组路径」，拿不到时返回空数组（静默降级）。
     *
     * 遍历一遍要 30+ 次请求，故按供应商缓存；站点接口报错/超时同样只返回空数组，
     * 让目录退化为名称推导，绝不让「刷新商品」因为补全分组而整体失败。
     *
     * @return array<int, array<int, string>>
     */
    private function siteGroupPaths(Supplier $supplier): array
    {
        $cacheKey = sprintf('tura_open_api:site_groups:%d', (int) $supplier->id);

        try {
            $paths = Cache::remember($cacheKey, self::SITE_GROUP_CACHE_TTL, fn (): array => $this->fetchSiteGroupPaths($supplier));
        } catch (\Throwable $exception) {
            Log::warning('[TuraIDC 开放接口] 站点分组补全失败，目录退化为名称推导', [
                'supplier_id' => (int) $supplier->id,
                'reason' => $exception->getMessage(),
            ]);

            // 失败结果短暂缓存：一次遍历要 30+ 次请求，接口异常时若不缓存，
            // 每次刷新商品都会重试整轮遍历，把上游拖垮。短 TTL 让站点恢复后
            // 能较快自愈，不必等满正常缓存时长。
            Cache::put($cacheKey, [], self::SITE_GROUP_FAILURE_CACHE_TTL);

            return [];
        }

        return is_array($paths) ? $paths : [];
    }

    /**
     * 遍历站点目录取分组路径。
     *
     * 只取二级分组的 products（level=2）即可覆盖全部商品：上游返回的每个商品都自带
     * first/second/third_product_group_name，实测只拉二级与递归到三级结果完全一致
     * （808 个商品、全部带三级路径），请求数却从 ~160 降到 35。
     *
     * @return array<int, array<int, string>>
     */
    private function fetchSiteGroupPaths(Supplier $supplier): array
    {
        $paths = [];
        $requests = 0;

        foreach ($this->fetchSiteGroupIds($supplier) as $groupId) {
            for ($page = 1; $page <= self::SITE_MAX_PAGES; $page++) {
                if ($requests >= self::SITE_MAX_REQUESTS) {
                    return $paths;
                }

                $payload = $this->client()->getPublic(
                    $supplier,
                    sprintf('/api/v2/site/product-groups/%d/products', $groupId),
                    ['level' => 2, 'page' => $page, 'page_size' => self::SITE_PAGE_SIZE]
                );
                $requests++;

                $list = is_array($payload['list'] ?? null) ? $payload['list'] : [];

                foreach ($list as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $productId = (int) ($item['id'] ?? 0);
                    if ($productId <= 0) {
                        continue;
                    }

                    $paths[$productId] = $this->siteGroupPath($item);
                }

                if (count($list) < self::SITE_PAGE_SIZE) {
                    break;
                }
            }
        }

        return $paths;
    }

    /**
     * 站点目录里的二级分组 ID 列表。
     *
     * @return array<int, int>
     */
    private function fetchSiteGroupIds(Supplier $supplier): array
    {
        $ids = [];

        for ($page = 1; $page <= self::SITE_MAX_PAGES; $page++) {
            $payload = $this->client()->getPublic($supplier, '/api/v2/site/product-groups', [
                'page' => $page,
                'page_size' => self::SITE_PAGE_SIZE,
            ]);

            $list = is_array($payload['list'] ?? null) ? $payload['list'] : [];

            foreach ($list as $group) {
                if (! is_array($group)) {
                    continue;
                }

                $groupId = (int) ($group['id'] ?? 0);
                if ($groupId > 0) {
                    $ids[] = $groupId;
                }
            }

            if (count($list) < self::SITE_PAGE_SIZE || count($ids) >= self::SITE_MAX_GROUPS) {
                break;
            }
        }

        return array_slice($ids, 0, self::SITE_MAX_GROUPS);
    }

    /**
     * 站点商品载荷 → 分组路径（一/二/三级名，去重去空）。
     *
     * @param  array<string, mixed>  $item
     * @return array<int, string>
     */
    private function siteGroupPath(array $item): array
    {
        $path = [];

        foreach (['first_product_group_name', 'second_product_group_name', 'third_product_group_name'] as $key) {
            $name = trim((string) ($item[$key] ?? ''));

            if ($name !== '' && ! in_array($name, $path, true)) {
                $path[] = $name;
            }
        }

        return $path;
    }

    /**
     * 与 ZJMF 目录插件保持同一 groups 契约（key/label/items），管理端批量对接弹窗、
     * 以及任何按 groups 兜底构建商品树的调用方都能直接复用。
     *
     * @param  array<string, array<int, array<string, mixed>>>  $grouped
     * @return array<int, array<string, mixed>>
     */
    private function catalogGroups(array $grouped): array
    {
        $groups = [];

        foreach ($grouped as $label => $items) {
            if ($label === '' || $items === []) {
                continue;
            }

            $key = 'group-'.md5((string) $label);

            $groups[] = [
                'key' => $key,
                'label' => $label,
                'count' => count($items),
                // items 只带建树需要的字段：完整商品在顶层 products 里已有一份，
                // 全量再嵌一遍会让千级商品的响应体翻倍（实测 1.1MB+）。
                'items' => array_values(array_map(
                    fn (array $product): array => [
                        'id' => $product['id'],
                        'name' => $product['name'],
                        'type' => $product['type'],
                        'type_label' => $product['type_label'],
                        'group_name' => $product['group_name'],
                        'remote_group_name' => $product['remote_group_name'],
                        'remote_group_path' => $product['remote_group_path'],
                        'stock' => $product['stock'],
                    ],
                    $items
                )),
            ];
        }

        usort($groups, fn (array $left, array $right): int => strcmp((string) $left['label'], (string) $right['label']));

        return $groups;
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

        $cycles = self::STANDARD_BILLING_CYCLES;
        $selectedProducts = collect($products)
            ->map(fn (array $product): array => $product)
            ->filter(fn (array $product): bool => $selected->has((int) ($product['id'] ?? 0)))
            ->values();

        // 询价请求数 = 商品数 × 计费周期数，串行时 RTT 线性累加
        // （选 5 个商品 × 4 周期 = 20 次串行 ≈ 10s，表现为前端「批量对接超时」）。
        // 先整体并发询价，仅对并发未覆盖的项串行兜底。
        $quoteMap = $this->fetchQuotesForProducts($supplier, $selectedProducts->all(), $cycles);

        return collect($products)->map(function (array $product) use ($quoteMap, $cycles): array {
            $productId = (int) ($product['id'] ?? 0);
            if ($productId <= 0 || ! isset($quoteMap[$productId])) {
                return $product;
            }

            $amounts = [];
            foreach ($cycles as $cycle) {
                $amount = trim((string) ($quoteMap[$productId][$cycle] ?? ''));
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

    /**
     * 批量拉取多个商品在多个计费周期下的报价。
     *
     * 优先整批并发（Http::pool）；并发不可用或部分失败时，
     * 对缺失项逐个串行重试，保证个别上游错误不会让整批询价作废。
     *
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<int, string>  $cycles
     * @return array<int, array<string, string>>  productId => cycle => amount
     */
    private function fetchQuotesForProducts(Supplier $supplier, array $products, array $cycles): array
    {
        $requests = [];
        $queryByAlias = [];

        foreach ($products as $product) {
            $productId = (int) ($product['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            foreach ($cycles as $cycle) {
                $alias = $productId.'@'.$cycle;
                $requests[$alias] = "/api/v2/open/products/{$productId}/quotes";
                $queryByAlias[$alias] = ['billing_cycle' => $cycle, 'quantity' => 1];
            }
        }

        if ($requests === []) {
            return [];
        }

        $quoteMap = [];

        // 分批并发，避免一次性打爆上游；每批 12 个请求
        foreach (array_chunk($requests, 12, true) as $requestChunk) {
            $queryChunk = array_intersect_key($queryByAlias, $requestChunk);
            $responses = $this->client()->getMany($supplier, $requestChunk, $queryChunk);

            foreach ($requestChunk as $alias => $uri) {
                $amount = trim((string) ($responses[$alias]['total_amount'] ?? ''));
                if ($amount !== '' && is_numeric($amount)) {
                    [$productId, $cycle] = $this->parseQuoteAlias((string) $alias);
                    $quoteMap[$productId][$cycle] = $amount;
                }
            }
        }

        // 并发未覆盖的项串行兜底（并发整体失败时走这里）
        foreach ($requests as $alias => $uri) {
            [$productId, $cycle] = $this->parseQuoteAlias((string) $alias);
            if (isset($quoteMap[$productId][$cycle])) {
                continue;
            }

            try {
                $quote = $this->client()->get($supplier, $uri, (array) ($queryByAlias[$alias] ?? []));
            } catch (BusinessException) {
                // 该周期不可售（如仅一次性商品），跳过
                continue;
            }

            $amount = trim((string) ($quote['total_amount'] ?? ''));
            if ($amount !== '' && is_numeric($amount)) {
                $quoteMap[$productId][$cycle] = $amount;
            }
        }

        return $quoteMap;
    }

    /**
     * 解析 "productId@cycle" 别名。
     *
     * @return array{0: int, 1: string}
     */
    private function parseQuoteAlias(string $alias): array
    {
        $parts = explode('@', $alias);

        return [(int) ($parts[0] ?? 0), (string) ($parts[1] ?? '')];
    }

    /**
     * 单个商品的可配置项。
     *
     * 此前这里直接返回空数组（原注释「开放 API 不暴露上游配置项」），后果是：
     * 导入出来的商品 config_options 恒为 0 项 → 展示名派生不出 CPU/内存 →
     * 后台与控制台一律显示「未配置规格 #ID」，看上去就像上游数据没下来。
     * 开放接口确实不返回配置项，但站点公开目录 /api/v2/site/products/{id} 带完整
     * config_options，且与本地字段语义一致，这里改为走站点目录。
     *
     * 返回值是「配置项列表」本身（与 ZJMF / 主控驱动一致），调用方会直接当
     * config_options 用，不要再包一层 ['product_id' => …, 'config_options' => …]。
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchRealConfigOptions(Supplier $supplier, int $productId): array
    {
        $detail = $this->siteProductDetail($supplier, $productId);

        return $detail === null
            ? []
            : $this->normalizeSiteConfigOptions(
                is_array($detail['config_options'] ?? null) ? $detail['config_options'] : []
            );
    }

    /**
     * 批量拉取商品配置项（定时同步 / 批量固化的入口）。
     *
     * 原实现是逐商品返回空数组的占位，定时同步因此永远「拉取到 0 项」而静默跳过
     * （见 ProductSyncService::syncUpstreamProductConfigOptions() 的 `=== []` 分支），
     * 已导入商品的空配置项永远补不上。改为真实拉取，并尊重 $deadline：
     * 上游慢时宁可少拉几个，也要在任务时间预算内收尾。
     *
     * @param  array<int, mixed>  $productIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchBatchProductConfigOptions(
        Supplier $supplier,
        array $productIds,
        int $chunkSize = 8,
        ?float $deadline = null
    ): array {
        $chunkSize = $chunkSize > 0 ? $chunkSize : 8;
        $ids = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $items = [];

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            if ($deadline !== null && $items !== [] && microtime(true) >= $deadline) {
                break;
            }

            // 站点目录接口逐商品串行时，选 N 个商品即 N 个 RTT 累加，
            // 与询价同理改为分批并发；未取到或已过 deadline 的项再串行兜底。
            $requests = [];
            foreach ($chunk as $productId) {
                $requests[(string) $productId] = "/api/v2/site/products/{$productId}";
            }

            // 站点目录是公开接口（无需 API 密钥），必须走 getPublicMany；
            // 且响应需解包 data['product']，与单条 getPublic 的处理保持一致。
            $responses = $this->client()->getPublicMany($supplier, $requests);

            foreach ($chunk as $productId) {
                if ($deadline !== null && $items !== [] && microtime(true) >= $deadline) {
                    break 2;
                }

                $items[$productId] = $this->normalizeFetchedSiteProductConfig(
                    $responses[(string) $productId] ?? null
                );
            }

            // 并发未覆盖的项串行重试；确认上游确实无配置项后才记为空，
            // 避免把「请求失败」误当成「该商品没有配置项」而静默丢失数据。
            foreach ($chunk as $productId) {
                if (array_key_exists($productId, $items)) {
                    continue;
                }

                if ($deadline !== null && $items !== [] && microtime(true) >= $deadline) {
                    break 2;
                }

                $items[$productId] = $this->normalizeFetchedSiteProductConfig(
                    $this->fetchSiteProductDetailForConfig($supplier, $productId)
                );
            }
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

    /**
     * 商品配置项模板（管理端「编辑商品 → 产品配置 → 拉取模板」）。
     *
     * 开放接口不返回配置项，但上游站点公开目录的 GET /api/v2/site/products/{id}
     * 带完整的 config_options（数据中心 / 操作系统 / CPU / 内存 / 带宽 / 磁盘…），
     * 且上游同样是 TuraIDC，字段语义与本地一致，可直接规范化后回填。
     *
     * 拿不到时返回空配置而不是抛错：拉取模板是「尽力而为」的辅助动作，上游没开放
     * 目录接口的老实例应保持可编辑，由管理员手工补配置项。
     */
    public function getProductConfigTemplate(Supplier $supplier, int $productId): array
    {
        $detail = $this->siteProductDetail($supplier, $productId);

        if ($detail === null) {
            return [
                'product' => [],
                'config_options' => [],
                'auto_filled_fields' => [],
            ];
        }

        $configOptions = $this->normalizeSiteConfigOptions(
            is_array($detail['config_options'] ?? null) ? $detail['config_options'] : []
        );

        return [
            'product' => $detail,
            'config_options' => $configOptions,
            'auto_filled_fields' => array_values(array_filter(array_map(
                fn (array $item): string => trim((string) ($item['field'] ?? '')),
                $configOptions
            ))),
        ];
    }

    /**
     * 上游站点公开目录的商品详情（带 config_options / pricing），失败返回 null。
     *
     * @return array<string, mixed>|null
     */
    private function siteProductDetail(Supplier $supplier, int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $cacheKey = sprintf('tura_open_api:site_product:%d:%d', (int) $supplier->id, $productId);

        try {
            $detail = Cache::remember($cacheKey, self::SITE_GROUP_CACHE_TTL, function () use ($supplier, $productId): array {
                $payload = $this->client()->getPublic(
                    $supplier,
                    sprintf('/api/v2/site/products/%d', $productId)
                );

                return is_array($payload['product'] ?? null) ? (array) $payload['product'] : [];
            });
        } catch (\Throwable $exception) {
            Log::warning('[TuraIDC 开放接口] 拉取商品配置项失败', [
                'supplier_id' => (int) $supplier->id,
                'product_id' => $productId,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }

        return is_array($detail) && $detail !== [] ? $detail : null;
    }

    /**
     * 串行补拉单个商品的站点详情（并发未覆盖时的兜底）。
     *
     * 复用 siteProductDetail 的缓存与 data['product'] 解包逻辑；
     * 返回 null 表示请求失败，调用方不得把它当成「无配置项」。
     */
    private function fetchSiteProductDetailForConfig(Supplier $supplier, int $productId): ?array
    {
        return $this->siteProductDetail($supplier, $productId);
    }

    /**
     * 把站点商品详情归一化为 config_options 列表。
     *
     * @param  array<string, mixed>|null  $siteProduct  data['product']；null 表示未取到
     * @return array<int, mixed>
     */
    private function normalizeFetchedSiteProductConfig(?array $siteProduct): array
    {
        if ($siteProduct === null) {
            return [];
        }

        return $this->normalizeSiteConfigOptions(
            is_array($siteProduct['config_options'] ?? null) ? $siteProduct['config_options'] : []
        );
    }

    /**
     * 规范化上游 config_options，补齐本地配置项编辑所需的字段。
     *
     * 上游字段（field / option_type / parameter / sub）语义与本地一致，故保留原值透传，
     * 只补 option_mode（空 → 按是否区间型推导）、config_id、order、sub_items 等前端要用的键。
     *
     * @param  array<int, mixed>  $options
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSiteConfigOptions(array $options): array
    {
        $normalized = [];

        foreach (array_values($options) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $optionId = (int) ($item['id'] ?? 0);
            $type = (int) ($item['option_type'] ?? 0);
            $field = trim((string) ($item['field'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            $sortOrder = (int) ($item['sort_order'] ?? $item['order'] ?? ($index + 1));
            $isRange = in_array($type, self::SITE_RANGE_OPTION_TYPES, true);
            $parameter = trim((string) ($item['parameter'] ?? ''));
            $subOptions = $this->normalizeSiteConfigSubOptions($item['sub'] ?? [], $parameter);
            $displayName = $name !== '' ? $name : ($field !== '' ? $field : '配置项 '.($index + 1));
            $isTextNotice = $this->isTextNoticeOption($displayName, $subOptions);

            // 区间型（滑块）的范围既可能挂在父项上，也可能只挂在唯一子项上
            // （上游「数据盘」就是父项 qty_minimum/qty_maximum 为 0、子项带 0~500）。
            // 父项缺失时回退到子项，否则后台编辑弹窗的「数据范围」会是空的。
            [$rangeMin, $rangeMax] = $this->resolveSiteRangeBounds($item, $subOptions, $isRange);

            $normalized[] = array_merge($item, [
                'id' => $optionId,
                'config_id' => $optionId,
                'field' => $field !== '' ? $field : 'option_'.($index + 1),
                'name' => $displayName,
                'option_name' => $displayName,
                'option_mode' => $this->resolveSiteOptionMode($item, $isRange, $isTextNotice),
                'required' => (int) ($item['required'] ?? 0),
                'hidden' => (int) ($item['hidden'] ?? 0),
                'order' => $sortOrder,
                'sort_order' => $sortOrder,
                'allow_upgrade' => (int) ($item['allow_upgrade'] ?? $item['upgrade'] ?? 0),
                'allow_promo_code' => (int) ($item['allow_promo_code'] ?? 1),
                'qty_minimum' => $rangeMin,
                'qty_maximum' => $rangeMax,
                'qty_step' => $this->resolveSiteRangeStep($item, $subOptions, $isRange),
                'qty_stage' => max(1, (int) ($item['qty_stage'] ?? 1)),
                'unit' => trim((string) ($item['unit'] ?? $item['suffix_text'] ?? '')),
                'parameter' => $parameter,
                // 回传字段名：本地订购页按 field 提交，滑块提交数量、单选提交子项传参值。
                'submit_field' => $field !== '' ? $field : 'option_'.($index + 1),
                'text_content' => $isTextNotice
                    ? $this->resolveTextNoticeContent($subOptions, $parameter)
                    : trim((string) ($item['description'] ?? '')),
                'sub' => $subOptions,
                'sub_items' => $subOptions,
            ]);
        }

        usort($normalized, fn (array $left, array $right): int => ((int) $left['sort_order']) <=> ((int) $right['sort_order']));

        return $normalized;
    }

    /**
     * 区间型配置项的取值模式。
     *
     * 提示项优先于区间判定：上游可能给提示项也带上区间型 option_type，
     * 但它本质是文字，不能当滑块渲染。
     */
    private function resolveSiteOptionMode(array $item, bool $isRange, bool $isTextNotice): string
    {
        // 上游已明确给了模式就以此为准；提示项靠结构反推，不该反过来覆盖显式配置。
        $mode = trim((string) ($item['option_mode'] ?? ''));

        if ($mode !== '') {
            return $mode;
        }

        return $isTextNotice ? self::OPTION_MODE_TEXT : ($isRange ? 'range' : 'select');
    }

    /**
     * 判定配置项是否实为「购买提示文字」。
     *
     * 与 ProductConfigOptionPresenter::isTextNotice() 同款判据（单子项 +
     * 整句中文），避免两条下发路径对同一配置项给出不同的呈现类型。
     *
     * @param  array<int, array<string, mixed>>  $subOptions
     */
    private function isTextNoticeOption(string $name, array $subOptions): bool
    {
        return ProductConfigOptionPresenter::isTextNotice($name, $subOptions);
    }

    /**
     * 取提示项要展示的文案：优先唯一子项文案，其次 parameter 的 label 段。
     *
     * @param  array<int, array<string, mixed>>  $subOptions
     */
    private function resolveTextNoticeContent(array $subOptions, string $parameter): string
    {
        $onlySubName = trim((string) ($subOptions[0]['option_name'] ?? ''));
        if ($onlySubName !== '') {
            return $onlySubName;
        }

        if ($parameter === '') {
            return '';
        }

        $firstPair = trim((string) (explode(',', $parameter)[0] ?? ''));
        $parts = explode('|', $firstPair, 2);

        return trim((string) ($parts[1] ?? $parts[0] ?? ''));
    }

    /**
     * 解析滑块范围 [最小值, 最大值]。
     *
     * 父项优先；父项未给出有效范围时回退到子项（上游「数据盘」的范围只挂在子项上）。
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, array<string, mixed>>  $subOptions
     * @return array{0: int, 1: int}
     */
    private function resolveSiteRangeBounds(array $item, array $subOptions, bool $isRange): array
    {
        if (! $isRange) {
            return [0, 0];
        }

        $min = (int) ($item['qty_minimum'] ?? 0);
        $max = (int) ($item['qty_maximum'] ?? 0);

        if ($max > 0) {
            return [max(0, $min), $max];
        }

        $subMin = 0;
        $subMax = 0;
        foreach ($subOptions as $sub) {
            $subMin = $subMin === 0 ? (int) ($sub['qty_minimum'] ?? 0) : min($subMin, (int) ($sub['qty_minimum'] ?? 0));
            $subMax = max($subMax, (int) ($sub['qty_maximum'] ?? 0));
        }

        if ($subMax <= 0) {
            return [max(0, $min), 0];
        }

        return [max(0, $min > 0 ? $min : $subMin), $subMax];
    }

    /**
     * 解析滑块步长：qty_step 优先，回退 qty_stage，再回退子项，最后 1。
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, array<string, mixed>>  $subOptions
     */
    private function resolveSiteRangeStep(array $item, array $subOptions, bool $isRange): int
    {
        if (! $isRange) {
            return 1;
        }

        $candidates = [
            $item['qty_step'] ?? null,
            $item['qty_stage'] ?? null,
            $subOptions[0]['qty_step'] ?? null,
            $subOptions[0]['qty_stage'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '' || ! is_numeric($candidate)) {
                continue;
            }

            $step = (int) $candidate;
            if ($step > 0) {
                return $step;
            }
        }

        return 1;
    }

    /**
     * 规范化配置项的子选项。
     *
     * 上游子选项已带 option_name / version / hidden / qty_*，这里只补前端渲染与提交
     * 会用到的 config_id / option_name_first / sort_order，不重写 option_name：
     * 上游可能是「父^子」形式（CentOS^CentOS-7.6.1810-x64），改写反而会丢信息。
     *
     * 关键补充：真实传参值在父项的 parameter 里（`12|CentOS^CentOS-7.6`），
     * 而上游 sub 只有自增 id。缺了这层映射，后台选配表格显示的就是 sub id
     * 而不是提交给上游的参数，本地一编辑还会把 id 当参数写回 parameter。
     *
     * @param  mixed  $subOptions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSiteConfigSubOptions(mixed $subOptions, string $parameter = ''): array
    {
        if (! is_array($subOptions)) {
            return [];
        }

        $parameterMap = $this->parseParameterPairs($parameter);
        $normalized = [];

        foreach (array_values($subOptions) as $index => $sub) {
            if (! is_array($sub)) {
                continue;
            }

            $subId = (int) ($sub['id'] ?? 0);
            $optionName = trim((string) ($sub['option_name'] ?? $sub['version'] ?? ''));

            // 按子项文案回查 parameter 拿到真实传参值；查不到时保留上游原值。
            $submitValue = trim((string) ($parameterMap[$optionName] ?? ''));
            if ($submitValue === '') {
                $submitValue = trim((string) ($sub['option_name_first'] ?? ''));
            }

            $normalized[] = array_merge($sub, [
                'id' => $subId,
                'config_id' => (int) ($sub['config_id'] ?? 0),
                'option_name' => $optionName,
                'option_name_first' => $submitValue !== '' ? $submitValue : (string) $subId,
                'version' => trim((string) ($sub['version'] ?? '')) !== '' ? (string) $sub['version'] : $optionName,
                'hidden' => (int) ($sub['hidden'] ?? 0),
                'sort_order' => (int) ($sub['sort_order'] ?? $sub['order'] ?? $index),
                'qty_minimum' => (int) ($sub['qty_minimum'] ?? 0),
                'qty_maximum' => (int) ($sub['qty_maximum'] ?? 0),
                'value' => $submitValue,
                'label' => $optionName,
            ]);
        }

        return $normalized;
    }

    /**
     * 解析 parameter（`12|CentOS^CentOS-7.6,62|Ubuntu^Ubuntu-24.04`）为
     * 「子项文案 => 传参值」映射，供子项回查真实提交值。
     *
     * @return array<string, string>
     */
    private function parseParameterPairs(string $parameter): array
    {
        if (trim($parameter) === '') {
            return [];
        }

        $pairs = [];

        foreach (explode(',', $parameter) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $parts = explode('|', $chunk, 2);
            $value = trim((string) $parts[0]);
            $label = trim((string) ($parts[1] ?? ''));

            if ($label === '') {
                $label = $value;
            }

            if ($label !== '' && $value !== '') {
                $pairs[$label] = $value;
            }
        }

        return $pairs;
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

    /**
     * 从订单快照取出可提交给上游的完整配置。
     *
     * config_snapshot 里混有本地元数据（商品路径、分类名、schema 版本等），
     * 这些上游并不认识，直接透传会让上游校验失败；同时剔除空值与「明确禁止」
     * 之类的展示项，避免把无意义的配置发过去。
     *
     * @return array<string, mixed>
     */
    private function buildUpstreamProvisionConfig(Order $order, string $hostname): array
    {
        $snapshot = data_get($order->config_snapshot, []);

        if (! is_array($snapshot)) {
            $snapshot = [];
        }

        $textOnlyFields = $this->resolveTextOnlyConfigFields($order);
        $config = [];

        foreach ($snapshot as $key => $value) {
            $key = trim((string) $key);

            if ($key === '' || str_starts_with($key, '_') || in_array($key, self::NON_UPSTREAM_CONFIG_KEYS, true)) {
                continue;
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            // 提示型配置项（如「明确禁止」）只用于前台展示购买须知，不作为配置提交。
            // 不能按字段名硬过滤：这类项的 field 常与真实规格项重名（上游的
            // 「明确禁止」field 就是 cpu），按名字过滤会连带删掉真实 CPU 配置。
            if (in_array($key, $textOnlyFields, true)) {
                continue;
            }

            $config[$key] = $value;
        }

        if ($hostname !== '' && ! array_key_exists('hostname', $config)) {
            $config['hostname'] = $hostname;
        }

        return $config;
    }

    /**
     * 找出商品上只用于展示的提示型配置项字段。
     *
     * 快照里只有 field => value，拿不到 option_mode，只能回查商品自身的
     * config_options 来判断。商品未加载时退化为空数组（不过滤），
     * 与历史行为一致，避免因关联缺失导致整单配置被清空。
     *
     * @return array<int, string>
     */
    private function resolveTextOnlyConfigFields(Order $order): array
    {
        $product = $order->relationLoaded('product') ? $order->product : $order->product()->first();

        if ($product === null) {
            return [];
        }

        // 提示项的 field 常与真实规格项重名（上游「明确禁止」的 field 就是 cpu）。
        // 因此先按 field 汇总：只有该 field 下**全部**配置项都是提示项时，
        // 才把这个 field 认定为纯展示字段；只要还存在一个真实规格项，就不过滤。
        //
        // 存量商品的 config_options 里提示项仍标成 select，需先补齐呈现类型，
        // 否则这里一个字段都识别不出来，提示文案会被当配置提交上游。
        $textFields = [];
        $realFields = [];

        foreach (ProductConfigOptionPresenter::present((array) ($product->config_options ?? [])) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $field = trim((string) ($item['field'] ?? ''));
            if ($field === '') {
                continue;
            }

            if (trim((string) ($item['option_mode'] ?? '')) === self::OPTION_MODE_TEXT) {
                $textFields[$field] = true;
            } else {
                $realFields[$field] = true;
            }
        }

        return array_values(array_diff(array_keys($textFields), array_keys($realFields)));
    }

    public function provisionOrder(Order $order, Supplier $supplier, ?Service $existingService = null): array
    {
        $productId = $this->resolveUpstreamProductId($order);
        $billingCycle = trim((string) $order->billing_cycle);
        $hostname = trim((string) data_get($order->config_snapshot, 'hostname', ''));
        throw_if($billingCycle === '', new BusinessException('本地订单缺少计费周期，无法在上游开通', 42200));

        // 上游开通必须收到完整配置：只传 hostname 会让上游按默认规格发货，
        // 买家付费购买的内存/磁盘/带宽等规格被静默忽略。
        $upstreamConfig = $this->buildUpstreamProvisionConfig($order, $hostname);

        $idempotencyKey = 'tura-open-provision-'.$order->id;

        // 1) 报价换取 quote_token（金额由上游服务端计价，下游不可篡改）
        $quote = $this->client()->get($supplier, "/api/v2/open/products/{$productId}/quotes", [
            'billing_cycle' => $billingCycle,
            'quantity' => 1,
            'config' => $upstreamConfig,
        ]);
        $quoteToken = trim((string) ($quote['quote_token'] ?? ''));
        throw_if($quoteToken === '', new BusinessException('上游未返回报价凭证，无法下单', 42200));

        // 2) 下单（幂等键固定：队列重试重放命中上游幂等兜底，返回同一账单）
        $orderPayload = [
            'product_id' => $productId,
            'billing_cycle' => $billingCycle,
            'quantity' => 1,
            'config' => $upstreamConfig,
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
            'upstream_invoice_no' => trim((string) ($invoice['invoice_no'] ?? '')),
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
            'upstream_invoice_no' => trim((string) ($response['invoice_no'] ?? '')),
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

    /**
     * 余额查询同时承担「连接检测」：能取到余额即视为上游可达，
     * 管理端据此把供应商标记为连接成功并刷新卡片。
     */
    public function getBalance(Supplier $supplier): array
    {
        $data = $this->client()->get($supplier, '/api/v2/open/balance');

        return [
            'balance' => (string) ($data['balance'] ?? ''),
            'currency' => (string) ($data['currency'] ?? 'CNY'),
            'connection_status' => 'connected',
            'connection_message' => '连接正常',
            'client' => $this->upstreamClientInfo($supplier),
        ];
    }

    /**
     * 上游密钥自述信息（GET /api/v2/open/keys/self）：只取密钥前缀与权限范围，
     * 用于卡片上人工核对是哪把密钥。取不到不影响余额同步，故失败仅降级返回空。
     *
     * @return array<string, mixed>
     */
    private function upstreamClientInfo(Supplier $supplier): array
    {
        $info = ['provider_key' => self::KEY];

        try {
            $self = $this->client()->get($supplier, '/api/v2/open/keys/self');
        } catch (BusinessException $exception) {
            Log::warning('[TuraIDC 开放接口] 读取上游密钥自述信息失败', [
                'supplier_id' => (int) $supplier->id,
                'message' => $exception->getMessage(),
            ]);

            return $info;
        }

        $info['key_prefix'] = trim((string) ($self['key_prefix'] ?? ''));
        $info['scopes'] = is_array($self['scopes'] ?? null) ? array_values($self['scopes']) : [];
        $info['expires_at'] = trim((string) ($self['expires_at'] ?? ''));
        $info['last_used_at'] = trim((string) ($self['last_used_at'] ?? ''));

        return $info;
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
        $normalized = $this->normalizePowerAction($action);

        $this->client()->post($supplier, "/api/v2/open/services/{$hostId}/power", [
            'action' => $normalized,
        ]);

        return [
            'status' => 200,
            'msg' => '电源指令已提交上游',
            'data' => ['action' => $normalized],
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

    /**
     * 「同步余额」：拉取上游余额并回填卡片；同时把连接结论回传管理端落库。
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function refreshSupplierCard(string $action, array $request): array
    {
        $supplier = $this->supplierFromContext($request);
        $remote = $this->getBalance($supplier);
        $checkedAt = now()->format('Y-m-d H:i:s');
        $remote['checked_at'] = $checkedAt;

        return [
            'success' => true,
            'action' => $action,
            'message' => '余额同步成功',
            'data' => [
                'remote' => $remote,
                'card' => $this->renderCard($supplier, [
                    'binding' => $this->bindingFromContext($request),
                    'remote' => $remote,
                    'checked_at' => $checkedAt,
                ]),
            ],
        ];
    }

    /**
     * 「批量导入/对接」：按货架层级把选中的上游商品导入本地商品库。
     * 商品目录与价格由本插件的 getProductCatalog / hydrateSelectedPricing 提供。
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function bulkConnectSupplierProducts(string $action, array $request, array $payload): array
    {
        $supplier = $this->supplierFromContext($request);
        $result = app(ProductCatalogService::class)->bulkConnectSupplierProducts(
            $supplier,
            $this->validateBulkConnectPayload($payload)
        );

        return [
            'success' => true,
            'action' => $action,
            'message' => '批量对接完成',
            'data' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function supplierFromContext(array $request): Supplier
    {
        $supplier = $request['context']['supplier'] ?? null;
        if (! $supplier instanceof Supplier) {
            throw new BusinessException('供应商上下文缺失，无法执行插件动作', 42200);
        }

        return $supplier;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function bindingFromContext(array $request): array
    {
        return is_array($request['context']['binding'] ?? null) ? (array) $request['context']['binding'] : [];
    }

    /**
     * 与 ZJMF / 康乐插件保持一致的批量对接入参校验（层级 + 商品 ID 去重）。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateBulkConnectPayload(array $payload): array
    {
        // 允许白名单枚举与历史遗留 code（如 vps/domain/other）两种形态。
        // 必须用 strictBusinessValue()：normalizeBusinessValue() 对未知值一律兜底
        // 为 OTHER，任意乱码都会被「归一化」成 other 而通过校验。
        $firstGroupCode = ProductType::strictBusinessValue($payload['first_product_group_code'] ?? '');

        if ($firstGroupCode === null) {
            throw new BusinessException('请选择有效的商品种类', 42200);
        }

        $productIds = collect($payload['product_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($productIds === []) {
            throw new BusinessException('请选择至少一个上游商品', 42200);
        }

        return [
            'first_product_group_code' => $firstGroupCode,
            'first_product_group_id' => $this->positiveInt($payload['first_product_group_id'] ?? null),
            'second_product_group_id' => $this->positiveInt($payload['second_product_group_id'] ?? null),
            'third_product_group_id' => $this->positiveInt($payload['third_product_group_id'] ?? null),
            'second_product_group_name' => trim((string) ($payload['second_product_group_name'] ?? '')),
            'third_product_group_name' => trim((string) ($payload['third_product_group_name'] ?? '')),
            'product_ids' => $productIds,
            'default_status' => (int) ($payload['default_status'] ?? 1) === 1 ? 1 : 0,
            'default_auto_setup' => (int) ($payload['default_auto_setup'] ?? 1) === 1 ? 1 : 0,
            'sync_config_options' => (int) ($payload['sync_config_options'] ?? 0) === 1 ? 1 : 0,
        ];
    }

    private function positiveInt(mixed $value): int
    {
        $int = (int) $value;

        return $int > 0 ? $int : 0;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $string = trim((string) ($value ?? ''));
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private function formatCardDateTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $string = trim((string) ($value ?? ''));

        return $string !== '' ? $string : '-';
    }

    private function moneyText(mixed $value): string
    {
        $string = trim((string) ($value ?? ''));
        if ($string === '') {
            return '0.00';
        }

        return is_numeric($string) ? number_format((float) $string, 2, '.', '') : $string;
    }

    /**
     * 卡片上的上游站点：优先绑定的 base_url，回退供应商表里的接口地址。
     *
     * @param  array<string, mixed>  $binding
     */
    private function upstreamSiteLabel(Supplier $supplier, array $binding): string
    {
        $url = $this->firstFilled([
            $binding['base_url'] ?? null,
            $supplier->api_url ?? null,
        ]);
        if ($url === '') {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }

    /**
     * 本插件供应商表单只有接口地址与密钥，没有账号字段，故不校验用户名。
     *
     * @param  array<string, mixed>  $binding
     */
    private function hasSupplierCredentials(Supplier $supplier, array $binding): bool
    {
        $secretValues = is_array($binding['has_secret_values'] ?? null) ? (array) $binding['has_secret_values'] : [];
        $hasBaseUrl = trim((string) ($binding['base_url'] ?? $supplier->api_url ?? '')) !== ''
            || (bool) ($binding['has_base_url'] ?? false);
        $hasApiKey = (bool) ($binding['has_api_key'] ?? false)
            || (bool) ($secretValues['api_key'] ?? false)
            || trim((string) ($supplier->api_key ?? '')) !== '';

        return $hasBaseUrl && $hasApiKey;
    }

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
     * @param  array<int, string>|null  $remotePath  站点目录给的真实分组路径，为空则按名称推导
     * @return array<string, mixed>
     */
    private function catalogProduct(array $item, ?array $remotePath = null): array
    {
        $stock = (int) ($item['stock'] ?? -1);
        $name = $this->normalizeCatalogName((string) ($item['name'] ?? ''));
        $type = trim((string) ($item['product_type'] ?? ''));
        $typeLabel = $this->productTypeLabel($type);
        // 站点目录没覆盖到（前台未上架）时退化为名称推导；
        // 推不出机房语义再退化为业务类型，保证每个商品都有归属。
        $series = $this->catalogSeries($name);
        $remotePath = ($remotePath === null || $remotePath === []) ? null : array_values($remotePath);
        $groupPath = $remotePath ?? [$series !== '' ? $series : $typeLabel];
        $groupName = (string) end($groupPath);

        return [
            'id' => (int) ($item['id'] ?? 0),
            'name' => $name !== '' ? $name : '未命名商品',
            'type' => $type !== '' ? $type : ProductType::OTHER,
            'type_label' => $typeLabel,
            // 上游不返回商品描述，用「分组路径 · 类型」拼一句可读的摘要，
            // 比写死「来自上游 TuraIDC 开放接口的转售商品」更有信息量。
            'description' => implode(' / ', $groupPath).' · '.$typeLabel,
            'billingcycle' => 'monthly',
            'product_price' => null,
            'monthly_price' => null,
            'setup_fee' => '0.00',
            'allow_qty' => 1,
            'stock_control' => $stock >= 0 ? 1 : 0,
            'qty' => $stock,
            'stock' => $stock,
            'group_name' => $groupName,
            'group_label' => implode(' / ', $groupPath),
            'remote_group_name' => $groupName,
            // 批量对接弹窗按 remote_group_path 建树（见 Suppliers.vue）：
            // 命中站点目录的是真实的一/二/三级路径，没命中的是推导出的单级兜底。
            'remote_group_path' => $groupPath,
        ];
    }

    /**
     * 清洗上游商品名：去掉零宽/格式字符、折叠空白、还原被双重转义的 HTML 实体。
     *
     * 上游存在「​西安电信 …」这类带零宽空格、以及「成都内存&amp;amp;硬盘型NAT」
     * 这类双重转义的名称，不清洗的话同一个机房会被拆成好几个分组，页面上也会直接
     * 把实体当文本显示出来。
     */
    private function normalizeCatalogName(string $name): string
    {
        $name = preg_replace('/[\p{Cf}]/u', '', $name) ?? $name;
        $name = preg_replace('/[\p{Cc}\s]+/u', ' ', $name) ?? $name;

        // 解两次：&amp;amp; → &amp; → &
        for ($i = 0; $i < 2; $i++) {
            $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $name) {
                break;
            }

            $name = $decoded;
        }

        return trim($name);
    }

    /**
     * 上游 product_type → 本地业务类型文案，与系统商品类型保持一致。
     */
    private function productTypeLabel(string $type): string
    {
        if ($type === '') {
            return ProductType::$labels[ProductType::OTHER] ?? '其他';
        }

        return ProductType::$labels[$type] ?? $type;
    }

    /**
     * 从商品名里提取机房/系列前缀，用来替代开放协议缺失的货架分组。
     *
     * 上游命名大致三种写法：
     *  1. 「德国9929 16核32G1Gbps」——空格分隔，首段即机房；
     *  2. 「高频2区|华东三线|24C32G」——竖线分隔，取首个竖线段里的首段；
     *  3. 「镇江BGP-E5-64G-500M」——连字符连写，要切到首个规格段之前。
     *
     * 只按 | 与空白切分会把第 3 类整串当成机房名，实测 1095 个商品碎成 257 个分组、
     * 其中 126 个只有一件商品；补上连字符处理（再去掉汉字后的尾随规格数字）后
     * 收敛到 161 组 / 23 个单元素组。以纯规格开头的（「4 vCPU 8G」）没有机房语义，
     * 返回空串交由调用方退化为业务类型分组。
     */
    private function catalogSeries(string $name): string
    {
        $name = $this->normalizeCatalogName($name);
        if ($name === '') {
            return '';
        }

        // 竖线分段优先于空白：「高频2区|华东三线|24C32G」的机房是「高频2区」
        $head = str_contains($name, '|') ? (string) strstr($name, '|', true) : $name;
        $segments = preg_split('/\s+/u', trim($head)) ?: [];
        $series = trim((string) ($segments[0] ?? ''));

        if ($series === '') {
            return '';
        }

        // 连字符连写：只取首个规格段之前的部分
        // （镇江BGP-E5-64G-500M → 镇江BGP，国内加速高防CDN-企业版 → 国内加速高防CDN）
        if (str_contains($series, '-')) {
            $series = trim((string) explode('-', $series)[0]);

            // 汉字后的尾随数字属于规格（西安云电脑2区16 → 西安云电脑2区）；
            // 紧跟字母的不动，那是型号的一部分（宿迁E5 的 5）。
            // 只在切过连字符时才削：否则「德国9929」里的型号会被当成规格削成「德国」。
            $series = preg_replace('/(?<=[\x{4e00}-\x{9fff}])\d+$/u', '', $series) ?? $series;
        }

        if ($series === '' || mb_strlen($series) > 24) {
            return '';
        }

        // 纯数字、或「数字 + 单位」的规格片段（4 / 2 vCPU / 16C）不是机房名
        if (preg_match('/^\d+(?:\.\d+)?$/u', $series) === 1) {
            return '';
        }

        if (preg_match('/^\d+\s*(?:vCPU|cpu|c|core|核|H|G|M|GB|TB|Mbps|Gbps)$/iu', $series) === 1) {
            return '';
        }

        // 以 CPU 型号开头的（R9-9950X-A1 切完是 R9）同样没有机房语义
        if (preg_match('/^(?:[EIeiRrXx]\d|AMD|Intel|Xeon|EPYC|金牌|铂金|银牌|至强)/u', $series) === 1) {
            return '';
        }

        return $series;
    }

    /**
     * 上游服务详情（GET /services/{id}）→ 主机载荷。
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function hostDetailFromService(array $detail): array
    {
        // 服务详情与主机载荷同源（都是 GET /services/{id} 的控制台投影），
        // 统一走 hostPayloadFromDetail，避免两处各写一份字段解析后逐渐漂移。
        return $this->hostPayloadFromDetail($detail);
    }

    /**
     * 上游服务详情（GET /services/{id}，返回的是控制台投影）→ 主机载荷。
     *
     * 列表项（GET /services）的字段是详情的子集，故列表项直接复用本方法。
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function hostPayloadFromDetail(array $detail): array
    {
        $product = is_array($detail['product'] ?? null) ? $detail['product'] : [];

        return [
            'id' => (int) ($detail['id'] ?? 0),
            'name' => trim((string) ($detail['name'] ?? '')),
            'domain' => $this->firstFilled([
                $detail['domain'] ?? null,
                $detail['hostname'] ?? null,
                $detail['name'] ?? null,
            ]),
            'domainstatus' => $this->domainStatusFromServiceStatus((int) ($detail['status'] ?? 0)),
            'product_name' => $this->firstFilled([
                $product['name'] ?? null,
                $detail['product_display_name'] ?? null,
                $detail['product_name'] ?? null,
            ]),
            'nextduedate' => trim((string) ($detail['expires_at'] ?? '')),
            'created_at' => trim((string) ($detail['created_at'] ?? '')),
            'dedicatedip' => trim((string) ($detail['dedicatedip'] ?? '')),
            'assignedips' => is_array($detail['assignedips'] ?? null) ? array_values($detail['assignedips']) : [],
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
        return $this->hostPayloadFromDetail($item);
    }

    /**
     * 电源动作归一：别名映射 + 白名单校验，非法动作在本地就给出可读提示。
     */
    private function normalizePowerAction(string $action): string
    {
        $raw = trim($action);
        $key = strtolower($raw);
        $normalized = self::POWER_ACTION_ALIASES[$key] ?? $key;

        throw_if(
            ! in_array($normalized, self::POWER_ACTIONS, true),
            new BusinessException('上游开放接口不支持该电源操作：'.$raw, 42200)
        );

        return $normalized;
    }

    /**
     * 上游服务状态（本地 ServiceStatus 常量）→ 通用主机 domainstatus 文本，
     * 与状态同步的 resolveServiceStatusFromUpstream 语义对齐。
     */
    /**
     * 实时上游报价（前台/管理端价格预览用）。
     *
     * 转售商品本地没有配置项单价（开放接口与站点目录的 config_options 都不含价格），
     * 真实价格全靠上游自己的实时报价接口 POST /api/v2/site/products/{id}/quote 计算。
     * 该接口面向官网访客、无需密钥，直接走公开客户端。返回的 data 含 base_amount /
     * config_amount / items / total_amount 等，调用方按需取用（报价逻辑只覆盖 config 加价）。
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>  上游解包后的 data；失败返回空数组
     */
    public function quoteUpstreamProduct(Supplier $supplier, int $upstreamProductId, array $config, string $billingCycle, int $quantity): array
    {
        $quantity = max((int) $quantity, 1);
        $configBody = $config === [] ? new \stdClass() : $config;
        $cacheKey = 'tura_open_api:quote:'
            . $this->supplierFingerprint($supplier) . ':'
            . $upstreamProductId . ':' . $billingCycle . ':' . $quantity . ':'
            . md5(json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Cache::remember($cacheKey, self::QUOTE_CACHE_TTL, function () use ($supplier, $upstreamProductId, $configBody, $billingCycle, $quantity): array {
            try {
                $data = $this->client()->postPublic(
                    $supplier,
                    '/api/v2/site/products/' . $upstreamProductId . '/quote',
                    [
                        'config' => $configBody,
                        'billing_cycle' => $billingCycle,
                        'quantity' => $quantity,
                    ]
                );
            } catch (\Throwable $exception) {
                Log::warning('[tura_open_api] 上游实时报价失败', [
                    'product' => $upstreamProductId,
                    'billing_cycle' => $billingCycle,
                    'error' => $exception->getMessage(),
                ]);

                return [];
            }

            return is_array($data) ? $data : [];
        });
    }

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
