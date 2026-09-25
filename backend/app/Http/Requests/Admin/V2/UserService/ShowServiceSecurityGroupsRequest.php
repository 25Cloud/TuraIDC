<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ShowServiceSecurityGroupsRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'fresh' => ['sometimes', 'boolean'],
            'per_page' => ['prohibited'],
        ];
    }
}