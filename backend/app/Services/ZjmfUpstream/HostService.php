<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ServiceRenewService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * 上游主机接口（被魔方财务对接）：/host/header、/host/renew、/host/cancel。
 *
 * 魔方财务把 TuraIDC 的 Service id 存为本地 host.dcimid，
 * 因此入参 host_id / hostid / id 即 TuraIDC Service id。
 * 上游侧的「同步」即 GET /host/header（协议中没有独立的 upstream /host/sync 路由）。
 */
class HostService
{
    private const DOMAIN_STATUS_MAP = [
        ServiceStatus::PENDING => 'Pending',
        ServiceStatus::ACTIVE => 'Active',
        ServiceStatus::SUSPENDED => 'Suspended',
        ServiceStatus::EXPIRED => 'Expired',
        ServiceStatus::CANCELLED => 'Deleted',
    ];

    public function __construct(
        private readonly ServiceRenewService $renewService,
    ) {}

    /**
     * host/header：返回主机头信息，供下游同步本地 host 记录与渲染控制台。
     *
     * @return array<string, mixed>
     */
    public function header(User $user, int $serviceId): array
    {
        $service = $this->findUserService($user, $serviceId);
        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        $hostData = $this->buildHostData($service);

        return [
            'status' => 200,
            'msg' => '请求成功',
            'data' => [
                'host_data' => $hostData,
                // CDN / 虚拟主机等「面板型产品」在魔方财务侧靠自定义 tab 承载
                // （对应插件 lecdn_ClientArea / mhbt_ClientArea 的下发格式）；
                // 机房型产品的功能入口统一走 P5 /dcim/* 接口，不下发自定义区域。
                'module_button' => ['control' => [], 'console' => []],
                'module_client_area' => $this->buildClientAreas($service),
                'module_chart' => [],
                'module_client_main_area' => $this->buildClientMainArea($service, $hostData),
                'dcimcloud' => ['nat_acl' => '', 'nat_web' => ''],
                'dcim' => ['flowpacket' => []],
                'module_power_status' => false,
                'reinstall_random_port' => false,
            ],
        ];
    }

    /**
     * 自定义 tab 内容：下游按 key 调 /zjmf_api/provision/custom/content 时返回 HTML。
     *
     * 返回结构与魔方财务 postClientAreaContent 一致：{status, data:{html}}。
     * HTML 内的动作地址指向本系统 /provision/custom/{hostId}（下游会改写为自身代理）。
     *
     * @return array<string, mixed>
     */
    public function customAreaContent(User $user, int $serviceId, string $areaKey, string $actionEndpoint = ''): array
    {
        $service = $this->findUserService($user, $serviceId);
        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        $definition = $this->customAreaDefinition($service, trim($areaKey));
        if ($definition === null) {
            return ['status' => 400, 'msg' => '不支持的功能面板'];
        }

        $actionEndpoint = trim($actionEndpoint) !== ''
            ? trim($actionEndpoint)
            : $this->defaultActionEndpoint($serviceId);

        $fields = $this->customAreaRows(
            $service,
            $this->buildHostData($service),
            $definition['rows'],
        );

        return [
            'status' => 200,
            'msg' => '请求成功',
            'data' => [
                'html' => $this->renderClientAreaHtml($definition['name'], $fields, $actionEndpoint),
            ],
        ];
    }

