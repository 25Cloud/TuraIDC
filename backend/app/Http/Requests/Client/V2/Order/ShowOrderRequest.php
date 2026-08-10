<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Order;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class ShowOrderRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'min:1'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'id' => $this->route('id'),
        ]);
    }
}
