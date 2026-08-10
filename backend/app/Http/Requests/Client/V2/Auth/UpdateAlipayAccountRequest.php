<?php

namespace App\Http\Requests\Client\V2\Auth;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class UpdateAlipayAccountRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'real_name' => ['required', 'string', 'max:80'],
            'account' => ['required', 'regex:/^1[3-9]\d{9}$/'],
            'code' => ['required', 'string', 'size:6'],
        ];
    }
}
