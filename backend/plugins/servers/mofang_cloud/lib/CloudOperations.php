<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\MofangCloud\Lib;

/**
 * 魔方云能力实现（一代一套）。
 *
 * 平台侧只认这里定义的归一化返回结构，不关心底下走的是老版 `/v1` 还是新版 `/api/v1`；
 * 反过来，两代实现的差异也只允许出现在本接口的实现里，绝不外泄给平台消费方。
 *
 * ## 归一化后的实例详情（`hostDetail()`）
 *
 * 键名沿用平台既有的「主机详情」形状（与魔方财务 host/header 的 data 对齐），
 * 让控制台、同步任务、状态机不需要为新驱动开分支：
 *
 * - `id`              int     上游实例 ID
 * - `domain`          string  主机名（上游 hostname/name）
 * - `domainstatus`    string  状态串（见 hostStatus 状态表，供平台状态机映射）
 * - `dedicatedip`     string  主 IP
 * - `assignedips`     array   附加 IP 列表
 * - `username`        string  系统用户名
 * - `password`        string  系统密码（上游返回才给，否则空串）
 * - `port`            int     远程端口
 * - `os`              string  操作系统名
 * - `bwusage`         float   已用流量（GB，保留两位以内）
 * - `bwlimit`         int     流量上限（GB，0 = 不限）
 * - `bwlimit_included` int    流量包加量（GB），没有则 0
 * - `cpu`             int     核数
 * - `memory`          int     内存（MB）
 * - `disk`            array   磁盘明细 `[['id'=>, 'size'=>GB, 'type'=>'system'|'data']]`
 * - `node_id`         int     节点/宿主机 ID（0 表示上游未给）
 * - `kvmid`           string  虚拟化实例标识（监控用，可空）
 * - `product_name`    string  上游套餐/规格名
 * - `created_at`      string  `Y-m-d H:i:s`，可空
 * - `expires_at`      string  `Y-m-d H:i:s`，可空
 * - `rescue`          int     是否处于救援模式（0/1）
 * - `iso`             string  已挂载 ISO（可空）
 * - `bootorder`       string  启动项（可空）
 * - `raw`             array   上游原始响应片段，排障用，不参与展示
 */
interface CloudOperations
{
    public function mode(): CloudApiMode;

    /**
     * @return array<string, mixed> 见接口头部的归一化字段表
     */
    public function hostDetail(int $hostId): array;

    /**
     * 电源/运行状态。
     *
     * 归一化返回：
     * - `status`     string  `on` | `off` | `suspend` | `wait_reboot` | `task` | `paused` | `unknown`
     * - `task`       string  当前任务标识（可空）
     * - `task_name`  string  当前任务中文名（可空）
     * - `raw`        array   上游原始响应
     *
     * @return array<string, mixed>
     */
    public function hostStatus(int $hostId): array;

    /**
     * 电源操作。
     *
     * @param  string  $action  on | off | reboot | hard_off | hard_reboot
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function power(string $action, int $hostId): array;

    /**
     * VNC 连接信息。
     *
     * 归一化返回：
     * - `url`   string 可直接打开的地址（ws/wss 或 http/https）
     * - `token` string 上游一次性令牌（可空）
     * - `pass`  string VNC 密码（可空）
     * - `raw`   array
     *
     * @return array<string, mixed>
     */
    public function vnc(int $hostId): array;

    /**
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function resetPassword(int $hostId, string $password, string $username = ''): array;

    /**
     * 可重装镜像列表。
     *
     * @return array<int, array{id:int|string, name:string, group?:string}>
     */
    public function reinstallOptions(int $hostId): array;

    /**
     * @param  array<string, mixed>  $params  os_id / password / port / partition_mode 等
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function reinstall(int $hostId, array $params): array;

    /**
     * @param  string  $system  linux | windows
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function rescue(int $hostId, string $system = 'linux'): array;

    /**
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function cancelTask(int $hostId): array;

    /**
     * 进行中的任务 / 重装进度。
     *
     * 归一化返回：
     * - `running`   bool    是否有进行中的任务
     * - `task_type` int     0 重装 / 1 救援 / 2 改密 / 3 硬件采集
     * - `name`      string  任务名
     * - `progress`  int     0-100
     * - `step`      string  当前步骤文案
     * - `error`     string  失败原因（成功为空）
     * - `raw`       array
     *
     * @return array<string, mixed>
     */
    public function taskStatus(int $hostId): array;

