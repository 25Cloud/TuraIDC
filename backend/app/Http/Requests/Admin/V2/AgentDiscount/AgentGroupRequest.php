<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\AgentDiscount;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class AgentGroupRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:30'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function payload(): array
    {
        return $this->safe()->only(['name', 'code', 'status', 'sort_order', 'remark']);
    }
}
