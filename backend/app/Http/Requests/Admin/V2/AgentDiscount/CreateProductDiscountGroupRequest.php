<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\AgentDiscount;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class CreateProductDiscountGroupRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:30', Rule::unique('product_discount_groups', 'code')],
            'min_discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cost_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'integer', Rule::in([0, 1])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'remark' => ['nullable', 'string', 'max:255'],
        ];
    }
}