    /**
     * 流量用量。
     *
     * 归一化返回：
     * - `used`      float  已用（GB）
     * - `limit`     int    配额（GB，0 = 不限）
     * - `extra`     int    流量包加量（GB）
     * - `percent`   float  百分比（0-100，limit 为 0 时给 0）
     * - `reset_at`  string 下次重置时间（可空）
     * - `raw`       array
     *
     * @return array<string, mixed>
     */
    public function trafficUsage(int $hostId): array;

    /**
     * 流量曲线。
     *
     * @param  array<string, mixed>  $query  start/end 等（秒级时间戳）
     * @return array<int, array{time:int, in:float, out:float}>
     */
    public function trafficChart(int $hostId, array $query): array;

    /**
     * 监控统计。
     *
     * @param  string  $type  cpu | memory | disk | network
     * @param  array<string, mixed>  $query
     * @return array<int, array{time:int, value:float, value2?:float}>
     */
    public function statistics(int $hostId, string $type, array $query): array;

    /**
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function suspend(int $hostId): array;

    /**
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function unsuspend(int $hostId): array;

    /**
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function terminate(int $hostId): array;

    /**
     * 镜像目录。
     *
     * @return array<int, array{id:int|string, name:string, group?:string, status?:int}>
     */
    public function mirrorOs(): array;

    /**
     * 区域目录；当前代际不支持时返回空数组。
     *
     * @return array<int, array{id:int|string, name:string, country_code?:string}>
     */
    public function areas(): array;

    /**
     * 安全组列表；当前代际不支持时抛 CloudApiException（消息面向用户可读）。
     *
     * `$hostId > 0` 表示「这台实例归属客户的安全组」，实现应按客户过滤；
     * `$hostId <= 0` 表示「这个面板账号能看到的安全组」——平台的安全组页按服务打开，
     * 取不到实例时会传 0，实现不得据此去查 `/clouds/0`。
     *
     * @return array<int, array<string, mixed>>
     */
    public function securityGroups(int $hostId): array;

    /**
     * NAT 端口转发列表；当前代际不支持时抛 CloudApiException。
     *
     * @return array<int, array<string, mixed>>
     */
    public function natRules(int $hostId): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function createNatRule(int $hostId, array $payload): array;

    /**
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function deleteNatRule(int $hostId, int $ruleId): array;

    /* ------------------------------ 快照 / 备份 ------------------------------ */

    /**
     * 快照与备份列表。
     *
     * 魔方财务把「快照」和「备份」当成同一类对象，只靠 `type` 区分（契约 §3.8），
     * 所以本平台也只有一个列表入口，靠 `type` 决定展示分组。
     *
     * 归一化返回（每条）：
     * - `id`         int    快照 ID
     * - `name`       string 快照名
     * - `type`       string `snap`（快照）| `backup`（备份）
     * - `disk_id`    int    所属磁盘 ID（0 = 上游未给）
     * - `disk_name`  string 所属磁盘名（可空）
     * - `size_gb`    float  体积（GB，0 = 上游未给）
     * - `created_at` string `Y-m-d H:i:s`（可空）
     * - `status`     string 上游状态串（可空）
     * - `raw`        array  上游原始条目，排障用
     *
     * @return array<int, array<string, mixed>>
     */
    public function snapshots(int $hostId): array;

    /**
     * 创建快照 / 备份。
     *
     * @param  array<string, mixed>  $params  `name` 必填；`type` 取 `snap`|`backup`（默认 `snap`）；
     *                                        `disk_id` 不给时取该实例的系统盘
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'snapshot_id' => int, 'raw' => array]`
     */
    public function createSnapshot(int $hostId, array $params): array;

    /**
     * 删除快照 / 备份。
     *
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function deleteSnapshot(int $hostId, int $snapshotId): array;

    /**
     * 用快照 / 备份回滚实例。
     *
     * 上游是异步任务，返回成功只代表任务已受理，不做「回滚完成」的承诺。
     *
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function restoreSnapshot(int $hostId, int $snapshotId): array;

    /* ------------------------------ ISO / 启动项 ------------------------------ */

    /**
     * 当前主机所在节点上的可挂载 ISO 列表。
     *
     * @return array<int, array{id:string, name:string}>
     */
    public function isoImages(int $hostId): array;

    /**
     * 挂载 ISO（挂上之后实例通常要重启才生效，由调用方决定何时重启）。
     *
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function mountIso(int $hostId, string $isoId): array;

    /**
     * 卸载 ISO。
     *
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function unmountIso(int $hostId): array;

    /**
     * 设置启动项。
     *
     * @param  string  $order  `disk`（硬盘优先）| `cdrom`（光驱优先）| 上游原始串
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function setBootOrder(int $hostId, string $order): array;

    /* ------------------------------ 安全组写 ------------------------------ */

