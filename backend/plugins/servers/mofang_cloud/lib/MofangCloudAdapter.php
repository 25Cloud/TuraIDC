<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\MofangCloud\Lib;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUpstreamBinding;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\SupplierPluginBinding;
use App\Services\Upstream\Contracts\ProvidesConsoleAccess;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesHostSuspension;
use App\Services\Upstream\Contracts\ProvidesHostTermination;
use App\Services\Upstream\Contracts\ProvidesOrderProvisioning;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use Throwable;

/**
 * 魔方云能力门面：把平台要的方法名翻译成「按代际选出来的能力实现」。
 *
 * 平台只认 `['status' => 200, 'msg' => ..., 'data' => ...]` 这一套返回结构，
 * 一切代际差异（老版 `/v1` 与新版的字段、状态串、错误语义）都收敛在
 * `CloudOperations` 的两套实现里，本类不出现任何分支判断路径/字段。
 */
final class MofangCloudAdapter implements ProvidesConsoleAccess, ProvidesConsoleRuntime, ProvidesHostSuspension, ProvidesHostTermination, ProvidesOrderProvisioning, ProvidesStatusSync
{
    /**
     * @var array<int, CloudOperations>
     */
    private array $operationsCache = [];

    public function __construct(
        private readonly CloudClientFactory $clients,
    ) {}

    /**
     * 魔方云没有会话令牌（老版用 access-token、新版用请求签名，都在客户端内部维护），
     * 这里只回传代际标识，供平台把同一个「会话」贯穿一次请求链路。
     */
    public function login(Supplier $supplier): string
    {
        return 'mofang-cloud:'.$this->client($supplier)->mode()->value;
    }

