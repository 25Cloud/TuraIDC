<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Ticket;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class ServiceOptionsRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
