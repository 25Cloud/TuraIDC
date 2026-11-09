<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\MofangCloud\Lib;

use Throwable;

/**
 * 魔方云新版本接口（dcims `/api/v1`）能力实现。
 *
 * 权威依据：魔方财务 3.8.0 的 `DcimsClient` / `DcimsAdapter`（本仓库已整理成
 * `docs` 之外的研究稿 `new-api-contract.md`）。这里只做两件事：
 * 1. 把平台要的能力翻译成 `POST|GET|PUT /api/v1/...` 的报文；
 * 2. 把上游 `{status,msg,data}` 信封与字段差异归一化成 {@see CloudOperations} 的返回结构。
 *
 * 三条硬约束（踩了就是「必然验签失败」或「上游忽略参数」）：
 * - 每个 POST/PUT 的 **path 与 JSON body 都必须带 `id`**：签名覆盖整个报文，少一个字段两端散列就不同；
 *   本类对所有写操作统一走 {@see self::body()}，不接受调用方漏字段。
 * - `product_id` 在本系统没有对应概念（那是魔方财务的 hostid），统一用实例 id 顶替，
 *   集中在 {@see self::productId()} 一处生成，后续要改口径只改一个方法。
 * - 业务成功只看 {@see DcimsCloudClient::isSuccess()}；失败一律抛 {@see CloudApiException}，
 *   绝不返回空数组让调用方以为「成功了但没数据」。
 *
 * 新版本接口确实没有的能力（安全组、NAT、监控统计、区域目录……）在这里显式登记并给中文原因，
 * 而不是发一个注定 404 的请求。
 */
final class DcimsCloudOperations implements CloudOperations
{
    /** 新版本接口未提供安全组/NAT 转发相关端点时的统一提示 */
    private const UNSUPPORTED_NETWORK_MESSAGE = '魔方云新版本接口不提供安全组/NAT 转发能力，请到魔方云面板操作';

    /** 新版本接口未提供强制断电/强制重启端点时的统一提示 */
    private const UNSUPPORTED_HARD_POWER_MESSAGE = '魔方云新版本接口不支持强制断电/强制重启，请用关机/重启';

    /** 新版本接口未提供快照/备份端点时的统一提示 */
    private const UNSUPPORTED_SNAPSHOT_MESSAGE = '魔方云新版本接口不提供快照/备份能力，请到魔方云面板操作';

    /** 新版本接口未提供 ISO 挂载与启动项端点时的统一提示 */
    private const UNSUPPORTED_ISO_MESSAGE = '魔方云新版本接口不提供 ISO 挂载与启动项设置，请到魔方云面板操作';

    /**
     * 改配里「新版本接口无法调整」的键 → 面向用户的中文名。
     *
     * 这些键出现在请求里（且值不是 null/''/0）时不会提交给上游：
     * 只请求了它们就直接报错，和可调整项混在一起时在返回的 message 里点明「未提交」。
     */
    private const CONFIG_UNSUPPORTED_KEYS = [
        'cpu' => 'CPU',
        'memory' => '内存',
        'disk_id' => '磁盘',
        'disk_size' => '磁盘容量',
        'ipv6_num' => 'IPv6 数量',
        'snap_num' => '快照配额',
        'backup_num' => '备份配额',
    ];

    /** 任务列表一次取多少条（契约 §5.5 的固定口径） */
    private const TASK_PAGE_SIZE = 20;

    public function __construct(
        private readonly CloudApiClient $client,
    ) {
        if ($client->mode() !== CloudApiMode::Dcims) {
            throw new CloudApiException(
                '新版本能力实现只能搭配新版本接口客户端，当前传入的是「'.$client->mode()->label().'」；'
                .'请在供应商配置里把接口代际改对，或换用对应代际的能力实现',
                null,
                CloudApiMode::Dcims
            );
        }
    }

    public function mode(): CloudApiMode
    {
        return CloudApiMode::Dcims;
    }

