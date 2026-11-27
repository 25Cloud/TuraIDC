<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;

final class ZjmfConsoleService
{
    private const FORM_HEADERS = ['content-type: application/x-www-form-urlencoded'];

    /** 能力缓存键：该站点是否有 /console/v1（探一次，按供应商记 6 小时） */
    private const CAPABILITY_CONSOLE_V1 = 'console_v1';

    /** 能力缓存键前缀：上游 host_data.type（cloud/dcimcloud/dcim…） */
    private const CAPABILITY_HOST_TYPE = 'host_type';

    public function __construct(
        private readonly ZjmfFinanceTransport $transport,
        private readonly ?ZjmfCapabilityMemory $capabilityMemory = null,
    ) {}

    private function capabilityMemory(): ZjmfCapabilityMemory
    {
        return $this->capabilityMemory ?? new ZjmfCapabilityMemory();
    }

    /**
     * 该站点是否提供 /console/v1（魔方 3.8.0 新控制台协议）。
     *
     * 只支持经典协议的站点（如 TL）三个模块前缀全是 404，只能走 /provision/default；
     * 而支持 v10 的站点对云类实例必须走 v10，否则经典协议会空操作返回 200。
     */
    private function consoleV1Available(Supplier $supplier, int $hostId, string $jwt): bool
    {
        $memory = $this->capabilityMemory();
        $cached = $memory->recall((int) $supplier->id, self::CAPABILITY_CONSOLE_V1);

        if ($cached !== null) {
            return $cached === '1';
        }

        try {
            $this->transport->get($supplier, "/console/v1/host/{$hostId}", $jwt);
            $available = true;
        } catch (\Throwable $exception) {
            // 路由存在但鉴权/参数不通过时也会抛错，只有明确 404 才算「没有这族接口」
            $available = ! str_contains($exception->getMessage(), 'HTTP 404');
        }

        $memory->remember((int) $supplier->id, self::CAPABILITY_CONSOLE_V1, $available ? '1' : '0');

        return $available;
    }

    private function upstreamProductType(Supplier $supplier, int $hostId, string $jwt): ?string
    {
        $memory = $this->capabilityMemory();
        $key = self::CAPABILITY_HOST_TYPE.':'.$hostId;
        $cached = $memory->recall((int) $supplier->id, $key);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = $this->getHostHeader($supplier, $hostId, $jwt);
            $payload = is_array($response['data'] ?? null) ? $response['data'] : [];
            $type = trim((string) ($payload['host_data']['type'] ?? ''));

            if ($type !== '') {
                $memory->remember((int) $supplier->id, $key, $type);

                return $type;
            }
        } catch (\Throwable) {
            // 类型拿不到时按经典通道处理，不影响指令本身
        }

