<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class CreateServiceNatForwardingRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'ext_port' => ['nullable', 'integer', 'between:1,65535'],
            'int_port' => ['required', 'integer', 'between:1,65535'],
            'protocol' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.-]+\z/', 'max:50'],
        ];
    }
}