    /**
     * {@inheritDoc}
     */
    public function hostDetail(int $hostId): array
    {
        // GET 也要带 id：上游把它当查询参数并计入签名（契约 §5.6）
        $body = $this->client->request('GET', '/server/'.$hostId, ['id' => $this->pathId($hostId)]);
        $data = $this->assertSuccess($body, '获取实例详情');

        $primaryIp = $this->stringOf($this->firstValue($data, ['primary_ip', 'zhuip', 'main_ip', 'dedicatedip']));
        $assignedIps = $this->normalizeIpList($data, $primaryIp);

        return [
            'id' => (int) $this->firstValue($data, ['id', 'server_id'], $hostId),
            'domain' => $this->stringOf($this->firstValue($data, ['wltag', 'server_name', 'name', 'hostname'])),
            'domainstatus' => $this->normalizeDomainStatus($this->firstValue($data, ['status'], '')),
            'dedicatedip' => $primaryIp,
            'assignedips' => $assignedIps,
            'username' => $this->stringOf($this->firstValue($data, ['username', 'os_username'])),
            'password' => $this->stringOf($this->firstValue($data, ['password', 'os_password'])),
            'port' => (int) ($this->firstValue($data, ['port', 'remote_port'], 0) ?: 0),
            'os' => $this->osName($data),
            'os_id' => (int) ($this->firstValue($data, ['os_id', 'mirror_os_id'], 0) ?: 0),
            'bwusage' => $this->bytesToGb($this->firstValue($data, ['used_traffic'], 0)),
            'bwlimit' => (int) ($this->firstValue($data, ['limit_traffic', 'limit_flow'], 0) ?: 0),
            'bwlimit_included' => (int) round($this->bytesToGb($this->firstValue($data, ['free_quota'], 0))),
            'cpu' => $this->cpuCores($data),
            'memory' => $this->memoryMb($data),
            'disk' => $this->normalizeDiskList($data),
            'node_id' => (int) ($this->firstValue($data, ['node_id', 'server_node_id', 'node', 'host_id'], 0) ?: 0),
            'kvmid' => $this->stringOf($this->firstValue($data, ['kvmid', 'kvm_id', 'vm_id', 'uuid'])),
            'product_name' => $this->productName($data),
            'created_at' => $this->formatTimestamp($this->firstValue($data, ['active_time', 'created_at'], null)),
            'expires_at' => $this->formatTimestamp($this->firstValue($data, ['due_time', 'expire_time'], null)),
            'rescue' => $this->isRescue($data) ? 1 : 0,
            'iso' => $this->stringOf($this->firstValue($data, ['iso', 'iso_name', 'mounted_iso'])),
            'bootorder' => $this->stringOf($this->firstValue($data, ['bootorder', 'boot_order'])),
            'raw' => $body,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function hostStatus(int $hostId): array
    {
        $body = $this->client->request('GET', '/server/'.$hostId.'/power_status', ['id' => $this->pathId($hostId)]);
        $data = $this->assertSuccess($body, '获取实例电源状态');

        $power = strtolower(trim($this->stringOf($this->firstValue($data, ['power_status', 'status'], ''))));
        $task = trim($this->stringOf($this->firstValue($data, ['task', 'task_type'], '')));
        $taskName = trim($this->stringOf($this->firstValue($data, ['task_name', 'description'], '')));

        // power_status 只回答电源；有任务时上游会把任务名放在详情里，缺了再补一次详情查询
        if ($task === '' && $taskName === '') {
            [$task, $taskName] = $this->taskFieldsFromDetail($hostId);
        }

        return [
            'status' => $this->mapPowerStatus($power, $task),
            'task' => $task,
            'task_name' => $taskName,
            'raw' => $body,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function power(string $action, int $hostId): array
    {
        $action = strtolower(trim($action));

        if (in_array($action, ['hard_off', 'hard_reboot'], true)) {
            throw new CloudApiException(self::UNSUPPORTED_HARD_POWER_MESSAGE, null, CloudApiMode::Dcims);
        }

        $operation = match ($action) {
            'on' => 'power_on',
            'off' => 'power_off',
            'reboot' => 'power_reboot',
            default => throw new CloudApiException(
                '不支持的电源操作：'.$action.'（仅支持 on / off / reboot）',
                null,
                CloudApiMode::Dcims
            ),
        };

        $body = $this->client->request('POST', '/server/'.$hostId.'/'.$operation, $this->body($hostId));

        return $this->ok($body, '电源操作已提交');
    }

    /**
     * {@inheritDoc}
     */
    public function vnc(int $hostId): array
    {
        $body = $this->client->request('POST', '/server/'.$hostId.'/vnc', $this->body($hostId));
        $data = $this->assertSuccess($body, '获取 VNC 连接信息');

        return [
            'url' => $this->stringOf($this->firstValue($data, ['vnc_link_url', 'url', 'link'])),
            'token' => $this->stringOf($this->firstValue($data, ['token'])),
            'pass' => $this->stringOf($this->firstValue($data, ['pass', 'password'])),
            'raw' => $body,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function resetPassword(int $hostId, string $password, string $username = ''): array
    {
        $body = $this->client->request('POST', '/server/'.$hostId.'/password_reset', $this->body($hostId, [
            'new_password' => $password,
            // 空串表示沿用上游默认账号（root/administrator），由面板决定
            'username' => trim($username),
        ]));

        return $this->ok($body, '重置密码任务已提交');
    }

    /**
     * {@inheritDoc}
     */
    public function reinstallOptions(int $hostId): array
    {
        // 新接口的镜像目录是全局的，与具体实例无关；$hostId 只为对齐接口签名
        return $this->mirrorOs();
    }

    /**
     * {@inheritDoc}
     */
    public function reinstall(int $hostId, array $params): array
    {
        $osId = (int) ($params['os_id'] ?? $params['mirror_os_id'] ?? 0);
        if ($osId <= 0) {
            throw new CloudApiException('请选择要重装的操作系统镜像', null, CloudApiMode::Dcims);
        }

        $port = (int) ($params['port'] ?? $params['remote_port'] ?? 22);
        if ($port < 1 || $port > 65535) {
            $port = 22;
        }

        $payload = [
            'mirror_os_id' => $osId,
            'os_password' => (string) ($params['password'] ?? $params['os_password'] ?? ''),
            'remote_port' => $port,
            'boot_mode' => $this->enumOrDefault($params['boot_mode'] ?? null, ['bios', 'uefi'], 'bios'),
            'partition_mode' => $this->enumOrDefault($params['partition_mode'] ?? null, ['default', 'template'], 'default'),
            'format_type' => $this->formatType($params),
        ];

        // 以下字段契约要求「>0 或非空才传」，传空值会被上游当成显式清空
        $installTemplateId = (int) ($params['mirror_install_template_id'] ?? 0);
        if ($installTemplateId > 0) {
            $payload['mirror_install_template_id'] = $installTemplateId;
        }

        $partitionTemplateId = (int) ($params['partition_template_id'] ?? 0);
        if ($payload['partition_mode'] === 'template' && $partitionTemplateId > 0) {
            $payload['partition_template_id'] = $partitionTemplateId;
        }

        foreach (['disk_mapping', 'custom_partition'] as $key) {
            $value = $params[$key] ?? null;
            if (is_array($value) && $value !== []) {
                $payload[$key] = $value;
            }
        }

        $customTag = trim((string) ($params['custom_tag'] ?? ''));
        if ($customTag !== '') {
            $payload['custom_tag'] = $customTag;
        }

        $ghostVersion = (int) ($params['ghost_version'] ?? 0);
        if ($ghostVersion > 0) {
            $payload['ghost_version'] = $ghostVersion;
        }

        $body = $this->client->request('POST', '/server/'.$hostId.'/reinstall', $this->body($hostId, $payload));

        return $this->ok($body, '重装任务已提交');
    }

    /**
     * {@inheritDoc}
     */
    public function rescue(int $hostId, string $system = 'linux'): array
    {
        $system = strtolower(trim($system));
        if (! in_array($system, ['linux', 'windows'], true)) {
            $system = 'linux';
        }

        $body = $this->client->request('POST', '/server/'.$hostId.'/rescue', $this->body($hostId, [
            'rescue' => $system,
        ]));

        return $this->ok($body, '救援模式任务已提交');
    }

    /**
     * {@inheritDoc}
     */
    public function cancelTask(int $hostId): array
    {
        $body = $this->client->request('POST', '/server/'.$hostId.'/task/cancel', $this->body($hostId));

        return $this->ok($body, '已请求取消任务');
    }

    /**
     * {@inheritDoc}
     */
    public function taskStatus(int $hostId): array
    {
        $body = $this->client->request(
            'GET',
            '/server/'.$hostId.'/tasks',
            ['id' => $this->pathId($hostId)],
            [
                'page' => 1,
                'limit' => self::TASK_PAGE_SIZE,
                'orderby' => 'id',
                'sort' => 'desc',
            ]
        );

        $rows = $this->listFrom($this->assertSuccess($body, '获取实例任务列表'));
        $task = $this->latestRecognizedTask($rows);

        if ($task === null) {
            return [
                'running' => false,
                'task_type' => 0,
                'name' => '',
                'progress' => 0,
                'step' => '',
                'error' => '',
                'raw' => $body,
            ];
        }

        $status = strtolower(trim($this->stringOf($this->firstValue($task, ['status'], ''))));
        $running = in_array($status, ['wait', 'exec', 'processing', 'running'], true);

        $step = $this->stringOf($this->firstValue($task, ['progress_text'], $this->firstValue($task, ['description', 'message', 'name'], '')));
        $error = $this->stringOf($this->firstValue($task, ['fail_reason', 'error_msg'], ''));

        return [
            'running' => $running,
            'task_type' => (int) $this->mapTaskType($task['task_type'] ?? null),
            'name' => $this->stringOf($this->firstValue($task, ['mirror_os_name', 'description', 'message', 'name'], '')),
            'progress' => $this->taskProgress($status, $task),
            'step' => $step,
            'error' => $status === 'failed' ? ($error !== '' ? $error : '任务执行失败') : '',
            'raw' => $body,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function trafficUsage(int $hostId): array
    {
        $body = $this->client->request('GET', '/server/'.$hostId.'/traffic/usage', ['id' => $this->pathId($hostId)]);
        $data = $this->assertSuccess($body, '获取流量用量');

        $used = $this->bytesToGb($this->firstValue($data, ['used_traffic'], 0));
        // limit_flow 的单位就是 GB（契约 §附录 A）；0 表示不限
        $limit = (int) ($this->firstValue($data, ['limit_flow', 'limit_traffic'], 0) ?: 0);

        return [
            'used' => $used,
            'limit' => $limit,
            'extra' => $this->sumTrafficPackages($hostId),
            'percent' => $limit > 0 ? round(min(100.0, $used / $limit * 100), 2) : 0.0,
            // 新接口的用量响应不带「下次重置时间」，重置规则在实例上另配，这里留空
            'reset_at' => '',
            'raw' => $body,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function trafficChart(int $hostId, array $query): array
    {
        $params = ['id' => $this->pathId($hostId)];

        $start = $this->queryTimestamp($query['start'] ?? $query['start_time'] ?? null);
        if ($start > 0) {
            $params['start_time'] = $start;
        }

        $end = $this->queryTimestamp($query['end'] ?? $query['end_time'] ?? null);
        if ($end > 0) {
            $params['end_time'] = $end;
        }

        $body = $this->client->request('GET', '/server/'.$hostId.'/flowchart', $params);
        $data = $this->assertSuccess($body, '获取流量曲线');

        $rows = $this->listFrom($data);
        $points = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $time = $this->timestampSeconds($row['time'] ?? null);
            if ($time <= 0) {
                continue;
            }

            $points[] = [
                'time' => $time,
                'in' => $this->floatOf($row['in'] ?? 0),
                'out' => $this->floatOf($row['out'] ?? 0),
            ];
        }

        return $points;
    }

    /**
     * {@inheritDoc}
     */
    public function statistics(int $hostId, string $type, array $query): array
    {
        // 新版本接口没有监控统计端点（只有流量曲线），不要编造路径让用户看到 404
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function suspend(int $hostId): array
    {
        $body = $this->client->request('POST', '/server/'.$hostId.'/suspend', $this->body($hostId));

        return $this->ok($body, '实例已暂停');
    }

    /**
     * {@inheritDoc}
     */
    public function unsuspend(int $hostId): array
    {
        $body = $this->client->request('POST', '/server/'.$hostId.'/unsuspend', $this->body($hostId));

        return $this->ok($body, '实例已恢复');
    }

    /**
     * {@inheritDoc}
     */
    public function terminate(int $hostId): array
    {
        $body = $this->client->request('POST', '/server/'.$hostId.'/terminate', $this->body($hostId));

        return $this->ok($body, '实例已删除');
    }

    /**
     * {@inheritDoc}
     */
    public function mirrorOs(): array
    {
        $body = $this->client->request('GET', '/mirror_os/enable');
        $data = $this->assertSuccess($body, '获取镜像列表');

        return $this->flattenMirrorOs($data);
    }

    /**
     * {@inheritDoc}
     */
    public function areas(): array
    {
        // 新版本接口没有区域目录（`DcimAccesshash` 那一代才用 getArea）
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function securityGroups(int $hostId): array
    {
        throw new CloudApiException(self::UNSUPPORTED_NETWORK_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     */
    public function natRules(int $hostId): array
    {
        throw new CloudApiException(self::UNSUPPORTED_NETWORK_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     */
    public function createNatRule(int $hostId, array $payload): array
    {
        throw new CloudApiException(self::UNSUPPORTED_NETWORK_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteNatRule(int $hostId, int $ruleId): array
    {
        throw new CloudApiException(self::UNSUPPORTED_NETWORK_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无快照/备份列表端点（`DcimsClient` 里没有 snapshot 相关路径）。
     */
    public function snapshots(int $hostId): array
    {
        throw new CloudApiException(self::UNSUPPORTED_SNAPSHOT_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无快照/备份创建端点。
     */
    public function createSnapshot(int $hostId, array $params): array
    {
        throw new CloudApiException(self::UNSUPPORTED_SNAPSHOT_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无快照/备份删除端点。
     */
    public function deleteSnapshot(int $hostId, int $snapshotId): array
    {
        throw new CloudApiException(self::UNSUPPORTED_SNAPSHOT_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无快照回滚端点。
     */
    public function restoreSnapshot(int $hostId, int $snapshotId): array
    {
        throw new CloudApiException(self::UNSUPPORTED_SNAPSHOT_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无 ISO 列表端点；这里**故意抛异常**而不是返回空数组，
     * 否则前端会显示「没有可用 ISO」，把「上游没有这个能力」伪装成「当前确实一个都没有」。
     */
    public function isoImages(int $hostId): array
    {
        throw new CloudApiException(self::UNSUPPORTED_ISO_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无挂载 ISO 的端点。
     */
    public function mountIso(int $hostId, string $isoId): array
    {
        throw new CloudApiException(self::UNSUPPORTED_ISO_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无卸载 ISO 的端点。
     */
    public function unmountIso(int $hostId): array
    {
        throw new CloudApiException(self::UNSUPPORTED_ISO_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无启动项设置端点（实例启动顺序只能在面板里改）。
     */
    public function setBootOrder(int $hostId, string $order): array
    {
        throw new CloudApiException(self::UNSUPPORTED_ISO_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无安全组规则读取端点。
     */
    public function securityRules(int $hostId, int $groupId): array
    {
        throw new CloudApiException(self::UNSUPPORTED_NETWORK_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无安全组创建端点。
     */
    public function createSecurityGroup(int $hostId, array $params): array
    {
        throw new CloudApiException(self::UNSUPPORTED_NETWORK_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无安全组删除端点。
     */
    public function deleteSecurityGroup(int $hostId, int $groupId): array
    {
        throw new CloudApiException(self::UNSUPPORTED_NETWORK_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无安全组规则创建端点。
     */
    public function createSecurityRule(int $hostId, int $groupId, array $params): array
    {
        throw new CloudApiException(self::UNSUPPORTED_NETWORK_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无安全组规则删除端点。
     */
    public function deleteSecurityRule(int $hostId, int $groupId, int $ruleId): array
    {
        throw new CloudApiException(self::UNSUPPORTED_NETWORK_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口无「把安全组应用到实例」的端点。
     */
    public function applySecurityGroup(int $hostId, int $groupId): array
    {
        throw new CloudApiException(self::UNSUPPORTED_NETWORK_MESSAGE, null, CloudApiMode::Dcims);
    }

    /**
     * {@inheritDoc}
     *
     * 新版本接口只有四个可写的规格端点：入带宽、出带宽、流量上限、IPv4 数量（按地址组）。
     * CPU / 内存 / 磁盘 / IPv6 / 快照配额 / 备份配额在新接口里没有写端点，
     * 因此这里分三种情况处理：
     * 1. 只请求了不支持的项 → 直接抛合并后的中文异常，一个请求都不发；
     * 2. 支持项与不支持项混在一起 → 只提交支持项，并在 `message` 里点明哪些没提交（避免管理员以为全改了）；
     * 3. 一个可提交项都没有（全是 null/''/0）→ 抛「改配失败：没有可提交的变更项」，
     *    与老版实现保持同一成败语义，适配器不必按代际写特例。
     *
     * 顺序固定为 in_bw → out_bw → flow → ip_num：一个端点一次调用，无法回滚，
     * 固定顺序至少让「改到一半失败」的结果可预期，审计日志也能按顺序对上；
     * 中途失败时消息里会带上「已生效」的部分，管理员才知道到底改了哪些。
     */
    public function changeConfig(int $hostId, array $params): array
    {
        $unsupported = [];
        foreach (self::CONFIG_UNSUPPORTED_KEYS as $key => $label) {
            if ($this->isSpecified($params[$key] ?? null)) {
                $unsupported[] = $label;
            }
        }

        // 支持项：值同样按「null/''/0 视为不改」过滤
        $inBw = $this->isSpecified($params['in_bw'] ?? null) ? max(0, (int) $params['in_bw']) : null;
        $outBw = $this->isSpecified($params['out_bw'] ?? null) ? max(0, (int) $params['out_bw']) : null;
        $flow = $this->isSpecified($params['flow'] ?? null) ? max(0, (int) $params['flow']) : null;
        $ipNum = $this->isSpecified($params['ip_num'] ?? null) ? max(0, (int) $params['ip_num']) : null;
        // ip_group 单独给没有意义（没有数量就不构成一次调整），只在 ip_num 生效时使用
        $ipGroup = $this->isSpecified($params['ip_group'] ?? null) ? (int) $params['ip_group'] : 0;

        if ($inBw === null && $outBw === null && $flow === null && $ipNum === null) {
            if ($unsupported !== []) {
                throw new CloudApiException(
                    '魔方云新版本接口不支持调整'.implode('、', $unsupported).'，请到魔方云面板操作',
                    null,
                    CloudApiMode::Dcims
                );
            }

            // 与老版实现同一语义：没给出任何可提交项就是一次失败的改配，不返回「成功但什么都没做」
            throw new CloudApiException('改配失败：没有可提交的变更项', null, CloudApiMode::Dcims);
        }

        $applied = [];
        $appliedLabels = [];
        $raw = [];

        if ($inBw !== null) {
            $raw = $this->applyConfigChange($hostId, '/server/'.$hostId.'/in_bandwidth', ['in_bw' => $inBw], '入方向带宽', $appliedLabels);
            $applied[] = 'in_bw';
            $appliedLabels[] = '入方向带宽';
        }

        if ($outBw !== null) {
            $raw = $this->applyConfigChange($hostId, '/server/'.$hostId.'/out_bandwidth', ['out_bw' => $outBw], '出方向带宽', $appliedLabels);
            $applied[] = 'out_bw';
            $appliedLabels[] = '出方向带宽';
        }

        if ($flow !== null) {
            $raw = $this->applyConfigChange($hostId, '/server/'.$hostId.'/limit_flow', ['limit_flow' => $flow], '流量上限', $appliedLabels);
            $applied[] = 'flow';
            $appliedLabels[] = '流量上限';
        }

        if ($ipNum !== null) {
            // 契约 §4.4：按地址组给数量，ip_group 缺省 0 = 默认地址组
            $raw = $this->applyConfigChange($hostId, '/server/'.$hostId.'/ip', ['ip' => [['ip_group_id' => $ipGroup, 'num' => $ipNum]]], 'IPv4 数量', $appliedLabels);
            $applied[] = 'ip_num';
            $appliedLabels[] = 'IPv4 数量';
        }

        $message = '已调整：'.implode('、', $appliedLabels);
        if ($unsupported !== []) {
            $message .= '；以下项目新版本接口不支持，未提交：'.implode('、', $unsupported);
        }

        return [
            'ok' => true,
            'message' => $message,
            'applied' => $applied,
            'raw' => $raw,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * 新接口没有「下单」概念，只有 `POST /server/auto_assign`（分配一台可售实例）+ 补装系统两步：
     * 契约 §6.1 的报文只认服务器分组、镜像、带宽、流量上限与 IP 分组，
     * CPU/内存/磁盘这些规格由上游套餐决定，本平台传入也不会生效（见 §6.1 字段表）。
     */
    public function provision(array $params): array
    {
        $body = $this->client->request('POST', '/server/auto_assign', $this->provisionPayload($params));

        if (! DcimsCloudClient::isSuccess($body)) {
            throw new CloudApiException(
                '开通实例失败：'.DcimsCloudClient::messageOf($body),
                null,
                CloudApiMode::Dcims
            );
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $hostId = (int) ($data['id'] ?? $data['server_id'] ?? 0);
        if ($hostId <= 0) {
            throw new CloudApiException('开通实例失败：上游没有返回实例 id', null, CloudApiMode::Dcims);
        }

        // 契约 §6.2：auto_assign 只分配资源，系统要另发一次重装；失败不改变开通结论，只写进 message
        $reinstallBody = $this->client->request(
            'POST',
            '/server/'.$hostId.'/reinstall',
            $this->installPayload($hostId, $params, $data)
        );

        $message = '实例已创建';
        if (DcimsCloudClient::isSuccess($reinstallBody)) {
            $message .= '，已提交系统安装';
        } else {
            $message .= '，但补装系统失败：'.DcimsCloudClient::messageOf($reinstallBody).'（请到魔方云面板手动重装）';
        }

        return [
            'ok' => true,
            'host_id' => $hostId,
            'message' => $message,
            'raw' => [
                'auto_assign' => $body,
                'reinstall' => $reinstallBody,
            ],
        ];
    }

    /**
     * {@inheritDoc}
     *
     * 契约 §4.3：流量包购买固定永久有效（`expiry_type=permanent`），按周期过期的包要传 `cycle`，
     * 本平台没有「随实例到期」的语义，因此这里只买永久包。
     */
    public function buyFlowPacket(int $hostId, int $trafficGb): array
    {
        if ($trafficGb <= 0) {
            throw new CloudApiException('流量包大小必须大于 0', null, CloudApiMode::Dcims);
        }

        $body = $this->client->request('POST', '/server/'.$hostId.'/traffic/package', $this->body($hostId, [
            'quota_gb' => $trafficGb,
            'expiry_type' => 'permanent',
        ]));

        return $this->ok($body, '流量包已购买');
    }

    /**
     * 写操作的统一报文：`id` 与 `product_id` 永远由这里补齐。
     *
     * 契约 §5.6 明确要求「每个 POST/PUT 的路径与 JSON body 都要带 id」——
     * 签名把整段报文算进去，漏了 id 服务端算出来的散列必然不同。
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function body(int $hostId, array $extra = []): array
    {
        return array_merge($extra, [
            'id' => $this->pathId($hostId),
            'product_id' => $this->productId($hostId),
        ]);
    }

    /**
     * path 与 body 里的 id 必须是同一个字符串（上游两侧都会读，且都进签名）。
     */
    private function pathId(int $hostId): string
    {
        return (string) $hostId;
    }

    /**
     * 本系统没有魔方财务的 hostid，用实例 id 顶替 `product_id`。
     *
     * 上游把它当「归属哪个产品」的标记，只用于展示与配额分组；改成别的口径时只改这里。
     */
    private function productId(int $hostId): int
    {
        return $hostId;
    }

    /**
     * 开通报文：把平台归一参数翻译成契约 §6.1 的 `POST /server/auto_assign` 字段。
     *
     * 只提交上游认得的字段，并且**值为 null / '' / 0 的入参一律不下发**（接口 docblock 的硬约束）；
     * CPU/内存/磁盘/IPv6/数量/主机名/计费周期在 auto_assign 报文里没有对应字段——
     * 实例规格由上游套餐与服务器分组决定，这些键直接忽略，塞进去只会被上游当未知字段丢弃。
     *
     * 客户归属走 `client` 块（`client_username` / `client_user_id` → username / rel_user_id）；
     * `client_username` 为空时整块省略，不用空串占位。
     *
     * 服务器分组（`server_group_id`）是 auto_assign 的必填项，按 `config_options → area_id` 顺序取，
     * 一个都取不到就地报错，避免发一个必然被拒的请求。
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function provisionPayload(array $params): array
    {
        $config = is_array($params['config_options'] ?? null) ? $params['config_options'] : [];

        $serverGroupId = (int) ($this->firstValue($params, ['server_group_id'], 0)
            ?: $this->firstValue($config, ['server_group_id', 'server_group'], 0)
            ?: $this->firstValue($params, ['area_id'], 0));

        if ($serverGroupId <= 0) {
            throw new CloudApiException(
                '开通实例失败：未指定服务器分组（server_group_id），请先在魔方云面板确认可用的服务器分组',
                null,
                CloudApiMode::Dcims
            );
        }

        $osId = (int) ($params['os_id'] ?? 0);
        if ($osId <= 0) {
            throw new CloudApiException('开通实例失败：未选择操作系统镜像', null, CloudApiMode::Dcims);
        }

        $payload = [
            'server_group_id' => $serverGroupId,
            'mirror_os_id' => $osId,
        ];

        $productId = (int) ($params['product_id'] ?? 0);
        if ($productId > 0) {
            $payload['product_id'] = $productId;
        }

        // 客户归属：契约 §6.1 的 client 块。上游要的是「前缀+本地用户 id」的登录名，
        // 用户名由适配层拼好传进来；为空说明这单没有用户归属，宁可不写也不要造空串
        $clientUsername = trim($this->stringOf($params['client_username'] ?? ''));
        if ($clientUsername !== '') {
            $payload['client'] = [
                'username' => $clientUsername,
                'rel_user_id' => (int) ($params['client_user_id'] ?? 0),
                // 我们扮演的就是财务/业务系统，上游只认这个来源枚举
                'from_channel' => 'finance',
            ];
        }

        $bandwidth = (int) ($params['bandwidth'] ?? 0);
        if ($bandwidth > 0) {
            $payload['in_bw'] = $bandwidth;
            $payload['out_bw'] = $bandwidth;
        }

        $flow = (int) ($params['flow'] ?? 0);
        if ($flow > 0) {
            $payload['limit_flow'] = $flow;
        }

        $ipGroups = $this->provisionIpGroups($params, $config);
        if ($ipGroups !== []) {
            $payload['ip'] = $ipGroups;
        }

        $trafficReset = $this->trafficResetPayload($config['traffic_reset'] ?? null);
        if ($trafficReset !== null) {
            $payload['traffic_reset'] = $trafficReset;
        }

        // 生效/到期时间：平台归一参数里没有这两个键，只有 config_options 明确给出时才下发，
        // 由后续 sync 流程补正，不在这里按计费周期推算日期（推错会把实例直接算过期）
        $activeTime = $this->queryTimestamp($config['active_time'] ?? $config['created_at'] ?? null);
        if ($activeTime > 0) {
            $payload['active_time'] = $activeTime;
        }

        $dueTime = $this->queryTimestamp($config['due_time'] ?? $config['expire_at'] ?? null);
        if ($dueTime > 0) {
            $payload['due_time'] = $dueTime;
        }

        return $payload;
    }

    /**
     * 补装系统报文（契约 §6.2）：`auto_assign` 只分配资源，装系统要另发一次重装。
     *
     * 密码优先用下单参数，其次用上游分配结果，都没有就随机生成一个（否则实例装完无法登录）。
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $data  上游 auto_assign 的 data
     * @return array<string, mixed>
     */
    private function installPayload(int $hostId, array $params, array $data): array
    {
        $config = is_array($params['config_options'] ?? null) ? $params['config_options'] : [];

        $password = trim($this->stringOf($params['password'] ?? ''));
        if ($password === '') {
            $password = trim($this->stringOf($data['password'] ?? ''));
        }
        if ($password === '') {
            $password = $this->randomPassword();
        }

        $port = (int) ($this->firstValue($config, ['port', 'remote_port'], 0) ?: $this->firstValue($params, ['port'], 0));
        if ($port < 1 || $port > 65535) {
            $port = 22;
        }

        return $this->body($hostId, [
            'mirror_os_id' => (int) ($params['os_id'] ?? 0),
            'os_password' => $password,
            'remote_port' => $port,
            // 契约 §5.4 的默认值：不指定分区方案时按整盘格式化
            'boot_mode' => 'bios',
            'partition_mode' => 'default',
            'format_type' => 'full',
        ]);
    }

    /**
     * IPv4 分组 → `[['ip_group_id'=>, 'num'=>]]`（契约 §6.1 的 `ip` 字段）。
     *
     * 支持两种入参：`config_options.ip_group` 给分组映射（`{组id: 数量}`），
     * 或只给 `ip_num`（此时分组号取 `config_options.ip_group` 的标量值，缺省 0 = 默认分组）。
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $config
     * @return array<int, array{ip_group_id: int, num: int}>
     */
    private function provisionIpGroups(array $params, array $config): array
    {
        $groups = [];
        $ipGroup = $config['ip_group'] ?? null;

        if (is_array($ipGroup)) {
            foreach ($ipGroup as $groupId => $num) {
                $num = (int) $num;
                if ($num > 0) {
                    $groups[] = ['ip_group_id' => (int) $groupId, 'num' => $num];
                }
            }

            return $groups;
        }

        $ipNum = (int) ($params['ip_num'] ?? 0);
        if ($ipNum > 0) {
            $groups[] = [
                'ip_group_id' => is_numeric($ipGroup) ? (int) $ipGroup : 0,
                'num' => $ipNum,
            ];
        }

        return $groups;
    }

    /**
     * 流量重置计划（契约 §5.6 的三字段校验：日 1..28 或 99，时 0..23，分 0..59）。
     *
     * @return array{reset_day: int, reset_hour: int, reset_minute: int}|null
     */
    private function trafficResetPayload(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        foreach (['reset_day', 'reset_hour', 'reset_minute'] as $field) {
            if (! isset($value[$field]) || ! is_numeric($value[$field])) {
                return null;
            }
        }

        $day = (int) $value['reset_day'];
        $hour = (int) $value['reset_hour'];
        $minute = (int) $value['reset_minute'];

        $dayValid = ($day >= 1 && $day <= 28) || $day === 99;
        if (! $dayValid || $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return ['reset_day' => $day, 'reset_hour' => $hour, 'reset_minute' => $minute];
    }

    /**
     * 随机初始密码：16 位十六进制，只在「下单没给、上游也不回」时兜底。
     */
    private function randomPassword(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function assertSuccess(array $body, string $action): array
    {
        if (! DcimsCloudClient::isSuccess($body)) {
            throw new CloudApiException(
                $action.'失败：'.DcimsCloudClient::messageOf($body),
                null,
                CloudApiMode::Dcims
            );
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * 写操作的归一化返回：`['ok' => bool, 'message' => string, 'raw' => array]`。
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function ok(array $body, string $fallbackMessage): array
    {
        if (! DcimsCloudClient::isSuccess($body)) {
            throw new CloudApiException(
                $fallbackMessage.'失败：'.DcimsCloudClient::messageOf($body),
                null,
                CloudApiMode::Dcims
            );
        }

        return [
            'ok' => true,
            'message' => DcimsCloudClient::messageOf($body, $fallbackMessage),
            'raw' => $body,
        ];
    }

    /**
     * 详情里附带的任务字段（power_status 不带任务信息时补查一次）。
     *
     * @return array{0: string, 1: string} `[task, task_name]`
     */
    private function taskFieldsFromDetail(int $hostId): array
    {
        try {
            $body = $this->client->request('GET', '/server/'.$hostId, ['id' => $this->pathId($hostId)]);
            $data = $this->assertSuccess($body, '获取实例详情');
        } catch (Throwable) {
            // 任务信息是锦上添花：详情拉不到时不要把「查状态」整体判失败
            return ['', ''];
        }

        $task = trim($this->stringOf($this->firstValue($data, ['task', 'task_type'], '')));
        $taskName = trim($this->stringOf($this->firstValue($data, ['task_name', 'task_description'], '')));

        return [$task, $taskName];
    }

    /**
     * 电源状态串 → 平台状态机认的取值。
     */
    private function mapPowerStatus(string $power, string $task): string
    {
        $mapped = match ($power) {
            'on', 'running', 'power_on', 'started', 'online' => 'on',
            'off', 'stopped', 'power_off', 'shutdown', 'offline' => 'off',
            'suspend', 'suspended' => 'suspend',
            'paused', 'pause' => 'paused',
            'wait_reboot', 'rebooting', 'restarting', 'pending_reboot' => 'wait_reboot',
            'task', 'exec', 'processing', 'wait' => 'task',
            default => '',
        };

        if ($mapped !== '') {
            return $mapped;
        }

        // 电源串不认识但有任务在跑：状态机看 task 才知道「现在别催」
        return $task !== '' ? 'task' : 'unknown';
    }

    /**
     * 上游 `status` → 平台 `domainstatus`。
     *
     * 映射口径来自契约 §4.7：1=free、2=expired、3=normal、4=fault、5=pre、6=lock、7=review；
     * 新接口也可能直接给字符串。
     */
    private function normalizeDomainStatus(mixed $status): string
    {
        $raw = strtolower(trim($this->stringOf($status)));

        $name = match ($raw) {
            '1' => 'free',
            '2' => 'expired',
            '3' => 'normal',
            '4' => 'fault',
            '5' => 'pre',
            '6' => 'lock',
            '7' => 'review',
            default => $raw,
        };

        return match ($name) {
            'normal' => 'Active',
            'free', 'pre' => 'Pending',
            'expired' => 'Expired',
            'fault', 'lock' => 'Suspended',
            'review' => 'Pending',
            default => $name,
        };
    }

    /**
     * 附加 IP：上游可能是 `\r\n` 分隔的字符串，也可能是数组（元素可为字符串，或带分组/备注的对象）。
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function normalizeIpList(array $data, string $primaryIp): array
    {
        $ips = [];

        foreach (['ips', 'ip', 'ip_list', 'assignedips'] as $field) {
            $value = $data[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (is_scalar($value)) {
                $value = preg_split('/\r\n|\r|\n|,/', (string) $value) ?: [];
            }

            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                $ip = is_array($item)
                    ? $this->stringOf($this->firstValue($item, ['ipaddress', 'ip', 'address'], ''))
                    : $this->stringOf($item);

                $ip = trim($ip);
                if ($ip === '' || $ip === $primaryIp || in_array($ip, $ips, true)) {
                    continue;
                }

                $ips[] = $ip;
            }
        }

        return $ips;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function osName(array $data): string
    {
        $os = $this->firstValue($data, ['os', 'os_name'], '');

        if (is_array($os)) {
            return $this->stringOf($this->firstValue($os, ['name', 'os_name'], ''));
        }

        if ($this->stringOf($os) !== '') {
            return $this->stringOf($os);
        }

        $mirror = $data['mirror_os'] ?? null;

        return is_array($mirror) ? $this->stringOf($this->firstValue($mirror, ['name', 'os_name'], '')) : '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function productName(array $data): string
    {
        $model = $data['server_model'] ?? null;
        if (is_array($model)) {
            $name = $this->stringOf($this->firstValue($model, ['name', 'model'], ''));
            if ($name !== '') {
                return $name;
            }
        }

        return $this->stringOf($this->firstValue($data, ['product_name', 'typename', 'type_name', 'spec_name'], ''));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function cpuCores(array $data): int
    {
        $cpu = $data['cpu'] ?? null;
        if (is_array($cpu)) {
            return (int) ($this->firstValue($cpu, ['num', 'cores', 'count'], 0) ?: 0);
        }

        return (int) ($this->firstValue($data, ['cpu', 'cpu_num', 'cpu_cores'], 0) ?: 0);
    }

    /**
     * 内存（MB）。
     *
     * @param  array<string, mixed>  $data
     */
    private function memoryMb(array $data): int
    {
        $value = $this->firstValue($data, ['memory', 'mem', 'ram'], 0);

        if (is_array($value)) {
            return (int) ($this->firstValue($value, ['num', 'size', 'mb'], 0) ?: 0);
        }

        return (int) ($value ?: 0);
    }

    /**
     * 磁盘明细 `[['id'=>, 'size'=>GB, 'type'=>'system'|'data']]`。
     *
     * 上游字段在不同版本里叫 `disk` / `harddisk` / `disk_info`，size 一般是 GB；
     * 给了 `size_gb` 的以它为准。这里只做归一化，不做单位换算（换算要靠 `decimal`，各版本不一致）。
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{id: int|string, size: float, type: string}>
     */
    private function normalizeDiskList(array $data): array
    {
        $raw = $this->firstValue($data, ['disk', 'harddisk', 'disk_info'], []);
        if (! is_array($raw)) {
            return [];
        }

        $disks = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $size = $this->firstValue($item, ['size_gb', 'size', 'capacity', 'quota_gb'], 0);
            $type = strtolower($this->stringOf($this->firstValue($item, ['type', 'disk_type', 'kind'], '')));
            $mount = strtolower($this->stringOf($this->firstValue($item, ['drive', 'mount', 'mount_point'], '')));

            if (! in_array($type, ['system', 'data'], true)) {
                $type = (str_contains($mount, 'system') || $mount === '/') ? 'system' : 'data';
            }

            $disks[] = [
                'id' => $this->firstValue($item, ['id', 'disk_id'], 0),
                'size' => $this->floatOf($size),
                'type' => $type,
            ];
        }

        return $disks;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isRescue(array $data): bool
    {
        $value = $this->firstValue($data, ['rescue', 'rescue_mode', 'is_rescue'], null);

        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            return true;
        }

        return in_array(strtolower($this->stringOf($value)), ['1', 'true', 'yes', 'on', 'running'], true);
    }

    /**
     * 镜像目录摊平：`data.group[]` + `data.os[]` → `[['id','name','group']]`。
     *
     * 兼容三种形状：标准分组、`data.list[]` 扁平列表、直接就是数组。
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{id: int|string, name: string, group?: string}>
     */
    private function flattenMirrorOs(array $data): array
    {
        $groups = is_array($data['group'] ?? null) ? $data['group'] : [];
        $oses = is_array($data['os'] ?? null) ? $data['os'] : null;

        if ($oses === null) {
            return $this->flattenNestedMirrorOs($this->listFrom($data));
        }

        $groupNames = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }
            $id = $this->firstValue($group, ['id', 'group_id'], '');
            if ($id !== '') {
                $groupNames[(string) $id] = $this->stringOf($this->firstValue($group, ['name', 'group_name'], ''));
            }
        }

        $options = [];
        foreach ($oses as $os) {
            if (! is_array($os)) {
                continue;
            }

            $id = $this->firstValue($os, ['id', 'mirror_os_id'], '');
            if ($id === '') {
                continue;
            }

            $groupId = (string) $this->firstValue($os, ['group_id', 'os_group_id'], '');
            $options[] = [
                'id' => is_numeric($id) ? (int) $id : (string) $id,
                'name' => $this->stringOf($this->firstValue($os, ['name', 'os_name'], '')),
                'group' => $groupNames[$groupId] ?? '',
            ];
        }

        return $options;
    }

    /**
     * 兼容两种非标准形状：分组行里嵌 `mirror_os[]`，或直接一排 OS 行。
     *
     * @param  array<int, mixed>  $rows
     * @return array<int, array{id: int|string, name: string, group?: string}>
     */
    private function flattenNestedMirrorOs(array $rows): array
    {
        $options = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $nested = is_array($row['mirror_os'] ?? null) ? $row['mirror_os'] : null;
            $groupName = $nested !== null
                ? $this->stringOf($this->firstValue($row, ['name', 'group_name', 'os_group_name'], ''))
                : $this->stringOf($this->firstValue($row, ['group_name', 'group', 'type'], ''));

            foreach ($nested ?? [$row] as $os) {
                if (! is_array($os)) {
                    continue;
                }

                $id = $this->firstValue($os, ['id', 'mirror_os_id'], '');
                if ($id === '') {
                    continue;
                }

                $options[] = [
                    'id' => is_numeric($id) ? (int) $id : (string) $id,
                    'name' => $this->stringOf($this->firstValue($os, ['name', 'os_name'], '')),
                    'group' => $groupName,
                ];
            }
        }

        return $options;
    }

    /**
     * 列表响应容错：`data.list` → `data.data` → `data` 本身；单条关联数组也认。
     *
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    private function listFrom(array $data): array
    {
        $list = $data['list'] ?? $data['data'] ?? $data;

        if (! is_array($list)) {
            return [];
        }

        if ($list !== [] && array_keys($list) !== range(0, count($list) - 1)) {
            // 关联数组可能是「单条任务对象」
            return array_key_exists('task_type', $list) || array_key_exists('status', $list) ? [$list] : [];
        }

        return array_values($list);
    }

    /**
     * 取最新一条可识别的任务（`task_type` 能映射到 0..3）。
     *
     * @param  array<int, mixed>  $rows
     * @return array<string, mixed>|null
     */
    private function latestRecognizedTask(array $rows): ?array
    {
        $candidates = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->mapTaskType($row['task_type'] ?? null, null) === null) {
                continue;
            }

            $candidates[] = $row;
        }

        if ($candidates === []) {
            return null;
        }

        // 上游按 sort=desc 返回；仍按 id 兜底排序，避免不同面板版本忽略排序参数
        usort($candidates, static fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));

        return $candidates[0];
    }

    /**
     * 任务进度：`finish` 直接给 100，其它按上游百分比并夹到 0..100。
     *
     * @param  array<string, mixed>  $task
     */
    private function taskProgress(string $status, array $task): int
    {
        if ($status === 'finish') {
            return 100;
        }

        $progress = (int) $this->firstValue($task, ['progress_percent', 'progress'], 0);

        return max(0, min(100, $progress));
    }

    /**
     * 任务类型：`server_reinstall→0`、`server_rescue→1`、`password_reset→2`、`hardware_info_collection→3`（契约 §5.5）。
     */
    private function mapTaskType(mixed $taskType, ?int $default = 0): ?int
    {
        $key = strtolower(trim($this->stringOf($taskType)));

        return match ($key) {
            'server_reinstall', '0' => 0,
            'server_rescue', '1' => 1,
            'password_reset', '2' => 2,
            'hardware_info_collection', '3' => 3,
            default => $default,
        };
    }

    /**
     * 汇总流量包加量（GB）。
     *
     * 上游流量包是分页的，契约 §5.5 的配额算法要求把所有有效包累加；这里只取第一页 100 条
     * （单实例超过 100 个有效包属于异常数据，宁可少算也不要把请求拖成 N 次往返）。
     */
    private function sumTrafficPackages(int $hostId): int
    {
        try {
            $body = $this->client->request('GET', '/server/'.$hostId.'/traffic/package', [
                'id' => $this->pathId($hostId),
                'page' => 1,
                'limit' => 100,
            ]);
            $data = $this->assertSuccess($body, '获取流量包列表');
        } catch (Throwable) {
            // 流量包汇总失败不应让「查用量」整体报错：把加量当 0，用量照常返回
            return 0;
        }

        $total = 0.0;
        foreach ($this->listFrom($data) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $status = strtolower($this->stringOf($this->firstValue($row, ['status'], '')));
            if (in_array($status, ['expired', 'cancelled', 'canceled', 'invalid'], true)) {
                continue;
            }

            $expireTime = (int) ($this->firstValue($row, ['expire_time'], 0) ?: 0);
            $permanent = (bool) ($this->firstValue($row, ['is_permanent'], false) ?: false);
            if (! $permanent && $expireTime > 0 && $expireTime <= time()) {
                continue;
            }

            $total += $this->floatOf($this->firstValue($row, ['quota_gb'], 0));
        }

        return (int) round($total);
    }

    /**
     * 重装清理方式：`part_type=1` 只格式化第一个分区（契约 §5.4）。
     *
     * @param  array<string, mixed>  $params
     */
    private function formatType(array $params): string
    {
        $explicit = strtolower(trim((string) ($params['format_type'] ?? '')));
        if (in_array($explicit, ['full', 'first_partition'], true)) {
            return $explicit;
        }

        return (int) ($params['part_type'] ?? 0) === 1 ? 'first_partition' : 'full';
    }

    /**
     * 枚举参数：非法值一律回落默认值，避免把上游不认的字符串发过去。
     *
     * @param  array<int, string>  $allowed
     */
    private function enumOrDefault(mixed $value, array $allowed, string $default): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, $allowed, true) ? $normalized : $default;
    }

    /**
     * 时间戳归一：接受 `Y-m-d H:i:s` 字符串或秒/毫秒数字，统一输出 `Y-m-d H:i:s`（可空）。
     */
    private function formatTimestamp(mixed $value): string
    {
        $seconds = $this->queryTimestamp($value);

        return $seconds > 0 ? date('Y-m-d H:i:s', $seconds) : '';
    }

    /**
     * 查询参数里的时间：既接受秒/毫秒时间戳，也接受 `Y-m-d H:i:s`（平台侧两种都有人传）。
     */
    private function queryTimestamp(mixed $value): int
    {
        if (is_numeric($value)) {
            return $this->timestampSeconds($value);
        }

        if (is_string($value) && trim($value) !== '') {
            $parsed = strtotime(trim($value));

            return $parsed === false ? 0 : $parsed;
        }

        return 0;
    }

    /**
     * 秒级时间戳：大于 10^11 的按毫秒处理（契约 §5.5 的 `timestampSeconds`）。
     */
    private function timestampSeconds(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $timestamp = (float) $value;
        if ($timestamp <= 0) {
            return 0;
        }

        // 10^11 秒 ≈ 5138 年，正常秒级时间戳不会超过它；超过必然带毫秒
        if ($timestamp >= 100000000000.0) {
            $timestamp /= 1000;
        }

        return (int) floor($timestamp);
    }

    private function bytesToGb(mixed $value): float
    {
        return round($this->floatOf($value) / 1073741824, 2);
    }

    private function floatOf(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return 0.0;
    }

    private function stringOf(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * 执行一次改配请求；失败时把「已生效」的部分拼进消息。
     *
     * 一个端点一次调用、没法回滚，所以失败消息必须回答「到底改了哪些」：
     * 例如 `出方向带宽调整失败（入方向带宽已生效）：带宽超过套餐上限`；
     * 一个都没成功时不加前缀，保持老版实现那种「整单失败」的读感。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $appliedLabels  已成功下发的项目（中文名，按下发顺序）
     * @return array<string, mixed> 上游原始响应体
     */
    private function applyConfigChange(int $hostId, string $path, array $payload, string $label, array $appliedLabels): array
    {
        $body = $this->client->request('PUT', $path, $this->body($hostId, $payload));

        try {
            $this->assertSuccess($body, $label.'调整');
        } catch (CloudApiException) {
            $prefix = $appliedLabels === [] ? '' : '（'.implode('、', $appliedLabels).'已生效）';

            throw new CloudApiException(
                $label.'调整失败'.$prefix.'：'.DcimsCloudClient::messageOf($body),
                null,
                CloudApiMode::Dcims
            );
        }

        return $body;
    }

    /**
     * 改配参数是否「本次指定了」。
     *
     * 口径与接口 docblock 一致：`null` / `''` / `0` / `[]` 都视为「本次不改这一项」，
     * 只有真的有值才算一次调整——否则平台把整套配置项原样传进来时会被误判成「要改成 0」。
     */
    private function isSpecified(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === [] || $value === false) {
            return false;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' && (! is_numeric($trimmed) || (float) $trimmed !== 0.0);
        }

        return true;
    }

    /**
     * 按别名顺序取第一个「存在且非空」的值（契约附录 A 的字段名在不同版本间有别名）。
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private function firstValue(array $data, array $keys, mixed $default = ''): mixed
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            return $value;
        }

        return $default;
    }
}