        return null;
    }

    /**
     * 按代际档位执行控制台指令。
     *
     * 通道顺序来自 ZjmfCompatibilityProfile::consoleChannels()：
     * - 云/DCIM 云类实例 + 站点支持 v10 → 先 v10（避免经典协议空操作假成功）
     * - 其余（含只支持经典协议的站点）→ /provision/default
     * 只有「通道根本不存在（404）」才换下一条，其它错误直接抛给调用方。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function dispatchConsoleAction(
        Supplier $supplier,
        int $hostId,
        string $classicFunc,
        array $payload,
        ?string $jwt,
        bool $isGet = false,
        ?string $v1Action = null,
        ?string $v1Method = null,
    ): array {
        $resolvedJwt = $this->resolveJwt($supplier, $jwt);
        $profile = ZjmfCompatibilityProfile::fromSupplier($supplier);
        $productType = $this->upstreamProductType($supplier, $hostId, $resolvedJwt);
        $v1Available = $this->consoleV1Available($supplier, $hostId, $resolvedJwt);
        $channels = $profile->consoleChannels($productType, $v1Available);

        $lastError = null;

        foreach ($channels as $channel) {
            try {
                if ($channel === ZjmfCompatibilityProfile::CONSOLE_V1) {
                    $module = ZjmfCompatibilityProfile::consoleV1Module($productType);
                    $uri = "/console/v1/{$module}/{$hostId}/".($v1Action ?? $classicFunc);
                    $method = strtolower((string) ($v1Method ?? ($isGet ? 'get' : 'post')));

                    return match ($method) {
                        'put' => $this->transport->put($supplier, $uri, $payload, $resolvedJwt),
                        'delete' => $this->transport->delete($supplier, $uri, $payload, $resolvedJwt),
                        'get' => $this->transport->get($supplier, $uri, $resolvedJwt, $payload),
                        default => $this->transport->post($supplier, $uri, $payload, $resolvedJwt),
                    };
                }

                return $this->postDefaultModuleAction($supplier, $hostId, $classicFunc, $payload, $resolvedJwt);
            } catch (\Throwable $exception) {
                $lastError = $exception;

                if (! str_contains($exception->getMessage(), 'HTTP 404')) {
                    throw $exception;
                }
            }
        }

        throw $lastError ?? new BusinessException('上游控制台指令执行失败', 50000);
    }

    /**
     * 上游 host/header 的原始 data 载荷（含 module_client_area /
     * module_client_main_area / module_button / module_chart 等字段），
     * 供中间层透传给自己的下游，行为对齐魔方财务中间层。
     *
     * @return array<string, mixed>
     */
    public function getHostHeaderPayload(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $response = $this->getHostHeader($supplier, $hostId, $jwt);

        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    public function getHostDetail(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->transport->getHostDetail($supplier, $hostId, $this->resolveJwt($supplier, $jwt));
    }

    /**
     * @return array{response: array, headers: array<int, string>, http_code: int, content_type: string}
     */
    public function getHostDetailWithMeta(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->transport->getHostDetailWithMeta($supplier, $hostId, $this->resolveJwt($supplier, $jwt));
    }

    public function getVncUrl(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        // more=1 让上游返回可直接进 noVNC 的参数（vnc_url/vnc_pass）；
        // v10 通道不需要该参数，多余字段上游会忽略。
        return $this->dispatchConsoleAction($supplier, $hostId, 'vnc', ['more' => 1], $jwt);
    }

    public function powerAction(Supplier $supplier, int $hostId, string $action, ?string $jwt = null): array
    {
        return $this->dispatchConsoleAction($supplier, $hostId, $action, [], $jwt);
    }

    /**
     * 暂停上游主机（默认模块 func=suspend）。
     */
    public function suspendHost(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->postDefaultModuleAction($supplier, $hostId, 'suspend', [], $jwt);
    }

    /**
     * 解除暂停上游主机（默认模块 func=unsuspend）。
     */
    public function unsuspendHost(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->postDefaultModuleAction($supplier, $hostId, 'unsuspend', [], $jwt);
    }

    public function getModuleStatus(Supplier $supplier, int $hostId, string $type = 'host', ?string $jwt = null): array
    {
        // v10 的电源状态是 GET /status；经典协议是 func=status。$type 目前只有 host 口径
        // 走这条链路（重装进度是另一套接口，不能复用电源状态）。
        return $this->dispatchConsoleAction($supplier, $hostId, 'status', [], $jwt, isGet: true);
    }

    public function getReinstallOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $response = $this->getHostHeader($supplier, $hostId, $jwt);
        $payload = is_array($response['data'] ?? null) ? $response['data'] : [];
        $response['data'] = $this->normalizeReinstallOptions($payload);

        return $response;
    }

    public function resetPassword(Supplier $supplier, int $hostId, string $password, ?string $jwt = null): array
    {
        // 经典协议动作名是 crack_pass，v10 是 reset_password
        return $this->dispatchConsoleAction($supplier, $hostId, 'crack_pass', [
            'password' => $password,
        ], $jwt, v1Action: 'reset_password');
    }

    public function reinstall(Supplier $supplier, int $hostId, string $osId, ?string $jwt = null): array
    {
        return $this->dispatchConsoleAction($supplier, $hostId, 'reinstall', [
            'os' => $osId,
        ], $jwt);
    }

    public function getSupportedModules(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $response = $this->getHostHeader($supplier, $hostId, $jwt);
        $payload = is_array($response['data'] ?? null) ? $response['data'] : [];
        $modules = $this->normalizeSupportedModules($payload);

        if ($this->hasMonitorCharts($payload)) {
            $modules[] = [
                'type' => 'default',
                'function' => 'charts',
                'name' => '图表',
                'select' => $this->monitorChartOptions($payload),
            ];
        }

        $response['data'] = $modules;

        return $response;
    }

    public function getMonitorChart(Supplier $supplier, int $hostId, array $query, ?string $jwt = null): array
    {
        $type = $this->normalizeMonitorType((string) ($query['type'] ?? ''));

        return $this->transport->get(
            $supplier,
            "/provision/chart/{$hostId}",
            $this->resolveJwt($supplier, $jwt),
            $this->buildMonitorChartQuery($hostId, $type, $query),
        );
    }

    /**
     * @param  array<string, array{type?: string, start?: int|string, end?: int|string, select?: string}>  $queries
     * @return array<string, array{status_code: int, response: array, error?: string}>
     */
    public function getMonitorCharts(Supplier $supplier, int $hostId, array $queries, ?string $jwt = null): array
    {
        $requests = [];

        foreach ($queries as $key => $query) {
            $type = $this->normalizeMonitorType((string) ($query['type'] ?? ''));
            $requests[(string) $key] = [
                'uri' => "/provision/chart/{$hostId}",
                'query' => $this->buildMonitorChartQuery($hostId, $type, $query),
            ];
        }

        return $this->transport->parallelGet($supplier, $requests, $this->resolveJwt($supplier, $jwt));
    }

    /**
     * 取自定义面板 HTML。
     *
     * @param  string  $apiUrl  上游渲染面板时要写入表单动作地址的同系统地址
     *                          （魔方财务中间层会下传自己的 /provision/custom/{id}）。
     */
    public function fetchCustomModulePage(
        Supplier $supplier,
        int $hostId,
        string $moduleKey,
        ?string $jwt = null,
        string $apiUrl = '',
    ): string {
        $resolvedJwt = $this->resolveJwt($supplier, $jwt);

        // 走魔方财务 API 协议端点（home/provision/postClientAreaContent）：
        // 入参 post.id = 上游主机 id、post.key = 自定义 tab 标识、post.now_jwt = API JWT、
        // post.api_url = 面板内表单要提交到的同系统地址，响应为 {status:200, data:{html}}。
        // 该端点用 API JWT 鉴权；若走客户区路由 GET /provision/custom/content，
        // 它只认客户区登录会话（client_user_login_token_ 缓存），API JWT 必然取不到内容。
        $payloadData = [
            'id' => $hostId,
            'key' => $moduleKey,
            'now_jwt' => $resolvedJwt,
        ];
        if (trim($apiUrl) !== '') {
            $payloadData['api_url'] = trim($apiUrl);
        }

        $response = $this->transport->post(
            $supplier,
            '/zjmf_api/provision/custom/content',
            $payloadData,
            $resolvedJwt,
            self::FORM_HEADERS,
            ['jwt' => $resolvedJwt],
        );

        $payload = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $html = $payload['html'] ?? null;

        return is_string($html) ? $this->normalizeModulePageBody($html) : '';
    }

    /**
     * Returns the same-system form action used by ZJMF custom modules.
     *
     * 动作端点用客户区路径 /provision/custom/{hostId}：魔方财务上游只注册了
     * 该动作路由（home/provision/customFunc），它同样接受 API JWT 鉴权。
     * 取内容走的是另一个端点 /zjmf_api/provision/custom/content。
     */
    public function getCustomModuleActionEndpoint(Supplier $supplier, int $hostId): string
    {
        throw_if($hostId <= 0, new BusinessException('上游实例参数无效', 42200));

        return $this->resolveSupplierRootUrl($supplier)."/provision/custom/{$hostId}";
    }

    private function postDefaultModuleAction(
        Supplier $supplier,
        int $hostId,
        string $func,
        array $payload = [],
        ?string $jwt = null
    ): array {
        // is_api=1 是魔方下游调用的固定契约：上游据此跳过操作二次验证、并决定
        // VNC 是否返回可直连的 noVNC 参数（ProvisionController 对 more/is_api 双重判定）。
        // 缺这个字段时，上游开启二次验证的账号所有指令都会被 400「请输入验证码」。
        return $this->transport->post(
            $supplier,
            '/provision/default',
            array_merge(['id' => $hostId, 'func' => $func, 'is_api' => 1], $payload),
            $this->resolveJwt($supplier, $jwt),
            self::FORM_HEADERS
        );
    }

    private function getHostHeader(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->transport->get($supplier, '/host/header', $this->resolveJwt($supplier, $jwt), [
            'host_id' => $hostId,
            'source' => 'API',
        ]);
    }

    /**
     * @return array{os: array<int, array{os_id: string, name: string, group_name: string}>, os_group: array<int, array{group_name: string, img: string}>}
     */
    private function normalizeReinstallOptions(array $payload): array
    {
        $groupNames = [];
        $groups = [];

        foreach ((array) ($payload['cloud_os_group'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $groupId = trim((string) ($item['id'] ?? ''));
            $groupName = trim((string) ($item['name'] ?? ''));

            if ($groupName === '') {
                continue;
            }

            if ($groupId !== '') {
                $groupNames[$groupId] = $groupName;
            }

            $groups[] = [
                'group_name' => $groupName,
                'img' => trim((string) ($item['img'] ?? '')),
            ];
        }

        $osList = [];

        foreach ((array) ($payload['cloud_os'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $osId = trim((string) ($item['id'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));

            if ($osId === '' || $name === '') {
                continue;
            }

            $groupName = trim((string) ($item['group_name'] ?? ''));
            if ($groupName === '') {
                $groupName = $groupNames[trim((string) ($item['group'] ?? ''))] ?? '默认分组';
            }

            $osList[] = [
                'os_id' => $osId,
                'name' => $name,
                'group_name' => $groupName,
            ];
        }

        return [
            'os' => $osList,
            'os_group' => $groups,
        ];
    }

    /**
     * @return array<int, array{type: string, function: string, name: string, select: array<int, array{value: string, label: string}>|string}>
     */
    private function normalizeSupportedModules(array $payload): array
    {
        $moduleButtons = is_array($payload['module_button'] ?? null) ? $payload['module_button'] : [];
        $groups = array_is_list($moduleButtons) ? ['' => $moduleButtons] : $moduleButtons;
        $modules = [];

        foreach ($groups as $select => $items) {
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $function = trim((string) ($item['function'] ?? $item['func'] ?? ''));
                if ($function === '') {
                    continue;
                }

                $modules[] = [
                    'type' => trim((string) ($item['type'] ?? '')),
                    'function' => $function,
                    'name' => trim((string) ($item['name'] ?? '')),
                    'select' => trim((string) ($item['select'] ?? $select)),
                ];
            }
        }

        foreach ((array) ($payload['module_client_area'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $function = trim((string) ($item['key'] ?? ''));
            if ($function === '') {
                continue;
            }

            $modules[] = [
                'type' => 'custom',
                'function' => $function,
                'name' => trim((string) ($item['name'] ?? '')),
                'select' => 'client_area',
            ];
        }

        return $modules;
    }

    private function hasMonitorCharts(array $payload): bool
    {
        // 魔方口径：module_chart 为空 = 上游没声明任何图表能力，客户区不渲染图表区。
        // 此前把空数组也判成"有图表"，导致前端渲染出注定拿不到数据的假监控卡片。
        $value = $payload['module_chart'] ?? $payload['host_data']['module_chart'] ?? false;

        return $value === true || $value === 1 || $value === '1' || (is_array($value) && $value !== []);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function monitorChartOptions(array $payload): array
    {
        $configured = $payload['module_chart'] ?? $payload['host_data']['module_chart'] ?? [];
        $configured = is_array($configured) ? $configured : [];
        $options = [];

        foreach ($configured as $key => $item) {
            $value = is_array($item)
                ? trim((string) ($item['value'] ?? $item['type'] ?? $item['key'] ?? ''))
                : trim((string) (is_string($key) ? $key : $item));
            $label = is_array($item)
                ? trim((string) ($item['label'] ?? $item['name'] ?? $item['title'] ?? ''))
                : '';
            $value = $this->normalizeMonitorType($value);

            if ($value !== '') {
                $options[$value] = [
                    'value' => $value,
                    'label' => $label !== '' ? $label : $this->monitorTypeLabel($value),
                ];
            }
        }

        return $options !== [] ? array_values($options) : [];
    }

    private function normalizeMonitorType(string $type): string
    {
        return trim($type);
    }

    /**
     * @param  array{start?: int|string, end?: int|string, select?: string}  $query
     * @return array{id: int, type: string, start: int, end: int, select?: string}
     */
    private function buildMonitorChartQuery(int $hostId, string $type, array $query): array
    {
        $payload = [
            'id' => $hostId,
            'type' => $type,
            'start' => $this->normalizeMonitorTimestampMilliseconds($query['start'] ?? null),
            'end' => $this->normalizeMonitorTimestampMilliseconds($query['end'] ?? null),
        ];

        $select = trim((string) ($query['select'] ?? ''));
        if ($select !== '') {
            $payload['select'] = $select;
        }

        return $payload;
    }

    private function normalizeMonitorTimestampMilliseconds(mixed $value): int
    {
        $timestamp = is_numeric($value) ? (int) $value : 0;

        if ($timestamp > 0 && $timestamp < 100000000000) {
            return $timestamp * 1000;
        }

        return max($timestamp, 0);
    }

    /**
     * 上游 module_chart 的 type 取值（cpu / disk / memory / flow），
     * 用于上游未给 title 时的兜底显示名。
     */
    private function monitorTypeLabel(string $type): string
    {
        return match ($type) {
            'cpu' => 'CPU',
            'bw', 'flow' => '带宽',
            'disk', 'disk_io' => '磁盘 I/O',
            'memory' => '内存',
            default => $type,
        };
    }

    private function resolveJwt(Supplier $supplier, ?string $jwt): string
    {
        $jwt = trim((string) $jwt);

        return $jwt !== '' ? $jwt : $this->transport->login($supplier);
    }

    private function normalizeModulePageBody(string $body): string
    {
        $body = trim($body, "\xEF\xBB\xBF");
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return $body;
        }

        $payload = $decoded['data'] ?? $decoded;

        if (is_string($payload) && trim($payload) !== '') {
            return $payload;
        }

        if (is_array($payload)) {
            foreach (['html', 'content', 'view', 'template'] as $key) {
                $value = $payload[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }

        return $body;
    }

    private function resolveSupplierRootUrl(Supplier $supplier): string
    {
        $baseUrl = trim((string) $supplier->api_url);
        $parts = parse_url($baseUrl);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return rtrim($baseUrl, '/');
        }

        $rootUrl = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $rootUrl .= ':'.$parts['port'];
        }

        return $rootUrl;
    }
}
