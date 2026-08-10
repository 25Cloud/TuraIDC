<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class QuoteTrafficPackageRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'target_value' => ['required', 'integer', 'min:1'],
        ];
    }
}
