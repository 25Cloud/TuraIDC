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
        $detail = $this->client->request('GET', '/clouds/'.$hostId);
        $userId = (int) ($detail['user_id'] ?? 0);

        // 没有归属用户就不查（避免把上游全部安全组拉回来）
        if ($userId <= 0) {
            return [];
        }

        $body = $this->client->request('GET', '/security_groups', [], [
            'list_type' => 'all',
            'user' => $userId,
        ]);

        return $this->rows($body);
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

    /* ------------------------------- 内部辅助 ------------------------------- */

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
}
