<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class UpdateRemarkRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'remark' => ['nullable', 'string', 'max:120'],
        ];
    }
}