    /**
     * 读取安全组内的规则。
     *
     * 归一化返回（每条）：
     * - `id`          int    规则 ID
     * - `direction`   string `in` | `out`
     * - `protocol`    string `tcp` | `udp` | `icmp` | ...
     * - `port`        string 端口或端口段（可空，如 `80`、`8000-9000`）
     * - `ip`          string 授权地址（可空，如 `0.0.0.0/0`）
     * - `action`      string `accept` | `drop`
     * - `priority`    int    优先级（0 = 上游未给）
     * - `description` string 备注
     * - `locked`      int    是否上游锁定（1 = 不允许用户改动）
     * - `raw`         array
     *
     * @return array<int, array<string, mixed>>
     */
    public function securityRules(int $hostId, int $groupId): array;

    /**
     * 新建安全组。
     *
     * 安全组挂在**上游客户**名下而不是单台实例名下，所以必须给 hostId 用于反查归属客户；
     * 跨代差异（老版还要带 `type`）由实现内部处理。
     *
     * @param  array<string, mixed>  $params  `name` 必填，`description` 可选
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'group_id' => int, 'raw' => array]`
     */
    public function createSecurityGroup(int $hostId, array $params): array;

    /**
     * 删除安全组。
     *
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function deleteSecurityGroup(int $hostId, int $groupId): array;

    /**
     * 新建安全组规则。
     *
     * @param  array<string, mixed>  $params  `direction` 与 `protocol` 必填；`action` 缺省按 `accept`
     *                                        处理（平台永远不会传这个键）；`port`、`ip`、`priority`、
     *                                        `description` 给了才提交；端口也可以拆成
     *                                        `start_port` + `end_port` 二段式
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'rule_id' => int, 'raw' => array]`
     */
    public function createSecurityRule(int $hostId, int $groupId, array $params): array;

    /**
     * 删除安全组规则。
     *
     * 上游把规则做成顶层资源（`DELETE /security_group_rules/{id}`），groupId 只用于
     * 调用前的归属校验，防止拿别人的组 ID 删本组规则。
     *
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function deleteSecurityRule(int $hostId, int $groupId, int $ruleId): array;

    /**
     * 把安全组应用到实例上。
     *
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function applySecurityGroup(int $hostId, int $groupId): array;

    /* ------------------------------ 改配 ------------------------------ */

    /**
     * 变更实例规格（不涉及计费，计费口径由平台在订单侧决定）。
     *
     * 入参键固定，值为 `null` / `''` / `0` 一律视为「本次不改这一项」，实现不得提交：
     *
     * - `cpu`        int  核数
     * - `memory`     int  内存（MB）
     * - `in_bw`      int  入方向带宽（Mbps）
     * - `out_bw`     int  出方向带宽（Mbps）
     * - `flow`       int  流量上限（GB）
     * - `ip_num`     int  附加 IPv4 数量
     * - `ip_group`   int  附加 IP 的地址组
     * - `ipv6_num`   int  附加 IPv6 数量
     * - `disk_id`    int  要扩容的数据盘 ID（0 = 没有数据盘，需要新建）
     * - `disk_size`  int  数据盘目标容量（GB）
     * - `snap_num`   int  快照配额
     * - `backup_num` int  备份配额
     *
     * 返回 `applied` 是本次真正下发过的字段名列表，供调用方与审计日志对照。
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'applied' => array<int, string>, 'raw' => array]`
     */
    public function changeConfig(int $hostId, array $params): array;

    /**
     * 开通实例。入参由 `MofangCloudAdapter::provisionOrder()` 归一后传入，键固定为：
     *
     * `product_id`(int 上游商品 id)、`hostname`、`password`、`os_id`、`area_id`(int)、
     * `cpu`(int)、`memory`(int MB)、`disk`(int GB)、`bandwidth`(int Mbps)、`flow`(int GB)、
     * `ip_num`(int)、`ipv6_num`(int)、`billing_cycle`、`quantity`(int)、`config_options`(array 原始配置项)、
     * `client_username`(该实例归属的上游客户用户名 = 配置前缀 + 本地用户 id)、`client_user_id`(本地用户 id)。
     *
     * 值为 `null` / `''` / `0` 的键一律视为「未指定」，实现不得提交。
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> `['ok' => bool, 'host_id' => int|string, 'message' => string, 'raw' => array]`
     */
    public function provision(array $params): array;

    /**
     * 购买流量包（按 GB 加量）。
     *
     * @return array<string, mixed> `['ok' => bool, 'message' => string, 'raw' => array]`
     */
    public function buyFlowPacket(int $hostId, int $trafficGb): array;
}
