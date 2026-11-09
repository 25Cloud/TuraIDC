<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class SetServiceIsoBootOrderRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'bootorder' => ['required', 'string', 'in:disk,cdrom'],
        ];
    }

    public function messages(): array
    {
        return [
            'bootorder.required' => '请选择启动项',
            'bootorder.in' => '启动项只能是硬盘优先或光驱优先',
        ];
    }
}
