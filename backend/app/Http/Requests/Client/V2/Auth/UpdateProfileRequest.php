<?php

namespace App\Http\Requests\Client\V2\Auth;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class UpdateProfileRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'nickname' => 'nullable|string|max:50',
        ];
    }
}
