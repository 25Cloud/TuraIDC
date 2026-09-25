<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Constants\BillingCycle;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Jobs\PushServiceToZjmfDownstreamJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\ZjmfUpstreamBinding;
use App\Services\ClientServiceConsole\ServiceConsoleAreaService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\ZjmfUpstream\ZjmfDownstreamPushService;
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
        private readonly ?ServiceConsoleAreaService $consoleArea = null,
    ) {}

    /**
     * host/header：返回主机头信息，供下游同步本地 host 记录与渲染控制台。
     *
     * 自定义区域的处理顺序与魔方财务中间层一致：
     *   1. 服务接入可控供应商时，透传供应商的 module_*（中间层不重复实现面板）；
     *   2. 否则对面板型产品（CDN / 虚拟主机）下发本地凭据渲染的区域，
     *      使下游能展示登录信息与控制入口；
     *   3. 机房型产品（云服务器 / 裸机）不下发，功能入口统一走 /dcim/* 接口。
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
        $passthrough = $this->consoleArea()->passthroughModulePayload($service);
        $canOperate = app(ServiceTransformService::class)->canExecuteConsoleActions($service);

        $moduleButtons = ['control' => [], 'console' => []];
        $moduleAreas = $this->buildClientAreas($service);
        $moduleCharts = [];
        $mainArea = $this->buildClientMainArea($service, $hostData);

        if (is_array($passthrough)) {
            $upstreamButtons = is_array($passthrough['module_button'] ?? null) ? $passthrough['module_button'] : [];
            $moduleButtons = [
                'control' => is_array($upstreamButtons['control'] ?? null) ? $upstreamButtons['control'] : [],
                'console' => is_array($upstreamButtons['console'] ?? null) ? $upstreamButtons['console'] : [],
            ];
            if (is_array($passthrough['module_client_area'] ?? null) && $passthrough['module_client_area'] !== []) {
                $moduleAreas = array_values(array_filter(
                    $passthrough['module_client_area'],
                    fn ($item): bool => is_array($item) && trim((string) ($item['key'] ?? '')) !== '',
                ));
            }
            if (is_array($passthrough['module_chart'] ?? null)) {
                $moduleCharts = $passthrough['module_chart'];
            }
            if (is_array($passthrough['module_client_main_area'] ?? null) && $passthrough['module_client_main_area'] !== []) {
                $mainArea = array_values(array_filter(
                    $passthrough['module_client_main_area'],
                    fn ($item): bool => is_array($item) && trim((string) ($item['name'] ?? '')) !== '',
                ));
            }
        }

        return [
            'status' => 200,
            'msg' => '请求成功',
            'data' => [
                'host_data' => $hostData,
                'module_button' => $moduleButtons,
                'module_client_area' => $moduleAreas,
                'module_chart' => $moduleCharts,
                'module_client_main_area' => $mainArea,
                'dcimcloud' => ['nat_acl' => '', 'nat_web' => ''],
                'dcim' => [
                    'flowpacket' => [],
                    'flow_packet_use_list' => [],
                    // 下游按 auth 决定渲染哪些控制按钮；未声明的动作一律不显示
                    'auth' => $this->buildDcimAuth($service),
                    'svg' => '',
                ],
                'reinstall_format_data_disk' => false,
                // 下游管理端按此渲染电源按钮区：透传时用上游声明，否则按本系统
                // 实际电源能力（机房型产品接入可控供应商后为 true）
                'module_power_status' => (bool) ($passthrough['module_power_status'] ?? $canOperate),
                'reinstall_random_port' => (bool) ($passthrough['reinstall_random_port'] ?? false),
            ],
        ];
    }

    private function consoleArea(): ServiceConsoleAreaService
    {
        return $this->consoleArea ?? app(ServiceConsoleAreaService::class);
    }

    /**
     * 自定义 tab 内容：下游按 key 调 /zjmf_api/provision/custom/content 时返回 HTML。
     *
     * 返回结构与魔方财务 postClientAreaContent 一致：{status, data:{html}}。
     * 优先透传供应商的面板 HTML（并把下游的 api_url 继续下传给上游渲染动作地址，
     * 与魔方财务中间层一致）；服务未接入可控供应商时，回退到本地凭据渲染。
     *
     * @return array<string, mixed>
     */
    public function customAreaContent(User $user, int $serviceId, string $areaKey, string $actionEndpoint = ''): array
    {
        $service = $this->findUserService($user, $serviceId);
        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        $areaKey = trim($areaKey);
        if ($areaKey === '') {
            return ['status' => 400, 'msg' => '功能标识不能为空'];
        }

        $actionEndpoint = trim($actionEndpoint);

        // 1. 透传供应商面板（中间层行为）
        $proxied = $this->consoleArea()->proxyModulePage($service, $areaKey, $actionEndpoint);
        if ($proxied !== null && trim($proxied) !== '') {
            return [
                'status' => 200,
                'msg' => '请求成功',
                'data' => ['html' => $proxied],
            ];
        }

        // 2. 回退：按本地下发的区域定义渲染
        $definition = $this->customAreaDefinition($service, $areaKey);
        if ($definition === null) {
            return ['status' => 400, 'msg' => '不支持的功能面板'];
        }

        if ($actionEndpoint === '') {
            $actionEndpoint = $this->defaultActionEndpoint($serviceId);
        }

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
     * host/setdownstream：下游在管理端手工改绑上游主机后，重新登记回推目标。
     *
     * 魔方财务在「服务管理」里填写/修改 dcimid 时调用（ClientsServicesController），
     * 入参：id=本系统 Service id、downstream_url/downstream_token/downstream_id=下游回推地址与凭据。
     * 该地址是后续 host/sync 推送目标，因此必须校验协议与非空，避免登记出不可用的目标。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function setDownstream(User $user, int $serviceId, array $data): array
    {
        $service = $this->findUserService($user, $serviceId);
        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        $url = trim((string) ($data['downstream_url'] ?? ''));
        $token = trim((string) ($data['downstream_token'] ?? ''));
        $downstreamId = (int) ($data['downstream_id'] ?? 0);

        if ($url === '') {
            return ['status' => 400, 'msg' => '下游回推地址不能为空'];
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return ['status' => 400, 'msg' => '下游回推地址必须是 http(s) 地址'];
        }

        try {
            ZjmfUpstreamBinding::query()->updateOrCreate(
                [
                    'user_id' => (int) $user->id,
                    'service_id' => (int) $service->id,
                ],
                [
                    'downstream_url' => rtrim($url, '/'),
                    'downstream_token' => $token,
                    'downstream_id' => $downstreamId,
                    'domain' => (string) ($service->domain ?? ''),
                ]
            );
        } catch (\Throwable $exception) {
            Log::warning('[zjmf-upstream] 下游回推绑定写入失败', [
                'service_id' => (int) $service->id,
                'error' => $exception->getMessage(),
            ]);

            return ['status' => 400, 'msg' => '下游回推绑定保存失败'];
        }

        return ['status' => 200, 'msg' => '保存成功'];
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

        // 下游删单后同步本地 host 为 Deleted（对齐魔方 Host::terminate 成功后的 pushHostInfo）
        PushServiceToZjmfDownstreamJob::dispatch(
            (int) $service->id,
            ZjmfDownstreamPushService::TYPE_TERMINATE
        );

        return ['status' => 200, 'msg' => '删除成功'];
    }

    private function findUserService(User $user, int $serviceId): ?Service
    {
        return Service::query()
            ->with(['order.invoice', 'product.productGroup'])
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

        $bwLimit = (int) ($provisionData['bw_limit'] ?? 0);

        $order = $service->order;
        $product = $service->product;
        $amount = (float) ($service->amount ?? 0);
        // 首付金额取订单实付，缺失时退回服务金额（两者在无优惠场景下相同）
        $firstPaymentAmount = (float) ($order->paid_amount ?? $amount);
        $billingCycle = (string) ($service->billing_cycle ?? '');
        $regdate = $service->created_at?->format('Y-m-d') ?? '';
        $payment = $this->resolvePaymentGateway($order);

        return [
            'id' => (int) $service->id,
            'domain' => (string) ($service->domain ?? ''),
            'dedicatedip' => (string) ($provisionData['dedicated_ip'] ?? ''),
            'assignedips' => is_array($provisionData['assigned_ips'] ?? null)
                ? array_values($provisionData['assigned_ips'])
                : [],
            'bwlimit' => $bwLimit,
            'bwusage' => (float) ($provisionData['bw_usage'] ?? 0),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
            'port' => (int) (($connection['port'] ?? 0) ?: 0),
            'os' => (string) ($provisionData['os'] ?? ''),
            'domainstatus' => $this->domainStatus((int) $service->status),
            'amount' => $amount,
            'nextduedate' => $expiresAt,
            // 下游按此决定是否渲染流量用量（与魔方财务逻辑一致：有配额才展示）
            'show_traffic_usage' => $bwLimit > 0,

            // ── 魔方财务管理端「上游信息」直接读取以下字段（无空值兜底），
            //    缺失会导致面板空白并产生 PHP 未定义索引告警 ──
            'regdate' => $regdate,
            'ocreate_time' => $order?->created_at?->format('Y-m-d') ?? $regdate,
            'domainstatus_desc' => $this->domainStatusLabel((int) $service->status),
            'firstpaymentamount' => $firstPaymentAmount,
            'firstpaymentamount_desc' => number_format($firstPaymentAmount, 2, '.', ''),
            'amount_desc' => number_format($amount, 2, '.', ''),
            'promo_code' => (string) ($order->coupon_code ?? ''),
            'payment' => $payment,
            'payment_zh' => $payment !== '' ? PaymentGatewayCode::label($payment) : '',
            'billingcycle' => $billingCycle,
            'billingcycle_desc' => $billingCycle !== ''
                ? (BillingCycle::LABELS[$billingCycle] ?? $billingCycle)
                : '',
            'group' => (string) ($product?->productGroup?->name ?? ''),
        ];
    }

    /**
     * 订单对应账单的实付网关；查不到时返回空串（面板显示为空，不报错）。
     */
    private function resolvePaymentGateway(?Order $order): string
    {
        $invoiceId = (int) ($order?->invoice?->id ?? 0);
        if ($invoiceId <= 0) {
            return '';
        }

        $payment = Payment::query()
            ->where('invoice_id', $invoiceId)
            ->where('status', PaymentStatus::PAID)
            ->orderByDesc('id')
            ->first();

        return trim((string) ($payment?->gateway ?? ''));
    }

    /**
     * 服务状态的中文描述（魔方财务管理端展示）。
     */
    private function domainStatusLabel(int $status): string
    {
        return match ($status) {
            ServiceStatus::PENDING => '待开通',
            ServiceStatus::ACTIVE => '正常',
            ServiceStatus::SUSPENDED => '暂停',
            ServiceStatus::EXPIRED => '已到期',
            ServiceStatus::CANCELLED => '已删除',
            default => '未知',
        };
    }

    /**
     * DCIM 控制按钮可用性（魔方按 auth 的 on/off 决定渲染哪些按钮）。
     *
     * 只声明本系统真正实现的能力：电源四态、VNC、重装、救援、改密；
     * KVM/iKVM/BMC/流量图走供应商协议未覆盖的路径（对应端点固定返回 400），
     * 因此标记 off，避免下游渲染出点了必失败的按钮。
     *
     * @return array<string, string>
     */
    private function buildDcimAuth(Service $service): array
    {
        $transform = app(ServiceTransformService::class);
        $canOperate = $transform->canExecuteConsoleActions($service);
        $canResetPassword = $transform->canResetPassword($service);

        $flag = static fn (bool $enabled): string => $enabled ? 'on' : 'off';

        return [
            'on' => $flag($canOperate),
            'off' => $flag($canOperate),
            'reboot' => $flag($canOperate),
            'novnc' => $flag($canOperate),
            'reinstall' => $flag($canOperate),
            'rescue' => $flag($canOperate),
            'crack_pass' => $flag($canResetPassword),
            'kvm' => 'off',
            'ikvm' => 'off',
            'bmc' => 'off',
            'traffic' => 'off',
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
