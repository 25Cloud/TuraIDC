<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Staff;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UpdateStaffStatusRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function enabled(): bool
    {
        return (bool) $this->validated()['enabled'];
    }
}
