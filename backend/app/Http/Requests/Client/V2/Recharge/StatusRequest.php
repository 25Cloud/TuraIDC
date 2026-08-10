<?php

namespace App\Http\Requests\Client\V2\Recharge;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class StatusRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'poll_token' => ['required', 'string', 'min:20', 'max:120'],
        ];
    }
}
