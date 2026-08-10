<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class MonitorRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'max:100'],
            'range' => ['nullable', 'string', 'in:3h,24h,7d,30d'],
            'start' => ['nullable', 'integer', 'min:0'],
            'end' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
