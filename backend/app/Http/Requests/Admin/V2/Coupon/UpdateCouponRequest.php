<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Coupon;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class UpdateCouponRequest extends AdminFormRequest
{
    public function rules(): array
    {
        $coupon = $this->route('coupon');

        return [
            'per_page' => ['prohibited'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('coupons', 'code')->ignore($coupon?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', Rule::in(['fixed', 'percentage'])],
            'discount_scope' => ['required', Rule::in(['first_month', 'recurring', 'renew'])],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'distribution_type' => ['required', Rule::in(['public', 'private'])],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'billing_cycles' => ['nullable', 'array'],
            'billing_cycles.*' => ['string', 'max:30'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'first_order_only' => ['nullable', 'boolean'],
            'total_usage_limit' => ['nullable', 'integer', 'min:0'],
            'per_user_limit' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:0,1'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'remark' => ['nullable', 'string', 'max:255'],
        ];
    }
}
