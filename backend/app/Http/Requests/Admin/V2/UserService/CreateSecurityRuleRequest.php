<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class CreateSecurityRuleRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'direction' => ['required', 'string', 'max:20'],
            'protocol' => ['required', 'string', 'max:50'],
            'port' => ['required', 'string', 'max:100'],
            'ip' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}