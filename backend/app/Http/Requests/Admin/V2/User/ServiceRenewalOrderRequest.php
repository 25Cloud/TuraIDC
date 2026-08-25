<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ServiceRenewalOrderRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'billing_cycle' => ['required', 'string', 'max:40'],
            'per_page' => ['prohibited'],
        ];
    }

    public function billingCycle(): string
    {
        return (string) $this->validated()['billing_cycle'];
    }
}
