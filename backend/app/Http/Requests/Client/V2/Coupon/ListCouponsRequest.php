<?php

namespace App\Http\Requests\Client\V2\Coupon;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class ListCouponsRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'status' => ['nullable', 'in:all,available,used_up,expired'],
            'keyword' => ['nullable', 'string', 'max:100'],
        ]);
    }

    public function filters(): array
    {
        return $this->safe()->only([
            'status',
            'keyword',
        ]);
    }
}
