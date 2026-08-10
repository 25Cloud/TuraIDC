<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Staff;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ResetStaffPasswordRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'max:64', 'confirmed'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function password(): string
    {
        return (string) $this->validated()['password'];
    }
}
