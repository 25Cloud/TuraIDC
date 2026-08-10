<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Order;

use App\Constants\OrderType;
use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use App\Http\Requests\Concerns\HasDateRangeFilter;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends ClientFormRequest
{
    use HasDateRangeFilter;

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', Rule::in(OrderType::values())],
            'keyword' => ['nullable', 'string', 'max:80'],
            ...$this->dateRangeRules(),
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }
}
