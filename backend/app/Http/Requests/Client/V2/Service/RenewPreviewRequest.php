<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class RenewPreviewRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'billing_cycle' => ['nullable', 'string', 'max:30'],
            'user_coupon_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
