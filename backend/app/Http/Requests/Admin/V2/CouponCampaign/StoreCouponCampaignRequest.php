<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\CouponCampaign;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class StoreCouponCampaignRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['prohibited'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'trigger_time' => ['required', 'string', 'regex:/^\\d{2}:\\d{2}(:\\d{2})?$/'],
            'issue_quantity' => ['required', 'integer', 'min:1'],
            'valid_duration_hours' => ['nullable', 'integer', 'min:1', 'max:87600'],
            'discount_type' => ['required', Rule::in(['fixed', 'percentage'])],
            'discount_scope' => ['required', Rule::in(['first_month', 'recurring', 'renew'])],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'billing_cycles' => ['nullable', 'array'],
            'billing_cycles.*' => ['string', 'max:30'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'first_order_only' => ['nullable', 'boolean'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:0,1'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'remark' => ['nullable', 'string', 'max:255'],
        ];
    }
}
