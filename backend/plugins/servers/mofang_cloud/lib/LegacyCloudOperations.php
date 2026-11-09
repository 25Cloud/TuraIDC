<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\MofangCloud\Lib;

/**
 * 魔方云老版本接口（legacy `/v1`）能力实现。
 *
 * 协议细节来自智简魔方财务 3.7.6 的 `app/common/logic/DcimCloud.php`（见
 * `C:\Windows\Temp\mfcloud-research\old-api-contract.md`），要点：
 *
 * - 响应体是**平**的：详情字段直接在顶层，列表在 `data` 里（NAT 另有 `nat_host_ip`）；
 * - `status` 字段含义随端点而变：写在业务响应里是「成功标志」(`success`)，写在
 *   `/clouds/{id}/status` 里是「云主机运行状态」(`on`/`off`/…)，两者不能混用；
 * - 成功判定按能力分两档（契约 §5.2）：电源/VNC/重装/改密/救援/暂停/恢复只认
 *   `status === "success"`；NAT/安全组这类写操作还接受 `200`；
 * - 删机特殊：`DELETE /clouds/{id}` 返回 HTTP 404 也算删除成功（实例已不存在）。
 *
 * 失败一律抛 {@see CloudApiException}（消息面向用户可读），不返回半成品结构。
 */
final class LegacyCloudOperations implements CloudOperations
{
    /** 老接口「成功」标志：字符串 success（少数端点用数字 200） */
    private const STATUS_SUCCESS = 'success';

    /** 老接口成功标志的另一形态（NAT/安全组等写操作允许 200） */
    private const STATUS_OK = 200;

    private const GB_DIVISOR = 1073741824;

    public function __construct(
        private readonly CloudApiClient $client,
    ) {
        if ($client->mode() !== CloudApiMode::Legacy) {
            throw new CloudApiException(
                '魔方云老版本能力实现只能搭配老版本客户端（当前代际：'.$client->mode()->label().'）',
                null,
                $client->mode()
            );
        }
    }

    public function mode(): CloudApiMode
    {
        return CloudApiMode::Legacy;
    }

    public function hostDetail(int $hostId): array
    {
        $body = $this->client->request('GET', '/clouds/'.$hostId);

        return [
            'id' => (int) ($body['id'] ?? $hostId),
            'domain' => $this->string($body['hostname'] ?? ''),
            'domainstatus' => $this->mapStatus($body['status'] ?? ''),
            'dedicatedip' => $this->string($body['mainip'] ?? ''),
            'assignedips' => $this->assignedIps($body),
            'username' => $this->string($body['osuser'] ?? ''),
            'password' => $this->string($body['rootpassword'] ?? ''),
            'port' => (int) ($body['port'] ?? 0),
            'os' => $this->string($body['system'] ?? ''),
            // 老接口详情不含已用流量与套餐名，留空交由平台自行补齐，不臆造
            'bwusage' => 0.0,
            'bwlimit' => (int) ($body['traffic_quota'] ?? 0),
            'bwlimit_included' => (int) ($body['tmp_traffic'] ?? 0),
            'cpu' => (int) ($body['cpu'] ?? 0),
            'memory' => (int) ($body['memory'] ?? 0),
            'disk' => $this->disks($body['disk'] ?? []),
            'node_id' => (int) ($body['node_id'] ?? 0),
            'kvmid' => $this->string($body['kvmid'] ?? ''),
            'product_name' => $this->string($body['product_name'] ?? ''),
            'created_at' => $this->string($body['create_time'] ?? ''),
            'expires_at' => $this->string($body['expires_at'] ?? ''),
            'rescue' => (int) ($body['rescue'] ?? 0),
            'iso' => $this->string($body['iso'] ?? ''),
            'bootorder' => $this->string($body['bootorder'] ?? ''),
            'raw' => $body,
        ];
    }

    public function hostStatus(int $hostId): array
    {
        $body = $this->client->request('GET', '/clouds/'.$hostId.'/status');

        return [
            'status' => $this->mapStatus($body['status'] ?? ''),
            'task' => $this->string($body['task'] ?? ''),
            'task_name' => $this->string($body['task_name'] ?? ''),
            'raw' => $body,
        ];
    }

    public function power(string $action, int $hostId): array
    {
        // 硬关是 hardoff、硬启是 hard_reboot —— 老接口没有下划线版本
        $endpoint = match (strtolower(trim($action))) {
            'on', 'start', 'boot', 'power_on' => 'on',
            'off', 'stop', 'shutdown', 'power_off' => 'off',
            'reboot', 'restart' => 'reboot',
            'hard_off', 'hardoff', 'force_off' => 'hardoff',
            'hard_reboot', 'hardreboot', 'force_restart' => 'hard_reboot',
            default => throw new CloudApiException(
                '不支持的电源操作：'.$action.'（仅支持 on / off / reboot / hard_off / hard_reboot）',
                null,
                CloudApiMode::Legacy
            ),
        };

        $body = $this->client->request('POST', '/clouds/'.$hostId.'/'.$endpoint);
        // 电源类只认字符串 success（契约 §5.2）
        $this->assertStrictSuccess($body, '电源操作失败');

        return [
            'ok' => true,
            'message' => $this->string($body['msg'] ?? '操作已提交'),
            'raw' => $body,
        ];
    }

    public function vnc(int $hostId): array
    {
        $body = $this->client->request('POST', '/clouds/'.$hostId.'/vnc');
        $this->assertStrictSuccess($body, '获取 VNC 连接失败');

        $https = $this->string($body['vnc_url_https'] ?? '');
        $http = $this->string($body['vnc_url_http'] ?? '');
        $ws = $this->string($body['vnc_url'] ?? '');

        // 两个都有时优先 https，避免 https 站点里嵌 http 地址被浏览器拦
        $url = $https !== '' ? $https : ($http !== '' ? $http : $ws);

        return [
            'url' => $url,
            'token' => $this->string($body['token'] ?? ''),
            'pass' => $this->string($body['vnc_pass'] ?? ''),
            'raw' => $body,
        ];
    }

    public function resetPassword(int $hostId, string $password, string $username = ''): array
    {
        // 老接口改密只收 password，没有 username 参数（传了也不认），故忽略 $username
        $body = $this->client->request('PUT', '/clouds/'.$hostId.'/password', [
            'password' => $password,
        ]);
        $this->assertStrictSuccess($body, '重置密码失败');

        return [
            'ok' => true,
            'message' => '发起重置密码成功',
            'raw' => $body,
        ];
    }

    public function reinstallOptions(int $hostId): array
    {
        $options = [];

        foreach ($this->imageList() as $item) {
            if ((int) ($item['status'] ?? 0) !== 1) {
                continue;
            }

            $options[] = [
                'id' => (int) ($item['id'] ?? 0),
                'name' => $this->string($item['name'] ?? ''),
                'group' => $this->imageGroup($item),
            ];
        }

        return $options;
    }

