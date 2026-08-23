<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\AgentDiscount;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class AgentGroupDiscountRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*.agent_group_id' => ['required', 'integer', 'exists:agent_groups,id'],
            'items.*.product_discount_group_id' => ['required', 'integer', 'exists:product_discount_groups,id'],
            'items.*.discount_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function items(): array
    {
        return $this->validated('items');
    }
}
