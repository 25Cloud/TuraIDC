<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Staff;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListStaffRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'keyword' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'role_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ]);
    }

    public function filters(): array
    {
        return $this->safe()->only(['keyword', 'status', 'role_id']);
    }
}
