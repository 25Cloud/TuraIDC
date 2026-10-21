<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\RainyunRcs\Lib;

use App\Exceptions\BusinessException;

final class RainyunPanel
{
    public function render(array $detail, string $key, string $endpoint): string
    {
        throw_if(! in_array($key, ['backups', 'snapshots', 'disks', 'traffic', 'gpu', 'terminal', 'upgrade', 'nat_acl'], true), new BusinessException('功能面板不存在', 42200));
        if ($key === 'nat_acl') {
            return $this->nat($detail);
        }
        $host = $detail['Data'];
        $data = ['key' => $key, 'endpoint' => $endpoint,
            'upgrade_plans' => $this->pickRows($detail['UpgradeablePlans'] ?? [], ['id', 'chinese', 'cpu', 'memory']),
            'ip_flags' => array_keys((array) ($host['Plan']['ip_prices'] ?? [])),
            'ips' => $this->pickRows($detail['EIPList'] ?? [], ['ID', 'IP']),
            'disks' => $this->pickRows($detail['EDiskList'] ?? [], ['ID', 'Slot', 'DiskType', 'Size', 'Backup']),
            'disk_types' => $detail['AvailableDiskType'] ?? [],
            'backups' => $this->pickRows($detail['RBSList'] ?? [], ['id', 'label', 'status', 'create_time', 'size']),
            'snapshots' => $this->pickRows($detail['SnapshotList'] ?? [], ['id', 'label', 'status', 'create_time']),
            'host' => array_intersect_key($host, array_flip(['TrafficBytes', 'TrafficBytesToday', 'TrafficBytesDayLimit', 'TrafficOnLimit',
                'TrafficResetDate', 'GPUDevice', 'GPUMemorySize', 'DGPUEnable', 'WebbarMinutes', 'RBSAutoBackup', 'RBSKeepLast', 'SnapAuto', 'SnapKeepLast']))];
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return str_replace('__RAINYUN_DATA__', $json, file_get_contents(dirname(__DIR__).'/resources/panel.html'));
    }

    private function pickRows(array $rows, array $keys): array
    {
        return array_map(fn ($row) => array_intersect_key($row, array_flip($keys)), $rows);
    }

    private function nat(array $detail): string
    {
        // 对齐图拉现有 NAT 表格解析器，显示仍由控制台内置 TDesign 页面完成。
        $html = '<button data-target="#natAclAddModal">新增转发</button><select name="select-protocol"><option value="tcp">TCP</option><option value="udp">UDP</option><option value="tcp_udp">TCP / UDP</option></select><div class="table-responsive"><table><tbody>';
        foreach ($detail['NatList'] ?? [] as $row) {
            $html .= '<tr><td>'.$this->escape($row['Tag'] ?? '').'</td><td>'.$this->escape(($detail['Data']['NatPublicIP'] ?? '').':'.($row['PortOut'] ?? '')).'</td><td>'.(int) ($row['PortIn'] ?? 0).'</td><td>'.$this->escape($row['PortType'] ?? '').'</td><td><button class="deleteNAT" data-id="'.(int) ($row['ID'] ?? 0).'">删除</button></td></tr>';
        }

        return $html.'</tbody></table></div>';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
