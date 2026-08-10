<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ServicePasswordResetActionRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
            'per_page' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->safe()->only([
            'password',
            'password_confirmation',
        ]);
    }
}
