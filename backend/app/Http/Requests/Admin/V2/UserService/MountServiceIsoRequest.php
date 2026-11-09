<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class MountServiceIsoRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
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
