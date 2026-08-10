<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Action;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class ClientActionRequest extends ClientFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['prohibited'],
        ];
    }
}
