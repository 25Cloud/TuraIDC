<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Ai;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class ConfirmActionRequest extends ClientFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm_token' => ['required', 'string', 'max:128'],
        ];
    }
}
