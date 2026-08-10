<?php

namespace App\Http\Requests\Client\V2\Auth;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class UpdatePasswordRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'oldPassword' => 'required|string|min:6',
            'newPassword' => 'required|string|min:6',
            'confirmPassword' => 'required|string|same:newPassword',
        ];
    }
}
