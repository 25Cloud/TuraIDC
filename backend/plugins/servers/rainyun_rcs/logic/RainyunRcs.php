<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\RainyunRcs\Logic;

use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Upstream\Contracts\ProvidesBatchStatusSync;
use App\Services\Upstream\Contracts\ProvidesBrowserConsole;
use App\Services\Upstream\Contracts\ProvidesConsoleAccess;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsolePurchaseFulfillment;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesHostTermination;
use App\Services\Upstream\Contracts\ProvidesInvoiceRenewal;
use App\Services\Upstream\Contracts\ProvidesLocalConsoleActions;
use App\Services\Upstream\Contracts\ProvidesOrderProvisioning;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesReinstallTimeout;
use App\Services\Upstream\Contracts\ProvidesRenewableCycleFiltering;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use App\Services\Upstream\Contracts\ProvidesSupplierBalance;
use App\Services\Upstream\Contracts\ProvidesSupplierFormSchema;
use App\Services\Upstream\Contracts\UpstreamDriver;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunActions;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunApiException;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunBinding;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunCatalog;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunClient;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunConsole;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunLifecycle;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunPanel;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunPurchases;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunValues;

class RainyunRcs implements ProvidesBatchStatusSync, ProvidesBrowserConsole, ProvidesConsoleAccess, ProvidesConsoleCatalog, ProvidesConsolePurchaseFulfillment, ProvidesConsoleRuntime, ProvidesHostTermination, ProvidesInvoiceRenewal, ProvidesLocalConsoleActions, ProvidesOrderProvisioning, ProvidesReinstallTimeout, ProvidesRenewableCycleFiltering, ProvidesStatusSync, ProvidesSupplierBalance, ProvidesSupplierFormSchema, UpstreamDriver
{
    public const CAPABILITIES = [ProvidesConsoleCatalog::class, ProvidesConsoleRuntime::class, ProvidesConsoleAccess::class,
        ProvidesBrowserConsole::class, ProvidesProvisioning::class, ProvidesOrderProvisioning::class, ProvidesRenewal::class,
        ProvidesInvoiceRenewal::class, ProvidesRenewableCycleFiltering::class, ProvidesStatusSync::class, ProvidesBatchStatusSync::class,
        ProvidesSupplierBalance::class, ProvidesHostTermination::class, ProvidesLocalConsoleActions::class, ProvidesConsolePurchaseFulfillment::class, ProvidesReinstallTimeout::class];

    private readonly RainyunClient $client;

    private readonly RainyunCatalog $catalog;

    private readonly RainyunConsole $console;

    public function __construct(?RainyunClient $client = null, ?\Closure $wait = null)
    {
        $this->client = $client ?? new RainyunClient;
        $this->catalog = new RainyunCatalog($this->client);
        $this->console = new RainyunConsole($this->client, $this->catalog, $wait);
    }

    public function key(): string
    {
        return 'rainyun_rcs';
    }

    public function label(): string
    {
        return 'RCS';
    }