    public function refreshJwt(Supplier $supplier): string
    {
        return $this->login($supplier);
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserProfile(Supplier $supplier): array
    {
        $credentials = $this->client($supplier)->credentials();

        return [
            'status' => 200,
            'msg' => 'ok',
            'data' => [
                'mode' => $credentials->mode->value,
                'mode_label' => $credentials->mode->label(),
                'base_url' => $credentials->baseUrl,
                'account' => $credentials->account,
                'account_type' => $credentials->agentAccount ? 'agent' : 'admin',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getHostDetail(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->ok($this->operations($supplier)->hostDetail($hostId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getModuleStatus(Supplier $supplier, int $hostId, string $type = 'host', ?string $jwt = null): array
    {
        return $this->ok($this->operations($supplier)->hostStatus($hostId));
    }

    /**
     * @return array<string, mixed>
     */
    public function powerAction(Supplier $supplier, int $hostId, string $action, ?string $jwt = null): array
    {
        return $this->ok($this->operations($supplier)->power($action, $hostId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getVncUrl(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->ok($this->operations($supplier)->vnc($hostId));
    }

    /**
     * @return array<string, mixed>
     */
    public function resetPassword(Supplier $supplier, int $hostId, string $password, ?string $jwt = null): array
    {
        return $this->ok($this->operations($supplier)->resetPassword($hostId, $password));
    }

    /**
     * 重装选项：平台要的是 `data.os[]` 里的 `os_id/name/group_name`，
     * 而两代魔方云给的镜像结构各不相同，这里统一摊平。
     *
     * @return array<string, mixed>
     */
    public function getReinstallOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $options = $this->operations($supplier)->reinstallOptions($hostId);

        $os = [];
        $groups = [];
        foreach ($options as $option) {
            $osId = trim((string) ($option['id'] ?? ''));
            $name = trim((string) ($option['name'] ?? ''));
            if ($osId === '' || $name === '') {
                continue;
            }

            $groupName = trim((string) ($option['group'] ?? '')) ?: '默认分组';
            $os[] = [
                'os_id' => $osId,
                'name' => $name,
                'group_name' => $groupName,
            ];

            if (! isset($groups[$groupName])) {
                $groups[$groupName] = ['group_name' => $groupName, 'img' => ''];
            }
        }

        return $this->ok([
            'os' => $os,
            'os_group' => array_values($groups),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function reinstall(Supplier $supplier, int $hostId, string $osId, ?string $jwt = null): array
    {
        $result = $this->operations($supplier)->reinstall($hostId, ['os_id' => $osId]);

        $data = [];
        if (isset($result['password']) && trim((string) $result['password']) !== '') {
            $data['password'] = (string) $result['password'];
        }
        if (isset($result['username']) && trim((string) $result['username']) !== '') {
            $data['username'] = (string) $result['username'];
        }

        return $this->ok($data, trim((string) ($result['message'] ?? '')) ?: '重装系统指令已提交');
    }

    /**
     * @return array<string, mixed>
     */
    public function getMonitorChart(Supplier $supplier, int $hostId, array $query, ?string $jwt = null): array
    {
        return $this->ok([
            'list' => $this->operations($supplier)->trafficChart($hostId, $query),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $queries
     * @return array<string, mixed>
     */
    public function getMonitorCharts(Supplier $supplier, int $hostId, array $queries, ?string $jwt = null): array
    {
        $operations = $this->operations($supplier);
        $charts = [];

        foreach ($queries as $key => $query) {
            $charts[is_string($key) ? $key : (string) $key] = $operations->trafficChart(
                $hostId,
                is_array($query) ? $query : []
            );
        }

        return $this->ok(['charts' => $charts]);
    }

    /**
     * @return array<string, mixed>
     */
    public function suspendHost(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->ok($this->operations($supplier)->suspend($hostId));
    }

    /**
     * @return array<string, mixed>
     */
    public function unsuspendHost(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->ok($this->operations($supplier)->unsuspend($hostId));
    }

    /**
     * @return array<string, mixed>
     */
    public function terminateHost(Supplier $supplier, int $hostId, string $reason = '', ?string $jwt = null): array
    {
        return $this->ok($this->operations($supplier)->terminate($hostId));
    }

    /**
     * 批量状态同步：逐台读详情 + 电源状态，形状与平台既有驱动一致。
     *
     * 详情读不到视为真故障（实例可能已被上游删除），整条判失败；
     * 电源状态是辅助信息，失败只降级为「仅同步详情」并带上 runtime_error。
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function syncServiceStatuses(Supplier $supplier, array $items, int $chunkSize = 10): array
    {
        $operations = $this->operations($supplier);
        $results = [];

        foreach ($items as $item) {
            $serviceId = (int) ($item['service_id'] ?? 0);
            $hostId = (int) ($item['host_id'] ?? 0);
            if ($serviceId <= 0 || $hostId <= 0) {
                continue;
            }

            try {
                $host = $operations->hostDetail($hostId);
            } catch (Throwable $exception) {
                $results[$serviceId] = ['error' => $exception->getMessage()];

                continue;
            }

            $runtime = [];
            $runtimeError = null;

            try {
                $status = $operations->hostStatus($hostId);
                $runtime = [
                    'status' => (string) ($status['status'] ?? ''),
                    'des' => (string) ($status['task_name'] ?? ''),
                    'task' => (string) ($status['task'] ?? ''),
                ];
            } catch (Throwable $exception) {
                $runtimeError = $exception->getMessage();
            }

            $results[$serviceId] = [
                'host' => $host,
                'runtime' => $runtime,
                'runtime_error' => $runtimeError,
            ];
        }

        return ['services' => $results];
    }

    /**
     * 安全组列表：平台的安全组页会先看驱动有没有这个具名方法。
     *
     * @return array<string, mixed>
     */
    public function getSecurityGroups(Supplier $supplier, int $page = 1, int $limit = 9999, ?string $jwt = null): array
    {
        return $this->ok([
            'list' => $this->operations($supplier)->securityGroups(0),
        ]);
    }

    /**
     * 下单开通：驱动内完成「上游建实例 → 回读详情」，编排层只调这一个方法。
     *
     * @param  ?Service  $existingService  本地既有实例，用于幂等回查
     * @return array<string, mixed>
     */
    public function provisionOrder(Order $order, Supplier $supplier, ?Service $existingService = null): array
    {
        $product = $order->product;
        if (! $product instanceof Product) {
            throw new BusinessException('商品信息不存在，无法自动开通', 42200);
        }

        $operations = $this->operations($supplier);

        // 幂等：本地已经绑定过上游实例时只核实其存在，绝不重复下单。
        $existingHostId = (int) ($existingService?->provision_data['upstream_host_id'] ?? 0);
        if ($existingService instanceof Service && $existingHostId <= 0) {
            $existingHostId = (int) ($existingService->upstreamBinding?->upstream_service_id ?? 0);
        }

        if ($existingHostId > 0) {
            $detail = $operations->hostDetail($existingHostId);

            return [
                'requested_host' => (string) (($existingService?->provision_data['requested_host'] ?? '') ?: ''),
                'upstream_host_ids' => [$existingHostId],
                'upstream_host_id' => $existingHostId,
                'host_detail' => $detail,
            ];
        }

        $params = $this->buildProvisionParams($order, $product, $supplier);

        $result = $operations->provision($params);
        if (($result['ok'] ?? false) !== true) {
            throw new BusinessException($this->messageOf($result), 42200);
        }

        $hostId = $result['host_id'] ?? 0;
        if ((string) $hostId === '' || (int) $hostId <= 0) {
            throw new BusinessException('上游未返回实例 ID，开通结果无法确认', 42200);
        }

        $hostId = (int) $hostId;
        $detail = [];

        try {
            // 刚开通的实例可能还在装系统，详情读不到不影响开通结果。
            $detail = $operations->hostDetail($hostId);
        } catch (Throwable) {
            $detail = [];
        }

        return [
            'requested_host' => (string) ($params['hostname'] ?? ''),
            'upstream_host_ids' => [$hostId],
            'upstream_host_id' => $hostId,
            'host_detail' => $detail,
        ];
    }

    /**
     * 魔方云没有「主机名生成规则」这种上游配置，返回空载荷让平台回退本地规则。
     *
     * @return array<string, mixed>
     */
    public function getProductProvisionConfig(Supplier $supplier, int $productId): array
    {
        return ['status' => 200, 'msg' => 'ok', 'data' => []];
    }

    /**
     * 流量包购买：魔方云是「上游直接加量」，不产生上游账单，因此账单号恒为 0。
     *
     * @param  array<string, mixed>  $configOption
     * @return array<string, mixed>
     */
    public function purchaseTrafficPackage(
        Supplier $supplier,
        int $hostId,
        string $mode = 'flowpacket',
        array $configOption = [],
        int $flowPacketId = 0,
        string $rootUrl = '',
        ?string $jwt = null,
    ): array {
        $operations = $this->operations($supplier);
        $this->ok($operations->buyFlowPacket($hostId, $flowPacketId));

        $detail = [];
        try {
            $detail = $operations->hostDetail($hostId);
        } catch (Throwable) {
            $detail = [];
        }

        return [
            'upstream_invoice_id' => 0,
            'host_detail' => $detail,
        ];
    }

    /**
     * 开通入参归一：把订单快照里的配置翻译成两代实现共用的键集合。
     *
     * 值缺失一律不传（`null` / `''` / `0` 视为未指定），避免把默认值当成用户选择发给上游。
     *
     * @return array<string, mixed>
     */
    private function buildProvisionParams(Order $order, Product $product, Supplier $supplier): array
    {
        $snapshot = is_array($order->config_snapshot ?? null) ? (array) $order->config_snapshot : [];
        $userId = (int) ($order->user_id ?? 0);
        $prefix = $this->client($supplier)->credentials()->userPrefix;

        return array_filter([
            'product_id' => $this->resolveUpstreamProductId($product, $supplier),
            'hostname' => $this->stringValue($snapshot, ['hostname', 'host', 'domain']),
            'password' => $this->stringValue($snapshot, ['password', 'root_password', 'rootpass']),
            'os_id' => $this->stringValue($snapshot, ['os_id', 'os', 'image', 'mirror_os_id']),
            'area_id' => $this->intValue($snapshot, ['area_id', 'area', 'house_id']),
            'cpu' => $this->intValue($snapshot, ['cpu', 'cpu_num', 'cores']),
            'memory' => $this->intValue($snapshot, ['memory', 'memory_mb', 'ram']),
            'disk' => $this->intValue($snapshot, ['disk', 'disk_size', 'system_disk']),
            'bandwidth' => $this->intValue($snapshot, ['bandwidth', 'bwlimit', 'bw']),
            'flow' => $this->intValue($snapshot, ['flow', 'traffic', 'traffic_quota']),
            'ip_num' => $this->intValue($snapshot, ['ip_num', 'ipnum']),
            'ipv6_num' => $this->intValue($snapshot, ['ipv6_num', 'ipv6num']),
            'billing_cycle' => (string) ($order->billing_cycle ?? ''),
            'quantity' => max(1, (int) ($order->quantity ?? 1)),
            'client_username' => $userId > 0 ? $prefix.$userId : '',
            'client_user_id' => $userId,
            'config_options' => $snapshot,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== 0 && $value !== []);
    }

    private function resolveUpstreamProductId(Product $product, Supplier $supplier): int
    {
        $query = ProductUpstreamBinding::query()->where('product_id', (int) $product->id);
        $bindingIds = SupplierPluginBinding::query()
            ->where('supplier_id', (int) $supplier->id)
            ->pluck('id')
            ->all();

        if ($bindingIds !== []) {
            $query->whereIn('supplier_plugin_binding_id', $bindingIds);
        }

        return (int) ($query->orderByDesc('id')->value('upstream_product_id') ?? 0);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, string>  $keys
     */
    private function stringValue(array $snapshot, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $snapshot[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, string>  $keys
     */
    private function intValue(array $snapshot, array $keys): int
    {
        foreach ($keys as $key) {
            $value = $snapshot[$key] ?? null;
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return 0;
    }

    private function operations(Supplier $supplier): CloudOperations
    {
        $supplierId = (int) $supplier->id;
        if (isset($this->operationsCache[$supplierId])) {
            return $this->operationsCache[$supplierId];
        }

        $client = $this->client($supplier);
        $operations = match ($client->mode()) {
            CloudApiMode::Legacy => new LegacyCloudOperations($client),
            CloudApiMode::Dcims => new DcimsCloudOperations($client),
        };

        $this->operationsCache[$supplierId] = $operations;

        return $operations;
    }

    /**
     * 能力实现统一返回 `['ok' => bool, 'message' => string, ...]`：
     * 失败直接抛出，成功则包装成平台约定的 `status/msg/data`。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ok(array $data, string $message = 'ok'): array
    {
        if (array_key_exists('ok', $data)) {
            if ($data['ok'] !== true) {
                throw new BusinessException($this->messageOf($data), 42200);
            }

            $message = $this->messageOf($data) ?: $message;
            $data = is_array($data['data'] ?? null) ? $data['data'] : [];
        }

        return [
            'status' => 200,
            'msg' => $message !== '' ? $message : 'ok',
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function messageOf(array $data): string
    {
        $message = trim((string) ($data['message'] ?? ''));

        return $message !== '' ? $message : '魔方云操作失败';
    }

    private function client(Supplier $supplier): CloudApiClient
    {
        return $this->clients->make($supplier);
    }
}