    public function reinstall(int $hostId, array $params): array
    {
        // 契约 §3.4：`os`/`port`/`format_data_disk`/`system_disk_size` 是真实字段，
        // password 与 partition_mode 老接口不收，只在返回里回传上游给的新密码
        $payload = [
            'os' => $this->string($params['os'] ?? $params['os_id'] ?? ''),
        ];

        $port = (int) ($params['port'] ?? 0);
        if ($port > 0 && $port <= 65535) {
            $payload['port'] = $port;
        }

        if (array_key_exists('format_data_disk', $params)) {
            $payload['format_data_disk'] = (int) $params['format_data_disk'];
        }

        if (isset($params['system_disk_size']) && $params['system_disk_size'] !== '') {
            $payload['system_disk_size'] = $params['system_disk_size'];
        }

        $body = $this->client->request('PUT', '/clouds/'.$hostId.'/reinstall', $payload);
        $this->assertStrictSuccess($body, '重装失败，请确认是否有其他任务正在执行');

        return [
            'ok' => true,
            'message' => '重装发起成功',
            // 上游重装后会返回新密码（data.password / data.user），平台要回写
            'password' => $this->string($body['data']['password'] ?? ''),
            'username' => $this->string($body['data']['user'] ?? ''),
            'raw' => $body,
        ];
    }

    public function rescue(int $hostId, string $system = 'linux'): array
    {
        $system = strtolower(trim($system));

        // `exit` 走退出救援（DELETE /clouds/{id}/rescue）
        if ($system === 'exit') {
            $body = $this->client->request('DELETE', '/clouds/'.$hostId.'/rescue');
            $this->assertStrictSuccess($body, '退出救援系统失败');

            return [
                'ok' => true,
                'message' => '退出救援系统发起成功',
                'raw' => $body,
            ];
        }

        // 允许 `linux:临时密码` 形式显式指定；不指定时随机生成并回传，因为老接口 temp_pass 必填
        [$type, $tempPass] = $this->rescueTarget($system);

        $body = $this->client->request('POST', '/clouds/'.$hostId.'/rescue', [
            'type' => $type,
            'temp_pass' => $tempPass,
        ]);
        $this->assertStrictSuccess($body, '发起救援系统失败');

        return [
            'ok' => true,
            'message' => '发起救援系统成功（临时密码：'.$tempPass.'）',
            'password' => $tempPass,
            'raw' => $body,
        ];
    }

    public function cancelTask(int $hostId): array
    {
        throw new CloudApiException('魔方云老版本接口不支持取消任务', null, CloudApiMode::Legacy);
    }

    public function taskStatus(int $hostId): array
    {
        $status = $this->hostStatus($hostId);
        $task = $this->string($status['task'] ?? '');

        return [
            'running' => $task !== '',
            'task_type' => $this->taskType($task),
            'name' => $this->string($status['task_name'] ?? '') !== ''
                ? $this->string($status['task_name'])
                : $task,
            // 老接口不暴露进度百分比，只能给 0
            'progress' => 0,
            'step' => $this->string($status['task_name'] ?? ''),
            'error' => '',
            'raw' => $status['raw'] ?? [],
        ];
    }

    public function trafficUsage(int $hostId): array
    {
        $body = $this->client->request('GET', '/net_info', [], ['host_id' => $hostId]);

        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        $window = is_array($body['info']['30_day'] ?? null) ? $body['info']['30_day'] : [];

        $limit = (int) ($meta['traffic_quota'] ?? 0);
        $extra = (int) ($meta['tmp_traffic'] ?? 0);

        // traffic_type: 1=进 accept、2=出 send、3=双向 total（契约 §5.4）
        $usedBytes = match ((int) ($meta['traffic_type'] ?? 3)) {
            1 => $this->float($window['accept'] ?? 0),
            2 => $this->float($window['send'] ?? 0),
            default => $this->float($window['total'] ?? 0),
        };
        $used = round($usedBytes / self::GB_DIVISOR, 2);

        return [
            'used' => $used,
            'limit' => $limit,
            'extra' => $extra,
            'percent' => $limit > 0 ? round($used / $limit * 100, 2) : 0.0,
            // 老接口不给下次重置时间
            'reset_at' => '',
            'raw' => $body,
        ];
    }

    public function trafficChart(int $hostId, array $query): array
    {
        $params = [
            'type' => (int) ($query['type'] ?? 2),
            'start_time' => $this->millisecond($query['start_time'] ?? $query['start'] ?? 0),
            'end_time' => $this->millisecond($query['end_time'] ?? $query['end'] ?? 0),
            'unit' => $this->string($query['unit'] ?? 'GB'),
        ];

        $body = $this->client->request('GET', '/clouds/'.$hostId.'/flow_data', [], $params);

        $points = [];
        foreach ($this->rows($body) as $item) {
            $points[] = [
                'time' => $this->timestamp($item['time'] ?? 0),
                'in' => round($this->float($item['in'] ?? 0), 2),
                'out' => round($this->float($item['out'] ?? 0), 2),
            ];
        }

        return $points;
    }

    public function statistics(int $hostId, string $type, array $query): array
    {
        $detail = $this->client->request('GET', '/clouds/'.$hostId);
        $nodeId = (int) ($detail['node_id'] ?? 0);
        $kvmid = $this->string($detail['kvmid'] ?? '');

        // 行形状：cpu/memory 走 kvm_info，disk 走 disk_io，network 走 net_adapter（契约 §4.3）
        $metric = match (strtolower(trim($type))) {
            'disk' => 'disk',
            'network', 'net', 'flow' => 'network',
            'memory', 'mem' => 'memory',
            default => 'cpu',
        };

        $params = [
            'node_id' => $nodeId,
            'kvm' => $kvmid,
            'type' => match ($metric) {
                'disk' => 'disk_io',
                'network' => 'net_adapter',
                default => 'kvm_info',
            },
            'st' => $this->string($query['st'] ?? $query['start'] ?? ''),
            'et' => $this->string($query['et'] ?? $query['end'] ?? ''),
        ];

        if ($metric === 'disk') {
            $params['dev_name'] = $this->string($query['dev_name'] ?? $query['select'] ?? 'vda');
        }

        if ($metric === 'network') {
            $params['kvm_ifname'] = $this->string($query['kvm_ifname'] ?? $kvmid.'.0');
        }

        $body = $this->client->request('GET', '/statistics', [], $params);

        $points = [];
        foreach ($this->rows($body) as $item) {
            $points[] = $this->statisticsPoint($metric, $item);
        }

        return $points;
    }

    public function suspend(int $hostId): array
    {
        // 平台触发暂停时给 other；老接口只接受 traffic / due / other（契约 §3.5）
        $body = $this->client->request('POST', '/clouds/'.$hostId.'/suspend', ['type' => 'other']);

        if (! $this->isStrictSuccess($body)) {
            // 失败时回查状态：已经是 suspend，或正在执行 suspend 任务，都算达成目标
            $status = $this->client->request('GET', '/clouds/'.$hostId.'/status');
            $current = $this->string($status['status'] ?? '');
            $task = $this->string($status['task'] ?? '');

            if ($current !== 'suspend' && $task !== 'suspend') {
                $this->assertStrictSuccess($body, '暂停失败');
            }
        }

        return [
            'ok' => true,
            'message' => '暂停成功',
            'raw' => $body,
        ];
    }

    public function unsuspend(int $hostId): array
    {
        $body = $this->client->request('POST', '/clouds/'.$hostId.'/unsuspend');

        if (! $this->isStrictSuccess($body)) {
            // 回查详情：只要不再是 suspend 就算解除成功
            $detail = $this->client->request('GET', '/clouds/'.$hostId);
            if ($this->string($detail['status'] ?? '') === 'suspend') {
                $this->assertStrictSuccess($body, '解除暂停失败');
            }
        }

        return [
            'ok' => true,
            'message' => '解除暂停成功',
            'raw' => $body,
        ];
    }

