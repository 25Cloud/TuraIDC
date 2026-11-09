<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

/**
 * 管理端变更实例规格。
 *
 * 全部字段可选：只校验「给了的值是否合理」，不强制填任何一项
 * （至少填一项由 `ServiceHostConfigService` 判定，避免在表单层写出重复的白名单）。
 */
class ChangeServiceHostConfigRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'cpu' => ['nullable', 'integer', 'min:1', 'max:256'],
            'memory' => ['nullable', 'integer', 'min:128', 'max:1048576'],
            'in_bw' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'out_bw' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'flow' => ['nullable', 'integer', 'min:1', 'max:10485760'],
            'ip_num' => ['nullable', 'integer', 'min:1', 'max:253'],
            'ip_group' => ['nullable', 'string', 'max:50'],
            'ipv6_num' => ['nullable', 'integer', 'min:1', 'max:253'],
            'disk_size' => ['nullable', 'integer', 'min:1', 'max:65536'],
            'disk_id' => ['nullable', 'integer', 'min:1'],
            'snap_num' => ['nullable', 'integer', 'min:0', 'max:1024'],
            'backup_num' => ['nullable', 'integer', 'min:0', 'max:1024'],
        ];
    }

    public function messages(): array
    {
        return [
            'cpu.integer' => 'CPU 核数必须是整数',
            'cpu.min' => 'CPU 核数不能小于 1',
            'cpu.max' => 'CPU 核数不能大于 256',
            'memory.integer' => '内存必须是整数（MB）',
            'memory.min' => '内存不能小于 128MB',
            'in_bw.integer' => '入方向带宽必须是整数（Mbps）',
            'out_bw.integer' => '出方向带宽必须是整数（Mbps）',
            'flow.integer' => '流量上限必须是整数（GB）',
            'ip_num.integer' => '附加 IPv4 数量必须是整数',
            'ip_num.max' => '附加 IPv4 数量不能大于 253',
            'ipv6_num.integer' => '附加 IPv6 数量必须是整数',
            'ipv6_num.max' => '附加 IPv6 数量不能大于 253',
            'disk_size.integer' => '数据盘容量必须是整数（GB）',
            'disk_id.integer' => '数据盘 ID 必须是整数',
            'snap_num.integer' => '快照配额必须是整数',
            'backup_num.integer' => '备份配额必须是整数',
        ];
    }
}
