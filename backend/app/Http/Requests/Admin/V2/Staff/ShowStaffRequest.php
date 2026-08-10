<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Staff;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\AdminUser;

class ShowStaffRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'staff' => ['required', 'integer', 'min:1'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $staff = $this->route('staff');

        $this->merge([
            'staff' => $staff instanceof AdminUser ? $staff->getKey() : $staff,
        ]);
    }
}
