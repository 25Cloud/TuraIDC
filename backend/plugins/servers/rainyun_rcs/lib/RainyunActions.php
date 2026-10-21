<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\RainyunRcs\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;

final class RainyunActions
{
    public function __construct(private readonly RainyunClient $client) {}

    public function execute(Supplier $supplier, int $id, array $input): array
    {
        $action = (string) ($input['func'] ?? '');
        $detail = $this->client->detail($supplier, $id);
        throw_if(in_array($detail['Data']['Status'] ?? '', ['banned', 'expired'], true), new BusinessException('雨云实例已暂停或到期，不能执行该操作', 42200));
        [$method, $suffix, $body, $query] = $this->requestFor($action, $input, $detail);
        $this->client->request($supplier, $method, $this->client->hostPath($id, $suffix), $body, $query);

        return ['status' => 200, 'msg' => '操作已提交', 'data' => ['submitted' => true]];
    }

    public function requestFor(string $action, array $input, array $detail): array
    {
        $body = [];
        $query = [];
        $method = 'POST';
        switch ($action) {
            case 'addNatAcl':
                $protocol = (string) ($input['select-protocol'] ?? '');
                throw_if(! in_array($protocol, ['tcp', 'udp', 'tcp_udp'], true), new BusinessException('NAT 协议无效', 42200));
                $body = ['port_in' => RainyunValues::integer($input['int_port'] ?? null, '内网端口', 1, 65535),
                    'port_out' => RainyunValues::integer($input['ext_port'] ?? null, '外网端口', 10000, 60000),
                    'port_type' => $protocol, 'tag' => $this->text($input['name'] ?? '', 0, 32)];
                $suffix = 'nat';
                break;
            case 'delNatAcl':
                $natId = $this->ownedId($detail['NatList'] ?? [], $input['id'] ?? null, 'ID');
                $method = 'DELETE';
                $suffix = 'nat';
                $query = ['nat_id' => $natId];
                break;
            case 'backup_create':
                $suffix = 'backup/';
                $body = ['label' => $this->text($input['label'] ?? '', 2, 32)];
                break;
            case 'backup_delete':
            case 'backup_restore':
            case 'backup_cancel':
                $backupId = $this->ownedId($detail['RBSList'] ?? [], $input['id'] ?? null, 'id');
                $suffix = 'backup/'.$backupId.'/'.match ($action) {
                    'backup_restore' => 'restore', 'backup_cancel' => 'cancel', default => ''
                };
                $method = $action === 'backup_delete' ? 'DELETE' : 'POST';
                break;
            case 'backup_setting':
                $suffix = 'backup/setting';
                $method = 'PATCH';
                $query = $this->schedule($input, false);
                break;
            case 'snapshot_create':
                $suffix = 'snapshot/';
                $body = ['label' => $this->text($input['label'] ?? '', 2, 32), 'include_ram' => RainyunValues::boolean($input['include_ram'] ?? false, '包含内存')];
                break;
            case 'snapshot_delete':
            case 'snapshot_restore':
                $snapshotId = $this->ownedId($detail['SnapshotList'] ?? [], $input['id'] ?? null, 'id');
                $suffix = 'snapshot/'.$snapshotId.'/'.($action === 'snapshot_restore' ? 'rollback' : '');
                $method = $action === 'snapshot_delete' ? 'DELETE' : 'POST';
                break;
            case 'snapshot_setting':
                $suffix = 'snapshot/setting';
                $method = 'PATCH';
                $body = $this->schedule($input, true);
                break;
            case 'traffic_limit':
                $suffix = 'traffic/limit';
                $body = ['day_traffic_in_gb' => RainyunValues::integer($input['day_traffic_in_gb'] ?? null, '每日流量', 0, 1000000),
                    'traffic_limit' => RainyunValues::integer($input['traffic_limit'] ?? null, '限速', 0, 100000)];
                break;
            case 'gpu_toggle':
                throw_if(empty($detail['Data']['Plan']['dgpu_dev_type']), new BusinessException('该实例没有独立显卡', 42200));
                $suffix = 'toggle_dgpu';
                break;
            default:
                throw new BusinessException('不支持的雨云控制台动作', 42200);
        }

        return [$method, $suffix, $body, $query];
    }

    private function ownedId(array $items, mixed $value, string $key): int
    {
        $id = RainyunValues::integer($value, '资源 ID');
        throw_if(! in_array($id, array_map(fn ($item) => (int) ($item[$key] ?? 0), $items), true), new BusinessException('该资源不属于当前实例', 42200));

        return $id;
    }

    private function text(mixed $value, int $min, int $max): string
    {
        throw_if(! is_string($value) || mb_strlen(trim($value)) < $min || mb_strlen(trim($value)) > $max, new BusinessException('名称长度无效', 42200));

        return trim($value);
    }

    private function schedule(array $input, bool $snapshot): array
    {
        $hour = RainyunValues::integer($input['hour'] ?? null, '小时', -1, 23);
        $minute = RainyunValues::integer($input['minute'] ?? null, '分钟', -1, 59);
        throw_if(($hour === -1) !== ($minute === -1), new BusinessException('关闭自动任务时小时和分钟必须同时为 -1', 42200));
        $keep = RainyunValues::integer($input['keep_last'] ?? null, '保留数量', 0, 31);
        throw_if(! in_array($keep, $snapshot ? [1, 2, 3] : [0, 1, 3, 7, 15, 31], true), new BusinessException('保留数量不在雨云允许范围内', 42200));
        $prefix = $snapshot ? 'auto_snapshot_' : 'auto_backup_';

        return [$prefix.'hour' => $hour, $prefix.'minute' => $minute, 'keep_last' => $keep];
    }
}
