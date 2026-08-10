<?php

declare(strict_types=1);

namespace EwyFinance\Plugins\Servers\KangHostx\Logic;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use App\Services\Upstream\Contracts\ProvidesSupplierFormSchema;
use App\Services\Upstream\Contracts\UpstreamDriver;
use EwyFinance\Plugins\Servers\KangHostx\Lib\KangHostxClient;

class KangHostx implements ProvidesConsoleCatalog, ProvidesConsoleRuntime, ProvidesProvisioning, ProvidesRenewal, ProvidesStatusSync, ProvidesSupplierFormSchema, UpstreamDriver
{
    private const KEY = 'kanghostx';

    private const LABEL = '康乐虚拟主机';

    private const ACCOUNT_PREFIX = 'cw';

    private const TEMPLATE_PRODUCT_ID = 1;

    private const CAPABILITIES = [
        ProvidesConsoleCatalog::class,
        ProvidesConsoleRuntime::class,
        ProvidesProvisioning::class,
        ProvidesRenewal::class,
        ProvidesStatusSync::class,
    ];

    private KangHostxClient $client;

    public function __construct(?KangHostxClient $client = null)
    {
        $this->client = $client ?? new KangHostxClient;
    }

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
            'help' => '康乐 WHM API 采用 accesshash 签名。这里仅保存面板连接信息，空间、数据库、流量等套餐规格请在商品目录的产品配置中维护。',
            'fields' => [
                [
                    'key' => 'api_url',
                    'label' => '康乐面板地址',
                    'type' => 'url',
                    'required' => true,
                    'placeholder' => 'http://1.2.3.4:3312',
                    'description' => '填写 WHM 面板根地址或 /api/index.php 完整地址。',
                ],
                [
                    'key' => 'api_key',
                    'label' => '访问密钥 accesshash',
                    'type' => 'password',
                    'required' => true,
                    'secret' => true,
                    'placeholder' => '编辑时留空则保持原密钥',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function renderCard(Supplier $supplier, array $context = []): array
    {
        $binding = is_array($context['binding'] ?? null) ? (array) $context['binding'] : [];
        $remote = is_array($context['remote'] ?? null) ? (array) $context['remote'] : [];
        $connectionStatus = $this->cardConnectionStatus($remote, $binding);
        $hasCredentials = $this->hasCardCredentials($supplier, $binding);

        return [
            'title' => trim((string) ($supplier->name ?? '')) ?: self::LABEL,
            'subtitle' => self::LABEL,
            'status' => [
                'label' => (int) ($supplier->status ?? 0) === 1 ? '启用中' : '已停用',
                'theme' => (int) ($supplier->status ?? 0) === 1 ? 'success' : 'default',
                'variant' => 'light',
            ],
            'fields' => [
                [
                    'key' => 'panel_url',
                    'label' => '面板地址',
                    'value' => $this->cardBaseUrl($supplier, $binding),
                ],
                [
                    'key' => 'connection_status',
                    'label' => '连接状态',
                    'value' => $connectionStatus['label'],
                    'theme' => $connectionStatus['theme'],
                ],
                [
                    'key' => 'updated_at',
                    'label' => '最近更新时间',
                    'value' => $this->formatCardDateTime(
                        $context['checked_at']
                            ?? $remote['checked_at']
                            ?? $binding['last_checked_at']
                            ?? $supplier->updated_at
                            ?? null
                    ),
                ],
            ],
            'actions' => [
                [
                    'key' => 'refresh_card',
                    'label' => '检测',
                    'action' => 'supplier.remote_metric.refresh',
                    'request_action' => 'server.supplier.refresh_card',
                    'theme' => 'primary',
                    'variant' => 'text',
                    'disabled' => ! $hasCredentials,
                    'disabled_reason' => '接口配置不完整，暂时无法检测连接',
                ],
            ],
        ];
    }

    public function getProductCatalog(Supplier $supplier): array
    {
        $product = $this->catalogProduct(self::TEMPLATE_PRODUCT_ID);

        return [
            'groups' => [
                [
                    'key' => 'kanghostx-virtual-host',
                    'label' => '康乐虚拟主机',
                    'items' => [$product],
                ],
            ],
            'products' => [$product],
        ];
    }

    public function fetchRealConfigOptions(Supplier $supplier, int $productId): array
    {
        return $this->getProductConfigTemplate($supplier, $productId);
    }

    public function fetchBatchProductConfigOptions(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        $items = [];
        foreach ($productIds as $productId) {
            $items[(int) $productId] = $this->getProductConfigTemplate($supplier, (int) $productId);
        }

        return $items;
    }

    public function fetchBatchProductStocks(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        $items = [];
        foreach ($productIds as $productId) {
            $items[(int) $productId] = [
                'stock' => -1,
                'qty' => -1,
                'stock_control' => 0,
            ];
        }

        return $items;
    }

    public function getProductConfigTemplate(Supplier $supplier, int $productId): array
    {
        return [
            'product_id' => $productId,
            'product' => $this->catalogProduct($productId > 0 ? $productId : self::TEMPLATE_PRODUCT_ID),
            'config_options' => $this->packageConfigOptions(),
            'auto_filled_fields' => [
                'web_quota_mb',
                'db_quota_mb',
                'flow_limit_gb',
                'domain_limit',
                'max_subdir',
                'default_subdir',
                'site_port',
                'speed_limit_mbps',
                'max_connect',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogProduct(int $id): array
    {
        return [
            'id' => $id,
            'name' => '康乐虚拟主机',
            'type' => 'hosting',
            'type_label' => '虚拟主机',
            'description' => '本地康乐虚拟主机模板，套餐规格在商品产品配置中维护。',
            'billingcycle' => 'monthly',
            'product_price' => '0.00',
            'monthly_price' => '0.00',
            'setup_fee' => '0.00',
            'allow_qty' => 1,
            'stock_control' => 0,
            'qty' => -1,
            'stock' => -1,
            'first_group_name' => '康乐虚拟主机',
            'second_group_name' => '虚拟主机',
            'remote_group_name' => '康乐虚拟主机',
            'group_name' => '虚拟主机',
            'group_label' => '康乐虚拟主机 / 虚拟主机',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function packageConfigOptions(): array
    {
        $definitions = [
            ['web_quota_mb', '空间容量(MB)', '1024', '开通时提交给康乐 web_quota。'],
            ['db_quota_mb', '数据库容量(MB)', '1024', '填 0 表示不开通数据库。'],
            ['flow_limit_gb', '月流量(GB)', '1024', '填 0 表示不限制。'],
            ['domain_limit', '绑定域名数', '-1', '填 -1 表示不限制。'],
            ['max_subdir', '子目录数', '0', '填 0 表示不限制。'],
            ['default_subdir', '默认目录', 'wwwroot', '康乐虚拟主机默认站点目录。'],
            ['site_port', '站点端口', '80', '可按康乐格式填写 80 或 80,443s。'],
            ['speed_limit_mbps', '限制带宽(M)', '0', '提交时按 M * 128 转换为 speed_limit。'],
            ['max_connect', '最大连接数', '0', '填 0 表示不限制。'],
            ['cdn', '产品类型 CDN', '0', '0 为普通虚拟主机，1 为 CDN。'],
            ['subdir_flag', '绑定子目录', '0', '0 为关闭，1 为开启。'],
            ['ftp', 'FTP 功能', '1', '0 为关闭，1 为开启。'],
            ['access', '自定义控制', '0', '0 为关闭，1 为开启。'],
            ['log_file', '独立日志', '0', '0 为关闭，1 为开启。'],
            ['log_handle', '日志分析', '0', '0 为关闭，1 为开启。'],
            ['ssi', 'SSI 支持', '0', '0 为关闭，1 为开启。'],
            ['htaccess', '伪静态', '0', '0 为关闭，1 为开启。'],
            ['module', '运行模块', 'php', '康乐虚拟主机运行模块。'],
            ['db_type', '数据库类型', 'mysql', '康乐虚拟主机数据库类型。'],
        ];

        return array_map(
            fn (array $definition, int $index): array => $this->fixedConfigOption(
                $index + 1,
                (string) $definition[0],
                (string) $definition[1],
                (string) $definition[2],
                (string) $definition[3]
            ),
            $definitions,
            array_keys($definitions)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fixedConfigOption(int $id, string $field, string $name, string $value, string $description): array
    {
        $subItem = [
            'id' => $value,
            'value' => $value,
            'label' => $name,
            'name' => $name,
            'option_name' => $name,
            'option_name_first' => $value,
            'monthly' => '0.00',
            'monthly_price' => '0.00',
            'pricing' => ['monthly' => '0.00'],
            'sort_order' => 1,
            'hidden' => 0,
            'default' => 1,
            'is_default' => 1,
        ];

        return [
            'id' => $id,
            'uid' => 'kanghostx-'.$field,
            'source' => 'kanghostx',
            'field' => $field,
            'name' => $name,
            'option_name' => $name,
            'type' => 'select',
            'option_type' => 'select',
            'option_mode' => 'select',
            'parameter' => $value.'|'.$name,
            'description' => $description,
            'suffix_text' => '',
            'advanced' => true,
            'required' => true,
            'hidden' => true,
            'sort_order' => $id,
            'range_pricing' => [],
            'sub' => [$subItem],
            'sub_items' => [$subItem],
        ];
    }

    /**
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
                    'account_rule' => self::ACCOUNT_PREFIX.'{service_id}',
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
            'server.supplier.refresh_card' => $this->refreshSupplierCard($action, $request),
            'server.health_check' => [
                'success' => true,
                'action' => $action,
                'data' => [
                    'healthy' => true,
                    'provider_key' => $this->key(),
                    'message' => '康乐虚拟主机插件加载正常',
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

    public function healthCheck(): array
    {
        return [
            'healthy' => true,
            'message' => '康乐虚拟主机插件加载正常',
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function refreshSupplierCard(string $action, array $request): array
    {
        $supplier = $request['context']['supplier'] ?? null;
        if (! $supplier instanceof Supplier) {
            throw new BusinessException('供应商上下文缺失，无法执行插件动作', 42200);
        }

        $remote = $this->getBalance($supplier);
        $checkedAt = now()->format('Y-m-d H:i:s');
        $remote['checked_at'] = $checkedAt;

        return [
            'success' => true,
            'action' => $action,
            'message' => '连接检测完成',
            'data' => [
                'remote' => $remote,
                'card' => $this->renderCard($supplier, [
                    'binding' => is_array($request['context']['binding'] ?? null) ? (array) $request['context']['binding'] : [],
                    'remote' => $remote,
                    'checked_at' => $checkedAt,
                ]),
            ],
        ];
    }

    public function login(Supplier $supplier): string
    {
        $response = $this->client->info($supplier);
        $this->client->assertSuccess($response, '康乐连接测试');

        return 'kanghostx:'.$this->supplierId($supplier);
    }

    public function refreshJwt(Supplier $supplier): string
    {
        return $this->login($supplier);
    }

    public function loginResponse(Supplier $supplier): array
    {
        return [
            'status' => 200,
            'data' => [
                'session' => $this->login($supplier),
                'provider_key' => $this->key(),
            ],
        ];
    }

    public function getUserProfile(Supplier $supplier): array
    {
        $response = $this->client->info($supplier);
        $this->client->assertSuccess($response, '读取康乐面板信息');

        return [
            'status' => 200,
            'data' => $response,
        ];
    }

    public function getBalance(Supplier $supplier): array
    {
        $response = $this->client->info($supplier);
        $this->client->assertSuccess($response, '康乐连接检测');

        return [
            'balance' => '0.00',
            'currency' => 'CNY',
            'connection_status' => 'connected',
            'connection_message' => '连接正常',
            'client' => [
                'provider_key' => self::KEY,
                'version' => $this->firstScalarString($response['version'] ?? null, data_get($response, 'data.version')),
            ],
        ];
    }

    public function provisionOrder(Order $order, Supplier $supplier, ?Service $existingService = null): array
    {
        $hostId = $this->resolveHostId($order, $existingService);
        $accountName = $this->accountName($hostId);
        $password = $this->resolveProvisionPassword($order);
        $payload = $this->buildVirtualHostPayload($supplier, $order, $accountName, $password);
        $response = $this->client->createVirtualHost($supplier, $payload);
        $this->client->assertSuccess($response, '康乐虚拟主机开通');

        return [
            'requested_host' => $accountName,
            'upstream_invoice_id' => 0,
            'upstream_host_ids' => [$hostId],
            'upstream_host_id' => $hostId,
            'host_detail' => $this->normalizeHostPayload($supplier, $hostId, $accountName, array_replace($payload, $response), $password),
        ];
    }

    public function getProductProvisionConfig(Supplier $supplier, int $productId): array
    {
        return [
            'status' => 200,
            'data' => [
                'id' => $productId,
                'rule' => [
                    'prefix' => self::ACCOUNT_PREFIX,
                    'len_num' => 10,
                    'num' => 1,
                    'lower' => 0,
                    'upper' => 0,
                ],
                'show' => 1,
            ],
        ];
    }

    public function getHostRenewInfo(Supplier $supplier, int $hostId, ?string $billingCycle = null): array
    {
        return [
            'status' => 200,
            'data' => [
                'host_id' => $hostId,
                'billingcycle' => $billingCycle ?: 'monthly',
                'amount' => '0.00',
            ],
        ];
    }

    public function renewHost(Supplier $supplier, int $hostId, string $billingCycle): array
    {
        $accountName = $this->accountName($hostId);
        $response = $this->client->updateVirtualHostStatus($supplier, $accountName, 0);
        $this->client->assertSuccess($response, '康乐虚拟主机续费恢复');

        return [
            'status' => 200,
            'data' => [
                'host_id' => $hostId,
                'billingcycle' => trim($billingCycle),
                'message' => '康乐虚拟主机已恢复访问',
            ],
        ];
    }

    public function renewServiceInvoice(Supplier $supplier, int $hostId, string $billingCycle): array
    {
        $renewResponse = $this->renewHost($supplier, $hostId, $billingCycle);

        return [
            'upstream_invoice_id' => 0,
            'renew_response' => $renewResponse,
            'fund_response' => [
                'status' => 200,
                'data' => [
                    'paid' => true,
                ],
            ],
            'host_detail' => $this->extractHostFromDetailResponse($supplier, $hostId, $this->getHostDetail($supplier, $hostId)),
        ];
    }

    public function recoverRenewInvoice(Supplier $supplier, int $hostId, int $upstreamInvoiceId): ?array
    {
        return [
            'upstream_invoice_id' => $upstreamInvoiceId,
            'fund_response' => [
                'status' => 200,
                'data' => [
                    'paid' => true,
                ],
            ],
            'host_detail' => $this->extractHostFromDetailResponse($supplier, $hostId, $this->getHostDetail($supplier, $hostId)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function syncServiceStatuses(Supplier $supplier, array $items, int $chunkSize = 10): array
    {
        $services = [];

        foreach ($items as $item) {
            $serviceId = (int) ($item['service_id'] ?? 0);
            $hostId = (int) ($item['upstream_host_id'] ?? $item['host_id'] ?? 0);
            if ($serviceId <= 0 || $hostId <= 0) {
                continue;
            }

            try {
                $detail = $this->getHostDetail($supplier, $hostId);
                $services[$serviceId] = [
                    'host' => $this->extractHostFromDetailResponse($supplier, $hostId, $detail),
                    'runtime' => $this->runtimePayloadFromKangle($detail['raw'] ?? []),
                ];
            } catch (\Throwable $exception) {
                $services[$serviceId] = [
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return [
            'jwt' => 'kanghostx:'.$this->supplierId($supplier),
            'services' => $services,
        ];
    }

    public function getHostDetail(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $accountName = $this->accountName($hostId);
        $response = $this->client->getVirtualHost($supplier, $accountName);
        $this->client->assertSuccess($response, '读取康乐虚拟主机详情');
        $hostPayload = $this->extractKangleHostPayload($response);

        return [
            'status' => 200,
            'data' => [
                'host' => $this->normalizeHostPayload($supplier, $hostId, $accountName, $hostPayload),
            ],
            'raw' => $hostPayload,
        ];
    }

    public function powerAction(Supplier $supplier, int $hostId, string $action, ?string $jwt = null): array
    {
        $action = strtolower(trim($action));
        $status = match ($action) {
            'on', 'reboot', 'hard_reboot' => 0,
            'off', 'hard_off' => 1,
            default => throw new BusinessException('康乐虚拟主机不支持该控制动作', 42200),
        };

        $accountName = $this->accountName($hostId);
        $response = $this->client->updateVirtualHostStatus($supplier, $accountName, $status);
        $this->client->assertSuccess($response, '康乐虚拟主机状态切换');

        return [
            'status' => 200,
            'data' => [
                'host_id' => $hostId,
                'action' => $action,
                'power_state' => $status === 0 ? 'running' : 'stopped',
            ],
            'msg' => $status === 0 ? '康乐虚拟主机已恢复访问' : '康乐虚拟主机已暂停访问',
        ];
    }

    public function getModuleStatus(Supplier $supplier, int $hostId, string $type = 'host', ?string $jwt = null): array
    {
        if ($type !== 'host') {
            return [
                'status' => 200,
                'data' => [
                    'status' => 'unsupported',
                    'des' => '康乐虚拟主机不提供该任务进度',
                ],
            ];
        }

        $detail = $this->getHostDetail($supplier, $hostId, $jwt);

        return [
            'status' => 200,
            'data' => $this->runtimePayloadFromKangle($detail['raw'] ?? []),
        ];
    }

    public function resetPassword(Supplier $supplier, int $hostId, string $password, ?string $jwt = null): array
    {
        $accountName = $this->accountName($hostId);
        $response = $this->client->changePassword($supplier, $accountName, $password);
        $this->client->assertSuccess($response, '康乐虚拟主机密码重置');

        return [
            'status' => 200,
            'data' => [
                'host_id' => $hostId,
                'reset' => true,
            ],
            'msg' => '康乐虚拟主机密码已重置',
        ];
    }

    public function getReinstallOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return [
            'status' => 200,
            'data' => [
                'os' => [],
                'os_group' => [],
            ],
        ];
    }

    public function reinstall(Supplier $supplier, int $hostId, string $osId, ?string $jwt = null): array
    {
        throw new BusinessException('康乐虚拟主机不支持重装系统', 42200);
    }

    public function getSupportedModules(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return [
            'status' => 200,
            'data' => [
                'modules' => [],
            ],
        ];
    }

    public function get(Supplier $supplier, string $uri, ?string $jwt = null, array $query = [], array $headers = []): array
    {
        if (preg_match('#/v1/hosts/(\d+)(?:/module/status)?#', trim($uri), $matches) === 1) {
            $hostId = (int) $matches[1];

            return str_contains($uri, '/module/status')
                ? $this->getModuleStatus($supplier, $hostId, (string) ($query['type'] ?? 'host'), $jwt)
                : $this->getHostDetail($supplier, $hostId, $jwt);
        }

        throw new BusinessException('康乐虚拟主机不支持该读取接口', 42200);
    }

    public function put(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
    {
        if (preg_match('#/v1/hosts/(\d+)/module/([^/?]+)#', trim($uri), $matches) === 1) {
            $hostId = (int) $matches[1];
            $module = (string) $matches[2];
            $payloadArray = is_array($payload) ? $payload : [];

            if ($module === 'repassword') {
                return $this->resetPassword($supplier, $hostId, (string) ($payloadArray['password'] ?? ''), $jwt);
            }

            return $this->powerAction($supplier, $hostId, $module, $jwt);
        }

        throw new BusinessException('康乐虚拟主机不支持该写入接口', 42200);
    }

    public function getText(Supplier $supplier, string $uri, ?string $jwt = null, array $query = [], array $headers = []): string
    {
        throw new BusinessException('康乐虚拟主机不支持文本页面抓取', 42200);
    }

    public function parallelGet(Supplier $supplier, array $requests, ?string $jwt = null, array $headers = []): array
    {
        $responses = [];
        foreach ($requests as $alias => $request) {
            $key = is_string($alias) ? $alias : (string) ($request['key'] ?? $alias);
            try {
                $responses[$key] = [
                    'status_code' => 200,
                    'response' => $this->get($supplier, (string) ($request['uri'] ?? ''), $jwt, is_array($request['query'] ?? null) ? $request['query'] : []),
                    'error' => '',
                    'content_type' => 'application/json',
                ];
            } catch (\Throwable $exception) {
                $responses[$key] = [
                    'status_code' => 0,
                    'response' => [],
                    'error' => $exception->getMessage(),
                    'content_type' => '',
                ];
            }
        }

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVirtualHostPayload(Supplier $supplier, Order $order, string $accountName, string $password): array
    {
        $config = array_replace($this->providerConfig($supplier), $this->productConfigDefaults($order));
        $snapshot = is_array($order->config_snapshot ?? null) ? (array) $order->config_snapshot : [];
        $payload = [];

        $this->putOptionalBoolean($payload, 'cdn', $this->firstValue($snapshot, $config, ['parameter1', 'cdn']));
        $this->putOptionalBoolean($payload, 'subdir_flag', $this->firstValue($snapshot, $config, ['parameter2', 'subdir_flag']));
        $this->putOptionalBoolean($payload, 'ftp', $this->firstValue($snapshot, $config, ['ftp']));
        $this->putOptionalBoolean($payload, 'access', $this->firstValue($snapshot, $config, ['parameter11', 'kl_access', 'access']));
        $this->putOptionalBoolean($payload, 'log_file', $this->firstValue($snapshot, $config, ['parameter12', 'kl_log_file', 'log_file']));
        $this->putOptionalBoolean($payload, 'log_handle', $this->firstValue($snapshot, $config, ['parameter13', 'kl_log_handle', 'log_handle']));
        $this->putOptionalBoolean($payload, 'ssi', $this->firstValue($snapshot, $config, ['parameter14', 'kl_ssi', 'ssi']));
        $this->putOptionalBoolean($payload, 'htaccess', $this->firstValue($snapshot, $config, ['parameter15', 'kl_htaccess', 'htaccess']));

        $this->putOptional($payload, 'web_quota', $this->firstValue($snapshot, $config, ['kl_site', 'parameter6', 'web_quota_mb']), 1024);
        $this->putOptional($payload, 'db_quota', $this->firstValue($snapshot, $config, ['kl_sql', 'parameter7', 'db_quota_mb']), 1024);
        $this->putOptional($payload, 'domain', $this->firstValue($snapshot, $config, ['kl_domain', 'parameter3', 'domain_limit']), -1);
        $this->putOptional($payload, 'max_subdir', $this->firstValue($snapshot, $config, ['kl_zi', 'parameter4', 'max_subdir']), 0);
        $this->putOptional($payload, 'flow_limit', $this->firstValue($snapshot, $config, ['kl_flow', 'parameter8', 'flow_limit_gb']), 1024);
        $this->putOptional($payload, 'subdir', $this->firstValue($snapshot, $config, ['parameter5', 'default_subdir']), 'wwwroot');
        $this->putOptional($payload, 'max_connect', $this->firstValue($snapshot, $config, ['kl_connect', 'parameter10', 'max_connect']), 0);
        $this->putOptional($payload, 'port', $this->firstValue($snapshot, $config, ['parameter16', 'site_port']));

        $speedLimit = $this->firstValue($snapshot, $config, ['kl_speed', 'parameter9', 'speed_limit_mbps']);
        if ($speedLimit !== null && $speedLimit !== '') {
            $payload['speed_limit'] = (int) round((float) $speedLimit * 128);
        }

        return array_merge($payload, [
            'init' => 1,
            'name' => $accountName,
            'passwd' => $password,
            'module' => trim((string) ($this->firstValue($snapshot, $config, ['module']) ?? 'php')) ?: 'php',
            'db_type' => trim((string) ($this->firstValue($snapshot, $config, ['db_type']) ?? 'mysql')) ?: 'mysql',
        ]);
    }

    private function resolveHostId(Order $order, ?Service $existingService): int
    {
        $serviceId = (int) ($existingService?->id ?? $order->service?->id ?? 0);
        if ($serviceId > 0) {
            return $serviceId;
        }

        return max(1, (int) ($order->id ?? 0));
    }

    private function accountName(int $hostId): string
    {
        return self::ACCOUNT_PREFIX.max(1, $hostId);
    }

    private function resolveProvisionPassword(Order $order): string
    {
        $snapshot = is_array($order->config_snapshot ?? null) ? (array) $order->config_snapshot : [];
        $password = trim((string) ($snapshot['password'] ?? $snapshot['passwd'] ?? ''));
        if ($password !== '') {
            return $password;
        }

        return substr(str_shuffle('abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 10).'Aa1';
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalizeHostPayload(Supplier $supplier, int $hostId, string $accountName, array $raw, string $password = ''): array
    {
        $status = $this->normalizeKangleStatus($raw['status'] ?? 0);
        $serverIp = $this->serverIp($supplier);
        $webQuota = $raw['web_quota'] ?? $this->providerConfig($supplier)['web_quota_mb'] ?? null;
        $dbQuota = $raw['db_quota'] ?? $this->providerConfig($supplier)['db_quota_mb'] ?? null;
        $flowLimit = $raw['flow_limit'] ?? $this->providerConfig($supplier)['flow_limit_gb'] ?? null;

        return [
            'id' => $hostId,
            'name' => (string) ($raw['name'] ?? $accountName),
            'domain' => (string) ($raw['name'] ?? $accountName),
            'domainstatus' => $status['domainstatus'],
            'status' => $status['raw_status'],
            'status_label' => $status['description'],
            'product_name' => self::LABEL,
            'dedicatedip' => $serverIp,
            'assignedips' => $serverIp !== '' ? [$serverIp] : [],
            'username' => (string) ($raw['name'] ?? $accountName),
            'password' => $password,
            'port' => (int) (($this->providerConfig($supplier)['ftp_port'] ?? 21) ?: 21),
            'os' => 'Kangle',
            'web_quota' => $webQuota,
            'db_quota' => $dbQuota,
            'flow_limit' => $flowLimit,
            'db_name' => (string) ($raw['db_name'] ?? ''),
            'control_panel_url' => $this->client->panelLoginUrl($supplier),
            'config_option' => [
                'web_quota_mb' => $webQuota,
                'db_quota_mb' => $dbQuota,
                'flow_limit_gb' => $flowLimit,
                'domain_limit' => $raw['domain'] ?? $this->providerConfig($supplier)['domain_limit'] ?? null,
            ],
        ];
    }

    /**
     * @return array{raw_status:int,domainstatus:string,runtime_status:string,description:string}
     */
    private function normalizeKangleStatus(mixed $status): array
    {
        $status = (int) $status;

        return match ($status) {
            0 => [
                'raw_status' => 0,
                'domainstatus' => 'Active',
                'runtime_status' => 'running',
                'description' => '正常运行',
            ],
            1 => [
                'raw_status' => 1,
                'domainstatus' => 'Suspended',
                'runtime_status' => 'stopped',
                'description' => '已暂停',
            ],
            2 => [
                'raw_status' => 2,
                'domainstatus' => 'Suspended',
                'runtime_status' => 'over_traffic',
                'description' => '超流量',
            ],
            3 => [
                'raw_status' => 3,
                'domainstatus' => 'Suspended',
                'runtime_status' => 'over_database',
                'description' => '超数据库',
            ],
            default => [
                'raw_status' => $status,
                'domainstatus' => 'Pending',
                'runtime_status' => 'unknown',
                'description' => '异常',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function runtimePayloadFromKangle(array $raw): array
    {
        $status = $this->normalizeKangleStatus($raw['status'] ?? 0);

        return [
            'status' => $status['runtime_status'],
            'des' => $status['description'],
            'raw_status' => $status['raw_status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function extractKangleHostPayload(array $response): array
    {
        foreach ([
            ['data', 'host'],
            ['data', 'vh'],
            ['data', 'vhost'],
            ['host'],
            ['vh'],
            ['vhost'],
            ['data'],
        ] as $path) {
            $payload = $response;
            foreach ($path as $segment) {
                if (! is_array($payload[$segment] ?? null)) {
                    $payload = [];
                    break;
                }

                $payload = (array) $payload[$segment];
            }

            if ($payload !== []) {
                return array_replace($response, $payload);
            }
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function extractHostFromDetailResponse(Supplier $supplier, int $hostId, array $response): array
    {
        $host = is_array($response['data']['host'] ?? null) ? $response['data']['host'] : [];

        return $host !== [] ? $host : $this->normalizeHostPayload($supplier, $hostId, $this->accountName($hostId), $response['raw'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putOptional(array &$payload, string $key, mixed $value, mixed $default = null): void
    {
        if ($value === null || $value === '') {
            $value = $default;
        }

        if ($value === null || $value === '') {
            return;
        }

        $payload[$key] = $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putOptionalBoolean(array &$payload, string $key, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $payload[$key] = $this->truthy($value) ? 1 : 0;
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $secondary
     * @param  array<int, string>  $keys
     */
    private function firstValue(array $primary, array $secondary, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $primary) && $primary[$key] !== null && $primary[$key] !== '') {
                return $primary[$key];
            }
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $secondary) && $secondary[$key] !== null && $secondary[$key] !== '') {
                return $secondary[$key];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function productConfigDefaults(Order $order): array
    {
        $product = $this->orderProduct($order);
        if (! $product instanceof Product) {
            return [];
        }

        $defaults = [];
        foreach ((array) ($product->config_options ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $field = $this->configOptionField($option);
            if ($field === '' || array_key_exists($field, $defaults)) {
                continue;
            }

            $value = $this->resolveConfigOptionDefaultValue($option);
            if ($value !== null && $value !== '') {
                $defaults[$field] = $value;
            }
        }

        return $defaults;
    }

    private function orderProduct(Order $order): ?Product
    {
        if ($order->relationLoaded('product')) {
            return $order->product instanceof Product ? $order->product : null;
        }

        if (! method_exists($order, 'product')) {
            return null;
        }

        $product = $order->product()->first();

        return $product instanceof Product ? $product : null;
    }

    /**
     * @param  array<string, mixed>  $option
     */
    private function configOptionField(array $option): string
    {
        $field = trim((string) ($option['field'] ?? $option['spec_key'] ?? ''));
        if ($field !== '') {
            return $field;
        }

        $name = trim((string) ($option['option_name'] ?? $option['name'] ?? ''));
        if (str_contains($name, '|')) {
            return trim((string) strtok($name, '|'));
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $option
     */
    private function resolveConfigOptionDefaultValue(array $option): mixed
    {
        foreach (['default_value', 'value'] as $key) {
            if (array_key_exists($key, $option) && $option[$key] !== null && $option[$key] !== '') {
                return $option[$key];
            }
        }

        $items = array_values(array_filter(
            array_merge(
                is_array($option['sub'] ?? null) ? $option['sub'] : [],
                is_array($option['sub_items'] ?? null) ? $option['sub_items'] : []
            ),
            static fn (mixed $item): bool => is_array($item)
        ));

        foreach ($items as $item) {
            if ($this->isDefaultConfigSubItem($item)) {
                return $this->configSubItemValue($item);
            }
        }

        if (count($items) === 1) {
            return $this->configSubItemValue($items[0]);
        }

        return $this->parameterDefaultValue((string) ($option['parameter'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isDefaultConfigSubItem(array $item): bool
    {
        return $this->truthy($item['is_default'] ?? $item['default'] ?? $item['selected'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function configSubItemValue(array $item): mixed
    {
        foreach (['value', 'option_name_first', 'id', 'label', 'option_name', 'name'] as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== null && $item[$key] !== '') {
                return $item[$key];
            }
        }

        return null;
    }

    private function parameterDefaultValue(string $parameter): ?string
    {
        $first = trim((string) strtok($parameter, ','));
        if ($first === '') {
            return null;
        }

        $separator = strpos($first, '|');

        return $separator === false ? $first : trim(substr($first, 0, $separator));
    }

    /**
     * @return array<string, mixed>
     */
    private function providerConfig(Supplier $supplier): array
    {
        return is_array($supplier->provider_config ?? null) ? (array) $supplier->provider_config : [];
    }

    private function supplierId(Supplier $supplier): int
    {
        return max(0, (int) ($supplier->id ?? 0));
    }

    private function serverIp(Supplier $supplier): string
    {
        $baseUrl = $this->firstScalarString($supplier->api_url ?? null, $this->providerConfig($supplier)['api_url'] ?? null);
        $host = trim((string) parse_url($baseUrl, PHP_URL_HOST));

        return $host !== '' ? $host : $this->firstScalarString($this->providerConfig($supplier)['server_ip'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    private function cardBaseUrl(Supplier $supplier, array $binding): string
    {
        $baseUrl = $this->firstScalarString($binding['base_url'] ?? null, $supplier->api_url ?? null);

        return $baseUrl !== '' ? $baseUrl : '未配置';
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  array<string, mixed>  $binding
     * @return array{label:string,theme:string}
     */
    private function cardConnectionStatus(array $remote, array $binding): array
    {
        $status = strtolower($this->scalarString($remote['connection_status'] ?? $binding['last_check_status'] ?? null));

        return match (true) {
            in_array($status, ['connected', 'success', 'succeeded', 'ok', 'healthy', 'valid', 'passed'], true) => [
                'label' => '正常',
                'theme' => 'success',
            ],
            in_array($status, ['failed', 'failure', 'error', 'invalid', 'unhealthy', 'disconnected'], true) => [
                'label' => '失败',
                'theme' => 'danger',
            ],
            default => [
                'label' => '未检测',
                'theme' => 'default',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    private function hasCardCredentials(Supplier $supplier, array $binding): bool
    {
        $secretValues = is_array($binding['has_secret_values'] ?? null) ? (array) $binding['has_secret_values'] : [];
        $hasBaseUrl = $this->firstScalarString($binding['base_url'] ?? null, $supplier->api_url ?? null) !== ''
            || (bool) ($binding['has_base_url'] ?? false);
        $hasApiKey = (bool) ($binding['has_api_key'] ?? false)
            || (bool) ($secretValues['api_key'] ?? false)
            || $this->scalarString($supplier->api_key ?? null) !== '';

        return $hasBaseUrl && $hasApiKey;
    }

    private function formatCardDateTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $string = $this->scalarString($value);

        return $string !== '' ? $string : '-';
    }

    private function scalarString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if ($value instanceof \Stringable) {
            return trim((string) $value);
        }

        return '';
    }

    private function firstScalarString(mixed ...$values): string
    {
        foreach ($values as $value) {
            $string = $this->scalarString($value);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        return (bool) $value;
    }
}