    public function terminate(int $hostId): array
    {
        try {
            $body = $this->client->request('DELETE', '/clouds/'.$hostId);
        } catch (CloudApiException $exception) {
            // 实例已不存在（404）视为删除成功，与魔方财务同口径（契约 §5.2）
            if ($exception->httpCode() === 404) {
                return [
                    'ok' => true,
                    'message' => '删除成功',
                    'raw' => [],
                ];
            }

            throw $exception;
        }

        $this->assertStrictSuccess($body, '删除失败');

        return [
            'ok' => true,
            'message' => '删除成功',
            'raw' => $body,
        ];
    }

    public function mirrorOs(): array
    {
        $images = [];

        foreach ($this->imageList() as $item) {
            $images[] = [
                'id' => (int) ($item['id'] ?? 0),
                'name' => $this->string($item['name'] ?? ''),
                'group' => $this->imageGroup($item),
                'status' => (int) ($item['status'] ?? 0),
            ];
        }

        return $images;
    }

    public function areas(): array
    {
        $body = $this->client->request('GET', '/areas', [], ['sort' => 'asc', 'list_type' => 'all']);

        $areas = [];
        foreach ($this->rows($body) as $item) {
            $areas[] = [
                'id' => (int) ($item['id'] ?? 0),
                'name' => $this->string($item['name'] ?? ''),
                'country_code' => $this->string($item['country_code'] ?? ''),
            ];
        }

        return $areas;
    }

    public function securityGroups(int $hostId): array
    {
        $query = ['list_type' => 'all'];

        // hostId > 0：只列该实例归属客户的安全组（一个面板账号下可能挂着多个客户）
        // hostId <= 0：平台要的是「这个面板上我能看到的安全组」，不带 user 过滤。
        // 平台的安全组页是按服务打开的，拿不到具体实例时传 0；早前这里直接按 0 去查
        // /clouds/0，必然 404，等于原生安全组列表永远读不出来。
        if ($hostId > 0) {
            $detail = $this->client->request('GET', '/clouds/'.$hostId);
            $userId = (int) ($detail['user_id'] ?? 0);

            // 没有归属用户就不查（避免把上游全部安全组拉回来）
            if ($userId <= 0) {
                return [];
            }

            $query['user'] = $userId;
        }

        return $this->rows($this->client->request('GET', '/security_groups', [], $query));
    }

    public function natRules(int $hostId): array
    {
        $body = $this->client->request('GET', '/clouds/'.$hostId.'/nat_acl', [], ['list_type' => 'all']);
        $hostIp = $this->string($body['nat_host_ip'] ?? '');

        $rules = [];
        foreach ($this->rows($body) as $item) {
            // 老接口把 NAT 出口 IP 放在列表外层，逐条补进去便于前端直接展示
            $item['nat_host_ip'] = $hostIp;
            $rules[] = $item;
        }

        return $rules;
    }

    public function createNatRule(int $hostId, array $payload): array
    {
        $body = $this->client->request('POST', '/clouds/'.$hostId.'/nat_acl', $payload);
        $this->assertLooseSuccess($body, '创建 NAT 转发失败');

        return [
            'ok' => true,
            'message' => '创建成功',
            'raw' => $body,
        ];
    }

    public function deleteNatRule(int $hostId, int $ruleId): array
    {
        // hostid 放 query（契约 §3.10）；魔方财务那边的空值 bug 与本实现无关
        $body = $this->client->request('DELETE', '/nat_acl/'.$ruleId, [], ['hostid' => $hostId]);
        $this->assertLooseSuccess($body, '删除 NAT 转发失败');

        return [
            'ok' => true,
            'message' => '删除成功',
            'raw' => $body,
        ];
    }

    /* ------------------------------ 开通 / 流量包 ------------------------------ */

    public function provision(array $params): array
    {
        // 老接口开通必须带 `client`（上游客户 ID）：先确保客户存在，再组装报文
        $clientId = $this->ensureUpstreamClient($params);

        $body = $this->client->request('POST', '/clouds', $this->provisionPayload($params, $clientId));
        $this->assertStrictSuccess($body, '开通失败：上游未返回成功标志');

        // 开通成功的唯一凭据就是上游回的新主机 ID（契约 §7 取回流程）
        $hostId = (int) ($body['data']['id'] ?? 0);
        if ($hostId <= 0) {
            throw new CloudApiException('开通失败：上游未返回云主机 ID', null, CloudApiMode::Legacy);
        }

        return [
            'ok' => true,
            'host_id' => $hostId,
            'message' => '开通成功',
            'raw' => $body,
        ];
    }

    public function buyFlowPacket(int $hostId, int $trafficGb): array
    {
        if ($trafficGb <= 0) {
            throw new CloudApiException('购买流量包失败：流量必须是正整数（GB）', null, CloudApiMode::Legacy);
        }

        // 老接口的「流量包」= 给实例加临时流量，单位 GB、整数增量（契约 §3.11）
        $body = $this->client->request('PUT', '/clouds/'.$hostId.'/temp_traffic', ['traffic' => $trafficGb]);
        $this->assertStrictSuccess($body, '流量包附加失败');

        return [
            'ok' => true,
            'message' => '流量包已附加到实例',
            'raw' => $body,
        ];
    }

    /* ------------------------------ 快照 / 备份 ------------------------------ */

    /** {@inheritDoc} */
    public function snapshots(int $hostId): array
    {
        $body = $this->client->request('GET', '/clouds/'.$hostId.'/snapshots', [], [
            'page' => 1,
            'per_page' => 9999,
        ]);

        // 列表只认严格 success（契约 §5.2 第一条），失败当空列表而不抛，避免整页报错
        if (! $this->isStrictSuccess($body)) {
            return [];
        }

        return $this->snapshotRows($body);
    }

    /** {@inheritDoc} */
    public function createSnapshot(int $hostId, array $params): array
    {
        $name = $this->string($params['name'] ?? '');
        if ($name === '') {
            throw new CloudApiException('创建快照失败：缺少快照名称', null, CloudApiMode::Legacy);
        }

        $type = strtolower($this->string($params['type'] ?? '')) ?: 'snap';
        if (! in_array($type, ['snap', 'backup'], true)) {
            throw new CloudApiException('创建快照失败：类型只支持 snap 或 backup', null, CloudApiMode::Legacy);
        }

        $diskId = (int) ($params['disk_id'] ?? 0);
        if ($diskId <= 0) {
            // 没指定磁盘就落到系统盘（契约 §3.8 的 `disks/{diskId}/snapshots` 是磁盘维度）
            $diskId = $this->systemDiskId($hostId);
        }

        if ($diskId <= 0) {
            throw new CloudApiException('创建快照失败：找不到系统盘，无法创建', null, CloudApiMode::Legacy);
        }

        $body = $this->client->request('POST', '/disks/'.$diskId.'/snapshots', [
            'type' => $type,
            'name' => $name,
        ]);
        $this->assertLooseSuccess($body, $type === 'backup' ? '创建备份失败' : '创建快照失败');

        return [
            'ok' => true,
            'message' => ($type === 'backup' ? '备份' : '快照').'任务已发起，请稍后查看',
            // 上游不一定回 ID：给不出就是 0，不伪造
            'snapshot_id' => (int) ($body['data']['id'] ?? $body['data']['snapshot_id'] ?? $body['id'] ?? 0),
            'raw' => $body,
        ];
    }

