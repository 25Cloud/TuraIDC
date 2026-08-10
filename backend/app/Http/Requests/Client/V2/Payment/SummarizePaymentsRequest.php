<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Payment;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class SummarizePaymentsRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }
}
