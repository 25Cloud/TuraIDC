<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class CreateServiceHostUpgradeOrderRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'billing_cycle' => ['required', 'string', 'max:30'],
            'promo_code' => ['nullable', 'string', 'max:100'],
            'per_page' => ['prohibited'],
        ];
    }
}