    /** {@inheritDoc} */
    public function deleteSnapshot(int $hostId, int $snapshotId): array
    {
        $body = $this->client->request('DELETE', '/snapshots/'.$snapshotId);
        $this->assertLooseSuccess($body, '删除快照失败');

        return [
            'ok' => true,
            'message' => '删除成功',
            'raw' => $body,
        ];
    }

    /** {@inheritDoc} */
    public function restoreSnapshot(int $hostId, int $snapshotId): array
    {
        // hostid 放 query：上游靠它校验快照归属（契约 §3.8）
        $body = $this->client->request('POST', '/snapshots/'.$snapshotId.'/restore', [], ['hostid' => $hostId]);
        $this->assertLooseSuccess($body, '回滚实例失败');

        return [
            'ok' => true,
            'message' => '回滚任务已发起，请稍后查看',
            'raw' => $body,
        ];
    }

    /* ------------------------------ ISO / 启动项 ------------------------------ */

    /** {@inheritDoc} */
    public function isoImages(int $hostId): array
    {
        $detail = $this->client->request('GET', '/clouds/'.$hostId);
        $nodeId = (int) ($detail['node_id'] ?? 0);

        if ($nodeId <= 0) {
            return [];
        }

        $body = $this->client->request('GET', '/node_isos', [], ['id' => $nodeId, 'type' => 'node']);

        return $this->isoRows($body);
    }

    /** {@inheritDoc} */
    public function mountIso(int $hostId, string $isoId): array
    {
        $isoId = $this->string($isoId);
        if ($isoId === '') {
            throw new CloudApiException('挂载 ISO 失败：缺少镜像 ID', null, CloudApiMode::Legacy);
        }

        $body = $this->client->request('POST', '/clouds/'.$hostId.'/iso', ['iso' => $isoId]);
        $this->assertLooseSuccess($body, '挂载 ISO 失败，请关机后重试');

        return [
            'ok' => true,
            'message' => 'ISO 挂载成功',
            'raw' => $body,
        ];
    }

    /** {@inheritDoc} */
    public function unmountIso(int $hostId): array
    {
        // 卸载要带「当前挂载的 ISO」；详情里没有就不带该字段，直接发卸载
        $detail = $this->client->request('GET', '/clouds/'.$hostId);
        $iso = $this->string($detail['iso'] ?? '');

        $body = $this->client->request('DELETE', '/clouds/'.$hostId.'/iso', $iso === '' ? [] : ['iso' => $iso]);
        $this->assertLooseSuccess($body, '卸载 ISO 失败');

        return [
            'ok' => true,
            'message' => 'ISO 卸载成功',
            'raw' => $body,
        ];
    }

    /** {@inheritDoc} */
    public function setBootOrder(int $hostId, string $order): array
    {
        $order = $this->string($order);
        if ($order === '') {
            throw new CloudApiException('设置启动项失败：缺少启动项参数', null, CloudApiMode::Legacy);
        }

        // disk / cdrom 是上游自己的枚举，原样透传
        $body = $this->client->request('PUT', '/clouds/'.$hostId, ['bootorder' => $order]);
        $this->assertLooseSuccess($body, '设置启动项失败');

        return [
            'ok' => true,
            'message' => '启动项已设置为 '.$order,
            'raw' => $body,
        ];
    }

    /* ------------------------------ 安全组写 ------------------------------ */

    /** {@inheritDoc} */
    public function securityRules(int $hostId, int $groupId): array
    {
        $this->assertOwnedSecurityGroup($hostId, $groupId);

        $body = $this->client->request('GET', '/security_groups/'.$groupId.'/rules', [], ['per_page' => 9999]);

        return $this->securityRuleRows($body);
    }

    /** {@inheritDoc} */
    public function createSecurityGroup(int $hostId, array $params): array
    {
        $name = $this->string($params['name'] ?? '');
        if ($name === '') {
            throw new CloudApiException('新建安全组失败：缺少安全组名称', null, CloudApiMode::Legacy);
        }

        $host = $this->hostOwnership($hostId);

        $body = $this->client->request('POST', '/security_groups', [
            'uid' => $host['user_id'],
            'name' => $name,
            'description' => $this->string($params['description'] ?? ''),
            'type' => $host['type'] === '' ? 'host' : $host['type'],
        ]);
        $this->assertLooseSuccess($body, '新建安全组失败');

        return [
            'ok' => true,
            'message' => '创建成功',
            'group_id' => (int) ($body['data']['id'] ?? $body['id'] ?? 0),
            'raw' => $body,
        ];
    }

    /** {@inheritDoc} */
    public function deleteSecurityGroup(int $hostId, int $groupId): array
    {
        $this->assertOwnedSecurityGroup($hostId, $groupId);

        $body = $this->client->request('DELETE', '/security_groups/'.$groupId);
        $this->assertLooseSuccess($body, '删除安全组失败');

        return [
            'ok' => true,
            'message' => '删除成功',
            'raw' => $body,
        ];
    }

