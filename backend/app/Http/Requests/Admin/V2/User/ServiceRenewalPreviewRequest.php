<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ServiceRenewalPreviewRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'billing_cycle' => ['nullable', 'string', 'max:40'],
            'per_page' => ['prohibited'],
        ];
    }

    public function billingCycle(): ?string
    {
        $value = (string) ($this->validated()['billing_cycle'] ?? '');

        return $value !== '' ? $value : null;
    }
}
