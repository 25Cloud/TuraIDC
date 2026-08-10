<?php

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ManualProvisionUserServiceRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'remark' => ['nullable', 'string', 'max:200'],
            ...$this->allPaginationRules(),
        ];
    }

    public function payload(): array
    {
        return $this->safe()->only(['remark']);
    }
}
