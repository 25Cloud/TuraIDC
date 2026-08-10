<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Action\ClientActionRequest;

class PasswordResetActionRequest extends ClientActionRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
        ]);
    }
}
