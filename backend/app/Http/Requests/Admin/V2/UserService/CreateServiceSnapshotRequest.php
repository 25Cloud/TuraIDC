<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class CreateServiceSnapshotRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'type' => ['nullable', 'string', 'in:snap,backup'],
            'disk_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => '请填写快照名称',
            'name.max' => '快照名称不能超过 64 个字符',
            'type.in' => '快照类型只能是快照或备份',
            'disk_id.integer' => '磁盘 ID 必须是整数',
            'disk_id.min' => '磁盘 ID 不正确',
        ];
    }
}
