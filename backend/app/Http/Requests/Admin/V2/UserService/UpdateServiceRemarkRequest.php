<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UpdateServiceRemarkRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'remark' => ['nullable', 'string', 'max:120'],
        ];
    }
}