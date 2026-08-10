<?php

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListUserOperationLogsRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'keyword' => ['nullable', 'string', 'max:100'],
            'start_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'source' => ['nullable', 'string', 'in:web,api'],
        ], $this->legacyPaginationRules());
    }

    public function filters(): array
    {
        return $this->safe()->only(['keyword', 'start_date', 'end_date', 'ip_address', 'source']);
    }
}
