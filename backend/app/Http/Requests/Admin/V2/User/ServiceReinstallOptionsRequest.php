<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ServiceReinstallOptionsRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'force_refresh' => ['nullable', 'boolean'],
            'per_page' => ['prohibited'],
        ];
    }

    public function forceRefresh(): bool
    {
        return filter_var($this->validated()['force_refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
