<?php

namespace App\Http\Requests\Admin\V2\Auth;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UpdateProfileRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return array_merge([
            'nickname' => ['nullable', 'string', 'max:50'],
            'email' => ['missing'],
        ], [
            'per_page' => ['prohibited'],
        ]);
    }
}
