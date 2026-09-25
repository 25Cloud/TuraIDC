<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ServiceRescueActionRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'system' => ['required', 'string', 'in:1,2'],
            'per_page' => ['prohibited'],
        ];
    }
}