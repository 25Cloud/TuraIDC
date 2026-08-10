<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Order;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class SummarizeOrdersRequest extends ClientFormRequest
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