    private function defaultActionEndpoint(int $serviceId): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v2/zjmf/provision/custom/'.$serviceId;
    }

    /**
     * 渲染自定义面板 HTML。面板型产品只需展示连接信息，因此输出静态表格；
     * 动作表单提交到下游改写后的代理地址，保持与魔方财务模板约定一致。
     *
     * @param  list<array{name: string, value: string}>  $fields
     */
    private function renderClientAreaHtml(string $title, array $fields, string $actionEndpoint): string
    {
        $rows = '';
        foreach ($fields as $field) {
            $rows .= '<tr><th>'.e($field['name']).'</th><td>'.e($field['value']).'</td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="2">暂无可展示的配置信息，请稍后重试或联系客服。</td></tr>';
        }

        return '<div class="zjmf-client-area">'
            .'<h4>'.e($title).'</h4>'
            .'<table class="table table-bordered">'.$rows.'</table>'
            .'<form method="post" action="'.e($actionEndpoint).'" style="display:none">'
            .'<input type="hidden" name="func" value="refresh">'
            .'</form>'
            .'</div>';
    }

    /**
     * 自定义 tab 列表，格式与魔方财务 Provision::clientArea 一致：[{key, name}]。
     *
     * 下游（魔方财务或 TuraIDC 自身作为下游）据此渲染 tab，再按 key 调
     * /zjmf_api/provision/custom/content 取内容；两者 key 必须与
     * customAreaContent() 的实现分支严格对应。
     *
     * @return list<array{key: string, name: string}>
     */
    private function buildClientAreas(Service $service): array
    {
        $areas = [];
        foreach ($this->customAreaDefinitions($service) as $key => $definition) {
            $areas[] = ['key' => $key, 'name' => $definition['name']];
        }

        return $areas;
    }

    /**
     * 登录信息区（魔方财务 software.tpl 等模板按 [{name, value}] 取值渲染）。
     *
     * @param  array<string, mixed>  $hostData
     * @return list<array{name: string, value: string}>
     */
    private function buildClientMainArea(Service $service, array $hostData): array
    {
        $definition = $this->customAreaDefinition($service, 'info');
        if ($definition === null) {
            return [];
        }

        return $this->customAreaRows($service, $hostData, $definition['rows']);
    }

    /**
     * 各产品类型可下发的自定义区域定义。
     *
     * 面板型产品（CDN / 虚拟主机）由上游面板承载登录与业务管理，TuraIDC 侧
     * 只负责透传连接凭据与面板入口；机房型产品（云服务器/裸机）走 /dcim/*。
     *
     * @return array<string, array{name: string, rows: list<array{name: string, key: string}>}>
     */
    private function customAreaDefinitions(Service $service): array
    {
        $rows = [
            ['name' => '面板地址', 'key' => 'panel_url'],
            ['name' => '用户名', 'key' => 'username'],
            ['name' => '密码', 'key' => 'password'],
            ['name' => '端口', 'key' => 'port'],
        ];

        return match ($this->serviceProductType($service)) {
            ProductType::CDN, ProductType::WEB_HOSTING => [
                'info' => ['name' => '配置信息', 'rows' => $rows],
            ],
            default => [],
        };
    }

    /**
     * @return array{name: string, rows: list<array{name: string, key: string}>}|null
     */
    private function customAreaDefinition(Service $service, string $key): ?array
    {
        return $this->customAreaDefinitions($service)[$key] ?? null;
    }

    /**
     * @param  list<array{name: string, key: string}>  $rows
     * @param  array<string, mixed>  $hostData
     * @return list<array{name: string, value: string}>
     */
    private function customAreaRows(Service $service, array $hostData, array $rows): array
    {
        $provisionData = is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $connection = $this->readCachedConnection($provisionData);
        $values = [
            'panel_url' => (string) ($connection['hostname'] ?? ''),
            'username' => (string) ($hostData['username'] ?? ''),
            'password' => (string) ($hostData['password'] ?? ''),
            'port' => (string) ($hostData['port'] ?? ''),
        ];

        $result = [];
        foreach ($rows as $row) {
            $value = (string) ($values[$row['key']] ?? '');
            if ($value === '') {
                continue;
            }
            $result[] = ['name' => $row['name'], 'value' => $value];
        }

        return $result;
    }

    private function serviceProductType(Service $service): string
    {
        $product = $service->product;
        if (! $product instanceof Product) {
            $product = $service->loadMissing('product')->product;
        }

        return $product instanceof Product ? (string) $product->product_type : '';
    }

    /**
     * host/renew：为下游主机创建续费账单，返回 invoiceid 供下游 apply_credit 支付。
     *
     * @return array<string, mixed>
     */
    public function renew(User $user, int $serviceId, string $billingCycle): array
    {
        $service = $this->findUserService($user, $serviceId);
        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }
        if (trim($billingCycle) === '') {
            return ['status' => 400, 'msg' => '计费周期不能为空'];
        }

        try {
            $invoice = $this->renewService->createRenewInvoiceForUser(
                $user,
                $serviceId,
                trim($billingCycle),
            );

            return [
                'status' => 200,
                'msg' => '续费账单已创建',
                'data' => [
                    'invoiceid' => (int) $invoice->id,
                ],
            ];
        } catch (\Throwable $exception) {
            Log::warning('[zjmf-upstream] 创建续费账单失败', [
                'user_id' => (int) $user->id,
                'service_id' => $serviceId,
                'billing_cycle' => $billingCycle,
                'error' => $exception->getMessage(),
            ]);

            return ['status' => 400, 'msg' => $exception->getMessage()];
        }
    }

    /**
     * host/cancel：删除主机（下游在结算删除时调用，type 为 Immediate）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function cancel(User $user, int $serviceId, array $data): array
    {
        $service = $this->findUserService($user, $serviceId);
        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        $service->forceFill([
            'status' => ServiceStatus::CANCELLED,
            'suspended_reason' => $reason !== '' ? $reason : null,
        ])->save();

        return ['status' => 200, 'msg' => '删除成功'];
    }

    private function findUserService(User $user, int $serviceId): ?Service
    {
        return Service::query()
            ->where('user_id', (int) $user->id)
            ->find($serviceId);
    }

    /**
     * 组装魔方财务 host_data 字段（字段名与下游 Host::sync 读取点对齐）。
     *
     * @return array<string, mixed>
     */
    private function buildHostData(Service $service): array
    {
        $provisionData = is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $connection = $this->readCachedConnection($provisionData);
        $expiresAt = 0;
        if ($service->expires_at !== null) {
            $expiresAt = (int) strtotime((string) $service->expires_at);
        }

        return [
            'id' => (int) $service->id,
            'domain' => (string) ($service->domain ?? ''),
            'dedicatedip' => (string) ($provisionData['dedicated_ip'] ?? ''),
            'assignedips' => is_array($provisionData['assigned_ips'] ?? null)
                ? array_values($provisionData['assigned_ips'])
                : [],
            'bwlimit' => (int) ($provisionData['bw_limit'] ?? 0),
            'bwusage' => (float) ($provisionData['bw_usage'] ?? 0),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
            'port' => (int) (($connection['port'] ?? 0) ?: 0),
            'os' => (string) ($provisionData['os'] ?? ''),
            'domainstatus' => $this->domainStatus((int) $service->status),
            'amount' => (float) ($service->amount ?? 0),
            'nextduedate' => $expiresAt,
        ];
    }

    /**
     * 解密缓存的连接凭据（与服务控制台 readCachedConnection 同构）。
     *
     * @param  array<string, mixed>  $provisionData
     * @return array<string, mixed>
     */
    private function readCachedConnection(array $provisionData): array
    {
        $defaults = [
            'hostname' => '',
            'username' => '',
            'password' => '',
            'port' => 0,
            'internal_ip' => '',
        ];

        $payload = trim((string) ($provisionData['connection_secret'] ?? ''));
        if ($payload === '') {
            return $defaults;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($payload), true);

            return is_array($decoded)
                ? array_replace($defaults, array_intersect_key($decoded, $defaults))
                : $defaults;
        } catch (\Throwable) {
            return $defaults;
        }
    }

    private function domainStatus(int $status): string
    {
        return self::DOMAIN_STATUS_MAP[$status] ?? 'Pending';
    }
}
