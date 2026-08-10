<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Role;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class CopyRoleRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }
}
