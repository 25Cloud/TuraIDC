<?php

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListUserTicketsRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'status' => ['nullable', 'in:0,1,2,3'],
            'priority' => ['nullable', 'in:1,2,3,4'],
        ], $this->legacyPaginationRules());
    }

    public function filters(): array
    {
        return $this->safe()->only([
            'status',
            'priority',
        ]);
    }
}
