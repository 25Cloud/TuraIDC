<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class MonitorBatchRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'types' => ['nullable', 'array', 'max:20'],
            'types.*' => ['nullable', 'string', 'max:100'],
            'range' => ['nullable', 'string', 'in:3h,24h,7d,30d'],
            'start' => ['nullable', 'integer', 'min:0'],
            'end' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
