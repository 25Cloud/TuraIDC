<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;

final class ZjmfConsoleService
{
    private const FORM_HEADERS = ['content-type: application/x-www-form-urlencoded'];

    public function __construct(
        private readonly ZjmfFinanceTransport $transport,
    ) {}

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
        return $this->postDefaultModuleAction($supplier, $hostId, 'vnc', [], $jwt);
    }

    public function powerAction(Supplier $supplier, int $hostId, string $action, ?string $jwt = null): array
    {
        return $this->postDefaultModuleAction($supplier, $hostId, $action, [], $jwt);
    }

    public function getModuleStatus(Supplier $supplier, int $hostId, string $type = 'host', ?string $jwt = null): array
    {
        return $this->postDefaultModuleAction($supplier, $hostId, 'status', [], $jwt);
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
        return $this->postDefaultModuleAction($supplier, $hostId, 'crack_pass', [
            'password' => $password,
        ], $jwt);
    }

    public function reinstall(Supplier $supplier, int $hostId, string $osId, ?string $jwt = null): array
    {
        return $this->postDefaultModuleAction($supplier, $hostId, 'reinstall', [
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

    public function fetchCustomModulePage(Supplier $supplier, int $hostId, string $moduleKey, ?string $jwt = null): string
    {
        $resolvedJwt = $this->resolveJwt($supplier, $jwt);
        $rootUrl = $this->resolveSupplierRootUrl($supplier);

        return $this->normalizeModulePageBody($this->transport->getText(
            $supplier,
            $rootUrl.'/provision/custom/content',
            $resolvedJwt,
            ['id' => $hostId, 'key' => $moduleKey, 'jwt' => $resolvedJwt],
            ['Authorization: JWT '.$resolvedJwt]
        ));
    }

    /**
     * Returns the same-system form action used by ZJMF custom modules.
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
        return $this->transport->post(
            $supplier,
            '/provision/default',
            array_merge(['id' => $hostId, 'func' => $func], $payload),
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
        $value = $payload['module_chart'] ?? $payload['host_data']['module_chart'] ?? false;

        return $value === true || $value === 1 || $value === '1' || is_array($value);
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
                ? trim((string) ($item['label'] ?? $item['name'] ?? ''))
                : '';
            $value = $this->normalizeMonitorType($value);

            if ($value !== '') {
                $options[$value] = [
                    'value' => $value,
                    'label' => $label !== '' ? $label : $this->monitorTypeLabel($value),
                ];
            }
        }

        return $options !== [] ? array_values($options) : [
            ['value' => 'cpu', 'label' => 'CPU'],
            ['value' => 'bw', 'label' => '带宽'],
            ['value' => 'disk_io', 'label' => '磁盘 I/O'],
            ['value' => 'memory', 'label' => '内存'],
        ];
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

    private function monitorTypeLabel(string $type): string
    {
        return match ($type) {
            'bw' => '带宽',
            'disk_io' => '磁盘 I/O',
            'memory' => '内存',
            default => 'CPU',
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
