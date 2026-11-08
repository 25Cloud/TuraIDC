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
