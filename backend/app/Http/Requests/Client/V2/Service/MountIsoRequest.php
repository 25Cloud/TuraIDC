<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class MountIsoRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            // ISO 标识由上游给（老接口是数字 ID，也可能是文件名），一律按字符串收
            'iso_id' => ['required', 'string', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'iso_id.required' => '请选择要挂载的 ISO',
            'iso_id.max' => 'ISO 标识过长',
        ];
    }
}
