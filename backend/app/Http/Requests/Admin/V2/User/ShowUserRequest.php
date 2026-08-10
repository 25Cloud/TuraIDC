<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\User;

class ShowUserRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'user' => ['required', 'integer', 'min:1'],
            'per_page' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $user = $this->route('user');

        $this->merge([
            'user' => $user instanceof User ? $user->getKey() : $user,
        ]);
    }
}
