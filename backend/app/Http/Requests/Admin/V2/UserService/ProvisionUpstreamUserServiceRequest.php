<?php

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ProvisionUpstreamUserServiceRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            // 1 = 管理员已到上游核实过「上一轮没有生成订单」，允许清掉未确认标记重新下单
            'force' => ['sometimes', 'boolean'],
            'remark' => ['nullable', 'string', 'max:200'],
            ...$this->allPaginationRules(),
        ];
    }

    public function payload(): array
    {
        $data = $this->safe()->only(['force', 'remark']);

        return [
            'force' => filter_var($data['force'] ?? false, FILTER_VALIDATE_BOOL),
            'remark' => $data['remark'] ?? null,
        ];
    }
}
