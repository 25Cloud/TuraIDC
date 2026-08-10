<?php

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class RefreshUserServiceStatusesRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'service_ids' => ['required', 'array', 'min:1', 'max:50'],
            'service_ids.*' => ['integer', 'min:1'],
            ...$this->allPaginationRules(),
        ];
    }
}
