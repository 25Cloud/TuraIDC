<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Auth;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UpdatePasswordRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return array_merge([
            'current_password' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'min:8', 'max:64', 'confirmed'],
        ], [
            'per_page' => ['prohibited'],
        ]);
    }

    public function payload(): array
    {
        return $this->safe()->only(['current_password', 'password']);
    }
}
