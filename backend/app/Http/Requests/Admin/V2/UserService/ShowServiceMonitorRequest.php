<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ShowServiceMonitorRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'max:100'],
            'range' => ['nullable', 'string', 'in:3h,24h,7d,30d'],
            'start' => ['nullable', 'integer', 'min:0'],
            'end' => ['nullable', 'integer', 'min:0'],
        ];
    }
}