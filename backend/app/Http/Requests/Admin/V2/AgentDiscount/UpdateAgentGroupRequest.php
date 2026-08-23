<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\AgentDiscount;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class UpdateAgentGroupRequest extends AdminFormRequest
{
    public function rules(): array
    {
        $agentGroup = $this->route('agent_group') ?? $this->route('agentGroup');

        return [
            'name' => ['required', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:30', Rule::unique('agent_groups', 'code')->ignore($agentGroup?->id)],
            'status' => ['nullable', 'integer', Rule::in([0, 1])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'remark' => ['nullable', 'string', 'max:255'],
        ];
    }
}
