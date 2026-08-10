<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\MemberLevel;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class CreateMemberLevelRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'code' => ['nullable', 'string', 'max:30'],
            'sales_amount_min' => ['required', 'numeric', 'min:0'],
            'sales_amount_max' => ['nullable', 'numeric', 'min:0'],
            'reward_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'remark' => ['nullable', 'string', 'max:255'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function payload(): array
    {
        return $this->safe()->only([
            'name',
            'code',
            'sales_amount_min',
            'sales_amount_max',
            'reward_rate',
            'status',
            'sort_order',
            'remark',
        ]);
    }
}