    public function capabilities(): array
    {
        return self::CAPABILITIES;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true) && $this instanceof $capability;
    }

    public function resolve(string $capability): ?object
    {
        return $this->supports($capability) ? $this : null;
    }

    public function execute(array $request): array
    {
        $action = (string) ($request['action'] ?? '');
        $data = match ($action) {
            'server.metadata' => ['key' => $this->key(), 'label' => $this->label(), 'capabilities' => $this->capabilities()],
            'server.supports' => ['supported' => $this->supports((string) ($request['payload']['capability'] ?? ''))],
            'server.resolve_capability' => ['resolved' => $this->resolve((string) ($request['payload']['capability'] ?? ''))],
            'server.supplier_form_schema' => $this->supplierFormSchema(),
            'server.health_check' => $this->healthCheck(),
            'server.supplier.refresh_card' => $this->refreshCard($request['context']['supplier'] ?? null),
            default => null,
        };

        return ['success' => $data !== null, 'action' => $action, 'message' => $data === null ? '不支持的插件动作' : '', 'data' => $data ?? []];
    }

    public function healthCheck(): array
    {
        return ['healthy' => true, 'message' => 'RCS 插件加载正常'];
    }

    public function supplierFormSchema(): array
    {
        $fields = [
            ['key' => 'api_url', 'label' => 'API 地址', 'type' => 'url', 'required' => true, 'placeholder' => 'https://api.v2.rainyun.com'],
            ['key' => 'api_key', 'label' => 'API 密钥', 'type' => 'password', 'required' => true, 'secret' => true],
        ];
        foreach (['disk_ssd_price' => '固态云盘（元/GB/月）', 'disk_hdd_price' => '机械云盘（元/GB/月）',
            'disk_chdd_price' => '系统机械盘（元/GB/月）', 'disk_backup_price' => '云盘备份（元/GB/月）',
            'traffic_price' => '附加流量（元/GB）', 'gpu_hour_price' => 'GPU 时长（元/小时）'] as $key => $label) {
            $fields[] = ['key' => $key, 'label' => $label, 'type' => 'text', 'description' => '填写销售单价，最多两位小数；留空时拒绝对应购买。'];
        }

        $fields[] = ['key' => 'plan_monthly_prices', 'label' => '套餐月销售价格 JSON', 'type' => 'textarea', 'description' => '格式 {"套餐ID":"月价格"}，升级须同时配置当前与目标套餐。'];
        $fields[] = ['key' => 'ip_monthly_prices', 'label' => 'IP 月销售价格 JSON', 'type' => 'textarea', 'description' => '格式 {"":"默认线路价格","线路标识":"月价格"}，留空不允许购买。'];

        return ['help' => 'RCS 直连插件。价格为本站销售价格，使用现有图拉账单支付；配置留空不开放相关付费操作。', 'fields' => $fields];
    }

    public function login(Supplier $supplier): string
    {
        throw_if(trim((string) $supplier->api_key) === '', new BusinessException('API 密钥未配置', 42200));

        return 'rainyun_rcs:'.(int) $supplier->id;
    }

    public function refreshJwt(Supplier $supplier): string
    {
        return $this->login($supplier);
    }

    public function getBalance(Supplier $supplier): array
    {
        $data = $this->client->request($supplier, 'GET', '/user/');
        throw_if(! is_numeric($data['Money'] ?? null), new BusinessException('接口未返回账户余额', 42200));

        return ['balance' => number_format((float) $data['Money'], 2, '.', ''), 'currency' => 'CNY'];
    }

    public function renderCard(Supplier $supplier, array $context = []): array
    {
        return ['title' => (string) ($supplier->name ?: $this->label()), 'subtitle' => $this->label(),
            'fields' => [['key' => 'balance', 'label' => '账户余额', 'value' => $context['remote']['balance'] ?? '待检测']],
            'actions' => [['key' => 'refresh_card', 'label' => '检测', 'action' => 'supplier.remote_metric.refresh', 'request_action' => 'server.supplier.refresh_card']]];
    }

    private function refreshCard(mixed $supplier): array
    {
        throw_if(! $supplier instanceof Supplier, new BusinessException('供应商上下文缺失', 42200));
        $remote = $this->getBalance($supplier);

        return ['remote' => $remote, 'card' => $this->renderCard($supplier, ['remote' => $remote])];
    }

    public function getProductCatalog(Supplier $supplier): array
    {
        return $this->catalog->catalog($supplier);
    }

    public function getProductConfigTemplate(Supplier $supplier, int $productId): array
    {
        return $this->catalog->template($supplier, $productId);
    }

    public function fetchRealConfigOptions(Supplier $supplier, int $productId): array
    {
        return $this->getProductConfigTemplate($supplier, $productId);
    }

    public function fetchBatchProductConfigOptions(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        $result = [];
        foreach ($productIds as $id) {
            $result[(int) $id] = $this->getProductConfigTemplate($supplier, (int) $id);
        }

        return $result;
    }

    public function fetchBatchProductStocks(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        $plans = array_column($this->catalog->plans($supplier), null, 'id');
        $result = [];
        foreach ($productIds as $id) {
            $stock = ($plans[$id]['is_selling'] ?? false) ? (int) ($plans[$id]['available_stock'] ?? 0) : 0;
            $result[(int) $id] = ['stock' => $stock, 'qty' => $stock, 'stock_control' => 1];
        }

        return $result;
    }

    public function provisionOrder(Order $order, Supplier $supplier, ?Service $existingService = null): array
    {
        return (new RainyunLifecycle($this->client, $this->catalog, $this->console))->provision($order, $supplier, $existingService);
    }

    public function renewServiceInvoice(Supplier $supplier, int $hostId, string $billingCycle): array
    {
        return (new RainyunLifecycle($this->client, $this->catalog, $this->console))->renew($supplier, $hostId, $billingCycle);
    }

    public function renewableCycles(Supplier $supplier, int $hostId): ?array
    {
        return ['monthly', 'quarterly', 'semiannually', 'annually'];
    }

    public function getHostDetail(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return ['status' => 200, 'data' => ['host' => $this->console->host($this->client->detail($supplier, $hostId))]];
    }

    public function getModuleStatus(Supplier $supplier, int $hostId, string $type = 'host', ?string $jwt = null): array
    {
        return ['status' => 200, 'data' => $this->console->status($this->client->detail($supplier, $hostId), $type)];
    }

    public function powerAction(Supplier $supplier, int $hostId, string $action, ?string $jwt = null): array
    {
        $suffix = match ($action) {
            'on' => 'start', 'off', 'hard_off' => 'stop', 'reboot', 'hard_reboot' => 'reboot', default => throw new BusinessException('不支持该电源操作', 42200)
        };
        $this->client->request($supplier, 'POST', $this->client->hostPath($hostId, $suffix));

        return ['status' => 200, 'msg' => '电源操作已提交', 'data' => []];
    }

    public function resetPassword(Supplier $supplier, int $hostId, string $password, ?string $jwt = null): array
    {
        throw_if(strlen($password) < 8 || strlen($password) > 128, new BusinessException('密码长度须为 8 至 128 位', 42200));
        $this->client->request($supplier, 'POST', $this->client->hostPath($hostId, 'reset-password'), ['password' => $password]);

        return ['status' => 200, 'msg' => '密码重置已提交', 'data' => []];
    }

    public function getReinstallOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $detail = $this->client->detail($supplier, $hostId);
        $images = $this->catalog->images($supplier, $detail['Data']['Plan']);

        return ['status' => 200, 'data' => ['os' => array_map(fn ($os) => ['os_id' => (string) $os['id'], 'id' => (string) $os['id'], 'name' => $os['chinese_name'], 'os_type' => $os['os_type']], $images), 'os_group' => []]];
    }

    public function reinstall(Supplier $supplier, int $hostId, string $osId, ?string $jwt = null): array
    {
        return $this->console->reinstall($supplier, $hostId, $osId);
    }

    public function reinstallTimeoutSeconds(): int
    {
        return 90;
    }

    public function getVncUrl(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->browserConsole($supplier, $hostId, 'novnc');
    }

    public function browserConsole(Supplier $supplier, int $id, string $type): array
    {
        throw_if(! in_array($type, ['novnc', 'xtermjs'], true), new BusinessException('控制台类型无效', 42200));
        $data = $this->client->request($supplier, 'GET', $this->client->hostPath($id, 'vnc'), ['console_type' => $type]);
        $url = (string) ($data['VNCProxyURL'] ?? '');
        $parts = parse_url($url);
        throw_if(! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || preg_match('/(^|\.)raincs\.cn$/i', (string) ($parts['host'] ?? '')) !== 1
            || isset($parts['user']) || isset($parts['pass']), new BusinessException('未获取到可用的远程控制台链接', 42200));

        return ['status' => 200, 'data' => ['url' => $url], 'msg' => '控制台链接已生成'];
    }

    public function getSupportedModules(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $detail = $this->client->detail($supplier, $hostId);
        $areas = ['backups' => '备份还原', 'snapshots' => '本地快照', 'disks' => '弹性云盘', 'traffic' => '流量与带宽', 'upgrade' => '套餐与 IP', 'terminal' => '终端'];
        if (! empty($detail['Data']['Plan']['dgpu_dev_type'])) {
            $areas['gpu'] = 'GPU 与时长';
        }
        if (($detail['Data']['MainIPv4'] ?? '') === '-') {
            $areas['nat_acl'] = 'NAT 转发';
        }
        $modules = [];
        foreach ($areas as $key => $name) {
            $modules[] = ['type' => 'custom', 'function' => $key, 'name' => $name, 'select' => 'client_area'];
        }
        $modules[] = ['type' => 'default', 'function' => 'charts', 'name' => '监控'];

        return ['status' => 200, 'data' => $modules];
    }

    public function getMonitorChart(Supplier $supplier, int $hostId, array $query, ?string $jwt = null): array
    {
        return $this->console->chart($supplier, $hostId, $query);
    }

    public function getMonitorCharts(Supplier $supplier, int $hostId, array $queries, ?string $jwt = null): array
    {
        $result = [];
        foreach ($queries as $key => $query) {
            $result[$key] = ['status_code' => 200, 'response' => $this->getMonitorChart($supplier, $hostId, $query)];
        }

        return $result;
    }

    public function fetchCustomModulePage(Supplier $supplier, int $hostId, string $moduleKey, ?string $jwt = null, string $apiUrl = ''): string
    {
        return (new RainyunPanel)->render($this->client->detail($supplier, $hostId), $moduleKey, $apiUrl ?: $this->getCustomModuleActionEndpoint($supplier, $hostId));
    }

    public function getCustomModuleActionEndpoint(Supplier $supplier, int $hostId): string
    {
        return $this->client->baseUrl($supplier).'/provision/custom/'.RainyunValues::integer($hostId, '实例 ID');
    }

    public function submitCustomModuleAction(Supplier $supplier, string $endpoint, array $data, ?string $jwt = null): array
    {
        throw_if(preg_match('~/provision/custom/(\d+)$~D', $endpoint, $m) !== 1
            || $endpoint !== $this->getCustomModuleActionEndpoint($supplier, (int) ($m[1] ?? 0)), new BusinessException('插件动作实例地址无效', 42200));

        return (new RainyunActions($this->client))->execute($supplier, (int) $m[1], $data);
    }

    public function submitServiceConsoleAction(Service $service, Supplier $supplier, int $hostId, array $data): array
    {
        RainyunBinding::require($service, $supplier, $hostId);
        throw_if((int) $service->status !== ServiceStatus::ACTIVE,
            new BusinessException('当前实例状态不支持插件操作', 42200));
        $action = (string) ($data['func'] ?? '');
        if ($action === 'quote' || $action === 'checkout') {
            return (new RainyunPurchases($this->client))->submit($service, $supplier, $hostId, $data);
        }
        if ($action === 'terminal') {
            return $this->browserConsole($supplier, $hostId, 'xtermjs');
        }

        return (new RainyunActions($this->client))->execute($supplier, $hostId, $data);
    }

    public function fulfillConsolePurchase(Order $order, Service $service, Supplier $supplier, int $hostId): Service
    {
        return (new RainyunPurchases($this->client))->fulfill($order, $service, $supplier, $hostId);
    }

    public function terminateHost(Supplier $supplier, int $hostId, string $reason = '', ?string $jwt = null): array
    {
        try {
            $this->client->request($supplier, 'POST', $this->client->hostPath($hostId, 'free'));
        } catch (RainyunApiException $e) {
            if ($e->upstreamCode !== 10005) {
                throw $e;
            }
        }

        return ['status' => 200, 'data' => ['terminated' => true]];
    }

    public function syncServiceStatuses(Supplier $supplier, array $items, int $chunkSize = 10): array
    {
        $services = [];
        foreach ($items as $item) {
            $serviceId = (int) ($item['service_id'] ?? 0);
            try {
                $detail = $this->client->detail($supplier, (int) ($item['upstream_host_id'] ?? $item['host_id'] ?? 0));
                $services[$serviceId] = ['host' => $this->console->host($detail), 'runtime' => $this->console->status($detail)];
            } catch (BusinessException $e) {
                $services[$serviceId] = ['error' => $e->getMessage()];
            }
        }

        return ['jwt' => $this->login($supplier), 'services' => $services];
    }
}
