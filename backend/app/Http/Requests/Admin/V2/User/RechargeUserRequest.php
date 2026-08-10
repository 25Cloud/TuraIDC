<?php

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class RechargeUserRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'not_in:0', 'min:-999999', 'max:999999'],
            'remark' => ['required', 'string', 'max:200'],
            ...$this->allPaginationRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'amount.not_in' => '调整金额不能为 0',
            'remark.required' => '请填写操作备注',
        ];
    }

    public function payload(): array
    {
        return $this->safe()->only([
            'amount',
            'remark',
        ]);
    }
}
