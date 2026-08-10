<?php

namespace App\Http\Requests\Client\V2\Referral;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class AccountLogsRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'event_type' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
