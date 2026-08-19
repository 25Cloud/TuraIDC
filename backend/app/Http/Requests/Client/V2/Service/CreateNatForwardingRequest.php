<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class CreateNatForwardingRequest extends ClientFormRequest
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
