<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class QuoteServiceTrafficPackageRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'target_value' => ['required', 'integer', 'min:1'],
            'per_page' => ['prohibited'],
        ];
    }
}