<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\RainyunRcs\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;

final class RainyunConsole
{
    public function __construct(private readonly RainyunClient $client, private readonly RainyunCatalog $catalog, private readonly ?\Closure $wait = null) {}

    public function host(array $detail): array
    {
        $host = $detail['Data'];
        $nat = ($host['MainIPv4'] ?? '-') === '-';
        $windows = str_contains(strtolower((string) ($host['OsName'] ?? '')), 'win');
        $port = $windows ? 3389 : 22;
        if ($nat) {
            foreach ($detail['NatList'] ?? [] as $rule) {
                if ((int) ($rule['PortIn'] ?? 0) === $port) {
                    $port = (int) $rule['PortOut'];
                    break;
                }
            }
        }
        $status = (string) ($host['Status'] ?? '');

        return [
            'id' => (int) $host['ID'], 'domain' => (string) (($host['Tag'] ?? '') ?: ($host['HostName'] ?? '')),
            'domainstatus' => match ($status) {
                'banned', 'expired' => 'Suspended', 'running', 'stopped', 'stopping', 'booting', 'reinstalling' => 'Active', default => 'Pending'
            },
            'dedicatedip' => (string) ($nat ? ($host['NatPublicIP'] ?? '') : ($host['MainIPv4'] ?? '')),
            'assignedips' => implode(',', array_column($detail['EIPList'] ?? [], 'IP')),
            'username' => $windows ? 'administrator' : 'root', 'password' => (string) ($host['DefaultPass'] ?? ''),
            'port' => $port, 'os' => (string) ($host['OsName'] ?? ''), 'os_id' => (int) ($host['OsID'] ?? 0),
            'nextduedate' => (int) ($host['ExpDate'] ?? 0), 'power_status' => $status,
            'bwusage' => round((int) ($host['TrafficBytesToday'] ?? 0) / 1073741824, 2),
            'bwlimit' => round(((int) ($host['TrafficBytes'] ?? 0) + (int) ($host['TrafficBytesToday'] ?? 0)) / 1073741824, 2),
            'cpu' => $host['CPU'] ?? null, 'memory' => $host['Memory'] ?? null, 'disk' => $host['Disk'] ?? null,
        ];
    }

    public function status(array $detail, string $type = 'host'): array
    {
        $status = (string) ($detail['Data']['Status'] ?? '');
        $state = match ($status) {
            'running' => 'on', 'stopped', 'banned', 'expired' => 'off',
            'creating', 'stopping', 'booting', 'reinstalling' => 'process', default => 'unknown',
        };

        return ['status' => $state, 'des' => match ($state) {
            'on' => '运行中', 'off' => '已停止', 'process' => $type === 'reinstall' ? '重装处理中' : '任务处理中', default => '状态未知',
        }];
    }

    public function reinstall(Supplier $supplier, int $id, string $osId): array
    {
        $imageId = RainyunValues::integer($osId, '系统镜像 ID');
        $deadline = microtime(true) + 55;
        $remaining = static function () use ($deadline): int {
            $seconds = (int) floor($deadline - microtime(true));
            throw_if($seconds < 1, new BusinessException('自动关机等待超时，重装尚未提交，请稍后重试', 42200));

            return min(8, $seconds);
        };
        $detail = $this->client->detail($supplier, $id, $remaining());
        $images = $this->catalog->images($supplier, $detail['Data']['Plan'] ?? [], $remaining());
        throw_if(! in_array($imageId, array_column($images, 'id'), true), new BusinessException('该系统镜像不可用于当前实例', 42200));
        $status = $detail['Data']['Status'] ?? '';
        throw_if(! in_array($status, ['running', 'stopped', 'stopping'], true), new BusinessException('实例正在执行其他任务，请稍后重装', 42200));
        if ($status === 'running') {
            $this->client->request($supplier, 'POST', $this->client->hostPath($id, 'stop'), timeout: $remaining());
        }
        // 用户只提交一次重装；后台等待关机确认，未停止前绝不提交 changeos。
        for ($attempt = 0; $status !== 'stopped' && $attempt < 15; $attempt++) {
            $this->wait !== null ? ($this->wait)(2) : sleep(2);
            $status = $this->client->detail($supplier, $id, $remaining())['Data']['Status'] ?? '';
            throw_if(! in_array($status, ['running', 'stopped', 'stopping'], true), new BusinessException('关机期间实例状态异常，重装未提交', 42200));
        }
        throw_if($status !== 'stopped', new BusinessException('自动关机等待超时，重装尚未提交，请稍后重试', 42200));
        $this->client->request($supplier, 'POST', $this->client->hostPath($id, 'changeos'), ['os_id' => $imageId], timeout: $remaining());

        return ['status' => 200, 'msg' => '已自动关机并提交重装，请等待系统安装完成', 'data' => ['submitted' => true]];
    }

    public function chart(Supplier $supplier, int $id, array $query): array
    {
        $type = (string) ($query['type'] ?? 'cpu');
        throw_if(! in_array($type, ['cpu', 'memory', 'disk', 'flow', 'network'], true), new BusinessException('监控类型无效', 42200));
        $start = (int) ($query['start'] ?? (time() - 3600));
        $end = (int) ($query['end'] ?? time());
        $start = $start > 100000000000 ? intdiv($start, 1000) : $start;
        $end = $end > 100000000000 ? intdiv($end, 1000) : $end;
        throw_if($start <= 0 || $end <= $start || $end - $start > 7 * 86400, new BusinessException('监控时间范围须在七天以内', 42200));
        $data = $this->client->request($supplier, 'GET', $this->client->hostPath($id, 'monitor'), ['start_date' => $start, 'end_date' => $end]);
        throw_if(! is_array($data) || ! is_array($data['Columns'] ?? null) || ! is_array($data['Values'] ?? null), new BusinessException('监控返回格式不正确', 42200));
        $columns = array_flip($data['Columns']);
        $fields = match ($type) {
            'cpu' => ['cpu'], 'memory' => ['freemem'], 'disk' => ['diskread', 'diskwrite'], default => ['netin', 'netout']
        };
        foreach (array_merge(['time'], $fields) as $field) {
            throw_if(! isset($columns[$field]), new BusinessException('监控缺少所需指标', 42200));
        }
        $maxMem = $type === 'memory' ? (int) ($this->client->detail($supplier, $id)['Data']['UsageData']['MaxMem'] ?? 0) : 0;
        $values = $data['Values'];
        usort($values, fn ($a, $b) => ($a[$columns['time']] ?? 0) <=> ($b[$columns['time']] ?? 0));
        $list = [];
        foreach ($fields as $index => $field) {
            $list[$index] = [];
            foreach ($values as $row) {
                if (! isset($row[$columns[$field]], $row[$columns['time']])) {
                    continue;
                }
                $value = (float) $row[$columns[$field]];
                $value = match ($type) {
                    'cpu' => $value * 100, 'memory' => max(0, $maxMem - $value) / 1073741824, default => $value / 1024
                };
                $list[$index][] = ['time' => date('Y-m-d H:i:s', (int) $row[$columns['time']]), 'value' => round($value, 2)];
            }
        }

        return ['status' => 200, 'data' => ['chart_type' => 'line', 'unit' => match ($type) {
            'cpu' => '%', 'memory' => 'GB', default => 'KB/s'
        },
            'label' => match ($type) {
                'cpu' => ['CPU 使用率'], 'memory' => ['已用内存'], 'disk' => ['读取', '写入'], default => ['入站', '出站']
            }, 'list' => $list]];
    }
}
