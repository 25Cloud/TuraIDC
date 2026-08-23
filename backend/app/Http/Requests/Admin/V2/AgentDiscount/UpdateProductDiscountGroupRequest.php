<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\AgentDiscount;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class UpdateProductDiscountGroupRequest extends AdminFormRequest
{
    public function rules(): array
    {
        $group = $this->route('product_discount_group') ?? $this->route('productDiscountGroup');

        return [
            'name' => ['required', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:30', Rule::unique('product_discount_groups', 'code')->ignore($group?->id)],
            'min_discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cost_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'integer', Rule::in([0, 1])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'remark' => ['nullable', 'string', 'max:255'],
        ];
    }
}
