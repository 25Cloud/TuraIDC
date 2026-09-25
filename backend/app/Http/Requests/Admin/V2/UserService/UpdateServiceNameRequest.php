<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UpdateServiceNameRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }
}