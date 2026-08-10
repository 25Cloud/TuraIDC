<?php

namespace App\Http\Requests\Client\V2\Referral;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class RewardsRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
