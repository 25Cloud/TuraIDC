<?php

declare(strict_types=1);

namespace App\Services\Upstream\Support;

class CloudConfigTemplate
{
    public function supports(array $product): bool
    {
        return in_array(strtolower(trim((string) ($product['type'] ?? ''))), ['cloud', 'vps'], true);
    }

    /**
     * @param  array<string,mixed>  $product
     * @return array<int,array<string,mixed>>
     */
    public function build(array $product): array
    {
        $autoParameters = $this->extractProductParameters($product);

        return array_map(function (array $item, int $index) use ($autoParameters): array {
            return [
                'spec_key' => $item['field'],
                'source' => $this->source(),
                'field' => $item['field'],
                'name' => $item['name'],
                'parameter' => $autoParameters[$item['field']] ?? '',
                'description' => $item['description'],
                'required' => $item['required'] ? 1 : 0,
                'default_value' => $item['default_value'],
                'sort_order' => $index + 1,
                'hidden' => 0,
                'allow_upgrade' => 0,
                'allow_promo_code' => 1,
            ];
        }, $this->catalog(), array_keys($this->catalog()));
    }

    protected function source(): string
    {
        return 'hosting_panel_api';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function catalog(): array
    {
        return [
            [
                'field' => 'area',
                'name' => '数据中心',
                'description' => '区域 ID，数据中心和节点 id 至少传一个。',
                'required' => true,
                'default_value' => '-',
            ],
            [
                'field' => 'node',
                'name' => '节点 id',
                'description' => '节点 ID，数据中心和节点 id 至少传一个。',
                'required' => false,
                'default_value' => '不传递系统将自动分配',
            ],
            [
                'field' => 'os',
                'name' => '操作系统',
                'description' => '镜像管理中的操作系统 ID。',
                'required' => true,
                'default_value' => '-',
            ],
            [
                'field' => 'cpu',
                'name' => 'CPU',
                'description' => '开通实例时分配的 CPU 核心数。',
                'required' => true,
                'default_value' => '-',
            ],
            [
                'field' => 'memory',
                'name' => '内存',
                'description' => '开通实例时分配的内存大小，单位 M。',
                'required' => true,
                'default_value' => '-',
            ],
            [
                'field' => 'system_disk_size',
                'name' => '系统盘',
                'description' => '支持固定系统盘、按系统区分大小以及指定存储 ID 三种传递方式。',
                'required' => false,
                'default_value' => '不传递默认 50G，windows 系统盘最小 30G',
            ],
            [
                'field' => 'network_type',
                'name' => '网络类型',
                'description' => 'normal 为经典网络，vpc 为 VPC 网络。',
                'required' => true,
                'default_value' => '-',
            ],
            [
                'field' => 'bw',
                'name' => '带宽',
                'description' => '上下行带宽，单位 Mbps。',
                'required' => false,
                'default_value' => '不传递默认为 0Mbps',
            ],
            [
                'field' => 'in_bw',
                'name' => '流入带宽',
                'description' => '进带宽，若配置该参数则优先使用。',
                'required' => false,
                'default_value' => '不传递默认为 0Mbps',
            ],
            [
                'field' => 'flow_limit',
                'name' => '流量',
                'description' => '流量大小，单位 G。',
                'required' => false,
                'default_value' => '不传递默认为不限量',
            ],
            [
                'field' => 'flow_way',
                'name' => '流量方向',
                'description' => '控制流量统计方向，可选 in、out、all。',
                'required' => false,
                'default_value' => '不传递默认为 all',
            ],
            [
                'field' => 'ip_num',
                'name' => 'IP 数量',
                'description' => '实例分配的 IPv4 数量。',
                'required' => true,
                'default_value' => '-',
            ],
            [
                'field' => 'data_disk_size',
                'name' => '数据盘',
                'description' => '数据盘大小，单位 G，可附带存储 ID。',
                'required' => false,
                'default_value' => '不传递默认无数据盘',
            ],
            [
                'field' => 'snap_num',
                'name' => '快照数量',
                'description' => '控制实例快照数量上限。',
                'required' => false,
                'default_value' => '不传递默认 2 个',
            ],
            [
                'field' => 'backup_num',
                'name' => '备份数量',
                'description' => '控制实例备份数量上限。',
                'required' => false,
                'default_value' => '不传递默认 2 个',
            ],
            [
                'field' => 'nat_acl_limit',
                'name' => 'NAT 转发',
                'description' => '控制 NAT 转发数量。',
                'required' => false,
                'default_value' => '不传递默认不支持',
            ],
            [
                'field' => 'nat_web_limit',
                'name' => '共享建站',
                'description' => '控制 NAT 建站数量。',
                'required' => false,
                'default_value' => '不传递默认不支持',
            ],
            [
                'field' => 'system_disk_io_limit',
                'name' => '系统盘性能',
                'description' => '系统盘读写带宽和 IOPS 限制。',
                'required' => false,
                'default_value' => '不传递默认不限制',
            ],
            [
                'field' => 'data_disk_io_limit',
                'name' => '数据盘性能',
                'description' => '数据盘读写带宽和 IOPS 限制。',
                'required' => false,
                'default_value' => '不传递默认不限制',
            ],
            [
                'field' => 'ip_group',
                'name' => 'IP 分组',
                'description' => 'IP 管理中的 IP 分组 ID。',
                'required' => false,
                'default_value' => '-',
            ],
            [
                'field' => 'node_group',
                'name' => '节点分组',
                'description' => '节点管理中的节点分组 ID。',
                'required' => false,
                'default_value' => '-',
            ],
            [
                'field' => 'node_priority',
                'name' => '节点选择优先级',
                'description' => '创建实例时的节点分配策略。',
                'required' => false,
                'default_value' => '不传递默认数量平均',
            ],
            [
                'field' => 'IP_MACBond',
                'name' => '嵌套虚拟化',
                'description' => '控制 IP-MAC 绑定开关。',
                'required' => false,
                'default_value' => '不传递默认开启绑定',
            ],
            [
                'field' => 'cpu_limit',
                'name' => 'CPU 限制',
                'description' => '实例 CPU 使用率限制。',
                'required' => false,
                'default_value' => '不传递默认以上游系统设置为准',
            ],
            [
                'field' => 'traffic_bill_type',
                'name' => '流量计费周期',
                'description' => '控制流量清零周期。',
                'required' => false,
                'default_value' => '不传递默认每月 1 日清零',
            ],
            [
                'field' => 'type',
                'name' => '云节点类型',
                'description' => '控制云节点类型。',
                'required' => false,
                'default_value' => '不传递默认 KVM 加强版',
            ],
            [
                'field' => 'advanced_cpu',
                'name' => '智能 CPU',
                'description' => '监控系统中的智能 CPU 规则 ID。',
                'required' => false,
                'default_value' => '-',
            ],
            [
                'field' => 'advanced_bw',
                'name' => '智能带宽',
                'description' => '监控系统中的智能带宽规则 ID。',
                'required' => false,
                'default_value' => '-',
            ],
            [
                'field' => 'port',
                'name' => '端口',
                'description' => '支持随机端口或指定端口。',
                'required' => false,
                'default_value' => '不传递默认不支持',
            ],
            [
                'field' => 'ipv6_num',
                'name' => 'ipv6 数量',
                'description' => '实例分配的 IPv6 数量。',
                'required' => false,
                'default_value' => '-',
            ],
            [
                'field' => 'resource_package',
                'name' => '资源包',
                'description' => '上游资源包 ID。',
                'required' => false,
                'default_value' => '不传递默认不支持',
            ],
            [
                'field' => 'gpu_num',
                'name' => 'GPU 数量',
                'description' => '实例分配的 GPU 数量。',
                'required' => false,
                'default_value' => '不传递默认不支持',
            ],
            [
                'field' => 'niccard',
                'name' => '网卡驱动',
                'description' => '控制网卡驱动类型。',
                'required' => false,
                'default_value' => '不传递默认不支持',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $product
     * @return array<string,string>
     */
    private function extractProductParameters(array $product): array
    {
        $description = $this->normalizeDescriptionText((string) ($product['description'] ?? ''));
        $facts = [];

        if (preg_match('/CPU[:：]\s*(\d+(?:\.\d+)?)\s*核/iu', $description, $matches) === 1) {
            $cpu = (int) round((float) $matches[1]);
            if ($cpu > 0) {
                $facts['cpu'] = "{$cpu}|{$cpu} 核心";
            }
        }

        if (preg_match('/内存[:：]\s*(\d+(?:\.\d+)?)\s*G/iu', $description, $matches) === 1) {
            $memoryGb = (float) $matches[1];
            $memoryMb = (int) round($memoryGb * 1024);
            if ($memoryMb > 0) {
                $memoryText = rtrim(rtrim(number_format($memoryGb, 2, '.', ''), '0'), '.');
                $facts['memory'] = "{$memoryMb}|{$memoryText}G";
            }
        }

        if (preg_match('/带宽[:：]\s*(\d+(?:\.\d+)?)\s*M/iu', $description, $matches) === 1) {
            $bandwidth = rtrim(rtrim(number_format((float) $matches[1], 2, '.', ''), '0'), '.');
            if ($bandwidth !== '' && (float) $bandwidth > 0) {
                $facts['bw'] = "{$bandwidth}|{$bandwidth}Mbps";
            }
        }

        if (preg_match('/流量[:：]\s*(\d+(?:\.\d+)?)\s*T/iu', $description, $matches) === 1) {
            $flowTb = (float) $matches[1];
            $flowGb = (int) round($flowTb * 1024);
            if ($flowGb > 0) {
                $flowText = rtrim(rtrim(number_format($flowTb, 2, '.', ''), '0'), '.');
                $facts['flow_limit'] = "{$flowGb}|{$flowText}T";
            }
        } elseif (preg_match('/流量[:：]\s*(\d+(?:\.\d+)?)\s*G/iu', $description, $matches) === 1) {
            $flowGb = rtrim(rtrim(number_format((float) $matches[1], 2, '.', ''), '0'), '.');
            if ($flowGb !== '' && (float) $flowGb > 0) {
                $facts['flow_limit'] = "{$flowGb}|{$flowGb}G";
            }
        }

        if (preg_match('/硬盘[:：]\s*(\d+(?:\.\d+)?)\s*G/iu', $description, $matches) === 1) {
            $disk = rtrim(rtrim(number_format((float) $matches[1], 2, '.', ''), '0'), '.');
            if ($disk !== '' && (float) $disk > 0) {
                $facts['system_disk_size'] = "{$disk}|系统盘";
            }
        }

        $contextText = mb_strtolower(
            implode(' ', array_filter([
                (string) ($product['name'] ?? ''),
                (string) ($product['group_name'] ?? ''),
                (string) ($product['group_label'] ?? ''),
                $description,
            ]))
        );

        if (str_contains($contextText, '轻量')) {
            $facts['type'] = 'lightHost|KVM 轻量版';
        } elseif (str_contains($contextText, 'hyper-v') || str_contains($contextText, 'hyperv')) {
            $facts['type'] = 'hyperv|Hyper-V';
        } elseif (str_contains($contextText, '拨号')) {
            $facts['type'] = 'adsl|拨号云';
        }

        return $facts;
    }

    private function normalizeDescriptionText(string $description): string
    {
        $description = preg_replace('/<br\s*\/?>/iu', "\n", $description) ?? $description;
        $description = strip_tags($description);
        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = preg_replace("/\r\n|\r/u", "\n", $description) ?? $description;

        return trim($description);
    }
}
