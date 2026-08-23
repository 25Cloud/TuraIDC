<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\AgentDiscount;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Validator;

class SaveAgentGroupDiscountsRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'discounts' => ['required', 'array'],
            'discounts.*' => ['required', 'array'],
            'discounts.*.product_discount_group_id' => ['required', 'integer', 'exists:product_discount_groups,id'],
            'discounts.*.discount_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'discounts.*.min_discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('discounts', []) as $index => $discount) {
                $rate = (float) ($discount['discount_rate'] ?? 0);
                $minimum = (float) ($discount['min_discount_rate'] ?? 0);
                if ($rate < $minimum) {
                    $validator->errors()->add("discounts.{$index}.discount_rate", '折扣率不能低于商品折扣组最低折扣率');
                }
            }
        });
    }
}
