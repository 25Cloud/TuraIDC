<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Role;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListRolesRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function filters(): array
    {
        return $this->safe()->only(['keyword']);
    }
}
