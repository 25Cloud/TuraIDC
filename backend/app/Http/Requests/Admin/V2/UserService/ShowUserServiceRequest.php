<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\User;

class ShowUserServiceRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user' => ['required', 'integer', 'min:1'],
            'service' => ['required', 'integer', 'min:1'],
            'refresh' => ['sometimes', 'boolean'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function validationData(): array
    {
        $user = $this->route('user');

        return array_merge(parent::validationData(), [
            'user' => $user instanceof User ? (int) $user->id : $user,
            'service' => $this->route('service'),
        ]);
    }
}