    /** {@inheritDoc} */
    public function createSecurityRule(int $hostId, int $groupId, array $params): array
    {
        $this->assertOwnedSecurityGroup($hostId, $groupId);

        // 方向与协议是上游硬要求，缺了直接给可读提示；平台报文里不会带 action
        $payload = [];
        foreach (['direction', 'protocol'] as $key) {
            $value = $this->string($params[$key] ?? '');
            if ($value === '') {
                throw new CloudApiException('新建安全组规则失败：缺少 '.$key, null, CloudApiMode::Legacy);
            }

            $payload[$key] = $value;
        }

        // action 缺省补 accept：上游要求显式动作，但平台侧提交只有
        // id/direction/protocol/port/ip/description 六个键，永远不带 action
        $action = $this->string($params['action'] ?? '');
        $payload['action'] = $action === '' ? 'accept' : $action;

        // 其余字段「没给就不提交」，避免把上游已有值覆盖成空
        foreach (['port', 'ip', 'description', 'start_port', 'end_port', 'start_ip', 'end_ip'] as $key) {
            $value = $this->string($params[$key] ?? '');
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        $priority = (int) ($params['priority'] ?? 0);
        if ($priority > 0) {
            $payload['priority'] = $priority;
        }

        $body = $this->client->request('POST', '/security_groups/'.$groupId.'/rules', $payload);
        $this->assertLooseSuccess($body, '新建安全组规则失败');

        return [
            'ok' => true,
            'message' => '创建成功',
            'rule_id' => (int) ($body['data']['id'] ?? $body['id'] ?? 0),
            'raw' => $body,
        ];
    }

    /** {@inheritDoc} */
    public function deleteSecurityRule(int $hostId, int $groupId, int $ruleId): array
    {
        $this->assertOwnedSecurityGroup($hostId, $groupId);

        // 规则必须真在这条组里：上游的删除端点只看规则 ID，不校验组
        $rules = $this->client->request('GET', '/security_groups/'.$groupId.'/rules', [], ['per_page' => 9999]);
        $ruleIds = array_map(
            static fn (array $item): int => (int) ($item['id'] ?? 0),
            $this->rows($rules)
        );

        if (! in_array($ruleId, $ruleIds, true)) {
            throw new CloudApiException('安全组规则不属于该安全组', null, CloudApiMode::Legacy);
        }

        $body = $this->client->request('DELETE', '/security_group_rules/'.$ruleId);
        $this->assertLooseSuccess($body, '删除安全组规则失败');

        return [
            'ok' => true,
            'message' => '删除成功',
            'raw' => $body,
        ];
    }

    /** {@inheritDoc} */
    public function applySecurityGroup(int $hostId, int $groupId): array
    {
        $this->assertOwnedSecurityGroup($hostId, $groupId);

        $body = $this->client->request('POST', '/security_groups/'.$groupId.'/links', [
            'cloud' => $hostId,
            'type' => 1,
        ]);
        $this->assertLooseSuccess($body, '应用安全组失败');

        return [
            'ok' => true,
            'message' => '应用成功',
            'raw' => $body,
        ];
    }

    /* -------------------------------- 改配 -------------------------------- */

    /** {@inheritDoc} */
    public function changeConfig(int $hostId, array $params): array
    {
        $applied = [];
        $raw = [];

        // 1) 主机属性与带宽共用一份报文，连发 `PUT /clouds/{id}` 与 `PUT /clouds/{id}/bw`（契约 §3.6）
        $main = $this->onlySpecified([
            'cpu' => $params['cpu'] ?? null,
            'memory' => $params['memory'] ?? null,
            'traffic_quota' => $params['flow'] ?? null,
            'snap_num' => $params['snap_num'] ?? null,
            'backup_num' => $params['backup_num'] ?? null,
            'in_bw' => $params['in_bw'] ?? null,
            'out_bw' => $params['out_bw'] ?? null,
        ]);

        if ($main !== []) {
            $raw = $this->client->request('PUT', '/clouds/'.$hostId, $main);
            $this->assertLooseSuccess($raw, '改配失败（主机属性）');

            $raw = $this->client->request('PUT', '/clouds/'.$hostId.'/bw', $main);
            $this->assertLooseSuccess($raw, '改配失败（带宽）');

            foreach (['cpu', 'memory', 'flow', 'snap_num', 'backup_num', 'in_bw', 'out_bw'] as $key) {
                if ($this->isSpecified($params[$key] ?? null)) {
                    $applied[] = $key;
                }
            }
        }

        // 2) 附加 IPv4 数量 / 地址组
        $ip = $this->onlySpecified([
            'num' => $params['ip_num'] ?? null,
            'ip_group' => $params['ip_group'] ?? null,
        ]);

        if ($ip !== []) {
            $raw = $this->client->request('PUT', '/clouds/'.$hostId.'/ip', $ip);
            $this->assertLooseSuccess($raw, '改配失败（IP 数量）');

            foreach (['ip_num', 'ip_group'] as $key) {
                if ($this->isSpecified($params[$key] ?? null)) {
                    $applied[] = $key;
                }
            }
        }

        // 3) 附加 IPv6 数量
        if ($this->isSpecified($params['ipv6_num'] ?? null)) {
            $raw = $this->client->request('PUT', '/clouds/'.$hostId.'/ipv6', ['num' => (int) $params['ipv6_num']]);
            $this->assertLooseSuccess($raw, '改配失败（IPv6 数量）');
            $applied[] = 'ipv6_num';
        }

        // 4) 数据盘：有盘就扩容，没有就新建（契约 §3.7）
        if ((int) ($params['disk_size'] ?? 0) > 0) {
            $raw = $this->resizeOrCreateDataDisk($hostId, (int) ($params['disk_id'] ?? 0), (int) $params['disk_size']);
            $applied[] = 'disk_size';
        }

        if ($applied === []) {
            throw new CloudApiException('改配失败：没有可提交的变更项', null, CloudApiMode::Legacy);
        }

        return [
            'ok' => true,
            'message' => '改配已提交',
            'applied' => $applied,
            'raw' => $raw,
        ];
    }

    /* ------------------------------- 内部辅助 ------------------------------- */

    /**
     * 确保实例归属的上游客户存在，返回其 `client`（上游用户 ID）。
     *
     * 取 ID 的顺序：
     * 1. `config_options.client` / `client_id` 已给出 → 直接用（平台侧已经建过客户）；
     * 2. `client_username` 查上游已存在 → 复用 `data.id`；
     * 3. 不存在则 `POST /user` 建档，再回查一次拿 ID（契约 §3.3：建档接口不回 ID，
     *    魔方财务也是「建档 → `/user/check` 取 id」两步）；
     * 4. `client_username` 为空且配置项也没有 → 抛异常，绝不盲发。
     *
     * `rid`（资源包）只有代理商账号（`account_type=agent`）的建档才需要，取不到就跳过。
     *
     * @param  array<string, mixed>  $params
     */
    private function ensureUpstreamClient(array $params): int
    {
        $config = is_array($params['config_options'] ?? null) ? $params['config_options'] : [];

        $explicit = (int) ($config['client'] ?? $config['client_id'] ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        $username = $this->string($params['client_username'] ?? '');
        if ($username === '') {
            throw new CloudApiException(
                '无法确定实例归属的上游客户：缺少 client_username，配置项里也没有 client',
                null,
                CloudApiMode::Legacy
            );
        }

        $existing = $this->findUpstreamClientId($username);
        if ($existing > 0) {
            return $existing;
        }

        // email / real_name 平台入参里没有，留空由上游接受，不编造
        $payload = [
            'username' => $username,
            'email' => '',
            'status' => 1,
            'real_name' => '',
            'password' => bin2hex(random_bytes(6)),
        ];

        if ($this->client->credentials()->agentAccount) {
            $rid = (int) ($config['rid'] ?? $config['resource_package'] ?? 0);
            if ($rid > 0) {
                $payload['rid'] = $rid;
            }
        }

        $this->client->request('POST', '/user', $payload);

        $created = $this->findUpstreamClientId($username);
        if ($created <= 0) {
            throw new CloudApiException(
                '上游客户建档后仍查不到 ID（username='.$username.'）',
                null,
                CloudApiMode::Legacy
            );
        }

        return $created;
    }

    /**
     * 按用户名查上游客户 ID；查不到返回 0。
     *
     * 契约（`DcimCloud.php:2596`）用的是 `POST /user/check` 表单；同款接口在部分版本上只接受
     * GET query，所以仅对 404/405 回退一次 GET，其它错误照抛，不做无差别重试。
     */
    private function findUpstreamClientId(string $username): int
    {
        try {
            $body = $this->client->request('POST', '/user/check', ['username' => $username]);
        } catch (CloudApiException $exception) {
            if (! in_array($exception->httpCode(), [404, 405], true)) {
                throw $exception;
            }

            $body = $this->client->request('GET', '/user/check', [], ['username' => $username]);
        }

        return (int) ($body['data']['id'] ?? 0);
    }

    /**
     * 按契约 §7 组装 `POST /clouds` 表单。
     *
     * 入参键是固定的（见 `CloudOperations::provision()` 的 docblock），这里只做两件事：
     * 1. 平台键 → 上游字段名：`os_id`→`os`、`disk`→`system_disk_size`、`bandwidth`→`in_bw`/`out_bw`、
     *    `flow`→`traffic_quota`、`password`→`rootpass`、`area_id`→`area`；
     * 2. 「值为 null / '' / 0 视为未指定」，未指定的键一个都不提交，剩下的交给上游默认值。
     *
     * §7 里平台没有对应入参的字段（`node`、`node_group`、`ip_group`、`network_type`、
     * `nat_acl_limit`、`snap_num`、`backup_num`、I/O 限速、`vpc` 等）只从 `config_options` 原样取，
     * 取不到就不发——契约里没有的字段绝不拼。
     *
     * `client`（上游客户 ID）不在这里推导：由 {@see ensureUpstreamClient()} 先算好再传进来，
     * 因为它是必填项，不能像可选字段那样「没有就不发」。
     *
     * @param  array<string, mixed>  $params
     * @param  int  $clientId  已确保存在的上游客户 ID
     * @return array<string, mixed>
     */
    private function provisionPayload(array $params, int $clientId): array
    {
        $config = is_array($params['config_options'] ?? null) ? $params['config_options'] : [];

        // 平台入参 → 契约 §7 字段名（bandwidth 同时写进/出带宽，与魔方财务开通同款）
        $payload = [
            'client' => $clientId,
            'hostname' => $this->string($params['hostname'] ?? ''),
            'rootpass' => $this->string($params['password'] ?? ''),
            'os' => $this->string($params['os_id'] ?? ''),
            'area' => $this->string($params['area_id'] ?? ''),
            'cpu' => (int) ($params['cpu'] ?? 0),
            'memory' => (int) ($params['memory'] ?? 0),
            'system_disk_size' => (int) ($params['disk'] ?? 0),
            'in_bw' => (int) ($params['bandwidth'] ?? 0),
            'out_bw' => (int) ($params['bandwidth'] ?? 0),
            'traffic_quota' => (int) ($params['flow'] ?? 0),
            'ip_num' => (int) ($params['ip_num'] ?? 0),
            'ipv6_num' => (int) ($params['ipv6_num'] ?? 0),
        ];

        foreach ([
            'node' => 'string', 'node_group' => 'string',
            'ip_group' => 'string', 'node_priority' => 'string', 'network_type' => 'string',
            'type' => 'string', 'nat_acl_limit' => 'int', 'nat_web_limit' => 'int',
            'snap_num' => 'int', 'backup_num' => 'int', 'gpu_num' => 'int',
            'advanced_cpu' => 'int', 'advanced_bw' => 'int', 'link_clone' => 'int',
            'cpu_limit' => 'int', 'cpu_model' => 'int', 'bind_mac' => 'int',
            'store' => 'int', 'port' => 'string', 'reset_flow_day' => 'int',
            'rid' => 'int', 'niccard' => 'int', 'vpc_name' => 'string',
        ] as $key => $kind) {
            if (! array_key_exists($key, $config)) {
                continue;
            }

            $value = $config[$key];
            if ($value === null || $value === '' || $value === 0) {
                continue;
            }

            if ($kind === 'int') {
                if (! is_numeric($value)) {
                    continue;
                }
                $payload[$key] = (int) $value;

                continue;
            }

            $payload[$key] = $this->string($value);
        }

        // 网卡型号只接受 1/2/3（契约 §7），其它值不发
        if (isset($payload['niccard']) && ! in_array((int) $payload['niccard'], [1, 2, 3], true)) {
            unset($payload['niccard']);
        }

        // 数据盘：契约形状（`[{size,store}]`）可原样给，否则用 data_disk_size 数值
        if (is_array($config['other_data_disk'] ?? null)) {
            $payload['other_data_disk'] = $config['other_data_disk'];
        } elseif ((int) ($config['data_disk_size'] ?? 0) > 0) {
            $payload['other_data_disk'] = [['size' => (int) $config['data_disk_size']]];
        }

        // 磁盘 IO 限速：四段逗号串（契约 §7）
        $this->applyDiskIoLimits($payload, 'system', $this->string($config['system_disk_io_limit'] ?? ''));
        $this->applyDiskIoLimits($payload, 'data', $this->string($config['data_disk_io_limit'] ?? ''));

        // 流量计费方向：显式 traffic_type 优先，其次 flow_way（in/out/all）
        if (isset($config['traffic_type']) && is_numeric($config['traffic_type'])) {
            $payload['traffic_type'] = (int) $config['traffic_type'];
        } elseif (isset($config['flow_way'])) {
            $payload['traffic_type'] = $this->trafficType($config['flow_way']);
        }

        // VPC：已经指定 vpc / vpc_name 就不查；否则挑一个挂载最少的网络（契约 §7）
        $networkType = $this->string($payload['network_type'] ?? '');
        $hasVpc = isset($config['vpc']) && $config['vpc'] !== '';
        if ($networkType === 'vpc' && ! $hasVpc && ! isset($payload['vpc_name']) && isset($payload['client'])) {
            $payload += $this->resolveVpc((int) $payload['client'], $payload);
        } elseif ($hasVpc) {
            $payload['vpc'] = $config['vpc'];
        }

        // 统一剔除「未指定」项：null / 空串 / 0 / 空数组都不提交
        return array_filter(
            $payload,
            static fn (mixed $value): bool => ! ($value === null || $value === '' || $value === 0 || $value === [])
        );
    }

    /**
     * `system_disk_io_limit` / `data_disk_io_limit` 是「读字节,写字节,读IOPS,写IOPS」四段逗号串
     * （契约 §7）。任一段为 0/空都按 0（不限）发送。
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyDiskIoLimits(array &$payload, string $scope, string $value): void
    {
        if (trim($value) === '') {
            return;
        }

        $parts = array_map(static fn (string $part): int => max((int) trim($part), 0), explode(',', $value));

        $payload[$scope.'_read_bytes_sec'] = $parts[0] ?? 0;
        $payload[$scope.'_write_bytes_sec'] = $parts[1] ?? 0;
        $payload[$scope.'_read_iops_sec'] = $parts[2] ?? 0;
        $payload[$scope.'_write_iops_sec'] = $parts[3] ?? 0;
    }

    /**
     * VPC 网络选择（契约 §7）：挑「挂载实例最少且 ≤250」的 VPC；都没有就让上游新建。
     *
     * @param  array<string, mixed>  $payload
     * @return array{vpc?: mixed, vpc_name?: string}
     */
    private function resolveVpc(int $client, array $payload): array
    {
        $query = ['user' => $client, 'sort' => 'desc', 'per_page' => 40, 'page' => 1];

        if ($this->string($payload['node'] ?? '') !== '') {
            $query['node'] = $payload['node'];
        } elseif ($this->string($payload['node_group'] ?? '') !== '') {
            $query['node_group'] = $payload['node_group'];
        } else {
            $query['area'] = $payload['area'] ?? '';
        }

        $body = $this->client->request('GET', '/vpc_networks', [], $query);

        $candidates = [];
        foreach ($this->rows($body) as $item) {
            $hosts = is_array($item['host'] ?? null) ? count($item['host']) : 0;
            if ($hosts <= 250) {
                $candidates[] = ['id' => $item['id'] ?? 0, 'hosts' => $hosts];
            }
        }

        if ($candidates === []) {
            return ['vpc_name' => 'VPC-'.bin2hex(random_bytes(4))];
        }

        usort($candidates, static fn (array $a, array $b): int => $a['hosts'] <=> $b['hosts']);

        return ['vpc' => $candidates[0]['id']];
    }

    /**
     * `flow_way` → 上游 `traffic_type`：in=1、out=2、all=3（契约 §7）。
     */
    private function trafficType(mixed $flowWay): int
    {
        return match ($this->string($flowWay)) {
            'in' => 1,
            'out' => 2,
            default => 3,
        };
    }

    /**
     * `GET /image` 的镜像列表（列表在 `data` 里）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function imageList(): array
    {
        $body = $this->client->request('GET', '/image', [], [
            'per_page' => 9999,
            'sort' => 'asc',
        ]);

        return $this->rows($body);
    }

    /**
     * 列表类响应的数据数组；`data` 不是数组时按空列表处理。
     *
     * @param  array<string, mixed>  $body
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $body): array
    {
        $rows = $body['data'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $item) {
            if (is_array($item)) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @param  mixed  $raw  上游 `disk` 数组
     * @return array<int, array{id:int, size:float, type:string}>
     */
    private function disks(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $disks = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $disks[] = [
                'id' => (int) ($item['id'] ?? 0),
                'size' => round($this->float($item['size'] ?? 0), 2),
                'type' => $this->string($item['type'] ?? '') === 'data' ? 'data' : 'system',
            ];
        }

        return $disks;
    }

    /**
     * 附加 IP：`ip[].ipaddress`（`client_show_ip_remark=1` 时附 remark）+ `ipv6[].ipv6`，
     * 排除主 IP 并去重（契约 §4.1）。
     *
     * @param  array<string, mixed>  $body
     * @return array<int, string>
     */
    private function assignedIps(array $body): array
    {
        $mainIp = $this->string($body['mainip'] ?? '');
        $showRemark = (int) ($body['client_show_ip_remark'] ?? 0) === 1;

        $ips = [];
        foreach ((array) ($body['ip'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $address = $this->string($item['ipaddress'] ?? '');
            if ($address === '') {
                continue;
            }

            $remark = $this->string($item['remark'] ?? '');
            if ($showRemark && $remark !== '') {
                // 与魔方财务同款：备注里的半角逗号换成全角，避免和 IP 列表分隔符混淆
                $address .= '('.str_replace(',', '，', $remark).')';
            }

            $ips[] = $address;
        }

        foreach ((array) ($body['ipv6'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $address = $this->string($item['ipv6'] ?? '');
            if ($address !== '') {
                $ips[] = $address;
            }
        }

        $ips = array_values(array_unique(array_filter(
            $ips,
            static fn (string $value): bool => $value !== '' && $value !== $mainIp
        )));

        return $ips;
    }

    /**
     * 镜像分组名：`group.name` 优先，退回 `group_name`。
     *
     * @param  array<string, mixed>  $item
     */
    private function imageGroup(array $item): string
    {
        $group = is_array($item['group'] ?? null) ? $item['group'] : [];

        $name = $this->string($group['name'] ?? '');
        if ($name !== '') {
            return $name;
        }

        return $this->string($item['group_name'] ?? '');
    }

    /**
     * 老接口状态 → 平台状态串（契约 §5.1；两处状态字段共用同一张表）。
     *
     * `cold_migrate`/`hot_migrate` 归为 `task`：平台只关心「正在忙，别下指令」。
     */
    private function mapStatus(mixed $status): string
    {
        return match (strtolower($this->string($status))) {
            'on' => 'on',
            'off' => 'off',
            'suspend' => 'suspend',
            'wait_reboot' => 'wait_reboot',
            'task', 'cold_migrate', 'hot_migrate' => 'task',
            'paused' => 'paused',
            default => 'unknown',
        };
    }

    /**
     * 救援类型：`linux`→1、`windows`→2；支持 `linux:TempPass` 显式指定临时密码。
     *
     * @return array{0: int, 1: string}
     */
    private function rescueTarget(string $system): array
    {
        $pass = '';
        if (str_contains($system, ':')) {
            [$system, $pass] = explode(':', $system, 2);
            $pass = trim($pass);
        }

        $type = match (trim($system)) {
            'windows', 'win', '2' => 2,
            'linux', 'lin', '1' => 1,
            default => throw new CloudApiException(
                '不支持的救援系统类型：'.$system.'（仅支持 linux / windows / exit）',
                null,
                CloudApiMode::Legacy
            ),
        };

        if ($pass === '') {
            // 老接口 temp_pass 必填，平台侧没有该入参，这里生成一个并随返回一起给出
            $pass = 'Rc'.bin2hex(random_bytes(5));
        }

        return [$type, $pass];
    }

    /**
     * 任务名 → 任务类型（0 重装 / 1 救援 / 2 改密 / 3 硬件采集，未知按 0）。
     *
     * 老接口只给任务名，没有类型字段，只能靠关键词猜；猜不到时给 0 而不是 3，
     * 避免把「不认识的任务」误报成硬件采集。
     */
    private function taskType(string $task): int
    {
        $task = strtolower($task);

        if ($task === '') {
            return 0;
        }

        if (str_contains($task, 'reinstall') || str_contains($task, 'rebuid') || str_contains($task, 'rebuild')) {
            return 0;
        }

        if (str_contains($task, 'rescue')) {
            return 1;
        }

        if (str_contains($task, 'password') || str_contains($task, 'passwd')) {
            return 2;
        }

        if (str_contains($task, 'hardware') || str_contains($task, 'collect') || str_contains($task, 'hw')) {
            return 3;
        }

        return 0;
    }

    /**
     * 监控行的取值列（契约 §4.3）：cpu→[0][1]、disk/network→[1][2]、memory→[2][3]。
     *
     * @param  array<int, mixed>  $item
     * @return array{time:int, value:float, value2?:float}
     */
    private function statisticsPoint(string $type, array $item): array
    {
        $time = $this->timestamp($item[0] ?? 0);

        if ($type === 'cpu') {
            return [
                'time' => $time,
                'value' => round($this->float($item[1] ?? 0), 2),
            ];
        }

        if ($type === 'memory') {
            return [
                'time' => $time,
                'value' => round($this->float($item[2] ?? 0), 2),
                'value2' => round($this->float($item[3] ?? 0), 2),
            ];
        }

        return [
            'time' => $time,
            'value' => round($this->float($item[1] ?? 0), 2),
            'value2' => round($this->float($item[2] ?? 0), 2),
        ];
    }

    /**
     * 电源/VNC/重装/改密/救援/暂停/恢复/删机：只认字符串 `success`（契约 §5.2）。
     *
     * @param  array<string, mixed>  $body
     */
    private function assertStrictSuccess(array $body, string $fallbackMessage): void
    {
        if ($this->isStrictSuccess($body)) {
            return;
        }

        throw new CloudApiException($this->failureMessage($body, $fallbackMessage), null, CloudApiMode::Legacy);
    }

    /**
     * NAT/安全组类写操作：`success` 或 `200` 都算成功（契约 §5.2）。
     *
     * @param  array<string, mixed>  $body
     */
    private function assertLooseSuccess(array $body, string $fallbackMessage): void
    {
        if ($this->isStrictSuccess($body) || (int) ($body['status'] ?? 0) === self::STATUS_OK) {
            return;
        }

        throw new CloudApiException($this->failureMessage($body, $fallbackMessage), null, CloudApiMode::Legacy);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function isStrictSuccess(array $body): bool
    {
        return $this->string($body['status'] ?? '') === self::STATUS_SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function failureMessage(array $body, string $fallbackMessage): string
    {
        $message = $this->string($body['msg'] ?? $body['message'] ?? '');
        if ($message !== '') {
            return $message;
        }

        $status = $this->string($body['status'] ?? '');

        return $status !== '' ? $fallbackMessage.'（上游返回 status='.$status.'）' : $fallbackMessage;
    }

    /**
     * 秒级时间戳 → 老接口要求的毫秒字符串（`秒 * 1000`）。
     */
    private function millisecond(mixed $value): string
    {
        if ($value === '' || $value === null) {
            return '';
        }

        $seconds = is_numeric($value) ? (int) $value : strtotime((string) $value);

        return $seconds > 0 ? (string) ($seconds * 1000) : '';
    }

    private function timestamp(mixed $value): int
    {
        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        $parsed = is_string($value) && $value !== '' ? strtotime($value) : false;

        return $parsed === false ? 0 : $parsed;
    }

    private function string(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /* ------------------------------- 私有工具 ------------------------------- */

    /**
     * `null` / `''` / `0` 一律视为「本次不改这一项」。
     */
    private function isSpecified(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return ! (is_numeric($value) && (float) $value === 0.0);
    }

    /**
     * 过滤掉「未指定」的键；数值型值转 int，表单提交更干净。
     *
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function onlySpecified(array $map): array
    {
        $payload = [];

        foreach ($map as $key => $value) {
            if (! $this->isSpecified($value)) {
                continue;
            }

            $payload[$key] = is_numeric($value) ? (int) $value : (string) $value;
        }

        return $payload;
    }

    /**
     * 读实例详情里与授权相关的两件事：归属客户 ID 与实例类型。
     *
     * @return array{user_id: int, type: string}
     */
    private function hostOwnership(int $hostId): array
    {
        $detail = $this->client->request('GET', '/clouds/'.$hostId);

        return [
            'user_id' => (int) ($detail['user_id'] ?? 0),
            'type' => $this->string($detail['type'] ?? ''),
        ];
    }

    /**
     * 安全组归属校验：组必须在该实例归属客户的安全组全集里。
     *
     * 一个面板账号管着上游所有客户，只校验「组存在」等于放任跨客户操作，
     * 所以这里必须按客户过滤后再比对。
     */
    private function assertOwnedSecurityGroup(int $hostId, int $groupId): void
    {
        if ($groupId <= 0) {
            throw new CloudApiException('安全组 ID 无效', null, CloudApiMode::Legacy);
        }

        $host = $this->hostOwnership($hostId);
        if ($host['user_id'] <= 0) {
            throw new CloudApiException('无法确认实例归属的上游客户，已拒绝操作安全组', null, CloudApiMode::Legacy);
        }

        $body = $this->client->request('GET', '/security_groups', [], [
            'list_type' => 'all',
            'user' => $host['user_id'],
        ]);

        $ownedIds = array_map(
            static fn (array $item): int => (int) ($item['id'] ?? 0),
            $this->rows($body)
        );

        if (! in_array($groupId, $ownedIds, true)) {
            throw new CloudApiException('安全组不属于该实例归属的客户', null, CloudApiMode::Legacy);
        }
    }

    /**
     * 快照/备份列表归一化（契约 §3.8 的 9 个键）。
     *
     * @param  array<string, mixed>  $body
     * @return array<int, array<string, mixed>>
     */
    private function snapshotRows(array $body): array
    {
        $rows = [];

        foreach ($this->rows($body) as $item) {
            $type = strtolower($this->string($item['type'] ?? ''));

            $rows[] = [
                'id' => (int) ($item['id'] ?? 0),
                'name' => $this->string($item['name'] ?? ''),
                // 上游只给 snap / backup，其它值（历史数据）原样保留小写
                'type' => $type,
                'disk_id' => (int) ($item['diskid'] ?? $item['disk_id'] ?? 0),
                'disk_name' => $this->string($item['disk_remarks'] ?? $item['disk_name'] ?? ''),
                'size_gb' => round($this->float($item['size'] ?? $item['size_gb'] ?? 0), 2),
                'created_at' => $this->string($item['created_at'] ?? $item['create_time'] ?? ''),
                'status' => $this->string($item['status'] ?? ''),
                'raw' => $item,
            ];
        }

        return $rows;
    }

    /**
     * 安全组规则归一化：保留上游锁定标记（`lock`），不在这里删规则。
     *
     * @param  array<string, mixed>  $body
     * @return array<int, array<string, mixed>>
     */
    private function securityRuleRows(array $body): array
    {
        $rows = [];

        foreach ($this->rows($body) as $item) {
            $startPort = $this->string($item['start_port'] ?? '');
            $endPort = $this->string($item['end_port'] ?? '');
            $port = $this->string($item['port'] ?? '');

            if ($port === '' && $startPort !== '') {
                $port = $endPort !== '' && $endPort !== $startPort ? $startPort.'-'.$endPort : $startPort;
            }

            $ip = $this->string($item['ip'] ?? $item['start_ip'] ?? '');

            $rows[] = [
                'id' => (int) ($item['id'] ?? 0),
                'direction' => strtolower($this->string($item['direction'] ?? '')),
                'protocol' => strtolower($this->string($item['protocol'] ?? '')),
                'port' => $port,
                'ip' => $ip,
                'action' => strtolower($this->string($item['action'] ?? '')),
                'priority' => (int) ($item['priority'] ?? 0),
                'description' => $this->string($item['description'] ?? ''),
                'locked' => (int) ($item['lock'] ?? 0) === 1 ? 1 : 0,
                'raw' => $item,
            ];
        }

        return $rows;
    }

    /**
     * `GET /node_isos` 的响应是「分组 => info[]」两层结构，这里摊平成 `[['id','name']]`。
     *
     * @param  array<string, mixed>  $body
     * @return array<int, array{id: string, name: string}>
     */
    private function isoRows(array $body): array
    {
        $isos = [];

        foreach ($this->rows($body) as $group) {
            $items = $group['info'] ?? [];
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $isos[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'name' => $this->string($item['name'] ?? ''),
                ];
            }
        }

        return $isos;
    }

    /**
     * 从实例详情里挑系统盘 ID（0 = 没找到）。
     */
    private function systemDiskId(int $hostId): int
    {
        $detail = $this->client->request('GET', '/clouds/'.$hostId);

        foreach ((array) ($detail['disk'] ?? []) as $disk) {
            if (! is_array($disk)) {
                continue;
            }

            if ($this->string($disk['type'] ?? '') === 'system') {
                return (int) ($disk['id'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * 数据盘扩容或新建：有数据盘就改容量，没有就用系统盘所在存储池新建一块。
     *
     * @return array<string, mixed> 最后一次上游响应
     */
    private function resizeOrCreateDataDisk(int $hostId, int $diskId, int $sizeGb): array
    {
        $detail = $this->client->request('GET', '/clouds/'.$hostId);
        $storeId = 0;

        foreach ((array) ($detail['disk'] ?? []) as $disk) {
            if (! is_array($disk)) {
                continue;
            }

            $type = $this->string($disk['type'] ?? '');

            if ($diskId <= 0 && $type === 'data') {
                $diskId = (int) ($disk['id'] ?? 0);
            }

            if ($storeId <= 0 && $type === 'system') {
                $storeId = (int) ($disk['store_id'] ?? 0);
            }
        }

        if ($diskId > 0) {
            $body = $this->client->request('PUT', '/disks/'.$diskId, ['size' => $sizeGb]);
            $this->assertLooseSuccess($body, '改配失败（数据盘扩容）');

            return $body;
        }

        // 系统盘也没带存储池 → 问上游要一份可用存储池
        if ($storeId <= 0) {
            $stores = $this->client->request('GET', '/clouds/'.$hostId.'/stores');
            $first = $this->rows($stores)[0] ?? [];
            $storeId = (int) ($first['id'] ?? 0);
        }

        if ($storeId <= 0) {
            throw new CloudApiException('找不到可用存储池，无法新建数据盘', null, CloudApiMode::Legacy);
        }

        $body = $this->client->request('POST', '/clouds/'.$hostId.'/disks', [
            'size' => $sizeGb,
            'store' => $storeId,
            'driver' => 'virtio',
        ]);
        $this->assertLooseSuccess($body, '改配失败（新建数据盘）');

        return $body;
    }
}
