<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class SetBootOrderRequest extends ClientFormRequest
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
