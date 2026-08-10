<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UpdateUserStatusRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'per_page' => ['prohibited'],
        ];
    }

    public function enabled(): bool
    {
        return (bool) $this->validated()['enabled'];
    }
}
